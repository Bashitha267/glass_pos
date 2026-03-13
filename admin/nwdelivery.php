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

// AJAX Handlers
if ($action == 'search_employee') {
    $term = '%' . $_GET['term'] . '%';
    $stmt = $pdo->prepare("SELECT id, full_name, contact_number, profile_pic FROM users WHERE role = 'employee' AND full_name LIKE ? LIMIT 5");
    $stmt->execute([$term]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action == 'search_customer') {
    $term = '%' . $_GET['term'] . '%';
    $stmt = $pdo->prepare("SELECT id, name, contact_number, address FROM customers WHERE name LIKE ? OR contact_number LIKE ? LIMIT 5");
    $stmt->execute([$term, $term]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action == 'search_brand_stock') {
    $term = '%' . $_GET['term'] . '%';
    $stmt = $pdo->prepare("
        SELECT b.id as brand_id, b.name as brand_name, 
               ci.id as item_id, ci.total_qty, ci.sold_qty, (ci.total_qty - ci.sold_qty) as available_qty,
               c.container_number, c.country, c.arrival_date, c.per_item_cost
        FROM container_items ci
        JOIN brands b ON ci.brand_id = b.id
        JOIN containers c ON ci.container_id = c.id
        WHERE b.name LIKE ? AND (ci.total_qty - ci.sold_qty) > 0
        LIMIT 10
    ");
    $stmt->execute([$term]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action == 'create_employee') {
    $name = $_POST['name'];
    $contact = $_POST['contact'];
    // No longer requiring username/password for staff as only admins have system access.
    $stmt = $pdo->prepare("INSERT INTO users (full_name, contact_number, role) VALUES (?, ?, 'employee')");
    $stmt->execute([$name, $contact]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'name' => $name, 'pic' => null]);
    exit;
}

if ($action == 'create_customer') {
    $name = $_POST['name'];
    $contact = $_POST['contact'];
    $address = $_POST['address'];
    $stmt = $pdo->prepare("INSERT INTO customers (name, contact_number, address) VALUES (?, ?, ?)");
    $stmt->execute([$name, $contact, $address]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'name' => $name, 'contact' => $contact]);
    exit;
}

if ($action == 'save_delivery') {
    try {
        $pdo->beginTransaction();
        $delivery_date = $_POST['delivery_date'];
        $employees = json_decode($_POST['employees'], true) ?? [];
        $expenses  = json_decode($_POST['expenses'],  true) ?? [];
        $customers = json_decode($_POST['customers'], true) ?? [];
        if (empty($customers) || empty($employees)) throw new Exception("Need at least one employee and one customer.");
        $total_expenses = 0;
        foreach ($expenses as $exp) $total_expenses += (float)$exp['amount'];
        $grand_total_sales = 0;
        $stmt = $pdo->prepare("INSERT INTO deliveries (delivery_date, total_expenses, total_sales, created_by) VALUES (?, ?, '0.00', ?)");
        $stmt->execute([$delivery_date, $total_expenses, $user_id]);
        $delivery_id = $pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO delivery_employees (delivery_id, user_id) VALUES (?, ?)");
        foreach ($employees as $emp_id) $stmt->execute([$delivery_id, $emp_id]);
        $stmt = $pdo->prepare("INSERT INTO delivery_expenses (delivery_id, expense_name, amount) VALUES (?, ?, ?)");
        foreach ($expenses as $exp) $stmt->execute([$delivery_id, $exp['name'], $exp['amount']]);
        foreach ($customers as $c) {
            $customer_subtotal = 0;
            $stmt = $pdo->prepare("INSERT INTO delivery_customers (delivery_id, customer_id, subtotal) VALUES (?, ?, 0.00)");
            $stmt->execute([$delivery_id, $c['customer_id']]);
            $dc_id = $pdo->lastInsertId();
            foreach ($c['items'] as $item) {
                $qty = (int)$item['qty'];
                $line_total = $qty * (float)$item['selling_price'];
                $customer_subtotal += $line_total;
                $pdo->prepare("UPDATE container_items SET sold_qty = sold_qty + ? WHERE id = ? AND (total_qty - sold_qty) >= ?")->execute([$qty, $item['item_id'], $qty]);
                $pdo->prepare("INSERT INTO delivery_items (delivery_customer_id, container_item_id, qty, cost_price, selling_price, total) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$dc_id, $item['item_id'], $qty, (float)$item['cost_price'], (float)$item['selling_price'], $line_total]);
            }
            $pdo->prepare("UPDATE delivery_customers SET subtotal = ? WHERE id = ?")->execute([$customer_subtotal, $dc_id]);
            $grand_total_sales += $customer_subtotal;
        }
        $pdo->prepare("UPDATE deliveries SET total_sales = ? WHERE id = ?")->execute([$grand_total_sales, $delivery_id]);
        $pdo->prepare("INSERT INTO delivery_ledger (delivery_id, action_type, notes, performed_by) VALUES (?, 'CREATED', ?, ?)")
            ->execute([$delivery_id, "Route started for {$delivery_date}.", $user_id]);
        $pdo->commit();
        echo json_encode(['success' => true, 'delivery_id' => $delivery_id]);
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

if ($action == 'view_delivery') {
    $id = (int)$_GET['id'];
    $del = $pdo->prepare("SELECT d.*, u.full_name as created_by_name FROM deliveries d JOIN users u ON d.created_by = u.id WHERE d.id = ?");
    $del->execute([$id]);
    $delivery = $del->fetch(PDO::FETCH_ASSOC);
    if (!$delivery) { echo json_encode(['success' => false, 'message' => 'Not found']); exit; }
    $emps = $pdo->prepare("SELECT u.full_name, u.contact_number FROM delivery_employees de JOIN users u ON de.user_id = u.id WHERE de.delivery_id = ?");
    $emps->execute([$id]);
    $delivery['employees'] = $emps->fetchAll(PDO::FETCH_ASSOC);
    $exps = $pdo->prepare("SELECT expense_name, amount FROM delivery_expenses WHERE delivery_id = ? ORDER BY id");
    $exps->execute([$id]);
    $delivery['expenses'] = $exps->fetchAll(PDO::FETCH_ASSOC);
    $custs = $pdo->prepare("SELECT dc.id, dc.subtotal, dc.status, c.name, c.contact_number, c.address FROM delivery_customers dc JOIN customers c ON dc.customer_id = c.id WHERE dc.delivery_id = ? ORDER BY dc.id");
    $custs->execute([$id]);
    $custRows = $custs->fetchAll(PDO::FETCH_ASSOC);
    foreach ($custRows as &$cr) {
        $items = $pdo->prepare("SELECT di.*, b.name as brand_name, c.container_number FROM delivery_items di JOIN container_items ci ON di.container_item_id = ci.id JOIN brands b ON ci.brand_id = b.id JOIN containers c ON ci.container_id = c.id WHERE di.delivery_customer_id = ?");
        $items->execute([$cr['id']]);
        $cr['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($cr);
    $delivery['customers'] = $custRows;
    echo json_encode(['success' => true, 'data' => $delivery]);
    exit;
}

if ($action == 'delete_delivery') {
    $id = (int)$_POST['id'];
    try {
        $pdo->beginTransaction();
        $items = $pdo->prepare("SELECT di.container_item_id, di.qty FROM delivery_items di JOIN delivery_customers dc ON di.delivery_customer_id = dc.id WHERE dc.delivery_id = ?");
        $items->execute([$id]);
        foreach ($items->fetchAll() as $it) $pdo->prepare("UPDATE container_items SET sold_qty = GREATEST(0, sold_qty - ?) WHERE id = ?")->execute([$it['qty'], $it['container_item_id']]);
        $pdo->prepare("DELETE FROM deliveries WHERE id = ?")->execute([$id]);
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

if ($action == 'complete_delivery') {
    $id = (int)$_POST['id'];
    $pdo->prepare("UPDATE deliveries SET status = 'completed' WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

// Fetch Records
$search = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 8;
$offset = ($page - 1) * $limit;
$where = [];
$params = [];
if ($search) { $where[] = "d.id LIKE ?"; $params[] = "%$search%"; }
if ($start_date) { $where[] = "d.delivery_date >= ?"; $params[] = $start_date; }
if ($end_date) { $where[] = "d.delivery_date <= ?"; $params[] = $end_date; }
$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";
$total_records = (int)$pdo->prepare("SELECT COUNT(*) FROM deliveries d $whereClause")->execute($params) ? 0 : $pdo->prepare("SELECT COUNT(*) FROM deliveries d $whereClause")->execute($params) ?? 0; // Fixed execution
// Redoing record count correctly
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM deliveries d $whereClause");
$stmtCount->execute($params);
$total_records = (int)$stmtCount->fetchColumn();
$total_pages = max(1, ceil($total_records / $limit));

$query = "SELECT d.*, 
    (SELECT COUNT(*) FROM delivery_customers WHERE delivery_id = d.id) as customer_count,
    (SELECT GROUP_CONCAT(u.full_name SEPARATOR ', ') FROM delivery_employees de JOIN users u ON de.user_id = u.id WHERE de.delivery_id = d.id) as employee_names
    FROM deliveries d $whereClause ORDER BY d.id DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$deliveries = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route Operations | Crystal POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;600;700&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background: url('../assests/glass_bg.png') no-repeat center center fixed;
            background-size: cover;
            color: #1e293b;
            min-height: 100vh;
        }

        .glass-header {
            background: rgba(248, 250, 252, 0.96);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            box-shadow: 0 4px 20px -5px rgba(0, 0, 0, 0.05);
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
            font-size: 13px;
        }

        .input-glass:focus {
            border-color: #0891b2;
            background: white;
            box-shadow: 0 0 15px rgba(8, 145, 178, 0.08);
        }

        .table-header {
            background: #1e293b;
            color: white;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="flex flex-col">

    <header class="glass-header sticky top-0 z-40 py-4">
        <div class="max-w-7xl mx-auto px-6 flex items-center justify-between">
            <div class="flex items-center space-x-5">
                <a href="dashboard.php" class="text-slate-800 hover:text-cyan-600 transition-colors p-2.5 rounded-2xl hover:bg-slate-100">
                    <i class="fa-solid fa-arrow-left text-xl"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-black text-slate-900 font-['Outfit'] tracking-tight">Deliveries</h1>
                    <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mt-0.5">Delivery & Logistics Tracker</p>
                </div>
            </div>
            
            <button onclick="openModal()" class="bg-slate-900 hover:bg-black text-white px-6 py-3 rounded-2xl font-bold text-xs uppercase tracking-widest transition-all shadow-xl shadow-slate-900/20 flex items-center gap-3">
                <div class="w-6 h-6 bg-slate-700/50 rounded-lg flex items-center justify-center border border-slate-600">
                    <i class="fa-solid fa-plus text-[10px]"></i>
                </div>
                <span>New Delivery</span>
            </button>
        </div>
    </header>

    <main class="max-w-7xl mx-auto w-full px-6 py-10">

        <!-- Filters -->
        <div class="glass-card p-6 mb-8 border-slate-200/50">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-6 items-end">
                <div class="md:col-span-5 relative">
                    <label class="text-[10px] uppercase font-black text-slate-600 mb-2 ml-1 block tracking-widest">Search ID</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Enter Trip ID..." class="input-glass w-full h-[48px]">
                </div>
                <div class="md:col-span-3">
                    <label class="text-[10px] uppercase font-black text-slate-600 mb-2 ml-1 block tracking-widest">Start Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="input-glass w-full h-[48px]">
                </div>
                <div class="md:col-span-3">
                    <label class="text-[10px] uppercase font-black text-slate-600 mb-2 ml-1 block tracking-widest">End Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="input-glass w-full h-[48px]">
                </div>
                <div class="md:col-span-1">
                    <button type="submit" class="w-full h-[48px] bg-slate-100 text-slate-900 rounded-2xl hover:bg-slate-200 transition-all flex items-center justify-center border border-slate-200">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- Data Table -->
        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="table-header">
                            <th class="px-6 py-5">TRIP ID</th>
                            <th class="px-6 py-5">DATE</th>
                            <th class="px-6 py-5">ASSIGNED STAFF</th>
                            <th class="px-6 py-5 text-center">STOPS</th>
                            <th class="px-6 py-5">EXPENSES</th>
                            <th class="px-6 py-5">REVENUE</th>
                            <th class="px-6 py-5 text-center">STATUS</th>
                            <th class="px-6 py-5 text-right">ACTION</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach($deliveries as $d): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="px-6 py-4 font-black text-indigo-600 text-xs text-nowrap">#TRP-<?php echo str_pad($d['id'], 4, '0', STR_PAD_LEFT); ?></td>
                            <td class="px-6 py-4 font-bold text-slate-700 text-sm"><?php echo date('M d, Y', strtotime($d['delivery_date'])); ?></td>
                            <td class="px-6 py-4">
                                <p class="text-[11px] font-bold text-slate-800 leading-tight"><?php echo htmlspecialchars($d['employee_names'] ?: 'Unassigned'); ?></p>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="bg-indigo-50 text-indigo-600 px-2.5 py-1 rounded-lg text-[10px] font-black"><?php echo $d['customer_count']; ?> STOPS</span>
                            </td>
                            <td class="px-6 py-4 font-bold text-rose-600 text-xs">LKR <?php echo number_format($d['total_expenses'], 2); ?></td>
                            <td class="px-6 py-4 font-black text-emerald-600 text-xs">LKR <?php echo number_format($d['total_sales'], 2); ?></td>
                            <td class="px-6 py-4 text-center">
                                <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-wider <?php echo $d['status'] == 'completed' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'; ?>">
                                    <?php echo $d['status']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end space-x-2">
                                    <button onclick="viewRouteDetails(<?php echo $d['id']; ?>)" class="p-2 text-slate-400 hover:text-indigo-600 transition-colors"><i class="fa-solid fa-eye text-sm"></i></button>
                                    <button onclick="confirmDeleteTrip(<?php echo $d['id']; ?>)" class="p-2 text-slate-400 hover:text-rose-600 transition-colors"><i class="fa-solid fa-trash-can text-sm"></i></button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination Interface -->
            <div class="px-6 py-5 bg-slate-50/50 border-t border-slate-100 flex items-center justify-between">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Page <?php echo $page; ?> of <?php echo $total_pages; ?></p>
                <div class="flex space-x-2">
                    <?php if($page > 1): ?>
                    <a href="?page=<?php echo $page-1; ?>" class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold hover:bg-slate-50">Prev</a>
                    <?php endif; ?>
                    <?php if($page < $total_pages): ?>
                    <a href="?page=<?php echo $page+1; ?>" class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold hover:bg-slate-50">Next</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal: Start New Route -->
    <div id="route-modal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="glass-card w-full max-w-5xl max-h-[90vh] flex flex-col overflow-hidden shadow-2xl">
            <div class="p-6 border-b border-white/40 flex items-center justify-between bg-white/20">
                <h3 class="text-xl font-black font-['Outfit'] text-slate-900">Initialize Supply Trip</h3>
                <button onclick="closeModal()" class="text-slate-500 hover:text-slate-800"><i class="fa-solid fa-times text-xl"></i></button>
            </div>
            
            <div class="overflow-y-auto p-5 md:p-6 pb-40 space-y-5 custom-scroll">
                <form id="route-form" class="space-y-5">
                    <!-- Base Details -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="text-[9px] uppercase font-black text-slate-400 mb-1.5 ml-1 block tracking-[0.2em]">Scheduled Trip Date</label>
                            <div class="relative">
                                <i class="fa-solid fa-calendar-day absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
                                <input type="date" id="delivery_date" class="input-glass w-full h-[38px] pl-9 text-xs font-bold text-slate-700" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        <div class="col-span-2 relative">
                            <label class="text-[9px] uppercase font-black text-slate-400 mb-1.5 ml-1 block tracking-[0.2em]">Personnel Assignment</label>
                            <div class="flex items-start gap-4">
                                <div class="relative w-[300px]">
                                    <i class="fa-solid fa-user-plus absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
                                    <input type="text" id="emp_search" placeholder="Search staff..." class="input-glass w-full h-[38px] pl-9 text-xs" onkeyup="searchEmployees(this.value)">
                                    <div id="emp_results" class="absolute w-full mt-1 bg-white/80 backdrop-blur-xl border border-white/40 rounded-xl shadow-2xl z-20 hidden overflow-hidden"></div>
                                </div>
                                <div id="assigned_staff" class="flex flex-wrap gap-2 flex-1"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Expenses Panel -->
                    <div class="p-4 bg-white/40 rounded-[1.5rem] border border-white/60">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-[10px] uppercase font-black text-slate-400 tracking-widest">Pre-paid Trip Expenses</h4>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="addQuickExpense('Fuel')" class="text-[9px] font-bold text-slate-500 bg-white border border-slate-200 px-3 py-1.5 rounded-xl hover:bg-indigo-50 hover:text-indigo-600 transition-all">Fuel</button>
                                <button type="button" onclick="addQuickExpense('Accommodation')" class="text-[9px] font-bold text-slate-500 bg-white border border-slate-200 px-3 py-1.5 rounded-xl hover:bg-indigo-50 hover:text-indigo-600 transition-all">Accomm.</button>
                                <button type="button" onclick="addQuickExpense('Meals')" class="text-[9px] font-bold text-slate-500 bg-white border border-slate-200 px-3 py-1.5 rounded-xl hover:bg-indigo-50 hover:text-indigo-600 transition-all">Meals</button>
                                <button type="button" onclick="addExpenseRow()" class="text-[10px] font-black text-indigo-600 bg-indigo-50 px-3 py-1.5 rounded-xl border border-indigo-100 uppercase ml-2">+ Custom</button>
                            </div>
                        </div>
                        <div id="expense_rows" class="space-y-2.5"></div>
                    </div>

                    <!-- Customers & Orders -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs uppercase font-black text-slate-900 tracking-widest">Customer Delivery Queue</h4>
                            <button type="button" onclick="addCustomerBlock()" class="bg-indigo-600 text-white px-5 py-2 rounded-xl font-bold text-[10px] uppercase tracking-widest shadow-lg shadow-indigo-600/20">Add Customer Order</button>
                        </div>
                        <div id="customer_blocks" class="space-y-4"></div>
                    </div>
                </form>
            </div>

            <div class="p-6 border-t border-white/40 flex items-center justify-between bg-white/40">
                <div class="flex space-x-8">
                    <div>
                        <p class="text-[9px] uppercase font-black text-slate-400 tracking-widest">Trip Expenses</p>
                        <p id="total_expenses_display" class="text-lg font-black text-rose-600">LKR 0.00</p>
                    </div>
                    <div>
                        <p class="text-[9px] uppercase font-black text-slate-400 tracking-widest">Estimated Revenue</p>
                        <p id="total_sales_display" class="text-lg font-black text-emerald-600">LKR 0.00</p>
                    </div>
                </div>
                <div class="flex space-x-3">
                    <button onclick="closeModal()" class="px-6 py-3 font-bold text-slate-400 uppercase text-[10px] tracking-widest">Abort</button>
                    <button onclick="processRouteSave()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-8 py-3.5 rounded-2xl font-black text-xs uppercase tracking-widest shadow-2xl shadow-indigo-600/30 transition-all active:scale-95 flex items-center gap-3">
                        <i class="fa-solid fa-paper-plane text-[10px]"></i>
                        <span>Authorize Trip</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Details View Modal -->
    <div id="details-modal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="glass-card w-full max-w-4xl max-h-[90vh] overflow-y-auto p-8 custom-scroll">
            <div id="trip-content"></div>
        </div>
    </div>

    <!-- Quick Add Employee Modal -->
    <div id="quick-employee-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xl z-[70] flex items-center justify-center p-4 hidden">
        <div class="glass-card w-full max-w-md bg-white/95 p-8 shadow-2xl relative">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h3 class="text-xl font-black font-['Outfit'] text-slate-900">Add Staff Member</h3>
                    <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest">Enroll new field personnel</p>
                </div>
                <button onclick="closeQuickModal()" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-slate-800 transition-all"><i class="fa-solid fa-times"></i></button>
            </div>
            
            <form id="quick-emp-form" onsubmit="saveQuickEmployee(event)" class="space-y-6">
                <div class="space-y-2">
                    <label class="text-[10px] uppercase font-black text-slate-500 tracking-widest ml-1">Full Identity Name</label>
                    <input type="text" name="name" id="quick_emp_name" required class="input-glass w-full h-[52px] font-bold" placeholder="e.g. Ruwan Kumara">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] uppercase font-black text-slate-500 tracking-widest ml-1">Contact Dial</label>
                    <input type="text" name="contact" required class="input-glass w-full h-[52px] font-bold" placeholder="07XXXXXXXX">
                </div>
                <div class="pt-2">
                    <button type="submit" class="w-full bg-indigo-600 hover:bg-black text-white py-4 rounded-2xl font-black text-xs uppercase tracking-widest shadow-2xl shadow-indigo-600/30 transition-all active:scale-95">
                        Enroll & Assign to Trip
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Quick Add Customer Modal -->
    <div id="quick-customer-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xl z-[70] flex items-center justify-center p-4 hidden">
        <div class="glass-card w-full max-w-md bg-white/95 p-8 shadow-2xl relative">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h3 class="text-xl font-black font-['Outfit'] text-slate-900">New Client Registry</h3>
                    <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest">Register a new delivery point</p>
                </div>
                <button onclick="closeCustomerModal()" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-slate-800 transition-all"><i class="fa-solid fa-times"></i></button>
            </div>
            
            <form id="quick-cust-form" onsubmit="saveQuickCustomer(event)" class="space-y-6">
                <input type="hidden" id="cust_block_id">
                <div class="space-y-2">
                    <label class="text-[10px] uppercase font-black text-slate-500 tracking-widest ml-1">Client / Business Name</label>
                    <input type="text" name="name" id="quick_cust_name" required class="input-glass w-full h-[52px] font-bold" placeholder="e.g. Sunil Stores">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] uppercase font-black text-slate-500 tracking-widest ml-1">Contact Hotline</label>
                    <input type="text" name="contact" required class="input-glass w-full h-[52px] font-bold" placeholder="0XXXXXXXXX">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] uppercase font-black text-slate-500 tracking-widest ml-1">Delivery Address</label>
                    <textarea name="address" required class="input-glass w-full min-h-[100px] font-bold" placeholder="Enter full geolocation address..."></textarea>
                </div>
                <div class="pt-2">
                    <button type="submit" class="w-full bg-emerald-600 hover:bg-black text-white py-4 rounded-2xl font-black text-xs uppercase tracking-widest shadow-2xl shadow-emerald-600/30 transition-all active:scale-95">
                        Register & Assign to Queue
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const routeModal = document.getElementById('route-modal');
        const detailsModal = document.getElementById('details-modal');
        let tripEmployees = [];
        let tripExpenses = [];
        let tripCustomers = [];

        function openModal() {
            routeModal.classList.remove('hidden');
            tripEmployees = [];
            tripExpenses = [];
            tripCustomers = [];
            document.getElementById('assigned_staff').innerHTML = '';
            document.getElementById('expense_rows').innerHTML = '';
            document.getElementById('customer_blocks').innerHTML = '';
            addExpenseRow();
            addCustomerBlock();
        }

        function closeModal() {
            routeModal.classList.add('hidden');
            detailsModal.classList.add('hidden');
        }

        function searchEmployees(term) {
            if(term.length < 2) return document.getElementById('emp_results').classList.add('hidden');
            fetch(`?action=search_employee&term=${term}`)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    if (data.length > 0) {
                        data.forEach(e => {
                            html += `<div class="p-3 hover:bg-indigo-50/50 cursor-pointer text-sm font-bold text-slate-700 flex items-center border-b border-white/5 transition-colors" onclick="addStaff(${e.id}, '${e.full_name}', '${e.contact_number}')">
                                <div class="w-7 h-7 bg-indigo-50 rounded-lg flex items-center justify-center mr-3 text-indigo-400 text-[10px] border border-indigo-100">
                                    <i class="fa-solid fa-user"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-bold leading-none mb-1">${e.full_name}</p>
                                    <p class="text-[9px] text-slate-400 font-medium tracking-wider">${e.contact_number}</p>
                                </div>
                            </div>`;
                        });
                    } else {
                        html = `
                            <div class="p-4 text-center">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 italic">Staff member not found</p>
                                <button type="button" onclick="openQuickEmployeeModal('${term}')" class="w-full bg-indigo-600 hover:bg-black text-white py-3 rounded-xl font-black text-[10px] uppercase tracking-widest transition-all shadow-lg">
                                    <i class="fa-solid fa-plus-circle mr-2"></i> Register "${term}"
                                </button>
                            </div>
                        `;
                    }
                    const res = document.getElementById('emp_results');
                    res.innerHTML = html;
                    res.classList.remove('hidden');
                });
        }

        function openQuickEmployeeModal(defaultName = '') {
            document.getElementById('quick_emp_name').value = defaultName;
            document.getElementById('quick-employee-modal').classList.remove('hidden');
        }

        function closeQuickModal() {
            document.getElementById('quick-employee-modal').classList.add('hidden');
        }

        function saveQuickEmployee(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            formData.append('action', 'create_employee');

            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        addStaff(res.id, res.name, formData.get('contact'));
                        closeQuickModal();
                        document.getElementById('quick-emp-form').reset();
                    } else {
                        alert(res.message);
                    }
                });
        }

        function addStaff(id, name, contact) {
            if(tripEmployees.some(e => e.id === id)) return;
            tripEmployees.push({id, name, contact});
            renderStaff();
            document.getElementById('emp_results').classList.add('hidden');
            document.getElementById('emp_search').value = '';
        }

        function removeStaff(id) {
            tripEmployees = tripEmployees.filter(e => e.id !== id);
            renderStaff();
        }

        function renderStaff() {
            const container = document.getElementById('assigned_staff');
            container.innerHTML = tripEmployees.map(e => `
                <div class="bg-white border border-slate-200 p-2 rounded-xl flex items-center gap-3 shadow-sm animate-[scaleIn_0.2s_ease]">
                    <div class="w-7 h-7 bg-slate-900 rounded-lg flex items-center justify-center text-white text-[10px]">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-black text-slate-800 leading-none mb-1">${e.name.toUpperCase()}</p>
                        <p class="text-[9px] font-bold text-slate-400 tracking-tighter">${e.contact}</p>
                    </div>
                    <button type="button" onclick="removeStaff(${e.id})" class="ml-1 text-slate-300 hover:text-rose-500 transition-colors">
                        <i class="fa-solid fa-circle-xmark text-sm"></i>
                    </button>
                </div>
            `).join('');
        }

        function addQuickExpense(name) {
            const id = Date.now();
            const html = `
                <div id="exp-${id}" class="grid grid-cols-12 gap-3 items-center animate-[fadeIn_0.3s_ease]">
                    <div class="col-span-8">
                        <input type="text" value="${name}" class="input-glass w-full h-[38px] exp-name text-xs font-bold">
                    </div>
                    <div class="col-span-3">
                        <input type="number" placeholder="0.00" class="input-glass w-full h-[38px] exp-amt text-xs font-bold" onkeyup="calculateTotals()" autofocus>
                    </div>
                    <div class="col-span-1 text-center font-bold text-slate-300 hover:text-rose-500 cursor-pointer" onclick="document.getElementById('exp-${id}').remove(); calculateTotals();">
                        <i class="fa-solid fa-times text-xs"></i>
                    </div>
                </div>
            `;
            document.getElementById('expense_rows').insertAdjacentHTML('beforeend', html);
        }

        function addExpenseRow() {
            const id = Date.now();
            const html = `
                <div id="exp-${id}" class="grid grid-cols-12 gap-3 items-center animate-[fadeIn_0.3s_ease]">
                    <div class="col-span-8">
                        <input type="text" placeholder="Expense description" class="input-glass w-full h-[38px] exp-name text-xs">
                    </div>
                    <div class="col-span-3">
                        <input type="number" placeholder="0.00" class="input-glass w-full h-[38px] exp-amt text-xs" onkeyup="calculateTotals()">
                    </div>
                    <div class="col-span-1 text-center font-bold text-slate-300 hover:text-rose-500 cursor-pointer" onclick="document.getElementById('exp-${id}').remove(); calculateTotals();">
                        <i class="fa-solid fa-times text-xs"></i>
                    </div>
                </div>
            `;
            document.getElementById('expense_rows').insertAdjacentHTML('beforeend', html);
        }

        function addCustomerBlock() {
            const id = Date.now();
            const html = `
                <div id="cust-${id}" class="glass-card p-3 border border-slate-200 relative animate-[fadeIn_0.4s_ease] mb-4">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-3 flex-1">
                            <div class="w-8 h-8 bg-indigo-50 border-2 border-indigo-500/30 rounded-xl flex items-center justify-center text-indigo-600"><i class="fa-solid fa-user-tag text-[10px]"></i></div>
                            <input type="text" placeholder="Assign Client..." class="input-glass flex-1 h-[38px] text-xs font-bold cust-search" onkeyup="searchCustomers(this.value, ${id})">
                        </div>
                        <button type="button" onclick="document.getElementById('cust-${id}').remove(); calculateTotals();" class="ml-3 text-slate-300 hover:text-rose-600"><i class="fa-solid fa-trash-can text-xs"></i></button>
                    </div>
                    <div class="hidden customer-results absolute w-full left-0 top-[80px] z-30 bg-white/80 backdrop-blur-xl border border-white/40 rounded-2xl shadow-2xl p-2 max-w-md mx-6"></div>
                    
                    <div class="selected-customer-info mb-6 hidden bg-emerald-500/10 p-4 rounded-2xl border border-emerald-500/20 text-emerald-700 text-sm font-bold"></div>
                    
                    <div class="space-y-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[9px] uppercase font-black text-slate-400 tracking-widest">Current Order Items</span>
                            <button type="button" onclick="addItemRow(${id})" class="text-[9px] font-black text-emerald-600 uppercase tracking-widest">+ Add Item</button>
                        </div>
                        <div class="order-items space-y-3"></div>
                    </div>
                </div>
            `;
            document.getElementById('customer_blocks').insertAdjacentHTML('beforeend', html);
            addItemRow(id);
        }

        function searchCustomers(term, blockId) {
            const block = document.getElementById(`cust-${blockId}`);
            const resultsDiv = block.querySelector('.customer-results');
            if(term.length < 2) return resultsDiv.classList.add('hidden');
            
            fetch(`?action=search_customer&term=${term}`)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    if (data.length > 0) {
                        data.forEach(c => {
                            html += `<div class="p-3 hover:bg-slate-50 cursor-pointer border-b border-white/5 last:border-0" onclick="selectCustomer(${blockId}, ${c.id}, '${c.name}', '${c.contact_number}')">
                                <p class="text-sm font-black text-slate-800">${c.name}</p>
                                <p class="text-[10px] text-slate-400 uppercase font-bold tracking-widest">${c.contact_number}</p>
                            </div>`;
                        });
                    } else {
                        html = `
                            <div class="p-4 text-center">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 italic">Client not found</p>
                                <button type="button" onclick="openQuickCustomerModal(${blockId}, '${term}')" class="w-full bg-emerald-600 hover:bg-black text-white py-3 rounded-xl font-black text-[10px] uppercase tracking-widest transition-all shadow-lg">
                                    <i class="fa-solid fa-plus-circle mr-2"></i> Register "${term}"
                                </button>
                            </div>
                        `;
                    }
                    resultsDiv.innerHTML = html;
                    resultsDiv.classList.remove('hidden');
                });
        }

        function openQuickCustomerModal(blockId, name) {
            document.getElementById('cust_block_id').value = blockId;
            document.getElementById('quick_cust_name').value = name;
            document.getElementById('quick-customer-modal').classList.remove('hidden');
        }

        function closeCustomerModal() {
            document.getElementById('quick-customer-modal').classList.add('hidden');
        }

        function saveQuickCustomer(e) {
            e.preventDefault();
            const blockId = document.getElementById('cust_block_id').value;
            const formData = new FormData(e.target);
            formData.append('action', 'create_customer');
            
            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        selectCustomer(blockId, res.id, res.name, res.contact);
                        closeCustomerModal();
                        document.getElementById('quick-cust-form').reset();
                    } else { alert(res.message); }
                });
        }

        function selectCustomer(blockId, id, name, contact) {
            const block = document.getElementById(`cust-${blockId}`);
            block.dataset.customerId = id;
            const info = block.querySelector('.selected-customer-info');
            info.innerHTML = `<i class="fa-solid fa-check-circle mr-2"></i> Client Active: ${name} (${contact})`;
            info.classList.remove('hidden');
            block.querySelector('.customer-results').classList.add('hidden');
            block.querySelector('.cust-search').value = name;
        }

        function addItemRow(blockId) {
            const id = Date.now();
            const html = `
                <div id="item-${id}" class="space-y-1">
                    <div class="grid grid-cols-12 gap-2 items-center">
                        <div class="col-span-6 relative">
                            <input type="text" placeholder="Select Product..." class="input-glass w-full h-[36px] text-xs font-bold item-search" onkeyup="searchBrands(this.value, ${id})">
                            <div class="brand-results hidden absolute w-full mt-1 bg-white/80 backdrop-blur-xl border border-white/40 rounded-xl shadow-2xl z-40 p-1"></div>
                            <input type="hidden" class="item-id">
                            <input type="hidden" class="cost-price">
                        </div>
                        <div class="col-span-2">
                            <input type="number" placeholder="Qty" class="input-glass w-full h-[36px] text-xs font-bold item-qty" onkeyup="calculateTotals()">
                        </div>
                        <div class="col-span-3">
                            <input type="number" placeholder="Price" class="input-glass w-full h-[36px] text-xs font-bold item-price" onkeyup="calculateTotals()">
                        </div>
                        <div class="col-span-1 text-center text-slate-300 hover:text-rose-500 cursor-pointer" onclick="document.getElementById('item-${id}').remove(); calculateTotals();">
                            <i class="fa-solid fa-minus-circle text-[10px]"></i>
                        </div>
                    </div>
                    <div class="stock-info px-2 hidden flex items-center gap-2">
                        <span class="text-[8px] font-black text-emerald-600 uppercase tracking-widest bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-100/30"></span>
                        <span class="text-[8px] font-black text-amber-600 uppercase tracking-widest bg-amber-50 px-1.5 py-0.5 rounded border border-amber-100/30"></span>
                    </div>
                </div>
            `;
            document.getElementById(`cust-${blockId}`).querySelector('.order-items').insertAdjacentHTML('beforeend', html);
        }

        function selectBrand(itemId, id, name, qty, cost) {
            const row = document.getElementById(`item-${itemId}`);
            row.querySelector('.item-id').value = id;
            row.querySelector('.item-search').value = name;
            row.querySelector('.cost-price').value = cost;
            
            const stockDiv = row.querySelector('.stock-info');
            const badges = stockDiv.querySelectorAll('span');
            badges[0].innerHTML = `<i class="fa-solid fa-box-archive mr-1"></i> Stock: ${qty} PKTS`;
            badges[1].innerHTML = `<i class="fa-solid fa-coins mr-1"></i> Unit Cost: LKR ${cost}`;
            stockDiv.classList.remove('hidden');
            
            row.querySelector('.brand-results').classList.add('hidden');
            calculateTotals();
        }

        function searchBrands(term, itemId) {
            const row = document.getElementById(`item-${itemId}`);
            const resultsDiv = row.querySelector('.brand-results');
            if(term.length < 2) return resultsDiv.classList.add('hidden');

            fetch(`?action=search_brand_stock&term=${term}`)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    data.forEach(b => {
                        html += `<div class="p-2 hover:bg-slate-50 cursor-pointer border-b border-slate-50 last:border-0" onclick="selectBrand(${itemId}, ${b.item_id}, '${b.brand_name}', ${b.available_qty}, ${b.per_item_cost})">
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-xs font-black text-slate-800">${b.brand_name}</span>
                                <span class="text-[9px] bg-emerald-50 text-emerald-600 px-1.5 py-0.5 rounded font-black">${b.available_qty} PKTS</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[8px] text-slate-400 uppercase font-bold bg-slate-100 px-1 rounded">${b.container_number}</span>
                                <span class="text-[8px] text-slate-400 uppercase font-bold bg-slate-100 px-1 rounded">${b.country}</span>
                                <span class="text-[8px] text-indigo-500 font-black ml-auto">LKR ${b.per_item_cost} / UNIT</span>
                            </div>
                        </div>`;
                    });
                    resultsDiv.innerHTML = html || '<p class="p-3 text-[9px] text-slate-400 text-center font-black">OUT OF STOCK</p>';
                    resultsDiv.classList.remove('hidden');
                });
        }

        function selectBrand(itemId, id, name, qty, cost) {
            const row = document.getElementById(`item-${itemId}`);
            row.querySelector('.item-id').value = id;
            row.querySelector('.item-search').value = `${name} (Avail: ${qty} / Cost: LKR ${cost})`;
            row.querySelector('.cost-price').value = cost;
            
            // Show stock badges if they exist or update them
            const stockDiv = row.querySelector('.stock-info');
            if(stockDiv) {
                const badges = stockDiv.querySelectorAll('span');
                if(badges.length >= 2) {
                    badges[0].innerHTML = `<i class="fa-solid fa-box-archive mr-1"></i> Stock: ${qty} PKTS`;
                    badges[1].innerHTML = `<i class="fa-solid fa-coins mr-1"></i> Unit Cost: LKR ${cost}`;
                    stockDiv.classList.remove('hidden');
                }
            }
            
            row.querySelector('.brand-results').classList.add('hidden');
            calculateTotals();
        }

        function calculateTotals() {
            let totalExp = 0;
            document.querySelectorAll('.exp-amt').forEach(i => totalExp += (parseFloat(i.value) || 0));
            document.getElementById('total_expenses_display').innerText = `LKR ${totalExp.toLocaleString()}`;

            let totalRev = 0;
            document.querySelectorAll('.order-items').forEach(cont => {
                cont.querySelectorAll('.grid').forEach(row => {
                    const q = parseFloat(row.querySelector('.item-qty').value) || 0;
                    const p = parseFloat(row.querySelector('.item-price').value) || 0;
                    totalRev += (q * p);
                });
            });
            document.getElementById('total_sales_display').innerText = `LKR ${totalRev.toLocaleString()}`;
        }

        function processRouteSave() {
            const btn = event.currentTarget;
            const originalHtml = btn.innerHTML;
            
            // Collect Data
            const date = document.getElementById('delivery_date').value;
            const emps = tripEmployees.map(e => e.id);
            
            const exps = [];
            document.querySelectorAll('#expense_rows .grid').forEach(r => {
                const n = r.querySelector('.exp-name').value;
                const a = r.querySelector('.exp-amt').value;
                if(n && a) exps.push({name: n, amount: a});
            });

            const custs = [];
            document.querySelectorAll('#customer_blocks .glass-card').forEach(b => {
                const cid = b.dataset.customerId;
                const items = [];
                b.querySelectorAll('.order-items .grid').forEach(r => {
                    const iid = r.querySelector('.item-id').value;
                    const q = r.querySelector('.item-qty').value;
                    const p = r.querySelector('.item-price').value;
                    const cp = r.querySelector('.cost-price').value;
                    if(iid && q && p) items.push({item_id: iid, qty: q, selling_price: p, cost_price: cp});
                });
                if(cid && items.length) custs.push({customer_id: cid, items});
            });

            if(!emps.length || !custs.length) return alert('Assign at least one staff and one customer order.');

            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;

            const formData = new FormData();
            formData.append('action', 'save_delivery');
            formData.append('delivery_date', date);
            formData.append('employees', JSON.stringify(emps));
            formData.append('expenses', JSON.stringify(exps));
            formData.append('customers', JSON.stringify(custs));

            fetch('nwdelivery.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if(res.success) location.reload();
                    else {
                        alert(res.message);
                        btn.innerHTML = originalHtml;
                        btn.disabled = false;
                    }
                });
        }

        function viewRouteDetails(id) {
            window.location.href = 'del_details.php?id=' + id;
        }

        function confirmDeleteTrip(id) {
            if(confirm(`Are you sure you want to PERMANENTLY DELETE Trip #TRP-${id}? All associated data and stocks will be affected.`)) {
                fetch('nwdelivery.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=delete_delivery&id=${id}`
                }).then(r => r.json()).then(res => {
                    if(res.success) location.reload();
                    else alert(res.message);
                });
            }
        }
    </script>
</body>
</html>
