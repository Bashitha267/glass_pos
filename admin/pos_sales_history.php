<?php
require_once '../auth.php';
require_once '../config.php';
checkAuth();
if (!isAdmin()) { header('Location: ../sale/dashboard.php'); exit; }
$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? null;

// ── Delete Sale ───────────────────────────────────────────────────────────────
if ($action === 'delete_pos_sale') {
    try {
        $pdo->beginTransaction();
        $id = (int)$_POST['id'];
        $old = $pdo->prepare("SELECT item_id, item_source, qty FROM pos_sale_items WHERE sale_id=?");
        $old->execute([$id]);
        foreach ($old->fetchAll() as $oi) {
            if ($oi['item_source'] === 'container') {
                $pdo->prepare("UPDATE container_items SET sold_qty=GREATEST(0,sold_qty-?) WHERE id=?")->execute([$oi['qty'], $oi['item_id']]);
            } else {
                $pdo->prepare("UPDATE other_purchase_items SET sold_qty=GREATEST(0,sold_qty-?) WHERE id=?")->execute([$oi['qty'], $oi['item_id']]);
            }
        }
        $pdo->prepare("INSERT INTO pos_sale_audits (sale_id, action_type, notes, changed_by) VALUES (?,?,?,?)")->execute([$id,'DELETED',"Sale #$id deleted.",$user_id]);
        $pdo->prepare("DELETE FROM pos_sales WHERE id=?")->execute([$id]);
        $pdo->commit();
        echo json_encode(['success'=>true]);
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── View Items ────────────────────────────────────────────────────────────────
if ($action === 'get_items') {
    $id = (int)($_GET['id'] ?? 0);
    $sale = $pdo->query("SELECT ps.*, c.name as customer_name, c.contact_number FROM pos_sales ps LEFT JOIN customers c ON ps.customer_id=c.id WHERE ps.id=$id")->fetch(PDO::FETCH_ASSOC);
    $items = $pdo->query("
        SELECT psi.*, 
        CASE WHEN psi.item_source = 'container' THEN b.name ELSE opi.item_name END as brand_name 
        FROM pos_sale_items psi 
        LEFT JOIN container_items ci ON psi.item_id=ci.id AND psi.item_source = 'container'
        LEFT JOIN brands b ON ci.brand_id=b.id 
        LEFT JOIN other_purchase_items opi ON psi.item_id=opi.id AND psi.item_source = 'other'
        WHERE psi.sale_id=$id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $payments = $pdo->query("SELECT psp.*, b.name as bank_name FROM pos_sale_payments psp LEFT JOIN banks b ON psp.bank_id=b.id WHERE psp.sale_id=$id ORDER BY psp.created_at")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'sale'=>$sale,'items'=>$items,'payments'=>$payments]);
    exit;
}

// ── Payment Handlers (Synced with managePayments.php) ────────────────────────
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

if ($action == 'save_payment') {
    try {
        $sale_id = $_POST['sale_id'];
        $type = $_POST['type'];
        $amount = (float)$_POST['amount'];
        $date = $_POST['date'];
        $bank_id = !empty($_POST['bank_id']) ? $_POST['bank_id'] : null;
        $chq_no = $_POST['chq_no'] ?: null;
        $chq_payer = $_POST['chq_payer'] ?: null;
        
        $proof = null;
        if (isset($_FILES['proof']) && $_FILES['proof']['error'] == 0) {
            $proof = time() . '_' . $_FILES['proof']['name'];
            if (!is_dir('../uploads/payments')) mkdir('../uploads/payments', 0777, true);
            move_uploaded_file($_FILES['proof']['tmp_name'], '../uploads/payments/' . $proof);
        }

        $stmt = $pdo->prepare("INSERT INTO pos_sale_payments (sale_id, amount, payment_type, bank_id, cheque_number, proof_image, payment_date, cheque_payer_name, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$sale_id, $amount, $type, $bank_id, $chq_no, $proof, $date, $chq_payer, $user_id]);
        
        // Update payment status
        $stmtStatus = $pdo->prepare("SELECT grand_total, (SELECT SUM(amount) FROM pos_sale_payments WHERE sale_id = ps.id) as total_paid FROM pos_sales ps WHERE ps.id = ?");
        $stmtStatus->execute([$sale_id]);
        $status = $stmtStatus->fetch();
        if ($status) {
            $new_status = ($status['total_paid'] >= $status['grand_total']) ? 'completed' : 'pending';
            $pdo->prepare("UPDATE pos_sales SET payment_status = ? WHERE id = ?")->execute([$new_status, $sale_id]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

if ($action == 'get_history') {
    $sale_id = (int)$_GET['sale_id'];
    $stmt = $pdo->prepare("
        SELECT psp.*, b.name as bank_name, b.account_number as bank_acc, psp.cheque_payer_name as cheque_payer 
        FROM pos_sale_payments psp 
        LEFT JOIN banks b ON psp.bank_id = b.id 
        WHERE psp.sale_id = ? 
        ORDER BY psp.payment_date DESC
    ");
    $stmt->execute([$sale_id]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action == 'delete_payment') {
    try {
        $pay_id = (int)$_POST['id'];
        $stmt = $pdo->prepare("SELECT sale_id FROM pos_sale_payments WHERE id = ?");
        $stmt->execute([$pay_id]);
        $pay = $stmt->fetch();
        if (!$pay) throw new Exception("Payment not found");
        
        $sale_id = $pay['sale_id'];
        $pdo->prepare("DELETE FROM pos_sale_payments WHERE id = ?")->execute([$pay_id]);
        
        // Update status
        $stmtStatus = $pdo->prepare("SELECT grand_total, (SELECT SUM(amount) FROM pos_sale_payments WHERE sale_id = ps.id) as total_paid FROM pos_sales ps WHERE ps.id = ?");
        $stmtStatus->execute([$sale_id]);
        $status = $stmtStatus->fetch();
        if ($status) {
            $new_status = ($status['total_paid'] >= $status['grand_total']) ? 'completed' : 'pending';
            $pdo->prepare("UPDATE pos_sales SET payment_status = ? WHERE id = ?")->execute([$new_status, $sale_id]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

// ── Filters ───────────────────────────────────────────────────────────────────
$search     = trim($_GET['search'] ?? '');
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date   = $_GET['end_date']   ?? date('Y-m-d');
$filter_month = $_GET['filter_month'] ?? '';
$filter_year  = $_GET['filter_year']  ?? '';
$status_f   = $_GET['status'] ?? '';
$page       = max(1,(int)($_GET['page'] ?? 1));
$limit      = 8;
$offset     = ($page-1)*$limit;

$where  = [];
$params = [];

if ($search) {
    $where[] = "(ps.bill_id LIKE ? OR c.name LIKE ? OR c.contact_number LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($filter_month && $filter_year && !$_GET['start_date']) {
    $where[] = "MONTH(ps.sale_date)=? AND YEAR(ps.sale_date)=?";
    $params[] = $filter_month; $params[] = $filter_year;
} else {
    if ($start_date) { $where[] = "ps.sale_date >= ?"; $params[] = $start_date; }
    if ($end_date)   { $where[] = "ps.sale_date <= ?"; $params[] = $end_date; }
}
if ($status_f) { $where[] = "ps.payment_status = ?"; $params[] = $status_f; }

$whereClause = $where ? "WHERE ".implode(" AND ",$where) : "";

$baseQ = "SELECT ps.*, c.name as customer_name, c.contact_number,
    (SELECT COALESCE(SUM(amount),0) FROM pos_sale_payments WHERE sale_id=ps.id) as total_paid
    FROM pos_sales ps LEFT JOIN customers c ON ps.customer_id=c.id
    $whereClause";

$count = $pdo->prepare("SELECT COUNT(*) FROM ($baseQ) as t");
$count->execute($params);
$total_records = (int)$count->fetchColumn();
$total_pages = max(1, ceil($total_records/$limit));

$sales = $pdo->prepare("$baseQ ORDER BY ps.id DESC LIMIT $limit OFFSET $offset");
$sales->execute($params);
$sales = $sales->fetchAll(PDO::FETCH_ASSOC);

// Header stats
$statsParams = $params;
$statsBase = "SELECT COALESCE(SUM(ps.grand_total),0) as revenue,
    COALESCE(SUM(ps.grand_total - ps.item_discount - ps.bill_discount),0) as net,
    COALESCE(SUM((SELECT SUM(psi.qty * psi.cost_price) FROM pos_sale_items psi WHERE psi.sale_id=ps.id)),0) as cost
    FROM pos_sales ps LEFT JOIN customers c ON ps.customer_id=c.id $whereClause";
$statsR = $pdo->prepare($statsBase);
$statsR->execute($statsParams);
$stats = $statsR->fetch(PDO::FETCH_ASSOC);
$revenue = (float)$stats['revenue'];
$profit  = $revenue - (float)$stats['cost'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Sales History | Sahan Picture & Mirror</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background: url('../assests/glass_bg.png') no-repeat center center fixed; background-size: cover; color: #1e293b; min-height: 100vh; }
        .glass-header { background: rgba(248,250,252,0.96); backdrop-filter: blur(20px); border-bottom: 1px solid rgba(226,232,240,0.8); box-shadow: 0 4px 20px -5px rgba(0,0,0,0.05); }
        .glass-card { background: rgba(255,255,255,0.88); backdrop-filter: blur(20px); border: 1px solid white; border-radius: 24px; box-shadow: 0 10px 30px -5px rgba(0,0,0,0.04); }
        .input-glass { background: rgba(255,255,255,0.6); border: 1px solid #e2e8f0; padding: 10px 16px; border-radius: 14px; outline: none; transition: all 0.3s; font-size: 14px; font-weight: 700; color: #0f172a; }
        .input-glass:focus { border-color: #0891b2; background: white; }
        .table-header { background: #1e293b; color: white; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; }
        .custom-scroll::-webkit-scrollbar { width: 6px; } .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        @media print {
            body * { visibility: hidden; }
            #invoice-print, #invoice-print * { visibility: visible; }
            #invoice-print { position: absolute; left: 0; top: 0; width: 100%; }
        }
    </style>
</head>
<body class="flex flex-col pb-12">
<header class="glass-header sticky top-0 z-40 py-4">
    <div class="px-5 flex items-center justify-between">
        <div class="flex items-center space-x-3 md:space-x-5">
            <a href="pos.php" class="text-slate-800 hover:text-cyan-600 p-2 rounded-2xl hover:bg-slate-100 transition-colors">
                <i class="fa-solid fa-arrow-left text-lg"></i>
            </a>
            <div>
                <h1 class="text-xl md:text-2xl font-black text-slate-900 font-['Outfit']">POS Sales History</h1>
                <p class="hidden md:block text-[10px] uppercase font-black text-slate-400 tracking-widest mt-0.5">Direct Sales Records</p>
            </div>
        </div>
    </div>
</header>
<main class="px-5 py-8 w-full">

    <!-- Stats Header -->
    <div class="grid grid-cols-2 gap-4 mb-6">
        <div class="glass-card p-5 bg-gradient-to-br from-emerald-500/10 to-transparent border-emerald-200">
            <p class="text-[10px] uppercase font-black text-slate-500 tracking-widest mb-1">Total Revenue</p>
            <h2 class="text-2xl font-black text-emerald-600">LKR <?php echo number_format($revenue,2); ?></h2>
        </div>
        <div class="glass-card p-5 bg-gradient-to-br from-teal-500/10 to-transparent border-teal-200">
            <p class="text-[10px] uppercase font-black text-slate-500 tracking-widest mb-1">Est. Profit</p>
            <h2 class="text-2xl font-black text-teal-600">LKR <?php echo number_format($profit,2); ?></h2>
        </div>
    </div>

    <!-- Filters -->
    <div class="glass-card p-5 mb-6 border-slate-200/50">
        <form method="GET" id="filterForm" class="grid grid-cols-2 md:grid-cols-12 gap-4 items-end">
            <div class="col-span-2 md:col-span-4 relative">
                <label class="text-[10px] uppercase font-black text-slate-600 mb-1.5 ml-1 block tracking-widest">Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Bill ID or Customer name..." class="input-glass w-full h-[46px]" oninput="autoSubmit()">
            </div>
            <div class="md:col-span-2">
                <label class="text-[10px] uppercase font-black text-slate-600 mb-1.5 ml-1 block tracking-widest">From</label>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="input-glass w-full h-[46px]" onchange="this.form.submit()">
            </div>
            <div class="md:col-span-2">
                <label class="text-[10px] uppercase font-black text-slate-600 mb-1.5 ml-1 block tracking-widest">To</label>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="input-glass w-full h-[46px]" onchange="this.form.submit()">
            </div>
            <div class="md:col-span-1">
                <label class="text-[10px] uppercase font-black text-slate-600 mb-1.5 ml-1 block tracking-widest">Month</label>
                <select name="filter_month" class="input-glass w-full h-[46px] appearance-none cursor-pointer" onchange="this.form.submit()">
                    <option value="">All</option>
                    <?php for($m=1;$m<=12;$m++): ?>
                    <option value="<?php echo str_pad($m,2,'0',STR_PAD_LEFT); ?>" <?php echo $filter_month==str_pad($m,2,'0',STR_PAD_LEFT)?'selected':''; ?>><?php echo date('M',mktime(0,0,0,$m,1)); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="md:col-span-1">
                <label class="text-[10px] uppercase font-black text-slate-600 mb-1.5 ml-1 block tracking-widest">Year</label>
                <select name="filter_year" class="input-glass w-full h-[46px] appearance-none cursor-pointer" onchange="this.form.submit()">
                    <?php for($y=date('Y');$y>=2024;$y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $filter_year==$y?'selected':''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="md:col-span-1">
                <label class="text-[10px] uppercase font-black text-slate-600 mb-1.5 ml-1 block tracking-widest">Status</label>
                <select name="status" class="input-glass w-full h-[46px] appearance-none cursor-pointer" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="pending" <?php echo $status_f=='pending'?'selected':''; ?>>Pending</option>
                    <option value="completed" <?php echo $status_f=='completed'?'selected':''; ?>>Paid</option>
                </select>
            </div>
            <div class="md:col-span-1">
                <a href="pos_sales_history.php" class="w-full h-[46px] bg-rose-50 text-rose-500 rounded-2xl hover:bg-rose-100 transition-all flex items-center justify-center border border-rose-200">
                    <i class="fa-solid fa-rotate-right"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="glass-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="table-header">
                        <th class="px-4 py-3.5">Bill ID</th>
                        <th class="px-4 py-3.5">Date</th>
                        <th class="px-4 py-3.5">Customer</th>
                        <th class="px-4 py-3.5">Contact</th>
                        <th class="px-4 py-3.5">Discount</th>
                        <th class="px-4 py-3.5">Bill Total</th>
                        <th class="px-4 py-3.5 text-emerald-600">Paid</th>
                        <th class="px-4 py-3.5 text-rose-500">Pending</th>
                        <th class="px-4 py-3.5 text-center">Status</th>
                        <th class="px-4 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach($sales as $s): ?>
                    <tr class="hover:bg-slate-50/50 transition-colors">
                        <td class="px-4 py-3 font-black text-indigo-600 text-[11px]"><?php echo htmlspecialchars($s['bill_id']); ?></td>
                        <td class="px-4 py-3 font-bold text-slate-700 text-xs"><?php echo date('M d, Y',strtotime($s['sale_date'])); ?></td>
                        <td class="px-4 py-3 font-bold text-slate-800 text-xs"><?php echo htmlspecialchars($s['customer_name'] ?: 'Walk-in'); ?></td>
                        <td class="px-4 py-3 text-slate-500 text-xs"><?php echo htmlspecialchars($s['contact_number'] ?: '—'); ?></td>
                        <td class="px-4 py-3 font-bold text-rose-500 text-xs">LKR <?php echo number_format((float)$s['item_discount']+(float)$s['bill_discount'],2); ?></td>
                        <td class="px-4 py-3 font-black text-emerald-600 text-xs">LKR <?php echo number_format($s['grand_total'],2); ?></td>
                        <td class="px-4 py-3 font-bold text-emerald-600 text-xs">LKR <?php echo number_format($s['total_paid'], 2); ?></td>
                        <td class="px-4 py-3 font-bold text-rose-600 text-xs">LKR <?php echo number_format($s['grand_total'] - $s['total_paid'], 2); ?></td>
                        <td class="px-4 py-3 text-center">
                            <?php $sc = $s['payment_status']==='completed' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'; ?>
                            <span class="px-2.5 py-1 rounded-full text-[9px] font-black uppercase tracking-wider <?php echo $sc; ?>"><?php echo $s['payment_status']; ?></span>
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <div class="flex items-center justify-end gap-1 flex-wrap">
                                <button onclick="openHistory(<?php echo $s['id']; ?>, '<?php echo addslashes($s['customer_name'] ?: 'Walk-in'); ?>', <?php echo (float)$s['grand_total'] - (float)$s['total_paid']; ?>)" class="bg-amber-500 hover:bg-black text-white px-2.5 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">Payments</button>
                                <button onclick="viewSale(<?php echo $s['id']; ?>)" class="bg-slate-700 hover:bg-black text-white px-2.5 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">View</button>
                                <button onclick="editSale(<?php echo $s['id']; ?>)" class="bg-emerald-600 hover:bg-emerald-800 text-white px-2.5 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">Edit</button>
                                <button onclick="printSale(<?php echo $s['id']; ?>)" class="bg-indigo-600 hover:bg-indigo-800 text-white px-2.5 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">Print</button>
                                <button onclick="deleteSale(<?php echo $s['id']; ?>, '<?php echo htmlspecialchars($s['bill_id']); ?>')" class="bg-rose-600 hover:bg-rose-800 text-white px-2.5 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">Del</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($sales)): ?>
                    <tr><td colspan="9" class="px-4 py-12 text-center text-slate-400 font-bold text-xs uppercase tracking-widest italic">No sales found for the selected filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Pagination -->
        <div class="px-5 py-4 bg-slate-50/50 border-t border-slate-100 flex items-center justify-between">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Page <?php echo $page; ?> of <?php echo $total_pages; ?> &bull; <?php echo $total_records; ?> records</p>
            <div class="flex space-x-2">
                <?php if($page>1): ?><a href="?<?php echo http_build_query(array_merge($_GET,['page'=>$page-1])); ?>" class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold hover:bg-slate-50">Prev</a><?php endif; ?>
                <?php if($page<$total_pages): ?><a href="?<?php echo http_build_query(array_merge($_GET,['page'=>$page+1])); ?>" class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold hover:bg-slate-50">Next</a><?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- View Modal -->
<div id="modal-view" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xl z-50 flex items-center justify-center p-4 hidden">
    <div class="glass-card w-full max-w-3xl max-h-[90vh] flex flex-col overflow-hidden shadow-2xl">
        <div class="p-5 border-b border-white/40 flex items-center justify-between bg-white/20">
            <h3 class="text-lg font-black font-['Outfit'] text-slate-900">Sale Details</h3>
            <button onclick="document.getElementById('modal-view').classList.add('hidden')" class="text-slate-500 hover:text-slate-800"><i class="fa-solid fa-times text-xl"></i></button>
        </div>
        <div id="view-content" class="overflow-y-auto p-6 space-y-4 custom-scroll"></div>
    </div>
</div>

<!-- Modal: Add Payment -->
<div id="add-payment-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-md z-[60] flex items-center justify-center p-4 hidden">
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
                <input type="hidden" name="sale_id" id="payment_sale_id">
                
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
                        <div class="col-span-2 sm:col-span-1">
                            <label class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Cheque Payer</label>
                            <input type="text" name="chq_payer" placeholder="Enter payer name (Optional)" class="input-glass w-full">
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
        <div id="history-header" class="mb-6 hidden">
             <button id="btn-add-payment-inside" class="bg-emerald-600 hover:bg-black text-white px-6 py-3 rounded-xl text-xs font-black uppercase tracking-widest transition-all shadow-lg shadow-emerald-600/20">
                <i class="fa-solid fa-plus mr-2"></i> Add New Payment
             </button>
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

<!-- Invoice Print -->
<div id="invoice-print" class="hidden p-8" style="font-family: Arial, sans-serif; color: #000; background: #fff; max-width: 600px; margin: 0 auto;">
    <div style="text-align:center; border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 12px;">
        <h2 style="font-size:20px; font-weight:900; margin:0;">Sahan Picture & Mirror</h2>
        <p style="font-size:11px; margin:4px 0;">Point of Sale Invoice</p>
    </div>
    <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:12px;">
        <div><strong>Bill ID:</strong> <span id="inv-bill-id"></span><br><strong>Date:</strong> <span id="inv-date"></span></div>
        <div style="text-align:right;"><strong>Customer:</strong><br><span id="inv-customer"></span></div>
    </div>
    <table style="width:100%; border-collapse:collapse; font-size:12px; margin-bottom:12px;">
        <thead><tr style="border-bottom:2px solid #000; background:#f0f0f0;">
            <th style="padding:6px 4px; text-align:left;">Brand</th>
            <th style="padding:6px 4px; text-align:center;">Qty</th>
            <th style="padding:6px 4px; text-align:right;">Unit Price</th>
            <th style="padding:6px 4px; text-align:right;">Disc</th>
            <th style="padding:6px 4px; text-align:right;">Total</th>
        </tr></thead>
        <tbody id="inv-items-body"></tbody>
    </table>
    <div style="border-top:1px solid #000; padding-top:8px; font-size:12px;">
        <div style="display:flex;justify-content:space-between;"><span>Subtotal</span><span id="inv-subtotal"></span></div>
        <div style="display:flex;justify-content:space-between;"><span>Discount</span><span id="inv-discount"></span></div>
        <div style="display:flex;justify-content:space-between; font-weight:900; font-size:16px; border-top:2px solid #000; margin-top:6px; padding-top:6px;"><span>Grand Total</span><span id="inv-grand"></span></div>
    </div>
    <div style="margin-top:12px; padding-top:8px; border-top:1px dashed #000; font-size:12px;">
        <div style="display:flex;justify-content:space-between;"><span><strong>Payment:</strong></span><span id="inv-method"></span></div>
        <div style="display:flex;justify-content:space-between;"><span><strong>Paid:</strong></span><span id="inv-paid"></span></div>
        <div style="display:flex;justify-content:space-between;"><span><strong>Status:</strong></span><span id="inv-status"></span></div>
    </div>
    <div style="text-align:center; margin-top:20px; font-size:10px; color:#555;">Thank you for your purchase!</div>
</div>

<script>
let searchTimeout;
function autoSubmit() { clearTimeout(searchTimeout); searchTimeout = setTimeout(()=>document.getElementById('filterForm').submit(), 400); }

function fmt(n) { return parseFloat(n||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2}); }

function viewSale(id) {
    fetch(`?action=get_items&id=${id}`).then(r=>r.json()).then(res => {
        if (!res.success) return;
        const s = res.sale; const items = res.items; const pays = res.payments;
        let rows = items.map(it=>`<tr class="border-b border-slate-100"><td class="py-2 pr-4 font-bold text-xs text-slate-800">${it.brand_name}</td><td class="py-2 pr-4 text-xs text-center">${it.qty}</td><td class="py-2 pr-4 text-xs text-right">LKR ${fmt(it.selling_price)}</td><td class="py-2 pr-4 text-xs text-right text-rose-500">LKR ${fmt(it.item_discount)}</td><td class="py-2 text-xs text-right font-black text-emerald-600">LKR ${fmt(it.line_total)}</td></tr>`).join('');
        let payRows = pays.map(p=>`<div class="flex justify-between text-xs"><span class="font-bold text-slate-600">${p.payment_type}${p.bank_name?' — '+p.bank_name:''}</span><span class="font-black text-slate-800">LKR ${fmt(p.amount)}</span></div>`).join('');
        const totalDisc = (parseFloat(s.item_discount||0)+parseFloat(s.bill_discount||0));
        document.getElementById('view-content').innerHTML = `
          <div class="flex justify-between items-start">
            <div><p class="font-black text-indigo-600 text-sm">${s.bill_id}</p><p class="text-[10px] text-slate-400">${s.sale_date}</p></div>
            <p class="text-xs font-bold text-slate-700">${s.customer_name||'Walk-in'}<br><span class="text-slate-400">${s.contact_number||''}</span></p>
          </div>
          <div class="overflow-x-auto rounded-xl border border-slate-100">
            <table class="w-full text-left">
              <thead><tr class="table-header text-[10px]"><th class="px-3 py-2.5">Brand</th><th class="px-3 py-2.5 text-center">Qty</th><th class="px-3 py-2.5 text-right">Price</th><th class="px-3 py-2.5 text-right">Disc</th><th class="px-3 py-2.5 text-right">Total</th></tr></thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
          <div class="bg-slate-50 rounded-xl p-4 space-y-1.5 text-sm">
            <div class="flex justify-between"><span class="text-slate-500">Item Discounts</span><span class="font-bold text-rose-500">- LKR ${fmt(s.item_discount)}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">Bill Discount</span><span class="font-bold text-rose-500">- LKR ${fmt(s.bill_discount)}</span></div>
            <div class="flex justify-between font-black text-base border-t border-slate-200 pt-2 mt-2"><span>Grand Total</span><span class="text-emerald-600">LKR ${fmt(s.grand_total)}</span></div>
          </div>
          ${payRows ? `<div class="space-y-2"><p class="text-[10px] uppercase font-black text-slate-500 tracking-widest">Payments</p>${payRows}</div>` : ''}
        `;
        document.getElementById('modal-view').classList.remove('hidden');
    });
}

function editSale(id) { window.location.href = `pos.php?edit=${id}`; }

function printSale(id) {
    fetch(`?action=get_items&id=${id}`).then(r=>r.json()).then(res => {
        if (!res.success) return;
        const s = res.sale; const items = res.items; const pays = res.payments;
        document.getElementById('inv-bill-id').textContent = s.bill_id;
        document.getElementById('inv-date').textContent = s.sale_date;
        document.getElementById('inv-customer').textContent = s.customer_name || 'Walk-in Customer';
        let rows = ''; items.forEach(it => { rows += `<tr style="border-bottom:1px solid #ddd;"><td style="padding:5px 4px;">${it.brand_name}</td><td style="padding:5px 4px;text-align:center;">${it.qty}</td><td style="padding:5px 4px;text-align:right;">LKR ${fmt(it.selling_price)}</td><td style="padding:5px 4px;text-align:right;">LKR ${fmt(it.item_discount)}</td><td style="padding:5px 4px;text-align:right;">LKR ${fmt(it.line_total)}</td></tr>`; });
        document.getElementById('inv-items-body').innerHTML = rows;
        const totalDisc = parseFloat(s.item_discount||0)+parseFloat(s.bill_discount||0);
        const totalPaid = pays.reduce((a,p)=>a+parseFloat(p.amount||0),0);
        document.getElementById('inv-subtotal').textContent = 'LKR '+fmt(s.subtotal);
        document.getElementById('inv-discount').textContent = '- LKR '+fmt(totalDisc);
        document.getElementById('inv-grand').textContent = 'LKR '+fmt(s.grand_total);
        document.getElementById('inv-method').textContent = s.payment_method;
        document.getElementById('inv-paid').textContent = 'LKR '+fmt(totalPaid);
        document.getElementById('inv-status').textContent = s.payment_status.toUpperCase();
        document.getElementById('invoice-print').classList.remove('hidden');
        setTimeout(()=>{ window.print(); document.getElementById('invoice-print').classList.add('hidden'); }, 300);
    });
}

function deleteSale(id, billId) {
    if (!confirm(`Delete sale ${billId}? This will revert stock. This cannot be undone.`)) return;
    const fd = new FormData();
    fd.append('action','delete_pos_sale'); fd.append('id',id);
    fetch('', {method:'POST',body:fd}).then(r=>r.json()).then(res => {
        if (res.success) location.reload();
        else alert('Error: '+res.message);
    });
}

// ── Payment Handlers ──
function openAddPayment(saleId, name, pending) {
    document.getElementById('payment_sale_id').value = saleId;
    document.getElementById('add-payment-cust-name').innerText = name;
    document.getElementById('payment_amount').value = pending > 0 ? pending.toFixed(2) : '';
    document.getElementById('add-payment-modal').classList.remove('hidden');
    togglePaymentFields();
}
function closeAddPayment() {
    document.getElementById('add-payment-modal').classList.add('hidden');
    document.getElementById('payment-form').reset();
    clearBank(); clearProof();
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
    fetch(`?action=search_bank&term=${term}`).then(r => r.json()).then(data => {
        let html = '';
        data.forEach(b => {
            html += `<div class="p-3 hover:bg-slate-100 cursor-pointer rounded-xl" onclick="selectBank(${b.id}, '${b.name}', '${b.account_number}')">
                <p class="text-xs font-black uppercase">${b.name}</p><p class="text-[9px] text-slate-400">ACC: ${b.account_number}</p>
            </div>`;
        });
        if(!data.length) html = `<div class="p-3 text-center"><p class="text-[9px] font-black text-slate-400 mb-2 uppercase">No banks found</p><button type="button" onclick="openCreateBankModal('${term}')" class="w-full bg-indigo-600 text-white py-2 rounded-xl text-[9px] font-black uppercase">Create New</button></div>`;
        results.innerHTML = html; results.classList.remove('hidden');
    });
}
function selectBank(id, name, acc) {
    document.getElementById('selected_bank_id').value = id;
    document.getElementById('disp_bank_name').innerText = name;
    document.getElementById('disp_bank_acc').innerText = `ACC: ${acc}`;
    document.getElementById('selected_bank_info').classList.remove('hidden');
    document.getElementById('bank_results').classList.add('hidden');
}
function clearBank() { document.getElementById('selected_bank_id').value = ''; document.getElementById('selected_bank_info').classList.add('hidden'); }
function openCreateBankModal(prefill) { document.getElementById('new_bank_name').value = prefill; document.getElementById('create-bank-modal').classList.remove('hidden'); document.getElementById('create-bank-modal').classList.add('flex'); }
function closeCreateBankModal() { document.getElementById('create-bank-modal').classList.add('hidden'); document.getElementById('create-bank-modal').classList.remove('flex'); }
function saveNewBank() {
    const name = document.getElementById('new_bank_name').value;
    const acc = document.getElementById('new_bank_acc_no').value;
    const holder = document.getElementById('new_bank_acc_name').value;
    const fd = new FormData(); fd.append('action','create_bank'); fd.append('name',name); fd.append('acc_no',acc); fd.append('acc_name',holder);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{ if(data.success){ selectBank(data.id, name, acc); closeCreateBankModal(); } });
}
function previewProof(input) {
    if(input.files && input.files[0]){
        const reader = new FileReader();
        reader.onload = e => { document.querySelector('#proof_preview img').src = e.target.result; document.getElementById('proof_preview').classList.remove('hidden'); }
        reader.readAsDataURL(input.files[0]);
    }
}
function clearProof() { document.getElementById('payment_proof').value=''; document.getElementById('proof_preview').classList.add('hidden'); }
document.getElementById('payment-form').onsubmit = function(e){
    e.preventDefault();
    const fd = new FormData(this); fd.append('action','save_payment');
    const btn = this.querySelector('button[type="submit"]'); btn.disabled=true; btn.innerText='PROCESSING...';
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{ if(data.success) location.reload(); else { alert(data.message); btn.disabled=false; btn.innerText='CONFIRM TRANSACTION'; } });
}
function openHistory(saleId, name, pending) {
    document.getElementById('history-cust-name').innerText = name;
    document.getElementById('history-content').innerHTML = '<div class="flex items-center justify-center py-20"><i class="fa-solid fa-spinner fa-spin text-3xl text-indigo-500"></i></div>';
    
    // Show/Hide Add Payment button inside history
    const header = document.getElementById('history-header');
    const addBtn = document.getElementById('btn-add-payment-inside');
    if (pending > 0.01) {
        header.classList.remove('hidden');
        addBtn.onclick = () => openAddPayment(saleId, name, pending);
    } else {
        header.classList.add('hidden');
    }

    document.getElementById('history-modal').classList.remove('hidden');
    fetch(`?action=get_history&sale_id=${saleId}`).then(r=>r.json()).then(res => {
        if(!res.success) return;
        let html = `<table class="w-full text-left text-xs"><thead class="table-header"><tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">Type</th><th class="px-4 py-2">Amount</th><th class="px-4 py-2">Details</th><th class="px-4 py-2 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-100">`;
        res.data.forEach(p => {
            html += `<tr>
                <td class="px-4 py-3">${p.payment_date}</td>
                <td class="px-4 py-3"><span class="font-black text-indigo-600 uppercase text-[9px]">${p.payment_type}</span></td>
                <td class="px-4 py-3 font-bold">LKR ${fmt(p.amount)}</td>
                <td class="px-4 py-3 text-[10px] text-slate-500">${p.bank_name ? p.bank_name : ''} ${p.cheque_number ? ' #'+p.cheque_number : ''}</td>
                <td class="px-4 py-3 text-right"><button onclick="deletePayment(${p.id})" class="text-rose-500 hover:text-rose-700 transition-colors"><i class="fa-solid fa-trash-can"></i></button></td>
            </tr>`;
        });
        if(!res.data.length) html += `<tr><td colspan="5" class="py-10 text-center text-slate-400 italic">No payment history found.</td></tr>`;
        html += `</tbody></table>`;
        document.getElementById('history-content').innerHTML = html;
    });
}
function closeHistory() { document.getElementById('history-modal').classList.add('hidden'); }
function deletePayment(id) {
    if(!confirm('Delete this payment record?')) return;
    const fd = new FormData(); fd.append('action','delete_payment'); fd.append('id',id);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{ if(data.success) location.reload(); else alert(data.message); });
}

// Handle edit redirect from pos.php
<?php if (!empty($_GET['edit'])): ?>
window.onload = function() { /* handled in pos.php */ };
<?php endif; ?>
</script>
</body>
</html>
