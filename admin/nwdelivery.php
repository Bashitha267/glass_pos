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

// Search Employees
if ($action == 'search_employee') {
    $term = '%' . $_GET['term'] . '%';
    $stmt = $pdo->prepare("SELECT id, full_name, profile_pic FROM users WHERE role = 'employee' AND system_access = 1 AND full_name LIKE ? LIMIT 5");
    $stmt->execute([$term]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Search Customers
if ($action == 'search_customer') {
    $term = '%' . $_GET['term'] . '%';
    $stmt = $pdo->prepare("SELECT id, name, contact_number, address FROM customers WHERE name LIKE ? OR contact_number LIKE ? LIMIT 5");
    $stmt->execute([$term, $term]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Search Brands (Containers Available)
if ($action == 'search_brand_stock') {
    $term = '%' . $_GET['term'] . '%';
    // Find brands that match term and have available stock
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

// Quick Create Employee
if ($action == 'create_employee') {
    $name = $_POST['name'];
    $contact = $_POST['contact'];
    $username = $_POST['username'];
    
    // Check if username exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'Username exists']);
        exit;
    }

    $password = password_hash('123456', PASSWORD_DEFAULT); // Default quick password
    $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, contact_number, role, system_access) VALUES (?, ?, ?, ?, 'employee', 1)");
    $stmt->execute([$username, $password, $name, $contact]);
    
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'name' => $name, 'pic' => null]);
    exit;
}

// Quick Create Customer
if ($action == 'create_customer') {
    $name = $_POST['name'];
    $contact = $_POST['contact'];
    $address = $_POST['address'];

    $stmt = $pdo->prepare("INSERT INTO customers (name, contact_number, address) VALUES (?, ?, ?)");
    $stmt->execute([$name, $contact, $address]);
    
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'name' => $name, 'contact' => $contact]);
    exit;
}

// Save Full Delivery
if ($action == 'save_delivery') {
    try {
        $pdo->beginTransaction();
        
        $delivery_date = $_POST['delivery_date'];
        $employees = json_decode($_POST['employees'], true) ?? [];
        $expenses  = json_decode($_POST['expenses'],  true) ?? [];
        $customers = json_decode($_POST['customers'], true) ?? [];
        
        if (empty($customers) || empty($employees)) {
            throw new Exception("Delivery must have at least one employee and one customer.");
        }

        $total_expenses = 0;
        foreach ($expenses as $exp) $total_expenses += (float)$exp['amount'];
        $grand_total_sales = 0;

        $stmt = $pdo->prepare("INSERT INTO deliveries (delivery_date, total_expenses, total_sales, created_by) VALUES (?, ?, '0.00', ?)");
        $stmt->execute([$delivery_date, $total_expenses, $user_id]);
        $delivery_id = $pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO delivery_employees (delivery_id, user_id) VALUES (?, ?)");
        foreach ($employees as $emp_id) { $stmt->execute([$delivery_id, $emp_id]); }

        $stmt = $pdo->prepare("INSERT INTO delivery_expenses (delivery_id, expense_name, amount) VALUES (?, ?, ?)");
        foreach ($expenses as $exp) { $stmt->execute([$delivery_id, $exp['name'], $exp['amount']]); }

        foreach ($customers as $c) {
            $customer_subtotal = 0;
            $stmt = $pdo->prepare("INSERT INTO delivery_customers (delivery_id, customer_id, subtotal) VALUES (?, ?, 0.00)");
            $stmt->execute([$delivery_id, $c['customer_id']]);
            $dc_id = $pdo->lastInsertId();

            $itemStmt        = $pdo->prepare("INSERT INTO delivery_items (delivery_customer_id, container_item_id, qty, cost_price, selling_price, total) VALUES (?, ?, ?, ?, ?, ?)");
            $updateStockStmt = $pdo->prepare("UPDATE container_items SET sold_qty = sold_qty + ? WHERE id = ? AND (total_qty - sold_qty) >= ?");

            foreach ($c['items'] as $item) {
                $qty        = (int)$item['qty'];
                $cost       = (float)$item['cost_price'];
                $sell       = (float)$item['selling_price'];
                $line_total = $qty * $sell;
                $customer_subtotal += $line_total;

                $updateStockStmt->execute([$qty, $item['item_id'], $qty]);
                if ($updateStockStmt->rowCount() === 0) {
                    throw new Exception("Not enough stock for Item ID: {$item['item_id']}.");
                }
                $itemStmt->execute([$dc_id, $item['item_id'], $qty, $cost, $sell, $line_total]);
            }
            $pdo->prepare("UPDATE delivery_customers SET subtotal = ? WHERE id = ?")->execute([$customer_subtotal, $dc_id]);
            $grand_total_sales += $customer_subtotal;
        }

        $pdo->prepare("UPDATE deliveries SET total_sales = ? WHERE id = ?")->execute([$grand_total_sales, $delivery_id]);

        // Ledger: CREATED
        $pdo->prepare("INSERT INTO delivery_ledger (delivery_id, action_type, notes, performed_by) VALUES (?, 'CREATED', ?, ?)")
            ->execute([$delivery_id, "New delivery created for {$delivery_date}. Expenses: Rs.{$total_expenses}, Sales: Rs.{$grand_total_sales}.", $user_id]);

        $pdo->commit();
        echo json_encode(['success' => true, 'delivery_id' => $delivery_id]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// View Delivery (full invoice data)
if ($action == 'view_delivery') {
    $id = (int)$_GET['id'];
    $del = $pdo->prepare("SELECT d.*, u.full_name as created_by_name FROM deliveries d JOIN users u ON d.created_by = u.id WHERE d.id = ?");
    $del->execute([$id]);
    $delivery = $del->fetch(PDO::FETCH_ASSOC);
    if (!$delivery) { echo json_encode(['success' => false, 'message' => 'Not found']); exit; }

    $emps = $pdo->prepare("SELECT u.full_name, u.contact_number FROM delivery_employees de JOIN users u ON de.user_id = u.id WHERE de.delivery_id = ?");
    $emps->execute([$id]);
    $delivery['employees'] = $emps->fetchAll(PDO::FETCH_ASSOC);

    // Admin-set expenses
    $exps = $pdo->prepare("SELECT expense_name, amount FROM delivery_expenses WHERE delivery_id = ? ORDER BY id");
    $exps->execute([$id]);
    $delivery['expenses'] = $exps->fetchAll(PDO::FETCH_ASSOC);

    // Field expenses (employee-added) with added_by name
    $fexps = $pdo->prepare("SELECT fe.expense_name, fe.amount, u.full_name as added_by FROM delivery_field_expenses fe JOIN users u ON fe.added_by = u.id WHERE fe.delivery_id = ? ORDER BY fe.added_at");
    $fexps->execute([$id]);
    $delivery['field_expenses'] = $fexps->fetchAll(PDO::FETCH_ASSOC);

    $custs = $pdo->prepare("SELECT dc.id, dc.subtotal, dc.status, c.name, c.contact_number, c.address FROM delivery_customers dc JOIN customers c ON dc.customer_id = c.id WHERE dc.delivery_id = ? ORDER BY dc.id");
    $custs->execute([$id]);
    $custRows = $custs->fetchAll(PDO::FETCH_ASSOC);

    $grand_assigned = 0;
    $grand_damaged_val = 0;

    foreach ($custRows as &$cr) {
        $items = $pdo->prepare("
            SELECT di.id as item_id, di.qty, di.cost_price, di.selling_price, di.total,
                   b.name as brand_name, c.container_number,
                   COALESCE(dmg.damaged_qty, 0) as damaged_qty,
                   COALESCE(dmg.damaged_qty, 0) * di.selling_price as damaged_value
            FROM delivery_items di
            JOIN container_items ci ON di.container_item_id = ci.id
            JOIN brands b ON ci.brand_id = b.id
            JOIN containers c ON ci.container_id = c.id
            LEFT JOIN delivery_item_damages dmg ON dmg.delivery_item_id = di.id
            WHERE di.delivery_customer_id = ?
        ");
        $items->execute([$cr['id']]);
        $rows = $items->fetchAll(PDO::FETCH_ASSOC);

        // Compute per-customer net totals
        $cr['assigned_total'] = array_sum(array_column($rows, 'total'));
        $cr['damaged_value']  = array_sum(array_column($rows, 'damaged_value'));
        $cr['net_total']      = $cr['assigned_total'] - $cr['damaged_value'];
        $cr['items']          = $rows;

        $grand_assigned   += $cr['assigned_total'];
        $grand_damaged_val += $cr['damaged_value'];
    }
    unset($cr);
    $delivery['customers']       = $custRows;
    $delivery['grand_assigned']  = $grand_assigned;
    $delivery['grand_damaged']   = $grand_damaged_val;
    $delivery['grand_net']       = $grand_assigned - $grand_damaged_val;
    $delivery['field_exp_total'] = array_sum(array_column($delivery['field_expenses'], 'amount'));
    $delivery['total_exp_all']   = $delivery['total_expenses'] + $delivery['field_exp_total'];

    // Ledger
    $ledger = $pdo->prepare("SELECT dl.action_type, dl.notes, dl.performed_at, u.full_name FROM delivery_ledger dl JOIN users u ON dl.performed_by = u.id WHERE dl.delivery_id = ? ORDER BY dl.id DESC");
    $ledger->execute([$id]);
    $delivery['ledger'] = $ledger->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $delivery]);
    exit;
}


// Delete Delivery
if ($action == 'delete_delivery') {
    $id = (int)$_POST['id'];
    try {
        $pdo->beginTransaction();
        // Restore stock
        $items = $pdo->prepare("
            SELECT di.container_item_id, di.qty
            FROM delivery_items di
            JOIN delivery_customers dc ON di.delivery_customer_id = dc.id
            WHERE dc.delivery_id = ?
        ");
        $items->execute([$id]);
        $restoreStmt = $pdo->prepare("UPDATE container_items SET sold_qty = GREATEST(0, sold_qty - ?) WHERE id = ?");
        foreach ($items->fetchAll() as $it) {
            $restoreStmt->execute([$it['qty'], $it['container_item_id']]);
        }
        // Log before delete (ledger FK CASCADE will remove after)
        $pdo->prepare("INSERT INTO delivery_ledger (delivery_id, action_type, notes, performed_by) VALUES (?, 'DELETED', 'Delivery permanently deleted. Stock reversed.', ?)")
            ->execute([$id, $user_id]);
        $pdo->prepare("DELETE FROM deliveries WHERE id = ?")->execute([$id]);
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Complete Delivery
if ($action == 'complete_delivery') {
    $id = (int)$_POST['id'];
    try {
        $stmt = $pdo->prepare("UPDATE deliveries SET status = 'completed' WHERE id = ?");
        $stmt->execute([$id]);
        
        // Log to ledger
        $pdo->prepare("INSERT INTO delivery_ledger (delivery_id, action_type, notes, performed_by) VALUES (?, 'EDITED', 'Delivery marked as COMPLETED.', ?)")
            ->execute([$id, $user_id]);
            
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch Initial Deliveries for View
$search     = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date   = $_GET['end_date'] ?? '';
$page       = max(1, (int)($_GET['page'] ?? 1));
$limit      = 8;
$offset     = ($page - 1) * $limit;

$where  = [];
$params = [];
if ($search)     { $where[] = "d.id LIKE ?";           $params[] = "%$search%"; }
if ($start_date) { $where[] = "d.delivery_date >= ?";  $params[] = $start_date; }
if ($end_date)   { $where[] = "d.delivery_date <= ?";  $params[] = $end_date; }
$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

// Total count for pagination
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries d $whereClause");
$countStmt->execute($params);
$total_records = (int)$countStmt->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $limit));
$page          = min($page, $total_pages);

$query = "SELECT d.*,
          (SELECT COUNT(*) FROM delivery_customers WHERE delivery_id = d.id) as customer_count,
          (SELECT GROUP_CONCAT(u.full_name SEPARATOR ', ') FROM delivery_employees de JOIN users u ON de.user_id = u.id WHERE de.delivery_id = d.id) as employee_names
          FROM deliveries d
          $whereClause
          ORDER BY d.id DESC LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$deliveries = $stmt->fetchAll();

// Build pagination query string helper
function pageUrl($p, $search, $start, $end) {
    $q = http_build_query(array_filter(['page' => $p, 'search' => $search, 'start_date' => $start, 'end_date' => $end]));
    return 'nwdelivery.php' . ($q ? "?$q" : '');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery System | Crystal POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.85)), url('../assests/bg.webp') no-repeat center center fixed;
            background-size: cover;
            color: white;
            min-height: 100vh;
        }
        .glass-header { background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(12px); border-bottom: 1px solid rgba(126, 34, 206, 0.2); }
        .glass-card { background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(10px); border: 1px solid rgba(126, 34, 206, 0.2); border-radius: 20px; }
        .container-modal { background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(20px); border: 2px solid rgba(126, 34, 206, 0.4); }
        .input-glass { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(126, 34, 206, 0.2); color: white; padding: 8px 12px; border-radius: 10px; outline: none; transition: all 0.3s; }
        .input-glass:focus { border-color: #a855f7; box-shadow: 0 0 15px rgba(168, 85, 247, 0.1); }
        
        /* Custom Scrollbar for Modal inside */
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.02); border-radius: 10px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: rgba(168, 85, 247, 0.4); border-radius: 10px; }
        .custom-scroll::-webkit-scrollbar-thumb:hover { background: rgba(168, 85, 247, 0.8); }

        /* Delivery Modal Layout */
        #add-modal .modal-inner {
            display: flex;
            flex-direction: column;
            max-height: 90vh;
            width: 100%;
        }
        #add-modal .modal-scroll-area {
            flex: 1 1 auto;
            overflow-y: auto;
            overflow-x: hidden;
            min-height: 0;
            max-height: calc(90vh - 130px); /* subtract header + footer height */
        }
    </style>
</head>
<body class="flex flex-col">

    <header class="glass-header sticky top-0 z-40 py-3">
        <div class="px-6 md:px-10 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="dashboard.php" class="text-white hover:text-purple-400 transition-colors">
                    <i class="fa-solid fa-arrow-left text-xl"></i>
                </a>
                <h1 class="text-xl sm:text-2xl font-bold tracking-tight uppercase">Deliveries</h1>
            </div>
            <button onclick="openModal()" class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white px-5 py-2.5 rounded-xl font-bold text-sm uppercase transition-all shadow-lg flex items-center space-x-2">
                <i class="fa-solid fa-truck-fast"></i>
                <span class="hidden sm:inline">Add New Delivery</span>
                <span class="sm:hidden">New Delivery</span>
            </button>
        </div>
    </header>

    <main class="w-full px-6 md:px-10 py-8">
        <!-- Filters Bar -->
        <div class="glass-card p-4 sm:p-6 mb-8">
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
                <div class="sm:col-span-2 lg:col-span-2 relative">
                    <label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Search Delivery ID</label>
                    <div class="relative">
                        <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="ID..." class="input-glass w-full pl-12 focus:ring-2 focus:ring-purple-500">
                    </div>
                </div>
                <div>
                    <label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Start Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="input-glass w-full" onchange="this.form.submit()">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">End Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="input-glass w-full" onchange="this.form.submit()">
                </div>
                <div class="flex space-x-2">
                    <?php if($search || $start_date || $end_date): ?>
                        <a href="nwdelivery.php" class="bg-rose-500/20 text-rose-400 p-2.5 px-4 rounded-xl hover:bg-rose-500/30 transition-all flex items-center h-[42px] w-full justify-center" title="Reset Filters">
                            <i class="fa-solid fa-rotate-left mr-2"></i>
                            <span class="text-xs font-bold uppercase tracking-wider">Reset</span>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Delivery List Table -->
        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left min-w-[800px] lg:min-w-0">
                    <thead>
                        <tr class="bg-purple-900 text-[12px] uppercase tracking-wider text-white border-b border-purple-700">
                            <th class="px-4 py-4 font-bold">Delivery ID</th>
                            <th class="px-4 py-4 font-bold">Date</th>
                            <th class="px-4 py-4 font-bold">Employees Assigned</th>
                            <th class="px-4 py-4 font-bold">Customers</th>
                            <th class="px-4 py-4 font-bold text-amber-400 whitespace-nowrap">Total Expenses</th>
                            <th class="px-4 py-4 font-bold text-emerald-400 whitespace-nowrap">Total Sales</th>
                            <th class="px-4 py-4 font-bold">Status</th>
                            <th class="px-4 py-4 text-center font-bold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-purple-900/30">
                        <?php if (empty($deliveries)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-10 text-center text-slate-500 italic">No delivery records found.</td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($deliveries as $d): ?>
                        <tr class="odd:bg-white/[0.01] even:bg-transparent hover:bg-purple-900/10 transition-colors">
                            <td class="px-4 py-4 text-sm font-bold text-purple-400 whitespace-nowrap">
                                <a href="../sale/del_details.php?id=<?php echo $d['id']; ?>" class="hover:underline">
                                    #DEL-<?php echo str_pad($d['id'], 4, '0', STR_PAD_LEFT); ?>
                                </a>
                            </td>
                            <td class="px-4 py-4 text-sm font-medium text-slate-300"><?php echo date('Y-m-d', strtotime($d['delivery_date'])); ?></td>
                            <td class="px-4 py-4 text-sm font-medium text-white max-w-[200px] truncate" title="<?php echo htmlspecialchars($d['employee_names'] ?? '-'); ?>">
                                <?php echo htmlspecialchars($d['employee_names'] ?? '-'); ?>
                            </td>
                            <td class="px-4 py-4 text-sm font-bold text-cyan-400"><?php echo $d['customer_count']; ?></td>
                            <td class="px-4 py-4 text-sm font-bold text-amber-500">Rs. <?php echo number_format($d['total_expenses'], 2); ?></td>
                            <td class="px-4 py-4 text-sm font-bold text-emerald-400">Rs. <?php echo number_format($d['total_sales'], 2); ?></td>
                            <td class="px-4 py-4 text-sm">
                                <?php if ($d['status'] === 'completed'): ?>
                                    <span class="px-2 py-1 bg-emerald-500/20 text-emerald-400 rounded-lg text-[10px] font-bold uppercase tracking-wider">Completed</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 bg-amber-500/20 text-amber-400 rounded-lg text-[10px] font-bold uppercase tracking-wider">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4 text-center">
                                <div class="flex items-center justify-center gap-1.5">
                                    <?php if ($d['status'] !== 'completed'): ?>
                                    <button onclick="markAsCompleted(<?php echo $d['id']; ?>)" class="text-slate-400 hover:text-emerald-400 transition-colors p-1.5 rounded-lg hover:bg-emerald-400/10" title="Mark as Completed">
                                        <i class="fa-solid fa-check-circle text-sm"></i>
                                    </button>
                                    <?php endif; ?>
                                    <a href="../sale/del_details.php?id=<?php echo $d['id']; ?>" class="text-slate-400 hover:text-cyan-400 transition-colors p-1.5 rounded-lg hover:bg-cyan-400/10" title="Operational Details">
                                        <i class="fa-solid fa-file-invoice text-sm"></i>
                                    </a>
                                    <button onclick="confirmDelete(<?php echo $d['id']; ?>)" class="text-slate-400 hover:text-rose-400 transition-colors p-1.5 rounded-lg hover:bg-rose-400/10" title="Delete Delivery">
                                        <i class="fa-solid fa-trash text-sm"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <!-- Pagination -->
            <div class="flex items-center justify-between px-4 py-3 border-t border-purple-900/30">
                <p class="text-[11px] text-slate-500">
                    Showing <span class="text-slate-300 font-bold"><?php echo $offset + 1; ?></span>–<span class="text-slate-300 font-bold"><?php echo min($offset + $limit, $total_records); ?></span> of <span class="text-slate-300 font-bold"><?php echo $total_records; ?></span> deliveries
                </p>
                <div class="flex items-center gap-1">
                    <!-- Prev -->
                    <?php if ($page > 1): ?>
                    <a href="<?php echo pageUrl($page - 1, $search, $start_date, $end_date); ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-white hover:bg-purple-500/20 border border-purple-900/40 transition-all text-xs">
                        <i class="fa-solid fa-chevron-left"></i>
                    </a>
                    <?php else: ?>
                    <span class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-700 border border-purple-900/20 text-xs cursor-not-allowed">
                        <i class="fa-solid fa-chevron-left"></i>
                    </span>
                    <?php endif; ?>

                    <!-- Page Numbers -->
                    <?php
                    $start_p = max(1, $page - 2);
                    $end_p   = min($total_pages, $page + 2);
                    if ($start_p > 1): ?>
                        <a href="<?php echo pageUrl(1, $search, $start_date, $end_date); ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-[11px] font-bold text-slate-400 hover:text-white hover:bg-purple-500/20 border border-purple-900/40 transition-all">1</a>
                        <?php if ($start_p > 2): ?><span class="text-slate-600 px-1 text-xs">…</span><?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start_p; $i <= $end_p; $i++): ?>
                    <a href="<?php echo pageUrl($i, $search, $start_date, $end_date); ?>"
                       class="w-8 h-8 flex items-center justify-center rounded-lg text-[11px] font-bold transition-all border
                              <?php echo $i === $page ? 'bg-purple-600 border-purple-500 text-white shadow-lg shadow-purple-900/40' : 'text-slate-400 hover:text-white hover:bg-purple-500/20 border-purple-900/40'; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>

                    <?php if ($end_p < $total_pages): ?>
                        <?php if ($end_p < $total_pages - 1): ?><span class="text-slate-600 px-1 text-xs">…</span><?php endif; ?>
                        <a href="<?php echo pageUrl($total_pages, $search, $start_date, $end_date); ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-[11px] font-bold text-slate-400 hover:text-white hover:bg-purple-500/20 border border-purple-900/40 transition-all"><?php echo $total_pages; ?></a>
                    <?php endif; ?>

                    <!-- Next -->
                    <?php if ($page < $total_pages): ?>
                    <a href="<?php echo pageUrl($page + 1, $search, $start_date, $end_date); ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-white hover:bg-purple-500/20 border border-purple-900/40 transition-all text-xs">
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                    <?php else: ?>
                    <span class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-700 border border-purple-900/20 text-xs cursor-not-allowed">
                        <i class="fa-solid fa-chevron-right"></i>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- Master Add Delivery Modal -->
    <div id="add-modal" class="fixed inset-0 bg-black/80 backdrop-blur-md z-50 flex items-center justify-center p-2 sm:p-4 hidden">
        <div class="container-modal modal-inner w-full max-w-6xl rounded-[20px] sm:rounded-[30px] shadow-2xl relative">
            
            <!-- Modal Header -->
            <div class="p-4 sm:p-5 border-b border-purple-500/20 flex items-center gap-4 bg-white/[0.02] rounded-t-[20px] sm:rounded-t-[28px] flex-shrink-0">
                <div class="flex items-center space-x-3 min-w-0">
                    <div class="w-9 h-9 shrink-0 bg-gradient-to-tr from-purple-500 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
                        <i class="fa-solid fa-truck-fast text-sm text-white"></i>
                    </div>
                    <h2 class="text-base sm:text-lg font-bold text-white truncate">Create Delivery Route</h2>
                </div>

                <!-- Live Totals in Header -->
                <div class="flex items-center gap-3 ml-auto shrink-0">
                    <div class="hidden sm:flex flex-col items-end bg-amber-500/10 border border-amber-500/20 px-3 py-1.5 rounded-xl">
                        <span class="text-[8px] uppercase font-bold text-amber-500 tracking-widest">Expenses</span>
                        <span id="hdr_total_expense" class="text-sm font-black text-amber-400">Rs. 0.00</span>
                    </div>
                    <div class="hidden sm:flex flex-col items-end bg-emerald-500/10 border border-emerald-500/20 px-3 py-1.5 rounded-xl">
                        <span class="text-[8px] uppercase font-bold text-emerald-500 tracking-widest">Exp. Sales</span>
                        <span id="hdr_total_sales" class="text-sm font-black text-emerald-400">Rs. 0.00</span>
                    </div>
                    <button onclick="closeModal()" class="text-slate-400 hover:text-white transition-colors bg-white/5 p-2 rounded-xl hover:bg-rose-500/20 hover:text-rose-400">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
            </div>

            <div class="modal-scroll-area custom-scroll p-4 sm:p-5">
                <form id="delivery-form" class="space-y-5 max-w-5xl mx-auto text-sm">
                    
                    <!-- Date Selection -->
                    <div class="flex justify-between items-center pb-3 border-b border-white/5">
                        <p class="text-[10px] uppercase tracking-widest font-bold text-slate-500">Delivery Details</p>
                        <div class="flex items-center space-x-2 bg-white/5 px-3 py-1.5 rounded-xl border border-purple-500/20">
                            <i class="fa-solid fa-calendar-day text-purple-400 text-xs"></i>
                            <input type="date" id="delivery_date" class="bg-transparent border-none text-white font-bold text-xs outline-none focus:ring-0" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <!-- Step 1: Employee Assignment -->
                    <div class="relative overflow-visible">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-[10px] uppercase tracking-widest font-bold text-purple-400"><i class="fa-solid fa-users mr-1"></i> Assigned Employees</p>
                        </div>
                        
                        <div class="relative w-full max-w-xs mb-3 z-20">
                            <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i>
                            <input type="text" id="emp_search" placeholder="Search by name..." class="input-glass w-full pl-9 text-xs" autocomplete="off" oninput="searchEmployee(this.value)">
                            <div id="emp_results" class="absolute w-full mt-1 bg-slate-900 border border-purple-500/30 rounded-xl shadow-2xl overflow-hidden hidden z-30">
                            </div>
                        </div>

                        <div id="selected_employees_container" class="flex flex-wrap gap-2">
                        </div>
                    </div>

                    <div class="border-t border-white/5"></div>

                    <!-- Step 2: Extra Expenses -->
                    <div>
                        <div class="flex flex-wrap items-center gap-2 mb-2">
                            <p class="text-[10px] uppercase tracking-widest font-bold text-amber-400"><i class="fa-solid fa-wallet mr-1"></i> Trip Expenses Given</p>
                            <div class="flex flex-wrap gap-1.5 ml-auto">
                                <button type="button" onclick="addExpenseRow('Fuel')" class="text-[9px] font-bold uppercase bg-amber-500/10 text-amber-400 border border-amber-500/20 px-2 py-1 rounded-lg hover:bg-amber-500/20 transition-all">+ Fuel</button>
                                <button type="button" onclick="addExpenseRow('Meals')" class="text-[9px] font-bold uppercase bg-amber-500/10 text-amber-400 border border-amber-500/20 px-2 py-1 rounded-lg hover:bg-amber-500/20 transition-all">+ Meals</button>
                                <button type="button" onclick="addExpenseRow('Accommodation')" class="text-[9px] font-bold uppercase bg-amber-500/10 text-amber-400 border border-amber-500/20 px-2 py-1 rounded-lg hover:bg-amber-500/20 transition-all">+ Lodge</button>
                                <button type="button" onclick="addExpenseRow('')" class="text-[9px] font-bold uppercase bg-white/5 text-slate-300 border border-white/10 px-2 py-1 rounded-lg hover:bg-white/10 transition-all">+ Other</button>
                            </div>
                        </div>

                        <div id="expense_rows" class="space-y-2">
                        </div>
                        <div class="mt-2 flex justify-end items-center space-x-2 text-amber-400">
                            <span class="text-[10px] uppercase font-bold tracking-widest">Total Expenses:</span>
                            <span id="trip_total_expense" class="text-base font-black">Rs. 0.00</span>
                        </div>
                    </div>

                    <div class="border-t border-white/5"></div>

                    <!-- Step 3: Customers & Invoices -->
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-[10px] uppercase tracking-widest font-bold text-cyan-400"><i class="fa-solid fa-receipt mr-1"></i> Customer Invoices</p>
                            <button type="button" onclick="addCustomerBlock()" class="bg-cyan-600/80 hover:bg-cyan-500 text-white px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase flex items-center space-x-1.5 transition-all">
                                <i class="fa-solid fa-user-plus text-[9px]"></i>
                                <span>New Customer Order </span>
                            </button>
                        </div>

                        <div id="customer_blocks_container" class="space-y-4">
                        </div>
                        
                        <div class="mt-4 pt-3 border-t border-white/10 flex justify-end items-center space-x-2 text-cyan-400">
                            <span class="text-[10px] uppercase font-bold tracking-widest">Total Expected Sales:</span>
                            <span id="trip_total_sales" class="text-lg font-black">Rs. 0.00</span>
                        </div>
                    </div>

                </form>
            </div>
            
            <!-- Sticky Footer for Save -->
            <div class="p-4 sm:p-6 border-t border-purple-500/20 bg-white/[0.02] rounded-b-[20px] sm:rounded-b-[28px] flex-shrink-0 flex justify-end space-x-4">
                <button type="button" onclick="closeModal()" class="px-6 py-3 rounded-xl border border-white/10 text-slate-300 font-bold hover:bg-white/5 transition-all">Cancel</button>
                <button type="button" onclick="saveDelivery()" class="px-6 sm:px-10 py-3 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-400 hover:to-teal-500 text-white font-black shadow-lg shadow-emerald-900/50 uppercase tracking-widest transition-all active:scale-95 flex items-center space-x-2">
                    <i class="fa-solid fa-check-circle"></i>
                    <span>Confirm &amp; Process Delivery</span>
                </button>
            </div>

        </div>
    </div>

    <!-- Mini Modal for Create Employee / Customer -->
    <div id="mini-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[60] flex items-center justify-center p-4 hidden">
        <div class="glass-card w-full max-w-md p-6 border border-purple-400/30 shadow-2xl">
            <h3 id="mini-modal-title" class="text-xl font-bold mb-4">Create New</h3>
            <div id="mini-modal-content" class="space-y-4">
                <!-- Dynamically populated -->
            </div>
            <div class="mt-6 flex justify-end space-x-3">
                <button onclick="document.getElementById('mini-modal').classList.add('hidden')" class="px-4 py-2 text-sm text-slate-400 hover:text-white font-bold">Cancel</button>
                <button id="mini-modal-save" class="px-5 py-2 bg-purple-600 text-white rounded-lg text-sm font-bold shadow-lg">Save</button>
            </div>
        </div>
    </div>

    <!-- Invoice View Modal -->
    <div id="invoice-modal" class="fixed inset-0 bg-black/80 backdrop-blur-md z-[70] flex items-center justify-center p-4 hidden">
        <div class="bg-[#0f172a] border border-slate-700 rounded-2xl w-full max-w-3xl max-h-[90vh] flex flex-col shadow-2xl">
            <!-- Invoice Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-700 flex-shrink-0">
                <h2 class="text-sm font-bold text-white tracking-widest uppercase">Delivery Invoice</h2>
                <button onclick="document.getElementById('invoice-modal').classList.add('hidden')" class="text-slate-500 hover:text-white p-1.5 rounded-lg hover:bg-white/5">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>
            <!-- Invoice Body -->
            <div id="invoice-body" class="overflow-y-auto p-6 text-sm space-y-5 flex-1">
                <p class="text-slate-500 text-center py-8">Loading...</p>
            </div>
            <!-- Invoice Footer -->
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-700 flex-shrink-0">
                <button onclick="window.print()" class="text-[11px] font-bold uppercase tracking-widest text-slate-400 hover:text-white px-4 py-2 border border-slate-700 rounded-lg hover:bg-white/5 transition-all flex items-center gap-2">
                    <i class="fa-solid fa-print"></i> Print
                </button>
                <button onclick="document.getElementById('invoice-modal').classList.add('hidden')" class="text-[11px] font-bold uppercase tracking-widest text-slate-400 hover:text-white px-4 py-2 border border-slate-700 rounded-lg hover:bg-white/5 transition-all">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Confirm Modal -->
    <div id="delete-modal" class="fixed inset-0 bg-black/80 backdrop-blur-md z-[70] flex items-center justify-center p-4 hidden">
        <div class="bg-[#0f172a] border border-rose-500/30 rounded-2xl w-full max-w-sm p-6 shadow-2xl">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 bg-rose-500/20 rounded-xl flex items-center justify-center">
                    <i class="fa-solid fa-triangle-exclamation text-rose-400"></i>
                </div>
                <div>
                    <p class="text-sm font-bold text-white">Delete Delivery</p>
                    <p class="text-[11px] text-slate-400">This action cannot be undone.</p>
                </div>
            </div>
            <p class="text-xs text-slate-400 mb-5 leading-relaxed">Deleting this delivery will <strong class="text-white">permanently remove</strong> all associated customer invoices, expenses, and will <strong class="text-white">restore the stock quantities</strong> back to the containers.</p>
            <div class="flex justify-end gap-3">
                <button onclick="document.getElementById('delete-modal').classList.add('hidden')" class="px-4 py-2 text-xs font-bold text-slate-400 hover:text-white border border-slate-700 rounded-lg hover:bg-white/5 transition-all">Cancel</button>
                <button id="delete-confirm-btn" class="px-5 py-2 text-xs font-bold text-white bg-rose-600 hover:bg-rose-500 rounded-lg transition-all flex items-center gap-2">
                    <i class="fa-solid fa-trash"></i> Yes, Delete
                </button>
            </div>
        </div>
    </div>

    <!-- Main Logic Script -->
    <script>
        // Global State
        let selectedEmployees = [];
        let customerBlocks = [];
        let blockCounter = 0;
        let itemCounter = 0;

        // Base Setup
        const modal = document.getElementById('add-modal');
        function openModal() {
            // Reset state
            selectedEmployees = [];
            customerBlocks = [];
            document.getElementById('selected_employees_container').innerHTML = '';
            document.getElementById('expense_rows').innerHTML = '';
            document.getElementById('customer_blocks_container').innerHTML = '';
            document.getElementById('emp_search').value = '';
            document.getElementById('trip_total_expense').innerText = 'Rs. 0.00';
            document.getElementById('trip_total_sales').innerText = 'Rs. 0.00';
            
            addCustomerBlock(); // start with one customer block
            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }

        function closeModal() {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }

        // ==========================================
        // 1. Employee Logic
        // ==========================================
        function searchEmployee(term) {
            const resDiv = document.getElementById('emp_results');
            if(term.length < 2) { resDiv.classList.add('hidden'); return; }

            fetch(`?action=search_employee&term=${term}`)
            .then(r => r.json())
            .then(data => {
                let html = '';
                if(data.length === 0) {
                    html = `<div class="p-3 text-center">
                                <p class="text-xs text-slate-400 mb-2">No employee found</p>
                                <button type="button" onclick="openCreateEmployee('${term}')" class="text-purple-400 hover:text-purple-300 font-bold text-xs underline">Create New Employee</button>
                            </div>`;
                } else {
                    data.forEach(emp => {
                        const img = emp.profile_pic ? `../${emp.profile_pic}` : `https://ui-avatars.com/api/?name=${emp.full_name}&background=7e22ce&color=fff`;
                        html += `
                            <div class="flex items-center space-x-3 p-3 hover:bg-white/10 cursor-pointer border-b border-white/5 last:border-0" onclick="addEmployee(${emp.id}, '${emp.full_name}', '${img}')">
                                <img src="${img}" class="w-8 h-8 rounded-full object-cover">
                                <span class="font-bold text-sm text-white">${emp.full_name}</span>
                            </div>
                        `;
                    });
                }
                resDiv.innerHTML = html;
                resDiv.classList.remove('hidden');
            });
        }

        // Hide employee results when clicking outside
        document.addEventListener('click', (e) => {
            if(!e.target.closest('#emp_search') && !e.target.closest('#emp_results')) {
                document.getElementById('emp_results').classList.add('hidden');
            }
        });

        function addEmployee(id, name, img) {
            if(selectedEmployees.includes(id)) return; // prevent dupes
            selectedEmployees.push(id);
            
            const html = `
                <div id="sel_emp_${id}" class="flex items-center space-x-2 bg-purple-500/10 border border-purple-500/30 p-2 pr-3 rounded-full animate-[fadeIn_0.3s_ease-out]">
                    <img src="${img}" class="w-6 h-6 rounded-full object-cover shadow-sm">
                    <span class="text-xs font-bold text-white">${name}</span>
                    <button type="button" onclick="removeEmployee(${id})" class="ml-2 text-rose-400 hover:text-rose-300 w-4 h-4 flex items-center justify-center rounded-full hover:bg-rose-500/20 transition-colors">
                        <i class="fa-solid fa-times text-[10px]"></i>
                    </button>
                </div>
            `;
            document.getElementById('selected_employees_container').insertAdjacentHTML('beforeend', html);
            document.getElementById('emp_search').value = '';
            document.getElementById('emp_results').classList.add('hidden');
        }

        function removeEmployee(id) {
            selectedEmployees = selectedEmployees.filter(e => e !== id);
            document.getElementById(`sel_emp_${id}`).remove();
        }

        function openCreateEmployee(nameHint) {
            document.getElementById('emp_results').classList.add('hidden');
            const mm = document.getElementById('mini-modal');
            document.getElementById('mini-modal-title').innerText = "Create Employee";
            
            const content = `
                <input type="text" id="new_emp_name" class="input-glass w-full" placeholder="Full Name" value="${nameHint}">
                <input type="text" id="new_emp_user" class="input-glass w-full" placeholder="Username (e.g. jdoe)">
                <input type="text" id="new_emp_cnt" class="input-glass w-full" placeholder="Contact Number">
                <p class="text-[10px] text-slate-400 italic">Default password will be '123456'. They can change it later.</p>
            `;
            document.getElementById('mini-modal-content').innerHTML = content;
            
            const saveBtn = document.getElementById('mini-modal-save');
            saveBtn.onclick = () => {
                const fd = new FormData();
                fd.append('action', 'create_employee');
                fd.append('name', document.getElementById('new_emp_name').value);
                fd.append('username', document.getElementById('new_emp_user').value);
                fd.append('contact', document.getElementById('new_emp_cnt').value);
                
                fetch('nwdelivery.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.success) {
                        addEmployee(res.id, res.name, `https://ui-avatars.com/api/?name=${res.name}&background=7e22ce&color=fff`);
                        mm.classList.add('hidden');
                    } else alert(res.message);
                });
            };
            mm.classList.remove('hidden');
        }

        // ==========================================
        // 2. Expenses Logic
        // ==========================================
        function addExpenseRow(name = '') {
            const rowId = 'exp_' + Date.now();
            const html = `
                <div id="${rowId}" class="flex items-center space-x-3 bg-white/5 p-2 rounded-xl border border-white/5 relative group">
                    <input type="text" class="exp-name input-glass flex-1 bg-transparent border-none focus:bg-white/5" placeholder="Expense description" value="${name}" required>
                    <div class="relative w-1/3">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold">Rs.</span>
                        <input type="number" step="0.01" class="exp-amount input-glass w-full pl-8 text-right bg-transparent border-none focus:bg-white/5" placeholder="0.00" required oninput="calcTotals()">
                    </div>
                    <button type="button" onclick="document.getElementById('${rowId}').remove(); calcTotals();" class="w-8 h-8 rounded-lg text-rose-500 hover:bg-rose-500/20 flex items-center justify-center transition-all opacity-100 sm:opacity-0 sm:group-hover:opacity-100">
                        <i class="fa-solid fa-trash-can text-sm"></i>
                    </button>
                </div>
            `;
            document.getElementById('expense_rows').insertAdjacentHTML('beforeend', html);
        }

        // ==========================================
        // 3. Customers & Items Logic
        // ==========================================
        function addCustomerBlock() {
            blockCounter++;
            const bId = `cb_${blockCounter}`;
            customerBlocks.push(blockCounter);

            const html = `
                <div id="${bId}" class="border border-cyan-500/20 rounded-xl overflow-visible relative">
                    <!-- Customer Header -->
                    <div class="flex flex-wrap items-center gap-2 px-3 py-2 bg-cyan-900/10 border-b border-cyan-500/15 rounded-t-xl">
                        <span class="text-[9px] font-black uppercase text-cyan-500 tracking-widest shrink-0">#${blockCounter}</span>
                        <div class="relative flex-1 min-w-[160px] z-30">
                            <input type="hidden" class="cust_id_val">
                            <i class="fa-solid fa-user absolute left-3 top-1/2 -translate-y-1/2 text-cyan-500 text-[10px]"></i>
                            <input type="text" class="cust-search input-glass w-full pl-8 text-xs py-1.5 border-cyan-500/30 focus:border-cyan-400" placeholder="Search Customer..." autocomplete="off" oninput="searchCustomer(this, ${blockCounter})">
                            <div class="cust-results absolute w-full mt-1 bg-slate-800 border border-cyan-500/30 rounded-xl shadow-2xl overflow-hidden hidden z-40"></div>
                        </div>
                        <span class="text-[9px] uppercase font-bold text-slate-500 bg-black/20 px-2 py-1 rounded cust-display-name">No customer selected</span>
                        ${blockCounter > 1 ? `<button type="button" onclick="removeCustBlock(${blockCounter})" class="ml-auto text-rose-400 hover:text-rose-300 text-xs"><i class="fa-solid fa-times"></i></button>` : ''}
                    </div>

                    <!-- Items Section -->
                    <div class="p-3">
                        <div class="flex items-center justify-between mb-2">
                            <div class="hidden lg:grid flex-1 grid-cols-12 gap-1.5 pr-2 text-[8px] uppercase font-bold text-slate-600 tracking-widest">
                                <div class="col-span-3">Brand / Container</div>
                                <div class="col-span-2 text-center">Avail.</div>
                                <div class="col-span-2 text-center text-purple-600">CostPer Item</div>
                                <div class="col-span-1 text-center">Qty</div>
                                <div class="col-span-2 text-right">Selling Price</div>
                                <div class="col-span-2 text-right text-emerald-600">Total</div>
                            </div>
                            <button type="button" onclick="addItemToCustomer(${blockCounter})" class="text-[9px] uppercase font-bold text-cyan-400 bg-cyan-500/10 px-2 py-1 rounded border border-cyan-500/20 hover:bg-cyan-500/20 transition-all shrink-0">+ Item</button>
                        </div>

                        <div class="items-container space-y-2">
                        </div>
                        
                        <div class="mt-2 flex justify-end items-center pr-1">
                            <span class="text-[9px] uppercase font-bold text-slate-500 mr-2">Subtotal:</span>
                            <span class="cust-subtotal text-sm font-bold text-cyan-400">Rs. 0.00</span>
                        </div>
                    </div>
                </div>
            `;
            document.getElementById('customer_blocks_container').insertAdjacentHTML('beforeend', html);
            addItemToCustomer(blockCounter);
        }

        function removeCustBlock(id) {
            customerBlocks = customerBlocks.filter(b => b !== id);
            document.getElementById(`cb_${id}`).remove();
            calcTotals();
        }

        function searchCustomer(input, blockId) {
            const term = input.value;
            const block = document.getElementById(`cb_${blockId}`);
            const resDiv = block.querySelector('.cust-results');
            
            // clear val if typing
            block.querySelector('.cust_id_val').value = '';
            block.querySelector('.cust-display-name').innerText = "NO CUSTOMER SELECTED";
            block.querySelector('.cust-display-name').classList.remove('bg-cyan-500/20', 'text-cyan-400');

            if(term.length < 2) { resDiv.classList.add('hidden'); return; }

            fetch(`?action=search_customer&term=${term}`)
            .then(r => r.json())
            .then(data => {
                let html = '';
                if(data.length === 0) {
                    html = `<div class="p-3 text-center">
                                <button type="button" onclick="openCreateCustomer(${blockId}, '${term}')" class="text-cyan-400 hover:text-cyan-300 font-bold text-xs underline">Create New Customer</button>
                            </div>`;
                } else {
                    data.forEach(c => {
                        html += `
                            <div class="p-3 hover:bg-white/10 cursor-pointer border-b border-white/5 last:border-0" onclick="selectCustomer(${blockId}, ${c.id}, '${c.name}', '${c.contact_number}')">
                                <p class="text-sm font-bold text-white">${c.name}</p>
                                <p class="text-[10px] text-slate-400">${c.contact_number} ${c.address ? '- '+c.address : ''}</p>
                            </div>
                        `;
                    });
                }
                resDiv.innerHTML = html;
                resDiv.classList.remove('hidden');
            });
        }

        function selectCustomer(blockId, id, name, contact) {
            const block = document.getElementById(`cb_${blockId}`);
            block.querySelector('.cust_id_val').value = id;
            block.querySelector('.cust-search').value = name;
            const disp = block.querySelector('.cust-display-name');
            disp.innerText = `${name} (${contact})`;
            disp.classList.add('bg-cyan-500/20', 'text-cyan-400', 'border', 'border-cyan-500/40');
            block.querySelector('.cust-results').classList.add('hidden');
        }

        function openCreateCustomer(blockId, nameHint) {
            document.getElementById(`cb_${blockId}`).querySelector('.cust-results').classList.add('hidden');
            const mm = document.getElementById('mini-modal');
            document.getElementById('mini-modal-title').innerText = "Create Customer";
            
            const content = `
                <input type="text" id="new_cust_name" class="input-glass w-full" placeholder="Customer Name" value="${nameHint}">
                <input type="text" id="new_cust_cnt" class="input-glass w-full" placeholder="Contact Number (Required)">
                <textarea id="new_cust_addr" class="input-glass w-full" placeholder="Address (Optional)"></textarea>
            `;
            document.getElementById('mini-modal-content').innerHTML = content;
            
            const saveBtn = document.getElementById('mini-modal-save');
            saveBtn.onclick = () => {
                const cnt = document.getElementById('new_cust_cnt').value;
                if(!cnt) { alert("Contact number required"); return; }
                const fd = new FormData();
                fd.append('action', 'create_customer');
                fd.append('name', document.getElementById('new_cust_name').value);
                fd.append('contact', cnt);
                fd.append('address', document.getElementById('new_cust_addr').value);
                
                fetch('nwdelivery.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.success) {
                        selectCustomer(blockId, res.id, res.name, res.contact);
                        mm.classList.add('hidden');
                    } else alert(res.message);
                });
            };
            mm.classList.remove('hidden');
        }

        // ==========================================
        // 4. Container Items Logic
        // ==========================================
        function addItemToCustomer(blockId) {
            itemCounter++;
            const iId = `item_${itemCounter}`;
            
            const html = `
                <div id="${iId}" class="item-row grid grid-cols-1 lg:grid-cols-12 gap-1.5 items-center bg-white/5 p-2 rounded-lg border border-white/5 relative group z-20">
                    
                    <input type="hidden" class="item_id_val">
                    <input type="hidden" class="item_cost_val" value="0">
                    <input type="hidden" class="item_max_qty" value="0">
                    
                    <!-- Brand Search (col-span-3) -->
                    <div class="col-span-1 lg:col-span-3 relative flex flex-col w-full">
                        <label class="text-[9px] uppercase font-bold text-slate-500 mb-0.5 lg:hidden block">Brand</label>
                        <input type="text" class="item-search input-glass w-full text-sm font-semibold" placeholder="Brand..." oninput="searchBrandStock(this, '${iId}')" autocomplete="off">
                        <div class="item-results absolute w-full top-full mt-1 bg-slate-900 border border-slate-600 rounded-xl shadow-2xl overflow-hidden hidden" style="z-index: 9999;"></div>
                    </div>

                    <!-- Available (col-span-2) -->
                    <div class="col-span-1 lg:col-span-2 flex justify-between lg:justify-center items-center">
                        <label class="text-[9px] uppercase font-bold text-slate-500 lg:hidden block">Available:</label>
                        <span class="disp-avail text-sm font-bold bg-white/5 px-2 py-0.5 rounded text-slate-400">--</span>
                    </div>

                    <!-- Cost Per Item (col-span-2) -->
                    <div class="col-span-1 lg:col-span-2 flex justify-between lg:justify-center items-center">
                        <label class="text-[9px] uppercase font-bold text-slate-500 lg:hidden block">Cost per Item:</label>
                        <span class="disp-cost text-sm font-bold text-purple-400">--</span>
                    </div>

                    <!-- Qty (col-span-1) -->
                    <div class="col-span-1 lg:col-span-1 flex flex-col justify-center">
                        <label class="text-[9px] uppercase font-bold text-slate-500 mb-0.5 lg:hidden block">Qty</label>
                        <input type="number" class="item-qty input-glass text-center text-sm font-bold w-full" placeholder="0" oninput="validateQty('${iId}'); calcTotals()" disabled>
                    </div>

                    <!-- Selling Price (col-span-2) -->
                    <div class="col-span-1 lg:col-span-2 flex flex-col justify-center">
                        <label class="text-[9px] uppercase font-bold text-slate-500 mb-0.5 lg:hidden block">Selling Price</label>
                        <input type="number" step="0.01" class="item-sell input-glass text-right text-amber-400 text-sm font-bold" placeholder="0.00" oninput="calcTotals()" disabled>
                        <p class="disp-profit text-[8px] text-emerald-400 font-bold text-right mt-0.5 opacity-0 transition-opacity leading-none">0%</p>
                    </div>

                    <!-- Total (col-span-2) -->
                    <div class="col-span-1 lg:col-span-2 flex justify-between lg:justify-end items-center pr-1">
                        <label class="text-[9px] uppercase font-bold text-slate-500 lg:hidden block">Total:</label>
                        <span class="item-total text-sm font-black text-emerald-400">Rs. 0.00</span>
                    </div>

                    <button type="button" onclick="document.getElementById('${iId}').remove(); calcTotals();" class="absolute -right-2 -top-2 w-5 h-5 bg-red-500 text-white rounded-full text-[9px] shadow-lg flex items-center justify-center opacity-100 lg:opacity-0 group-hover:opacity-100 transition-opacity z-10"><i class="fa-solid fa-times"></i></button>
                </div>
            `;
            document.getElementById(`cb_${blockId}`).querySelector('.items-container').insertAdjacentHTML('beforeend', html);
        }

        function searchBrandStock(input, iId) {
            const row = document.getElementById(iId);
            const resDiv = row.querySelector('.item-results');
            const term = input.value;
            
            // clear selections
            row.querySelector('.item_id_val').value = '';
            row.querySelector('.item_cost_val').value = '0';
            row.querySelector('.item_max_qty').value = '0';
            row.querySelector('.disp-avail').innerText = '--';
            row.querySelector('.item-qty').disabled = true;
            row.querySelector('.item-sell').disabled = true;

            if(term.length < 2) { resDiv.classList.add('hidden'); return; }

            fetch(`?action=search_brand_stock&term=${term}`)
            .then(r => r.json())
            .then(data => {
                let html = '';
                if(data.length === 0) {
                    html = `<div class="p-2 text-xs text-slate-400 text-center">No stock available</div>`;
                } else {
                    data.forEach(d => {
                        // Data encoded cleanly to avoid quote issues
                        const cData = encodeURIComponent(JSON.stringify(d));
                        html += `
                            <div class="p-3 border-b border-white/5 hover:bg-white/10 cursor-pointer transition-colors" onclick="selectContainerItem('${iId}', '${cData}')">
                                <div class="flex justify-between items-center mb-1">
                                    <span class="font-bold text-sm text-cyan-400">${d.brand_name}</span>
                                    <span class="text-[10px] bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded font-bold">${d.available_qty} Avail</span>
                                </div>
                                <div class="flex justify-between items-center text-[10px] text-slate-400">
                                    <span>${d.container_number} (${d.country || '-'})</span>
                                    <span>Cost per item: Rs. ${parseFloat(d.per_item_cost).toFixed(2)}</span>
                                </div>
                            </div>
                        `;
                    });
                }
                resDiv.innerHTML = html;
                resDiv.classList.remove('hidden');
            });
        }

        function selectContainerItem(iId, dataEncoded) {
            const d = JSON.parse(decodeURIComponent(dataEncoded));
            const row = document.getElementById(iId);
            
            row.querySelector('.item_id_val').value = d.item_id;
            row.querySelector('.item_cost_val').value = d.per_item_cost;
            row.querySelector('.item_max_qty').value = d.available_qty;
            
            row.querySelector('.item-search').value = `${d.brand_name} [${d.container_number}]`;
            row.querySelector('.disp-avail').innerText = d.available_qty;
            row.querySelector('.disp-avail').classList.remove('text-slate-400');
            row.querySelector('.disp-avail').classList.add('text-emerald-400');
            row.querySelector('.disp-cost').innerText = 'Rs. ' + parseFloat(d.per_item_cost).toFixed(2);
            
            const qtyInput = row.querySelector('.item-qty');
            const sellInput = row.querySelector('.item-sell');
            qtyInput.disabled = false;
            sellInput.disabled = false;
            
            // Default selling price: cost + 20% margin
            sellInput.value = (parseFloat(d.per_item_cost) * 1.2).toFixed(2);
            qtyInput.value = 1;

            row.querySelector('.item-results').classList.add('hidden');
            calcTotals(); // Update lines
            qtyInput.focus();
        }

        // Global click to hide item results
        document.addEventListener('click', (e) => {
            if(!e.target.closest('.item-search') && !e.target.closest('.item-results')) {
                document.querySelectorAll('.item-results').forEach(el => el.classList.add('hidden'));
            }
        });

        function validateQty(iId) {
            const row = document.getElementById(iId);
            const input = row.querySelector('.item-qty');
            const max = parseInt(row.querySelector('.item_max_qty').value);
            let val = parseInt(input.value);
            if(isNaN(val)) val = 0;
            if(val < 0) val = 0;
            if(val > max) {
                alert(`Cannot exceed available stock (${max})`);
                val = max;
                input.value = max;
            }
        }

        // ==========================================
        // 5. Calculations & Saving
        // ==========================================
        function calcTotals() {
            let totalExpenses = 0;
            document.querySelectorAll('#expense_rows .exp-amount').forEach(el => {
                totalExpenses += parseFloat(el.value || 0);
            });
            const expFmt = 'Rs. ' + totalExpenses.toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('trip_total_expense').innerText = expFmt;
            document.getElementById('hdr_total_expense').innerText = expFmt;

            let grandSales = 0;
            customerBlocks.forEach(bId => {
                const block = document.getElementById(`cb_${bId}`);
                if(!block) return;
                
                let subtotal = 0;
                block.querySelectorAll('.item-row').forEach(row => {
                    const qty = parseInt(row.querySelector('.item-qty').value || 0);
                    const sell = parseFloat(row.querySelector('.item-sell').value || 0);
                    const cost = parseFloat(row.querySelector('.item_cost_val').value || 0);
                    
                    const lineTotal = qty * sell;
                    subtotal += lineTotal;
                    row.querySelector('.item-total').innerText = 'Rs. ' + lineTotal.toLocaleString(undefined, {minimumFractionDigits: 2});

                    // Profit calculation display
                    if(sell > 0 && cost > 0 && qty > 0) {
                        const profit = ((sell - cost) / cost) * 100;
                        const pText = row.querySelector('.disp-profit');
                        pText.innerText = `${profit.toFixed(1)}%`;
                        pText.classList.remove('opacity-0');
                        pText.classList.remove('text-rose-400', 'text-emerald-400');
                        pText.classList.add(profit >= 0 ? 'text-emerald-400' : 'text-rose-400');
                    }
                });
                
                block.querySelector('.cust-subtotal').innerText = 'Rs. ' + subtotal.toLocaleString(undefined, {minimumFractionDigits: 2});
                grandSales += subtotal;
            });

            const salesFmt = 'Rs. ' + grandSales.toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('trip_total_sales').innerText = salesFmt;
            document.getElementById('hdr_total_sales').innerText = salesFmt;
        }

        function saveDelivery() {
            // Validate Employees
            if(selectedEmployees.length === 0) { alert('Please assign at least one employee.'); return; }

            // Gather Expenses
            const expenses = [];
            document.querySelectorAll('#expense_rows > div').forEach(row => {
                const name = row.querySelector('.exp-name').value;
                const amt = parseFloat(row.querySelector('.exp-amount').value || 0);
                if(name && amt > 0) expenses.push({name, amount: amt});
            });

            // Gather Customers & Items
            const customers = [];
            let isValid = true;

            customerBlocks.forEach(bId => {
                const block = document.getElementById(`cb_${bId}`);
                if(!block) return;
                
                const cId = block.querySelector('.cust_id_val').value;
                if(!cId) { alert(`A customer selection is missing in block ${bId}`); isValid = false; return; }

                const items = [];
                block.querySelectorAll('.item-row').forEach(row => {
                    const iId = row.querySelector('.item_id_val').value;
                    const qty = parseInt(row.querySelector('.item-qty').value || 0);
                    const cost = parseFloat(row.querySelector('.item_cost_val').value || 0);
                    const sell = parseFloat(row.querySelector('.item-sell').value || 0);
                    
                    if(iId && qty > 0) {
                        items.push({item_id: iId, qty: qty, cost_price: cost, selling_price: sell});
                    }
                });

                if(items.length > 0) {
                    customers.push({customer_id: cId, items: items});
                } else {
                    alert(`Customer selected but no items added in block ${bId}`);
                    isValid = false;
                }
            });

            if(!isValid) return;
            if(customers.length === 0) { alert("Please add at least one customer with items."); return; }

            // Submit
            const fd = new FormData();
            fd.append('action', 'save_delivery');
            fd.append('delivery_date', document.getElementById('delivery_date').value);
            fd.append('employees', JSON.stringify(selectedEmployees));
            fd.append('expenses', JSON.stringify(expenses));
            fd.append('customers', JSON.stringify(customers));

            const btn = event.currentTarget;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;

            fetch('nwdelivery.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if(res.success) {
                    location.reload();
                } else {
                    alert(res.message);
                    btn.innerHTML = '<i class="fa-solid fa-check-circle"></i> Confirm & Process Delivery';
                    btn.disabled = false;
                }
            }).catch(e => {
                alert("An error occurred connecting to the server.");
                btn.innerHTML = '<i class="fa-solid fa-check-circle"></i> Confirm & Process Delivery';
                btn.disabled = false;
            });
        }

        // ==========================================
        // View Delivery (Invoice)
        // ==========================================
        function viewDelivery(id) {
            const modal = document.getElementById('invoice-modal');
            const body  = document.getElementById('invoice-body');
            body.innerHTML = '<p class="text-slate-500 text-center py-10">Loading...</p>';
            modal.classList.remove('hidden');

            fetch(`?action=view_delivery&id=${id}`)
            .then(r => r.json())
            .then(res => {
                if (!res.success) { body.innerHTML = `<p class="text-rose-400 text-center">${res.message}</p>`; return; }
                const d = res.data;

                const fmt  = n => 'Rs. ' + parseFloat(n||0).toLocaleString('en-US', {minimumFractionDigits: 2});
                const date = new Date(d.delivery_date).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'});
                const empList = d.employees.map(e => `${e.full_name} (${e.contact_number})`).join(', ') || 'N/A';

                // ── Admin Given Expenses ──────────────────────────────────
                let givenExpRows = d.expenses.length
                    ? d.expenses.map(e => `<tr class="border-b border-slate-800"><td class="py-1.5 text-slate-300">${e.expense_name}</td><td class="py-1.5 text-right text-amber-400 font-bold">${fmt(e.amount)}</td></tr>`).join('')
                    : `<tr><td colspan="2" class="py-2 text-slate-600 italic text-xs">No admin expenses.</td></tr>`;

                // ── Field Expenses ────────────────────────────────────────
                let fieldExpRows = (d.field_expenses && d.field_expenses.length)
                    ? d.field_expenses.map(e => `<tr class="border-b border-slate-800"><td class="py-1.5 text-slate-300">${e.expense_name}</td><td class="py-1.5 text-slate-500 text-[10px]">by ${e.added_by}</td><td class="py-1.5 text-right text-cyan-400 font-bold">${fmt(e.amount)}</td></tr>`).join('')
                    : `<tr><td colspan="3" class="py-2 text-slate-600 italic text-xs">No field expenses added.</td></tr>`;

                // ── Customer Blocks ───────────────────────────────────────
                let custBlocks = d.customers.map(c => {
                    let itemRows = c.items.map(it => {
                        const net    = it.qty - (it.damaged_qty || 0);
                        const netVal = net * it.selling_price;
                        return `<tr class="border-b border-slate-800/60 text-xs">
                            <td class="py-1.5 text-slate-300">${it.brand_name}</td>
                            <td class="py-1.5 text-slate-500 text-[10px]">${it.container_number}</td>
                            <td class="py-1.5 text-center text-white font-bold">${it.qty}</td>
                            <td class="py-1.5 text-center text-rose-400 font-bold">${it.damaged_qty || 0}</td>
                            <td class="py-1.5 text-center text-emerald-400 font-bold">${net}</td>
                            <td class="py-1.5 text-right text-purple-400">${fmt(it.cost_price)}</td>
                            <td class="py-1.5 text-right text-amber-400">${fmt(it.selling_price)}</td>
                            <td class="py-1.5 text-right text-emerald-400 font-bold">${fmt(netVal)}</td>
                        </tr>`;
                    }).join('');

                    const isDelivered = (c.status === 'delivered');
                    const statusBadge = isDelivered 
                        ? `<span class="bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded text-[8px] uppercase font-black tracking-widest border border-emerald-500/20"><i class="fa-solid fa-check mr-1"></i> Delivered</span>`
                        : `<span class="bg-slate-500/20 text-slate-400 px-2 py-0.5 rounded text-[8px] uppercase font-black tracking-widest">Pending</span>`;

                    return `<div class="border border-slate-700 rounded-lg overflow-hidden relative">
                        ${isDelivered ? `<div class="absolute inset-0 bg-emerald-500/5 pointer-events-none"></div>` : ''}
                        <div class="flex flex-wrap justify-between items-center px-4 py-2 bg-slate-800/60 text-xs gap-2 relative z-10">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-white">${c.name}</span>
                                ${statusBadge}
                            </div>
                            <span class="text-slate-400 text-[10px] ml-1">${c.contact_number || ''}${c.address ? ' &bull; ' + c.address : ''}</span>
                            <div class="flex items-center gap-3 ml-auto">
                                <span class="text-slate-500 text-[10px]">Assigned <span class="text-white font-bold">${fmt(c.assigned_total)}</span></span>
                                <span class="text-rose-400 text-[10px]">Damaged <span class="font-bold">${fmt(c.damaged_value)}</span></span>
                                <span class="text-emerald-400 font-black">${fmt(c.net_total)}</span>
                            </div>
                        </div>
                        <table class="w-full text-xs relative z-10">
                            <thead><tr class="text-[9px] uppercase text-slate-600 tracking-widest border-b border-slate-800">
                                <th class="px-3 py-1.5 text-left">Brand</th>
                                <th class="px-3 py-1.5 text-left">Container</th>
                                <th class="px-3 py-1.5 text-center">Assigned</th>
                                <th class="px-3 py-1.5 text-center text-rose-500">Damaged</th>
                                <th class="px-3 py-1.5 text-center text-emerald-500">Net</th>
                                <th class="px-3 py-1.5 text-right">Cost</th>
                                <th class="px-3 py-1.5 text-right">Sell</th>
                                <th class="px-3 py-1.5 text-right">Net Total</th>
                            </tr></thead>
                            <tbody class="${isDelivered ? 'opacity-70' : ''}">${itemRows}</tbody>
                        </table>
                    </div>`;
                }).join('');

                // ── Ledger ────────────────────────────────────────────────
                let ledgerRows = d.ledger.map(l => {
                    const badge = l.action_type === 'CREATED' ? 'text-emerald-400' : l.action_type === 'DELETED' ? 'text-rose-400' : 'text-amber-400';
                    return `<tr class="border-b border-slate-800 text-xs">
                        <td class="py-1.5"><span class="font-bold ${badge}">${l.action_type}</span></td>
                        <td class="py-1.5 text-slate-400">${l.full_name}</td>
                        <td class="py-1.5 text-slate-300">${l.notes}</td>
                        <td class="py-1.5 text-slate-500 text-right whitespace-nowrap">${new Date(l.performed_at).toLocaleString('en-GB')}</td>
                    </tr>`;
                }).join('');

                body.innerHTML = `
                    <!-- Meta -->
                    <div class="flex justify-between items-start border-b border-slate-700 pb-4">
                        <div>
                            <p class="text-[10px] uppercase text-slate-500 tracking-widest mb-1">Delivery Reference</p>
                            <p class="text-2xl font-black text-white">#DEL-${String(d.id).padStart(4,'0')}</p>
                            <p class="text-xs text-slate-500 mt-1">Created by <span class="text-slate-300">${d.created_by_name}</span></p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] uppercase text-slate-500 tracking-widest mb-1">Date</p>
                            <p class="text-lg font-bold text-white">${date}</p>
                        </div>
                    </div>

                    <!-- Employees -->
                    <div>
                        <p class="text-[9px] uppercase font-bold text-slate-500 tracking-widest mb-1">Assigned Employees</p>
                        <p class="text-xs text-slate-300">${empList}</p>
                    </div>

                    <!-- Expenses -->
                    <div>
                        <p class="text-[9px] uppercase font-bold text-slate-500 tracking-widest mb-2">Admin Expenses</p>
                        <table class="w-full text-xs"><tbody>${givenExpRows}</tbody>
                            <tfoot><tr><td class="pt-1.5 text-[10px] uppercase font-bold text-slate-500">Given Total</td>
                            <td class="pt-1.5 text-right font-black text-amber-400">${fmt(d.total_expenses)}</td></tr></tfoot>
                        </table>
                    </div>

                    <div>
                        <p class="text-[9px] uppercase font-bold text-slate-500 tracking-widest mb-2">Field Expenses <span class="text-slate-700 normal-case">(by employees)</span></p>
                        <table class="w-full text-xs"><tbody>${fieldExpRows}</tbody>
                            <tfoot><tr><td class="pt-1.5 text-[10px] uppercase font-bold text-slate-500">Field Total</td>
                            <td></td>
                            <td class="pt-1.5 text-right font-black text-cyan-400">${fmt(d.field_exp_total)}</td></tr></tfoot>
                        </table>
                    </div>

                    <!-- Customers -->
                    <div>
                        <p class="text-[9px] uppercase font-bold text-slate-500 tracking-widest mb-2">Customer Invoices</p>
                        <div class="space-y-3">${custBlocks}</div>
                    </div>

                    <!-- Grand Totals -->
                    <div class="border-t border-slate-700 pt-4 space-y-2">
                        <p class="text-[9px] uppercase font-bold text-slate-600 tracking-widest mb-3">Delivery Summary</p>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div class="flex justify-between bg-white/[0.02] rounded-lg px-3 py-2">
                                <span class="text-slate-500">Assigned Sales</span><span class="font-bold text-white">${fmt(d.grand_assigned)}</span>
                            </div>
                            <div class="flex justify-between bg-rose-500/5 rounded-lg px-3 py-2">
                                <span class="text-slate-500">Damaged Value</span><span class="font-bold text-rose-400">${fmt(d.grand_damaged)}</span>
                            </div>
                            <div class="flex justify-between bg-emerald-500/5 rounded-lg px-3 py-2">
                                <span class="text-slate-500">Actual (Net) Sales</span><span class="font-black text-emerald-400">${fmt(d.grand_net)}</span>
                            </div>
                            <div class="flex justify-between bg-white/[0.02] rounded-lg px-3 py-2">
                                <span class="text-slate-500">Admin Expenses</span><span class="font-bold text-amber-400">${fmt(d.total_expenses)}</span>
                            </div>
                            <div class="flex justify-between bg-white/[0.02] rounded-lg px-3 py-2">
                                <span class="text-slate-500">Field Expenses</span><span class="font-bold text-cyan-400">${fmt(d.field_exp_total)}</span>
                            </div>
                            <div class="flex justify-between bg-amber-500/5 rounded-lg px-3 py-2 border border-amber-500/10">
                                <span class="text-slate-400 font-bold">Total Expenses</span><span class="font-black text-amber-400">${fmt(d.total_exp_all)}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Ledger -->
                    <div class="border-t border-slate-800 pt-4">
                        <p class="text-[9px] uppercase font-bold text-slate-600 tracking-widest mb-2">Audit Ledger</p>
                        <table class="w-full"><thead><tr class="text-[9px] uppercase text-slate-700 tracking-widest">
                            <th class="text-left pb-1">Action</th><th class="text-left pb-1">By</th>
                            <th class="text-left pb-1">Notes</th><th class="text-right pb-1">Time</th>
                        </tr></thead><tbody>${ledgerRows || '<tr><td colspan="4" class="text-slate-700 text-xs py-2">No entries.</td></tr>'}</tbody></table>
                    </div>`;
            });
        }

        // ==========================================
        // Delete Delivery
        // ==========================================
        function confirmDelete(id) {
            const modal = document.getElementById('delete-modal');
            const btn   = document.getElementById('delete-confirm-btn');
            modal.classList.remove('hidden');
            btn.onclick = () => {
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting...';
                btn.disabled = true;
                const fd = new FormData();
                fd.append('action', 'delete_delivery');
                fd.append('id', id);
                fetch('nwdelivery.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        location.reload();
                    } else {
                        alert(res.message);
                        btn.innerHTML = '<i class="fa-solid fa-trash"></i> Yes, Delete';
                        btn.disabled = false;
                    }
                });
            };
        }

        // ==========================================
        // Mark as Completed
        // ==========================================
        function markAsCompleted(id) {
            if (!confirm("Are you sure you want to mark this delivery as completed?")) return;
            
            const fd = new FormData();
            fd.append('action', 'complete_delivery');
            fd.append('id', id);
            
            fetch('nwdelivery.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    location.reload();
                } else {
                    alert(res.message);
                }
            });
        }
    </script>
</body>
</html>
