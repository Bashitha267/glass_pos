<?php
require_once '../auth.php';
require_once '../config.php';
checkAuth();

if (!isAdmin()) {
    header('Location: ../sale/dashboard.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? null;

// AJAX Handlers (Synced with nwdelivery.php logic)
if ($action == 'search_bank') {
    $term = '%' . $_GET['term'] . '%';
    $stmt = $pdo->prepare("SELECT * FROM banks WHERE name LIKE ? LIMIT 5");
    $stmt->execute([$term]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action == 'create_bank') {
    $name = $_POST['name'];
    $acc_no = $_POST['acc_no'];
    $acc_name = $_POST['acc_name'];
    $stmt = $pdo->prepare("INSERT INTO banks (name, account_number, account_name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE account_number=?, account_name=?");
    $stmt->execute([$name, $acc_no, $acc_name, $acc_no, $acc_name]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM banks WHERE name='$name'")->fetchColumn(), 'name' => $name]);
    exit;
}

if ($action == 'search_cheque_customer') {
    $term = '%' . $_GET['term'] . '%';
    $stmt = $pdo->prepare("SELECT id, name FROM customers WHERE name LIKE ? LIMIT 5");
    $stmt->execute([$term]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action == 'save_payment') {
    try {
        $dc_id = $_POST['dc_id'];
        $type = $_POST['type'];
        $amount = (float)$_POST['amount'];
        $date = $_POST['date'];
        $bank_id = !empty($_POST['bank_id']) ? $_POST['bank_id'] : null;
        $chq_no = $_POST['chq_no'] ?: null;
        $chq_cust_id = !empty($_POST['chq_cust_id']) ? $_POST['chq_cust_id'] : null;
        
        $proof = null;
        if (isset($_FILES['proof']) && $_FILES['proof']['error'] == 0) {
            $proof = time() . '_' . $_FILES['proof']['name'];
            if (!is_dir('../uploads/payments')) mkdir('../uploads/payments', 0777, true);
            move_uploaded_file($_FILES['proof']['tmp_name'], '../uploads/payments/' . $proof);
        }

        $stmt = $pdo->prepare("INSERT INTO delivery_payments (delivery_customer_id, amount, payment_type, bank_id, cheque_number, proof_image, payment_date, cheque_customer_id, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$dc_id, $amount, $type, $bank_id, $chq_no, $proof, $date, $chq_cust_id, $user_id]);
        
        // Update customer payment status
        $stmtStatus = $pdo->prepare("SELECT dc.subtotal, dc.discount, (SELECT SUM(amount) FROM delivery_payments WHERE delivery_customer_id = dc.id) as total_paid FROM delivery_customers dc WHERE dc.id = ?");
        $stmtStatus->execute([$dc_id]);
        $status = $stmtStatus->fetch();
        if ($status) {
            $new_status = ($status['total_paid'] >= ($status['subtotal'] - $status['discount'])) ? 'completed' : 'pending';
            $pdo->prepare("UPDATE delivery_customers SET payment_status = ? WHERE id = ?")->execute([$new_status, $dc_id]);
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

if ($action == 'get_history') {
    $dc_id = (int)$_GET['dc_id'];
    $stmt = $pdo->prepare("
        SELECT dp.*, b.name as bank_name, b.account_number as bank_acc, cust.name as cheque_payer 
        FROM delivery_payments dp 
        LEFT JOIN banks b ON dp.bank_id = b.id 
        LEFT JOIN customers cust ON dp.cheque_customer_id = cust.id 
        WHERE dp.delivery_customer_id = ? 
        ORDER BY dp.payment_date DESC
    ");
    $stmt->execute([$dc_id]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action == 'delete_payment') {
    try {
        $pay_id = (int)$_POST['id'];
        $stmt = $pdo->prepare("SELECT delivery_customer_id FROM delivery_payments WHERE id = ?");
        $stmt->execute([$pay_id]);
        $pay = $stmt->fetch();
        if (!$pay) throw new Exception("Payment not found");
        
        $dc_id = $pay['delivery_customer_id'];
        $pdo->prepare("DELETE FROM delivery_payments WHERE id = ?")->execute([$pay_id]);
        
        // Update status
        $stmtStatus = $pdo->prepare("SELECT dc.subtotal, dc.discount, (SELECT SUM(amount) FROM delivery_payments WHERE delivery_customer_id = dc.id) as total_paid FROM delivery_customers dc WHERE dc.id = ?");
        $stmtStatus->execute([$dc_id]);
        $status = $stmtStatus->fetch();
        if ($status) {
            $new_status = ($status['total_paid'] >= ($status['subtotal'] - $status['discount'])) ? 'completed' : 'pending';
            $pdo->prepare("UPDATE delivery_customers SET payment_status = ? WHERE id = ?")->execute([$new_status, $dc_id]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

if ($action == 'live_search') {
    // Exact same logic as main page to ensure consistency
    $search = $_GET['search'] ?? '';
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $p_type_filter = $_GET['type'] ?? '';
    $month = $_GET['month'] ?? '';
    $year = $_GET['year'] ?? '';

    $where = ["1=1"];
    $params = [];

    if ($search) {
        $s = "%$search%";
        $where[] = "(c.name LIKE ? 
                    OR CAST(dc.delivery_id AS CHAR) LIKE ? 
                    OR CONCAT('DEL-', LPAD(dc.delivery_id, 4, '0')) LIKE ? 
                    OR CONCAT('#DEL-', LPAD(dc.delivery_id, 4, '0')) LIKE ? 
                    OR EXISTS (SELECT 1 FROM delivery_payments dp WHERE dp.delivery_customer_id = dc.id AND dp.cheque_number LIKE ?))";
        $params = array_merge($params, [$s, $s, $s, $s, $s]);
    }
    if ($start_date) { $where[] = "d.delivery_date >= ?"; $params[] = $start_date; }
    if ($end_date) { $where[] = "d.delivery_date <= ?"; $params[] = $end_date; }
    if ($month && $year && !$start_date && !$end_date) {
        $where[] = "MONTH(d.delivery_date) = ? AND YEAR(d.delivery_date) = ?";
        $params[] = $month; $params[] = $year;
    }
    if ($status_filter) { $where[] = "dc.payment_status = ?"; $params[] = $status_filter; }
    if ($p_type_filter) { $where[] = "EXISTS (SELECT 1 FROM delivery_payments dp WHERE dp.delivery_customer_id = dc.id AND dp.payment_type = ?)"; $params[] = $p_type_filter; }

    $whereClause = implode(" AND ", $where);

    // Get Stats
    $statsStmt = $pdo->prepare("SELECT SUM(dp.amount) as total_payments, SUM(CASE WHEN dp.payment_type = 'Cash' THEN dp.amount ELSE 0 END) as cash_total, SUM(CASE WHEN dp.payment_type = 'Cheque' THEN dp.amount ELSE 0 END) as cheque_total, SUM(CASE WHEN dp.payment_type = 'Account Transfer' THEN dp.amount ELSE 0 END) as transfer_total, SUM(CASE WHEN dp.payment_type = 'Card' THEN dp.amount ELSE 0 END) as card_total FROM delivery_payments dp JOIN delivery_customers dc ON dp.delivery_customer_id = dc.id JOIN deliveries d ON dc.delivery_id = d.id LEFT JOIN customers c ON dc.customer_id = c.id WHERE $whereClause");
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    $pendingStmt = $pdo->prepare("SELECT SUM(dc.subtotal - dc.discount - (SELECT COALESCE(SUM(amount), 0) FROM delivery_payments WHERE delivery_customer_id = dc.id)) as pending_total FROM delivery_customers dc JOIN deliveries d ON dc.delivery_id = d.id LEFT JOIN customers c ON dc.customer_id = c.id WHERE $whereClause");
    $pendingStmt->execute($params);
    $pending_total = $pendingStmt->fetchColumn();

    // Get Records
    $recordsStmt = $pdo->prepare("SELECT dc.*, c.name as customer_name, d.delivery_date, (SELECT COALESCE(SUM(amount), 0) FROM delivery_payments WHERE delivery_customer_id = dc.id) as total_paid FROM delivery_customers dc JOIN deliveries d ON dc.delivery_id = d.id JOIN customers c ON dc.customer_id = c.id WHERE $whereClause ORDER BY d.delivery_date DESC");
    $recordsStmt->execute($params);
    $records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'stats' => [
            'total_received' => number_format($stats['total_payments'] ?? 0, 2),
            'pending_balance' => number_format($pending_total ?? 0, 2),
            'cash' => number_format($stats['cash_total'] ?? 0, 2),
            'cheque' => number_format($stats['cheque_total'] ?? 0, 2),
            'transfer' => number_format($stats['transfer_total'] ?? 0, 2),
            'card' => number_format($stats['card_total'] ?? 0, 2)
        ],
        'records' => $records
    ]);
    exit;
}

// ---------------------------------------------------------------------------------------------------------------------
// MAIN PAGE LOGIC
// ---------------------------------------------------------------------------------------------------------------------

// Filters
$search = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$status_filter = $_GET['status'] ?? '';
$p_type_filter = $_GET['type'] ?? '';
$month = $_GET['month'] ?? '';
$year = $_GET['year'] ?? '';

$where = ["1=1"];
$params = [];

if ($search) {
    $s = "%$search%";
    $where[] = "(c.name LIKE ? 
                OR CAST(dc.delivery_id AS CHAR) LIKE ? 
                OR CONCAT('DEL-', LPAD(dc.delivery_id, 4, '0')) LIKE ? 
                OR CONCAT('#DEL-', LPAD(dc.delivery_id, 4, '0')) LIKE ? 
                OR EXISTS (SELECT 1 FROM delivery_payments dp WHERE dp.delivery_customer_id = dc.id AND dp.cheque_number LIKE ?))";
    $params = array_merge($params, [$s, $s, $s, $s, $s]);
}

if ($start_date) {
    $where[] = "d.delivery_date >= ?";
    $params[] = $start_date;
}
if ($end_date) {
    $where[] = "d.delivery_date <= ?";
    $params[] = $end_date;
}

if ($month && $year && !$start_date && !$end_date) {
    $where[] = "MONTH(d.delivery_date) = ? AND YEAR(d.delivery_date) = ?";
    $params[] = $month;
    $params[] = $year;
}

if ($status_filter) {
    $where[] = "dc.payment_status = ?";
    $params[] = $status_filter;
}

if ($p_type_filter) {
    $where[] = "EXISTS (SELECT 1 FROM delivery_payments dp WHERE dp.delivery_customer_id = dc.id AND dp.payment_type = ?)";
    $params[] = $p_type_filter;
}

$whereClause = implode(" AND ", $where);

// Stats
$statsQuery = "
    SELECT 
        SUM(dp.amount) as total_payments,
        SUM(CASE WHEN dp.payment_type = 'Cash' THEN dp.amount ELSE 0 END) as cash_total,
        SUM(CASE WHEN dp.payment_type = 'Cheque' THEN dp.amount ELSE 0 END) as cheque_total,
        SUM(CASE WHEN dp.payment_type = 'Account Transfer' THEN dp.amount ELSE 0 END) as transfer_total,
        SUM(CASE WHEN dp.payment_type = 'Card' THEN dp.amount ELSE 0 END) as card_total
    FROM delivery_payments dp
    JOIN delivery_customers dc ON dp.delivery_customer_id = dc.id
    JOIN deliveries d ON dc.delivery_id = d.id
    LEFT JOIN customers c ON dc.customer_id = c.id
    WHERE $whereClause
";
$statsStmt = $pdo->prepare($statsQuery);
$statsStmt->execute($params);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$pendingBalanceQuery = "
    SELECT SUM(dc.subtotal - dc.discount - (SELECT COALESCE(SUM(amount), 0) FROM delivery_payments WHERE delivery_customer_id = dc.id)) as pending_total
    FROM delivery_customers dc
    JOIN deliveries d ON dc.delivery_id = d.id
    LEFT JOIN customers c ON dc.customer_id = c.id
    WHERE $whereClause
";
$pendingStmt = $pdo->prepare($pendingBalanceQuery);
$pendingStmt->execute($params);
$pending_total = $pendingStmt->fetchColumn();

// Main Table Data
$query = "
    SELECT 
        dc.*, 
        c.name as customer_name, 
        d.delivery_date, 
        (SELECT COALESCE(SUM(amount), 0) FROM delivery_payments WHERE delivery_customer_id = dc.id) as total_paid
    FROM delivery_customers dc
    JOIN deliveries d ON dc.delivery_id = d.id
    JOIN customers c ON dc.customer_id = c.id
    WHERE $whereClause
    ORDER BY d.delivery_date DESC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Payments | Crystal POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@300;400;600;700&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc url('../assests/glass_bg.png') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
        }

        .glass-header {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 1);
            box-shadow: 0 4px 12px -2px rgba(0,0,0,0.05);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid white;
            border-radius: 24px;
            box-shadow: 0 10px 30px -5px rgba(0,0,0,0.04);
        }

        .input-glass {
            background: rgba(255, 255, 255, 0.6);
            border: 1px solid #e2e8f0;
            padding: 10px 16px;
            border-radius: 14px;
            outline: none;
            transition: all 0.3s;
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
        }

        /* Fix for visibility in dark selects */
        select.input-glass option {
            background-color: #0f172a;
            color: white;
            padding: 10px;
        }

        .input-glass:focus {
            border-color: #6366f1;
            background: white;
            box-shadow: 0 0 15px rgba(99, 102, 241, 0.1);
        }

        .table-header {
            background: #1e293b;
            color: white;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .stat-card {
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .stat-card:hover { transform: translateY(-5px); }
        
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="pb-20">

    <header class="glass-header sticky top-0 z-40 py-4 mb-8">
        <div class="max-w-[1600px] mx-auto px-6 flex items-center justify-between">
            <div class="flex items-center space-x-5">
                <a href="dashboard.php" class="text-slate-800 hover:text-indigo-600 transition-colors p-2.5 rounded-2xl hover:bg-slate-100">
                    <i class="fa-solid fa-arrow-left text-xl"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-black text-slate-900 font-['Outfit'] tracking-tight">Payment Management</h1>
                    <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mt-0.5">Finance & AR Control Center</p>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-[1600px] mx-auto px-6">
        
        <!-- Stats Row -->
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-6 mb-8">
            <div class="glass-card p-6 border-l-4 border-indigo-500 stat-card">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">Total Received</p>
                <h3 id="stat-total-received" class="text-xl font-black text-slate-900 tracking-tight">LKR <?php echo number_format($stats['total_payments'] ?? 0, 2); ?></h3>
            </div>
            <div class="glass-card p-6 border-l-4 border-rose-500 stat-card">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">Pending Balance</p>
                <h3 id="stat-pending-balance" class="text-xl font-black text-rose-600 tracking-tight">LKR <?php echo number_format($pending_total ?? 0, 2); ?></h3>
            </div>
            <div class="glass-card p-6 border-l-4 border-emerald-500 stat-card">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">Cash Payments</p>
                <h3 id="stat-cash" class="text-lg font-bold text-slate-800">LKR <?php echo number_format($stats['cash_total'] ?? 0, 2); ?></h3>
            </div>
            <div class="glass-card p-6 border-l-4 border-amber-500 stat-card">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">Cheque Total</p>
                <h3 id="stat-cheque" class="text-lg font-bold text-slate-800">LKR <?php echo number_format($stats['cheque_total'] ?? 0, 2); ?></h3>
            </div>
            <div class="glass-card p-6 border-l-4 border-cyan-500 stat-card">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">Bank Transfers</p>
                <h3 id="stat-transfer" class="text-lg font-bold text-slate-800">LKR <?php echo number_format($stats['transfer_total'] ?? 0, 2); ?></h3>
            </div>
            <div class="glass-card p-6 border-l-4 border-violet-500 stat-card">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">Card Payments</p>
                <h3 id="stat-card" class="text-lg font-bold text-slate-800">LKR <?php echo number_format($stats['card_total'] ?? 0, 2); ?></h3>
            </div>
        </div>

        <!-- Filters (Dark Mode Style from addcontainer) -->
        <div class="glass-card bg-slate-900/90 p-6 mb-8 border-slate-700">
            <form id="filterForm" method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-5 items-end">
                <div class="md:col-span-3">
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Search</label>
                    <div class="relative">
                        <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
                        <input type="text" name="search" id="liveSearch" value="<?php echo htmlspecialchars($search); ?>" placeholder="ID, Client or Cheque#" class="w-full pl-11 bg-slate-800/50 border border-slate-700 text-white placeholder:text-slate-600 focus:bg-slate-800 px-4 py-2.5 rounded-xl outline-none transition-all text-sm font-semibold focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/50">
                    </div>
                </div>
                <div class="md:col-span-2">
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">From Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="w-full bg-slate-800/50 border border-slate-700 text-white px-4 py-2.5 rounded-xl outline-none transition-all text-sm font-semibold focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/50">
                </div>
                <div class="md:col-span-2">
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">To Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="w-full bg-slate-800/50 border border-slate-700 text-white px-4 py-2.5 rounded-xl outline-none transition-all text-sm font-semibold focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/50">
                </div>
                <div class="md:col-span-1">
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Month</label>
                    <select name="month" class="w-full bg-slate-800/50 border border-slate-700 text-white px-4 py-2.5 rounded-xl outline-none transition-all text-sm font-semibold focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/50 appearance-none cursor-pointer">
                        <option value="" class="bg-slate-900 text-white">All Term</option>
                        <?php for($m=1; $m<=12; $m++): ?>
                            <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>" <?php echo $month == str_pad($m, 2, '0', STR_PAD_LEFT) ? 'selected' : ''; ?> class="bg-slate-900 text-white">
                                <?php echo date('M', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="md:col-span-1">
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Year</label>
                    <select name="year" class="w-full bg-slate-800/50 border border-slate-700 text-white px-4 py-2.5 rounded-xl outline-none transition-all text-sm font-semibold focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/50 appearance-none cursor-pointer">
                        <option value="" class="bg-slate-900 text-white">All Term</option>
                        <?php for($y=date('Y'); $y>=2024; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?> class="bg-slate-900 text-white"><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="md:col-span-1">
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Status</label>
                    <select name="status" class="w-full bg-slate-800/50 border border-slate-700 text-white px-4 py-2.5 rounded-xl outline-none transition-all text-sm font-semibold focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/50 appearance-none cursor-pointer">
                        <option value="" class="bg-slate-900 text-white">All</option>
                        <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?> class="bg-slate-900 text-white">Complete</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?> class="bg-slate-900 text-white">Pending</option>
                    </select>
                </div>
                <div class="md:col-span-1">
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Type</label>
                    <select name="type" class="w-full bg-slate-800/50 border border-slate-700 text-white px-4 py-2.5 rounded-xl outline-none transition-all text-sm font-semibold focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/50 appearance-none cursor-pointer">
                        <option value="" class="bg-slate-900 text-white">All</option>
                        <option value="Cash" <?php echo $p_type_filter == 'Cash' ? 'selected' : ''; ?> class="bg-slate-900 text-white">Cash</option>
                        <option value="Cheque" <?php echo $p_type_filter == 'Cheque' ? 'selected' : ''; ?> class="bg-slate-900 text-white">Cheque</option>
                        <option value="Account Transfer" <?php echo $p_type_filter == 'Account Transfer' ? 'selected' : ''; ?> class="bg-slate-900 text-white">Transfer</option>
                        <option value="Card" <?php echo $p_type_filter == 'Card' ? 'selected' : ''; ?> class="bg-slate-900 text-white">Card</option>
                    </select>
                </div>
                <div class="md:col-span-1">
                    <a href="managePayments.php" class="w-full h-[46px] bg-white/10 text-white rounded-xl hover:bg-white/20 transition-all flex items-center justify-center border border-white/10" title="Reset Filters">
                        <i class="fa-solid fa-rotate-right"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Main Data Table -->
        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="table-header">
                            <th class="px-6 py-4">Trip ID</th>
                            <th class="px-6 py-4">Customer Name</th>
                            <th class="px-6 py-4">Total Amount</th>
                            <th class="px-6 py-4 text-emerald-500">Paid</th>
                            <th class="px-6 py-4 text-rose-500">Pending</th>
                            <th class="px-6 py-4 text-center">Status</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="payments-table-body" class="divide-y divide-slate-100">
                        <?php if(empty($records)): ?>
                        <tr><td colspan="7" class="px-6 py-10 text-center text-slate-400 font-medium italic">No payment records found.</td></tr>
                        <?php endif; ?>
                        <?php foreach($records as $r): 
                            $total = $r['subtotal'] - $r['discount'];
                            $paid = $r['total_paid'];
                            $pending = $total - $paid;
                            $isComplete = $pending <= 0;
                        ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="px-6 py-4">
                                <span class="bg-indigo-50 text-indigo-700 px-3 py-1 rounded-lg text-[10px] font-black uppercase ring-1 ring-indigo-100">#DEL-<?php echo str_pad($r['delivery_id'], 4, '0', STR_PAD_LEFT); ?></span>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-sm font-black text-slate-800 uppercase tracking-tight"><?php echo htmlspecialchars($r['customer_name']); ?></p>
                                <p class="text-[9px] text-zinc-400 font-bold tracking-widest uppercase mt-0.5"><?php echo date('M j, Y', strtotime($r['delivery_date'])); ?></p>
                            </td>
                            <td class="px-6 py-4 font-black text-slate-900 text-sm">LKR <?php echo number_format($total, 2); ?></td>
                            <td class="px-6 py-4 font-black text-emerald-600 text-sm">LKR <?php echo number_format($paid, 2); ?></td>
                            <td class="px-6 py-4 font-black text-rose-600 text-sm">LKR <?php echo number_format($pending, 2); ?></td>
                            <td class="px-6 py-4 text-center">
                                <span class="px-2.5 py-1 rounded-full text-[9px] font-black uppercase tracking-wider <?php echo $isComplete ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'; ?>">
                                    <?php echo $isComplete ? 'Complete' : 'Pending'; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end space-x-2">
                                    <button onclick="openAddPayment(<?php echo $r['id']; ?>, '<?php echo addslashes($r['customer_name']); ?>', <?php echo $pending; ?>, <?php echo $r['customer_id']; ?>)" class="bg-emerald-600 hover:bg-black text-white px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg shadow-emerald-500/10" <?php echo $isComplete ? 'disabled style="opacity:0.5;cursor:not-allowed"' : ''; ?>>
                                        Add Pay
                                    </button>
                                    <button onclick="openHistory(<?php echo $r['id']; ?>, '<?php echo addslashes($r['customer_name']); ?>')" class="bg-slate-900 hover:bg-black text-white px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg">
                                        History
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal: Add Payment (Reused from nwdelivery) -->
    <div id="add-payment-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="glass-card w-full max-w-xl max-h-[90vh] overflow-y-auto p-1 text-slate-800">
             <div class="p-6">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h3 class="text-xl font-black text-slate-900 font-['Outfit']">Record New Transaction</h3>
                        <p id="add-payment-cust-name" class="text-[10px] uppercase font-black text-indigo-500 tracking-widest mt-1">CLIENT NAME</p>
                    </div>
                    <button onclick="closeAddPayment()" class="text-slate-400 hover:text-rose-500 transition-colors"><i class="fa-solid fa-times text-2xl"></i></button>
                </div>

                <form id="payment-form" class="space-y-6">
                    <input type="hidden" name="dc_id" id="payment_dc_id">
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2 sm:col-span-1">
                            <label class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Amount (LKR)</label>
                            <input type="number" step="0.01" name="amount" id="payment_amount" class="input-glass w-full" required>
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <label class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Payment Type</label>
                            <select name="type" id="payment_type" class="input-glass w-full appearance-none cursor-pointer" onchange="togglePaymentFields()" required>
                                <option value="Cash">Cash</option>
                                <option value="Account Transfer">Account Transfer</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Card">Card</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2 sm:col-span-1">
                            <label class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Transaction Date</label>
                            <input type="date" name="date" class="input-glass w-full" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <!-- Bank Section -->
                    <div id="bank_section" class="hidden space-y-4 animate-[fadeIn_0.3s_ease]">
                        <div class="relative">
                            <label class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Select Bank Account</label>
                            <input type="text" placeholder="Search saved banks..." class="input-glass w-full" onkeyup="searchBanks(this.value)">
                            <div id="bank_results" class="hidden absolute w-full top-[110%] left-0 z-30 bg-white/95 backdrop-blur-xl border border-slate-200 rounded-2xl shadow-2xl p-2 max-h-[200px] overflow-y-auto"></div>
                            <input type="hidden" name="bank_id" id="selected_bank_id">
                        </div>
                        <div id="selected_bank_info" class="hidden bg-indigo-50 p-4 rounded-2xl border border-indigo-100 flex items-center justify-between">
                            <div>
                                <p id="disp_bank_name" class="text-sm font-black text-indigo-900"></p>
                                <p id="disp_bank_acc" class="text-[10px] font-bold text-indigo-400"></p>
                            </div>
                            <button type="button" onclick="clearBank()" class="text-rose-500 hover:scale-110 transition-transform"><i class="fa-solid fa-circle-xmark"></i></button>
                        </div>
                    </div>

                    <!-- Cheque Section -->
                    <div id="cheque_section" class="hidden space-y-4 animate-[fadeIn_0.3s_ease]">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="col-span-2 sm:col-span-1">
                                <label class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Cheque Number</label>
                                <input type="text" name="chq_no" class="input-glass w-full" placeholder="XXXXXX">
                            </div>
                            <div class="col-span-2 sm:col-span-1 relative">
                                <label class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Cheque Payer</label>
                                <input type="text" id="chq_cust_search" placeholder="Search customer..." class="input-glass w-full" onkeyup="searchChequeCustomer(this.value)">
                                <div id="chq_cust_results" class="hidden absolute w-full top-[110%] left-0 z-30 bg-white/95 backdrop-blur-xl border border-slate-200 rounded-2xl shadow-2xl p-2 max-h-[200px] overflow-y-auto"></div>
                                <input type="hidden" name="chq_cust_id" id="selected_chq_cust_id">
                            </div>
                        </div>
                    </div>

                    <!-- Proof Section -->
                    <div id="proof_section" class="hidden animate-[fadeIn_0.3s_ease]">
                        <label class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Payment Proof (Image)</label>
                        <div class="relative">
                            <input type="file" name="proof" id="payment_proof" class="hidden" accept="image/*" onchange="previewProof(this)">
                            <button type="button" onclick="document.getElementById('payment_proof').click()" class="w-full flex items-center justify-center gap-3 p-8 border-2 border-dashed border-slate-200 rounded-2xl hover:bg-slate-50 hover:border-indigo-300 transition-all text-slate-400 group">
                                <i class="fa-solid fa-cloud-arrow-up text-3xl group-hover:scale-110 transition-transform"></i>
                                <span class="text-[10px] font-black uppercase tracking-widest">Click to upload scan/photo</span>
                            </button>
                            <div id="proof_preview" class="hidden mt-4 relative rounded-2xl overflow-hidden border border-slate-100">
                                <img src="" alt="Proof Preview" class="w-full h-auto">
                                <button type="button" onclick="clearProof()" class="absolute top-3 right-3 bg-rose-500 text-white w-8 h-8 rounded-full flex items-center justify-center shadow-lg"><i class="fa-solid fa-times"></i></button>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-slate-900 hover:bg-black text-white py-5 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-xl transition-all active:scale-[0.98]">
                        Confirm Transaction
                    </button>
                </form>
             </div>
        </div>
    </div>

    <!-- Modal: History -->
    <div id="history-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="glass-card w-full max-w-4xl max-h-[90vh] overflow-y-auto p-8 text-slate-800">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h3 class="text-xl font-black text-slate-900 font-['Outfit']">Payment History</h3>
                    <p id="history-cust-name" class="text-[10px] uppercase font-black text-indigo-500 tracking-widest mt-1">CLIENT NAME</p>
                </div>
                <button onclick="closeHistory()" class="text-slate-400 hover:text-rose-500 transition-colors"><i class="fa-solid fa-times text-2xl"></i></button>
            </div>
            <div id="history-content" class="overflow-x-auto min-h-[300px]">
                <!-- History Table goes here -->
            </div>
        </div>
    </div>

    <!-- Quick Add Bank Modal -->
    <div id="create-bank-modal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-md z-[70] hidden items-center justify-center p-4">
        <div class="glass-card w-full max-w-md p-7 text-slate-800">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h4 class="text-lg font-black text-slate-900 font-['Outfit']">Add New Bank</h4>
                    <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mt-1">Register a new bank account</p>
                </div>
                <button type="button" onclick="closeCreateBankModal()" class="text-slate-400 hover:text-rose-500 transition-colors"><i class="fa-solid fa-times text-xl"></i></button>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Bank Name</label>
                    <input type="text" id="new_bank_name" class="input-glass w-full" placeholder="e.g. Bank of Ceylon">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Account Number</label>
                    <input type="text" id="new_bank_acc_no" class="input-glass w-full" placeholder="e.g. 0023456789">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Account Name</label>
                    <input type="text" id="new_bank_acc_name" class="input-glass w-full" placeholder="e.g. Crystal Distributors">
                </div>
                <button type="button" onclick="saveNewBank()" class="w-full mt-2 bg-indigo-600 hover:bg-indigo-700 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest transition-colors shadow-lg shadow-indigo-600/20">
                    <i class="fa-solid fa-floppy-disk mr-2"></i>Save Bank
                </button>
            </div>
        </div>
    </div>

    <script>
        function openAddPayment(dcId, name, pending, custId) {
            document.getElementById('payment_dc_id').value = dcId;
            document.getElementById('add-payment-cust-name').innerText = name;
            document.getElementById('payment_amount').value = pending > 0 ? pending : '';
            document.getElementById('selected_chq_cust_id').value = custId;
            document.getElementById('add-payment-modal').classList.remove('hidden');
            togglePaymentFields();
        }

        function closeAddPayment() {
            document.getElementById('add-payment-modal').classList.add('hidden');
            document.getElementById('payment-form').reset();
            clearBank();
            clearProof();
        }

        function togglePaymentFields() {
            const type = document.getElementById('payment_type').value;
            const bankSec = document.getElementById('bank_section');
            const chqSec = document.getElementById('cheque_section');
            const proofSec = document.getElementById('proof_section');
            
            bankSec.classList.toggle('hidden', type !== 'Account Transfer' && type !== 'Cheque');
            chqSec.classList.toggle('hidden', type !== 'Cheque');
            proofSec.classList.toggle('hidden', type !== 'Account Transfer' && type !== 'Cheque');
        }

        function searchBanks(term) {
            const results = document.getElementById('bank_results');
            if(term.length < 2) return results.classList.add('hidden');
            fetch(`?action=search_bank&term=${term}`)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    data.forEach(b => {
                        html += `<div class="p-3 hover:bg-slate-100 cursor-pointer rounded-xl border-b border-slate-100 last:border-0" onclick="selectBank(${b.id}, '${b.name}', '${b.account_number}')">
                            <p class="text-xs font-black text-slate-800 uppercase">${b.name}</p>
                            <p class="text-[9px] font-bold text-slate-400">ACC: ${b.account_number}</p>
                        </div>`;
                    });
                    if(!data.length) {
                        html = `<div class="p-3 text-center">
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-3">No banks found for "${term}"</p>
                            <button type="button" onclick="openCreateBankModal('${term}')" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-colors">
                                <i class="fa-solid fa-plus mr-2"></i>Create New Bank
                            </button>
                        </div>`;
                    }
                    results.innerHTML = html;
                    results.classList.remove('hidden');
                });
        }

        function selectBank(id, name, acc) {
            document.getElementById('selected_bank_id').value = id;
            document.getElementById('disp_bank_name').innerText = name;
            document.getElementById('disp_bank_acc').innerText = `ACC: ${acc}`;
            document.getElementById('selected_bank_info').classList.remove('hidden');
            document.getElementById('bank_results').classList.add('hidden');
        }

        function clearBank() {
            document.getElementById('selected_bank_id').value = '';
            document.getElementById('selected_bank_info').classList.add('hidden');
        }

        function openCreateBankModal(prefillName = '') {
            document.getElementById('new_bank_name').value = prefillName;
            document.getElementById('new_bank_acc_no').value = '';
            document.getElementById('new_bank_acc_name').value = '';
            document.getElementById('bank_results').classList.add('hidden');
            const modal = document.getElementById('create-bank-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeCreateBankModal() {
            const modal = document.getElementById('create-bank-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function saveNewBank() {
            const name = document.getElementById('new_bank_name').value.trim();
            const acc_no = document.getElementById('new_bank_acc_no').value.trim();
            const acc_name = document.getElementById('new_bank_acc_name').value.trim();
            if (!name) { alert('Bank name is required.'); return; }

            const formData = new FormData();
            formData.append('action', 'create_bank');
            formData.append('name', name);
            formData.append('acc_no', acc_no);
            formData.append('acc_name', acc_name);

            fetch('managePayments.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        selectBank(data.id, name, acc_no);
                        closeCreateBankModal();
                    } else {
                        alert('Error saving bank.');
                    }
                });
        }

        function searchChequeCustomer(term) {
            const results = document.getElementById('chq_cust_results');
            if(term.length < 2) return results.classList.add('hidden');
            fetch(`?action=search_cheque_customer&term=${term}`)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    data.forEach(c => {
                        html += `<div class="p-3 hover:bg-slate-100 cursor-pointer rounded-xl" onclick="selectChequeCust(${c.id}, '${c.name}')">
                            <p class="text-xs font-black text-slate-800 uppercase">${c.name}</p>
                        </div>`;
                    });
                    results.innerHTML = html || '<p class="p-4 text-center text-xs text-slate-400">Not found</p>';
                    results.classList.remove('hidden');
                });
        }

        function selectChequeCust(id, name) {
            document.getElementById('selected_chq_cust_id').value = id;
            document.getElementById('chq_cust_search').value = name;
            document.getElementById('chq_cust_results').classList.add('hidden');
        }

        function previewProof(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => {
                    document.querySelector('#proof_preview img').src = e.target.result;
                    document.getElementById('proof_preview').classList.remove('hidden');
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function clearProof() {
            document.getElementById('payment_proof').value = '';
            document.getElementById('proof_preview').classList.add('hidden');
        }

        document.getElementById('payment-form').onsubmit = function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'save_payment');
            
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> PROCESSING...';

            fetch('managePayments.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if(data.success) location.reload();
                    else {
                        alert(data.message);
                        submitBtn.disabled = false;
                        submitBtn.innerText = 'CONFIRM TRANSACTION';
                    }
                });
        }

        function openHistory(dcId, name) {
            document.getElementById('history-cust-name').innerText = name;
            const container = document.getElementById('history-content');
            container.innerHTML = '<div class="flex items-center justify-center py-20"><i class="fa-solid fa-spinner fa-spin text-3xl text-indigo-500"></i></div>';
            document.getElementById('history-modal').classList.remove('hidden');

            fetch(`?action=get_history&dc_id=${dcId}`)
                .then(r => r.json())
                .then(res => {
                    if(!res.success) return alert(res.message);
                    let html = `
                        <table class="w-full text-left">
                            <thead>
                                <tr class="text-[10px] uppercase font-black text-slate-600 border-b border-slate-200">
                                    <th class="pb-3 px-2">Type</th>
                                    <th class="pb-3 px-2">Date</th>
                                    <th class="pb-3 px-2">Bank Details</th>
                                    <th class="pb-3 px-2 text-center">Proof</th>
                                    <th class="pb-3 px-2 text-center">Chq#</th>
                                    <th class="pb-3 px-2 text-right">Amount</th>
                                    <th class="pb-3 px-2 text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                    `;

                    res.data.forEach(p => {
                        html += `
                            <tr class="text-[12px] font-bold text-slate-800 hover:bg-slate-50 transition-colors">
                                <td class="py-4 px-2">
                                    <span class="bg-indigo-100 text-indigo-800 px-2.5 py-1.5 rounded-md text-[10px] uppercase font-black ring-1 ring-indigo-200">${p.payment_type}</span>
                                </td>
                                <td class="py-4 px-2 text-slate-800 font-extrabold">${new Date(p.payment_date).toLocaleDateString()}</td>
                                <td class="py-4 px-2">
                                    ${p.bank_name ? `
                                        <p class="text-slate-900 leading-none mb-1 font-black">${p.bank_name}</p>
                                        <p class="text-[10px] text-slate-600 font-bold uppercase">${p.bank_acc}</p>
                                    ` : '<span class="text-slate-400 italic font-medium">N/A</span>'}
                                    ${p.cheque_payer ? `<p class="text-[10px] text-indigo-600 font-black mt-1 uppercase tracking-tighter">Payer: ${p.cheque_payer}</p>` : ''}
                                </td>
                                <td class="py-4 px-2 text-center">
                                    ${p.proof_image ? `
                                        <a href="../uploads/payments/${p.proof_image}" target="_blank" class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 hover:bg-emerald-600 hover:text-white transition-all inline-flex items-center justify-center border border-emerald-200 shadow-sm" title="View Proof">
                                            <i class="fa-solid fa-image text-xs"></i>
                                        </a>
                                    ` : '<span class="text-[10px] text-slate-400 font-bold uppercase">N/A</span>'}
                                </td>
                                <td class="py-4 px-2 text-center">
                                    <span class="text-[12px] text-slate-700 font-black">${p.cheque_number || '-'}</span>
                                </td>
                                <td class="py-4 px-2 text-right font-black text-slate-900 leading-tight">LKR ${parseFloat(p.amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                                <td class="py-4 px-2 text-center">
                                    <button onclick="deletePayment(${p.id})" class="text-rose-500 hover:text-rose-700 transition-all p-2 hover:bg-rose-100 rounded-lg">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });

                    if(res.data.length === 0) html += '<tr><td colspan="7" class="py-10 text-center text-slate-500 italic font-bold">No transactions recorded.</td></tr>';
                    
                    html += '</tbody></table>';
                    container.innerHTML = html;
                });
        }

        function closeHistory() {
            document.getElementById('history-modal').classList.add('hidden');
        }

        function deletePayment(id) {
            if(!confirm('Are you sure you want to delete this payment record? This will adjust the customer balance.')) return;
            
            fetch('managePayments.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete_payment&id=${id}`
            })
            .then(r => r.json())
            .then(data => {
                if(data.success) location.reload();
                else alert(data.message);
            });
        }

        // Live AJAX Search Implementation
        let searchTimeout;
        const liveSearchInput = document.getElementById('liveSearch');
        const filterForm = document.getElementById('filterForm');

        function performSearch() {
            const formData = new FormData(filterForm);
            const params = new URLSearchParams(formData);
            params.append('action', 'live_search');

            fetch(`managePayments.php?${params.toString()}`)
                .then(r => r.json())
                .then(data => {
                    // Update Stats
                    document.getElementById('stat-total-received').innerText = 'LKR ' + data.stats.total_received;
                    document.getElementById('stat-pending-balance').innerText = 'LKR ' + data.stats.pending_balance;
                    document.getElementById('stat-cash').innerText = 'LKR ' + data.stats.cash;
                    document.getElementById('stat-cheque').innerText = 'LKR ' + data.stats.cheque;
                    document.getElementById('stat-transfer').innerText = 'LKR ' + data.stats.transfer;
                    document.getElementById('stat-card').innerText = 'LKR ' + data.stats.card;

                    // Update Table
                    const tbody = document.getElementById('payments-table-body');
                    let html = '';
                    if (data.records.length === 0) {
                        html = '<tr><td colspan="7" class="px-6 py-10 text-center text-slate-400 font-medium italic">No payment records found.</td></tr>';
                    } else {
                        data.records.forEach(r => {
                            const total = parseFloat(r.subtotal) - parseFloat(r.discount);
                            const paid = parseFloat(r.total_paid);
                            const pending = total - paid;
                            const isComplete = pending <= 0;
                            const tripId = r.delivery_id.toString().padStart(4, '0');
                            const date = new Date(r.delivery_date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

                            html += `
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="px-6 py-4">
                                        <span class="bg-indigo-50 text-indigo-700 px-3 py-1 rounded-lg text-[10px] font-black uppercase ring-1 ring-indigo-100">#DEL-${tripId}</span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <p class="text-sm font-black text-slate-800 uppercase tracking-tight">${r.customer_name}</p>
                                        <p class="text-[9px] text-zinc-400 font-bold tracking-widest uppercase mt-0.5">${date}</p>
                                    </td>
                                    <td class="px-6 py-4 font-black text-slate-900 text-sm">LKR ${total.toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                                    <td class="px-6 py-4 font-black text-emerald-600 text-sm">LKR ${paid.toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                                    <td class="px-6 py-4 font-black text-rose-600 text-sm">LKR ${pending.toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="px-2.5 py-1 rounded-full text-[9px] font-black uppercase tracking-wider ${isComplete ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}">
                                            ${isComplete ? 'Complete' : 'Pending'}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end space-x-2">
                                            <button onclick="openAddPayment(${r.id}, '${r.customer_name.replace(/'/g, "\\'")}', ${pending}, ${r.customer_id})" class="bg-emerald-600 hover:bg-black text-white px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg shadow-emerald-500/10" ${isComplete ? 'disabled style="opacity:0.5;cursor:not-allowed"' : ''}>
                                                Add Pay
                                            </button>
                                            <button onclick="openHistory(${r.id}, '${r.customer_name.replace(/'/g, "\\'")}')" class="bg-slate-900 hover:bg-black text-white px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg">
                                                History
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        });
                    }
                    tbody.innerHTML = html;
                });
        }

        liveSearchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(performSearch, 300); // 300ms is standard for live feel
        });

        // Also add listeners to other filters so the whole panel is "Live"
        filterForm.querySelectorAll('select, input[type="date"]').forEach(el => {
            el.addEventListener('change', performSearch);
        });
    </script>
</body>
</html>
