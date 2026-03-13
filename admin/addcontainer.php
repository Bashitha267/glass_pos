<?php
require_once '../auth.php';
require_once '../config.php';
checkAuth();

if (!isAdmin()) {
    header('Location: ../sale/dashboard.php');
    exit;
}

$username = $_SESSION['username'];
$user_id = $_SESSION['user_id'];

// Handle AJAX Actions
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action == 'get_next_number') {
    $stmt = $pdo->query("SELECT container_number FROM containers ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    
    // Fallback if no containers exist
    if (!$last) {
        echo json_encode(['success' => true, 'next' => '0001']);
        exit;
    }

    // Try to extract number and increment
    $num = (int)$last;
    $next = str_pad($num + 1, 4, '0', STR_PAD_LEFT);
    echo json_encode(['success' => true, 'next' => $next]);
    exit;
}

if ($action == 'search_brand') {
    $term = '%' . $_GET['term'] . '%';
    $stmt = $pdo->prepare("SELECT name FROM brands WHERE name LIKE ? LIMIT 5");
    $stmt->execute([$term]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    exit;
}

if ($action == 'get_details') {
    $container_no = $_GET['container_number'];
    
    // Get Container
    $stmt = $pdo->prepare("SELECT * FROM containers WHERE container_number = ?");
    $stmt->execute([$container_no]);
    $container = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$container) {
        echo json_encode(['success' => false, 'message' => 'Container not found']);
        exit;
    }

    $container_id = $container['id'];

    // Get Items
    $stmt = $pdo->prepare("SELECT ci.*, b.name as brand_name FROM container_items ci JOIN brands b ON ci.brand_id = b.id WHERE ci.container_id = ?");
    $stmt->execute([$container_id]);
    $container['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get Expenses
    $stmt = $pdo->prepare("SELECT * FROM container_expenses WHERE container_id = ?");
    $stmt->execute([$container_id]);
    $container['expenses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get Payments
    $stmt = $pdo->prepare("SELECT * FROM container_payments WHERE container_id = ?");
    $stmt->execute([$container_id]);
    $container['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $container]);
    exit;
}

if ($action == 'delete_container') {
    try {
        $container_id = $_POST['container_id'];
        $pdo->beginTransaction();
        
        // Delete dependencies first
        $pdo->prepare("DELETE FROM container_items WHERE container_id = ?")->execute([$container_id]);
        $pdo->prepare("DELETE FROM container_expenses WHERE container_id = ?")->execute([$container_id]);
        $pdo->prepare("DELETE FROM container_ledger WHERE container_id = ?")->execute([$container_id]);
        
        // Delete container
        $stmt = $pdo->prepare("DELETE FROM containers WHERE id = ?");
        $stmt->execute([$container_id]);
        
        if ($stmt->rowCount()) {
            $pdo->commit();
            echo json_encode(['success' => true]);
        } else {
            throw new Exception("Container not found or already deleted.");
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX Save Container
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_container') {
    try {
        $pdo->beginTransaction();

        $container_no = $_POST['container_number'];
        $arrival_date = $_POST['arrival_date'];
        $expenses = json_decode($_POST['expenses'], true);
        $items = json_decode($_POST['items'], true);
        $damaged_qty = (int)($_POST['damaged_qty'] ?? 0);
        $container_cost = (float)($_POST['container_cost'] ?? 0);
        $country = $_POST['country'] ?? null;

        // 1. Calculate totals
        $other_expenses = 0;
        foreach ($expenses as $exp) {
            $other_expenses += (float)$exp['amount'];
        }
        $total_expenses = $other_expenses + $container_cost;

        $total_qty = 0;
        foreach ($items as $item) {
            $total_qty += (int)$item['pallets'] * (int)$item['qty_per_pallet'];
        }

        $net_qty = $total_qty - $damaged_qty;
        $per_item_cost = ($net_qty > 0) ? ($total_expenses / $net_qty) : 0;

        // 2. Fetch Old Data for Ledger if exists (Include all columns to be compared)
        $stmt = $pdo->prepare("SELECT id, damaged_qty, total_expenses, country, total_qty, container_cost FROM containers WHERE container_number = ?");
        $stmt->execute([$container_no]);
        $old_container = $stmt->fetch(PDO::FETCH_ASSOC);

        // 3. Insert/Update Container
        $stmt = $pdo->prepare("INSERT INTO containers (container_number, arrival_date, added_by, total_expenses, container_cost, total_qty, damaged_qty, per_item_cost, country) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) 
                               ON DUPLICATE KEY UPDATE 
                               arrival_date = VALUES(arrival_date),
                               total_expenses = VALUES(total_expenses),
                               container_cost = VALUES(container_cost),
                               total_qty = VALUES(total_qty),
                               damaged_qty = VALUES(damaged_qty),
                               per_item_cost = VALUES(per_item_cost),
                               country = VALUES(country)");
        $stmt->execute([$container_no, $arrival_date, $user_id, $total_expenses, $container_cost, $total_qty, $damaged_qty, $per_item_cost, $country]);
        
        if ($old_container) {
            $container_id = $old_container['id'];
            
            // Log Total Qty change (requested)
            if ($old_container['total_qty'] != $total_qty) {
                $ledgerStmt = $pdo->prepare("INSERT INTO container_ledger (container_id, action_type, field_name, old_value, new_value, changed_by) VALUES (?, 'UPDATE', 'total_qty', ?, ?, ?)");
                $ledgerStmt->execute([$container_id, $old_container['total_qty'], $total_qty, $user_id]);
            }

            // Log Damaged Qty change
            if ($old_container['damaged_qty'] != $damaged_qty) {
                $ledgerStmt = $pdo->prepare("INSERT INTO container_ledger (container_id, action_type, field_name, old_value, new_value, changed_by) VALUES (?, 'UPDATE', 'damaged_qty', ?, ?, ?)");
                $ledgerStmt->execute([$container_id, $old_container['damaged_qty'], $damaged_qty, $user_id]);
            }

            // Log Total Expenses change
            if ($old_container['total_expenses'] != $total_expenses) {
                $ledgerStmt = $pdo->prepare("INSERT INTO container_ledger (container_id, action_type, field_name, old_value, new_value, changed_by) VALUES (?, 'UPDATE', 'total_expenses', ?, ?, ?)");
                $ledgerStmt->execute([$container_id, $old_container['total_expenses'], $total_expenses, $user_id]);
            }

            // Log Country change (truncated to 50 chars for safety)
            if (($old_container['country'] ?? '') != ($country ?? '')) {
                $field_name = substr('country', 0, 50);
                $ledgerStmt = $pdo->prepare("INSERT INTO container_ledger (container_id, action_type, field_name, old_value, new_value, changed_by) VALUES (?, 'UPDATE', ?, ?, ?, ?)");
                $ledgerStmt->execute([$container_id, $field_name, $old_container['country'] ?? '-', $country ?? '-', $user_id]);
            }

            // Log Container Cost change
            if ($old_container['container_cost'] != $container_cost) {
                $ledgerStmt = $pdo->prepare("INSERT INTO container_ledger (container_id, action_type, field_name, old_value, new_value, changed_by) VALUES (?, 'UPDATE', 'container_cost', ?, ?, ?)");
                $ledgerStmt->execute([$container_id, $old_container['container_cost'], $container_cost, $user_id]);
            }

            $action_main = "UPDATED";
        } else {
            $container_id = $pdo->lastInsertId();
            $action_main = "CREATED";
        }

        // 3. Handle Items & Brands
        // Clear existing items for this container if updating
        $pdo->prepare("DELETE FROM container_items WHERE container_id = ?")->execute([$container_id]);
        
        foreach ($items as $item) {
            $brand_name = trim($item['brand']);
            if (!$brand_name) continue;

            // Ensure brand exists
            $stmt = $pdo->prepare("SELECT id FROM brands WHERE name = ?");
            $stmt->execute([$brand_name]);
            $brand_id = $stmt->fetchColumn();

            if (!$brand_id) {
                $stmt = $pdo->prepare("INSERT INTO brands (name) VALUES (?)");
                $stmt->execute([$brand_name]);
                $brand_id = $pdo->lastInsertId();
            }

            $pallets = (int)$item['pallets'];
            $qty_per_pallet = (int)$item['qty_per_pallet'];
            $line_total = $pallets * $qty_per_pallet;

            $stmt = $pdo->prepare("INSERT INTO container_items (container_id, brand_id, pallets, qty_per_pallet, total_qty) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$container_id, $brand_id, $pallets, $qty_per_pallet, $line_total]);
        }

        // 4. Handle Expenses
        $pdo->prepare("DELETE FROM container_expenses WHERE container_id = ?")->execute([$container_id]);
        foreach ($expenses as $exp) {
            if (!$exp['name'] || $exp['amount'] <= 0) continue;
            $stmt = $pdo->prepare("INSERT INTO container_expenses (container_id, expense_name, amount) VALUES (?, ?, ?)");
            $stmt->execute([$container_id, $exp['name'], $exp['amount']]);
            
            // Log Expense (truncated description for safety)
            $desc = substr('Added Expense: '.$exp['name'], 0, 50);
            $lStmt = $pdo->prepare("INSERT INTO container_ledger (container_id, action_type, field_name, new_value, changed_by) VALUES (?, 'EXPENSE', ?, ?, ?)");
            $lStmt->execute([$container_id, $desc, "Rs. ".number_format((float)$exp['amount'], 2), $user_id]);
        }

        // 5. Handle Payments
        $payments = json_decode($_POST['payments'], true);
        $pdo->prepare("DELETE FROM container_payments WHERE container_id = ?")->execute([$container_id]);
        foreach ($payments as $pay) {
            if ($pay['amount'] <= 0) continue;
            $stmt = $pdo->prepare("INSERT INTO container_payments (container_id, payment_id, payment_type, method, amount, description) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$container_id, $pay['payment_id'], $pay['type'], $pay['method'], $pay['amount'], $pay['desc']]);
            
            // Log Payment (truncated description for safety)
            $method_desc = substr('Payment Method: '.$pay['method'], 0, 50);
            $lStmt = $pdo->prepare("INSERT INTO container_ledger (container_id, action_type, field_name, new_value, changed_by) VALUES (?, 'PAYMENT', ?, ?, ?)");
            $lStmt->execute([$container_id, $method_desc, "Rs. ".number_format((float)$pay['amount'], 2), $user_id]);
        }

        // 6. Final Ledger Entry
        $stmt = $pdo->prepare("INSERT INTO container_ledger (container_id, action_type, changed_by) VALUES (?, ?, ?)");
        $stmt->execute([$container_id, $action_main, $user_id]);

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 1. Prepare Filter variables
$search = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$payment_status = $_GET['payment_status'] ?? '';

// Pagination settings
$limit = 8;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

if ($search) {
    $where[] = "(c.container_number LIKE ? OR b.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($start_date) { $where[] = "c.arrival_date >= ?"; $params[] = $start_date; }
if ($end_date) { $where[] = "c.arrival_date <= ?"; $params[] = $end_date; }

if ($payment_status === 'pending') {
    $where[] = "c.total_expenses > (SELECT COALESCE(SUM(amount), 0) FROM container_payments WHERE container_id = c.id)";
} elseif ($payment_status === 'completed') {
    $where[] = "c.total_expenses <= (SELECT COALESCE(SUM(amount), 0) FROM container_payments WHERE container_id = c.id)";
}

$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

// Count total for pagination
$countQuery = "SELECT COUNT(DISTINCT c.id) FROM containers c
               LEFT JOIN container_items ci ON c.id = ci.container_id
               LEFT JOIN brands b ON ci.brand_id = b.id
               $whereClause";
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$total_records = $countStmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Fetch Containers with joined Brand and Total Paid aggregation
$query = "SELECT c.*, b.name as brand_name,
          COALESCE((SELECT SUM(amount) FROM container_payments WHERE container_id = c.id), 0) as total_paid
          FROM containers c
          LEFT JOIN (SELECT container_id, MIN(brand_id) as brand_id FROM container_items GROUP BY container_id) ci ON c.id = ci.container_id
          LEFT JOIN brands b ON ci.brand_id = b.id
          $whereClause
          ORDER BY CAST(c.container_number AS UNSIGNED) DESC, c.arrival_date DESC
          LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$containers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Containers | Crystal POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@300;400;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background: url('../assests/glass_bg.png') no-repeat center center fixed;
            background-size: cover;
            color: #0f172a;
            min-height: 100vh;
        }
        .glass-header {
            background: rgba(241, 245, 249, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            box-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.08);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 1);
            border-radius: 20px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .container-modal {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(2px);
            border: 1px solid rgba(255, 255, 255, 1);
            color: #121822ff;
        }
        .input-glass {
            background: rgba(255, 255, 255, 0.6);
            border: 1px solid rgba(203, 213, 225, 0.6);
            color: #1e293b;
            padding: 8px 12px;
            border-radius: 12px;
            outline: none;
            transition: all 0.3s;
        }
        .input-glass:focus {
            border-color: #0891b2;
            background: white;
            box-shadow: 0 0 20px rgba(8, 145, 178, 0.1);
        }
        .btn-freq {
            background: rgba(8, 145, 178, 0.1);
            border: 1px solid rgba(8, 145, 178, 0.2);
            color: #0891b2;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.8rem;
            transition: all 0.3s;
            font-weight: 600;
        }
        .btn-freq:hover {
            background: rgba(8, 145, 178, 0.2);
            transform: translateY(-1px);
        }
        .text-glass-muted { color: #64748b; }
        .border-glass { border-color: rgba(203, 213, 225, 0.4); }
    </style>
</head>
<body class="flex flex-col">

    <header class="glass-header sticky top-0 z-40 py-3">
        <div class="px-10 flex items-center justify-between">
            <div class="flex items-center space-x-3 sm:space-x-4">
                <a href="dashboard.php" class="text-slate-800 hover:text-cyan-600 transition-colors">
                    <i class="fa-solid fa-arrow-left text-lg sm:text-xl"></i>
                </a>
                <h1 class="text-xl sm:text-2xl font-bold tracking-tight uppercase truncate max-w-[200px] sm:max-w-none text-slate-800">Container Registry</h1>
            </div>
            <button id="btn-add-container" onclick="openModal()" class="bg-cyan-600 hover:bg-cyan-500 text-white px-3 sm:px-5 py-2 lg:py-2.5 rounded-xl font-bold text-xs sm:text-sm uppercase transition-all shadow-lg flex items-center space-x-2">
                <i class="fa-solid fa-plus text-[10px] sm:text-xs text-white"></i>
                <span class="hidden xs:inline">Add Container</span>
                <span class="xs:hidden">Add a New Container</span>
            </button>
        </div>
    </header>

    <main class="w-full px-6 py-8 sm:py-10">
        <!-- Filters Bar -->
        <div class="glass-card bg-slate-800/80 p-4 sm:p-6 mb-8 border-slate-700">
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-7 gap-4 items-end">
                <div class="sm:col-span-2 lg:col-span-2 relative">
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">Search</label>
                    <div class="relative">
                        <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="ID or Brand..." class="input-glass w-full pl-12 bg-slate-900/40 border-slate-700 text-white placeholder:text-slate-500 focus:ring-2 focus:ring-cyan-500 auto-search">
                    </div>
                </div>
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">Start Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="input-glass w-full bg-slate-900/40 border-slate-700 text-white" onchange="this.form.submit()">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">End Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="input-glass w-full bg-slate-900/40 border-slate-700 text-white" onchange="this.form.submit()">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">Payment Status</label>
                    <select name="payment_status" class="input-glass w-full bg-slate-900/40 border-slate-700 text-white" onchange="this.form.submit()">
                        <option value="" class="bg-slate-800">All Status</option>
                        <option value="pending" <?php echo $payment_status === 'pending' ? 'selected' : ''; ?> class="bg-slate-800">Pending</option>
                        <option value="completed" <?php echo $payment_status === 'completed' ? 'selected' : ''; ?> class="bg-slate-800">Completed</option>
                    </select>
                </div>
                <div class="flex sm:col-span-2 lg:col-span-1 space-x-2">
                    <?php if($search || $start_date || $end_date || $payment_status): ?>
                        <a href="addcontainer.php" class="bg-rose-500/20 text-rose-400 p-2.5 px-4 rounded-xl hover:bg-rose-500/30 transition-all flex items-center h-[42px] w-full lg:w-auto justify-center" title="Reset Filters">
                            <i class="fa-solid fa-rotate-left mr-2"></i>
                            <span class="text-xs font-bold uppercase tracking-wider">Reset</span>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <!-- Container List -->
        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left min-w-[1000px] lg:min-w-0">
                    <thead>
                        <tr class="bg-slate-700 text-[12px] uppercase tracking-wider text-white border-b border-slate-800">
                            <th class="px-3 py-4 font-black">Container ID</th>
                            <th class="px-3 py-4 font-black">Brand</th>
                            <th class="px-3 py-4 font-black">Country</th>
                            <th class="px-3 py-4 font-black">Date</th>
                            <th class="px-3 py-4 font-black">Total Qty</th>
                            <th class="px-3 py-4 font-black text-amber-400">Damaged</th>
                            <th class="px-3 py-4 font-black text-emerald-400 whitespace-nowrap">Item Per Cost</th>
                            <th class="px-3 py-4 font-black whitespace-nowrap text-slate-100">Total Expenses</th>
                            <th class="px-3 py-4 font-black text-emerald-400 whitespace-nowrap">Total Paid</th>
                            <th class="px-3 py-4 font-black text-rose-400 whitespace-nowrap">Remain</th>
                            <th class="px-3 py-4 text-center font-black">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($containers)): ?>
                        <tr>
                            <td colspan="11" class="px-6 py-10 text-center text-slate-500 italic">No records found matching your criteria.</td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($containers as $c): ?>
                        <tr class="odd:bg-gray-50/40 even:bg-gray-100/40 hover:bg-cyan-500/5 transition-colors">
                            <td class="px-3 py-4 text-sm font-bold text-cyan-600 whitespace-nowrap"><?php echo htmlspecialchars($c['container_number']); ?></td>
                            <td class="px-3 py-4 text-sm font-bold text-slate-800"><?php echo htmlspecialchars($c['brand_name'] ?? '-'); ?></td>
                            <td class="px-3 py-4 text-sm font-medium text-slate-500"><?php echo htmlspecialchars($c['country'] ?? '-'); ?></td>
                            <td class="px-3 py-4 text-sm text-slate-600 whitespace-nowrap"><?php echo date('Y-m-d', strtotime($c['arrival_date'])); ?></td>
                            <td class="px-3 py-4 text-sm font-semibold text-slate-700"><?php echo number_format($c['total_qty']); ?></td>
                            <td class="px-3 py-4 text-sm font-bold text-amber-600"><?php echo number_format($c['damaged_qty']); ?></td>
                            <td class="px-3 py-4 text-sm font-bold text-emerald-600 whitespace-nowrap">Rs. <?php echo number_format($c['per_item_cost'], 2); ?></td>
                            <td class="px-3 py-4 text-sm font-bold text-slate-800 whitespace-nowrap">Rs. <?php echo number_format($c['total_expenses'], 2); ?></td>
                            <td class="px-3 py-4 text-sm font-bold text-emerald-600 whitespace-nowrap">Rs. <?php echo number_format($c['total_paid'], 2); ?></td>
                            <td class="px-3 py-4 text-sm font-bold text-rose-600 whitespace-nowrap">Rs. <?php echo number_format($c['total_expenses'] - $c['total_paid'], 2); ?></td>
                            <td class="px-3 py-4 text-center">
                                <div class="flex items-center justify-center space-x-2">
                                    <button onclick="viewContainer('<?php echo $c['container_number']; ?>')" class="text-slate-400 hover:text-cyan-600 transition-colors p-2 rounded-lg hover:bg-cyan-600/10" title="View">
                                        <i class="fa-solid fa-eye text-sm"></i>
                                    </button>
                                    <button onclick="editContainer('<?php echo $c['container_number']; ?>')" class="text-slate-400 hover:text-emerald-600 transition-colors p-2 rounded-lg hover:bg-emerald-600/10" title="Edit">
                                        <i class="fa-solid fa-pen-to-square text-sm"></i>
                                    </button>
                                    <button onclick="deleteContainer(<?php echo $c['id']; ?>, '<?php echo $c['container_number']; ?>')" class="text-slate-400 hover:text-rose-600 transition-colors p-2 rounded-lg hover:bg-rose-600/10" title="Delete">
                                        <i class="fa-solid fa-trash-can text-sm"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Support -->
            <?php if ($total_pages > 1): ?>
            <div class="px-4 sm:px-6 py-4 bg-slate-50 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="text-[10px] sm:text-xs text-slate-500 uppercase font-bold tracking-wider">
                    Showing <span class="text-slate-800"><?php echo $offset + 1; ?></span> to <span class="text-slate-800"><?php echo min($offset + $limit, $total_records); ?></span> of <span class="text-slate-800"><?php echo $total_records; ?></span> entries
                </div>
                <div class="flex items-center space-x-1 sm:space-x-2">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="p-2 sm:px-4 sm:py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg text-xs font-bold transition-all"><i class="fa-solid fa-chevron-left"></i></a>
                    <?php endif; ?>
                    
                    <div class="flex items-center space-x-1">
                        <?php 
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        for ($i = $start; $i <= $end; $i++): 
                        ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="w-8 h-8 sm:w-10 sm:h-10 flex items-center justify-center rounded-lg text-xs font-bold transition-all <?php echo $page == $i ? 'bg-cyan-600 text-white shadow-lg shadow-cyan-900/20' : 'bg-white hover:bg-slate-50 border border-slate-200 text-slate-500'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>

                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="p-2 sm:px-4 sm:py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg text-xs font-bold transition-all"><i class="fa-solid fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal Backdrop -->
    <div id="modal-container" class="fixed inset-0 bg-black/40 backdrop-blur-[3px] z-50 flex items-center justify-center p-2 sm:p-4 hidden">
        <div class="container-modal w-full max-w-4xl max-h-[95vh] overflow-y-auto rounded-[20px] sm:rounded-[30px] shadow-2xl">
            <div class="p-4 sm:p-8">
                <div class="flex items-center justify-between mb-6 sm:mb-8">
                    <div>
                        <h2 id="modal-title" class="text-xl sm:text-2xl font-bold text-slate-800">Add New Container</h2>
                    </div>
                    <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600">
                        <i class="fa-solid fa-times text-2xl"></i>
                    </button>
                </div>

                <form id="container-form" onsubmit="saveContainer(event)" class="space-y-8">
                    <input type="hidden" name="action" value="save_container">
                    
                    <!-- Basic Info -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Container Number</label>
                            <input type="text" name="container_number" id="container_number" class="input-glass" required placeholder="0001" readonly>
                        </div>
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Arrival Date</label>
                            <input type="date" name="arrival_date" id="arrival_date" class="input-glass" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Country (Optional)</label>
                            <input type="text" name="country" id="country" class="input-glass" placeholder="e.g. China, Dubai">
                        </div>
                    </div>

                    <!-- Items Section -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Container Items</label>
                            <button id="btn-add-item" type="button" onclick="addItemRow()" class="text-cyan-600 hover:text-cyan-500 text-xs font-bold uppercase tracking-widest">+ Add Item</button>
                        </div>
                        <div id="items-list-header" class="hidden lg:grid grid-cols-5 gap-3 px-4 py-2 mb-2 bg-slate-100 rounded-lg border border-slate-200">
                            <span class="text-[10px] uppercase font-bold text-slate-600 tracking-widest col-span-2">Brand Name</span>
                            <span class="text-[10px] uppercase font-bold text-slate-600 tracking-widest">Pallets</span>
                            <span class="text-[10px] uppercase font-bold text-slate-600 tracking-widest">Qty / Pallet</span>
                            <span class="text-[10px] uppercase font-bold text-slate-600 tracking-widest text-center">Total</span>
                        </div>
                        <div id="items-list" class="space-y-3">
                            <!-- Dynamic Item Rows -->
                        </div>
                    </div>

                    <!-- Expenses Section -->
                    <div class="space-y-6">
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-cyan-600 tracking-wider">Base Container Cost</label>
                            <input type="number" step="0.01" name="container_cost" id="container_cost" class="input-glass border-cyan-500/20" oninput="calculateTotals()" placeholder="0.00">
                        </div>

                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Other Expenses</label>
                                <div class="flex space-x-2">
                                    <button type="button" onclick="addFreqExpense('Transport')" class="btn-freq">+ Transport</button>
                                    <button type="button" onclick="addFreqExpense('Duty Charge')" class="btn-freq">+ Duty Charge</button>
                                </div>
                            </div>
                            <div id="expenses-list" class="space-y-3">
                                <!-- Dynamic Expense Rows -->
                            </div>
                            <div id="add-exp-container" class="pt-2">
                                <button type="button" onclick="addExpenseRow()" class="w-full py-4 border-2 border-dashed  rounded-2xl  text-cyan-400 border-cyan-400/50 bg-cyan-400/5 hover:text-cyan-600 hover:border-cyan-600/50 hover:bg-cyan-600/5 transition-all flex items-center justify-center space-x-2 font-bold uppercase text-xs">
                                    <i class="fa-solid fa-plus-circle"></i>
                                    <span>Add Extra Expense</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Payments Section -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Payments History</label>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Total to Pay: <span id="pay-header-total" class="text-slate-800">Rs. 0.00</span></p>
                        </div>
                        <div id="payments-list" class="space-y-3">
                            <!-- Dynamic Payment Rows -->
                        </div>
                        <div id="add-pay-container" class="pt-2">
                            <button type="button" onclick="addPaymentRow()" class="w-full py-4 border-2 border-dashed  rounded-2xl text-emerald-400 border-emerald-400/50 bg-emerald-400/5 hover:text-emerald-600 hover:border-emerald-600/50 hover:bg-emerald-600/5 transition-all flex items-center justify-center space-x-2 font-bold uppercase text-xs">
                                <i class="fa-solid fa-plus-circle"></i>
                                <span>Add New Payment</span>
                            </button>
                        </div>
                    </div>

                    <!-- Footer Info (Damaged, Totals) -->
                    <div class="pt-6 border-t border-slate-200 grid grid-cols-1 lg:grid-cols-5 gap-4 items-center">
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-amber-600 tracking-wider">Damaged Qty</label>
                            <input type="number" name="damaged_qty" id="damaged_qty" class="input-glass border-amber-500/20 w-full" oninput="calculateTotals()" placeholder="0">
                        </div>
                        <div class="lg:col-span-4 glass-card bg-white/60 p-4 flex flex-col sm:flex-row justify-around items-center gap-4">
                            <div class="text-center border-r border-slate-200 px-4 flex-1">
                                <p class="text-[9px] uppercase font-bold text-slate-500 mb-1">Expenses</p>
                                <p id="disp-total-expenses" class="text-base font-bold text-slate-800">Rs. 0</p>
                            </div>
                            <div class="text-center border-r border-slate-200 px-4 flex-1">
                                <p class="text-[9px] uppercase font-bold text-slate-500 mb-1">Total Qty</p>
                                <p id="disp-grand-total-qty" class="text-base font-bold text-slate-800">0</p>
                            </div>
                            <div class="text-center border-r border-slate-200 px-4 flex-1">
                                <p class="text-[9px] uppercase font-bold text-emerald-600 mb-1">Total Paid</p>
                                <p id="disp-total-paid" class="text-base font-bold text-emerald-600">Rs. 0</p>
                            </div>
                            <div class="text-center border-r border-slate-200 px-4 flex-1">
                                <p id="label-balance-due" class="text-[9px] uppercase font-bold text-rose-600 mb-1">Balance Due</p>
                                <p id="disp-balance-due" class="text-base font-bold text-rose-600">Rs. 0</p>
                            </div>
                            <div class="text-center px-4 flex-1">
                                <p class="text-[9px] uppercase font-bold text-cyan-600 mb-1">Unit Cost</p>
                                <p id="disp-per-item-cost" class="text-base font-bold text-cyan-600">Rs. 0</p>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row justify-end pt-1 gap-3">
                        <button type="button" onclick="closeModal()" class="sm:hidden order-2 bg-slate-100 text-slate-600 font-bold py-3 px-6 rounded-2xl border border-slate-200">Cancel</button>
                        <button type="submit" class="order-1 bg-gradient-to-r from-cyan-600 to-blue-700 hover:from-cyan-500 hover:to-blue-600 text-white font-bold py-3 px-8 sm:px-12 rounded-2xl shadow-xl shadow-cyan-900/10 transition-all active:scale-95 text-sm sm:text-base">
                            Save Container Record
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('modal-container');
        const itemsList = document.getElementById('items-list');
        const expensesList = document.getElementById('expenses-list');
        const addExpBtn = document.getElementById('add-exp-container');
        let currentMode = 'add';
        
        function openModal(mode = 'add') {
            currentMode = mode;
            document.getElementById('modal-title').innerText = mode === 'add' ? "Add New Container" : (mode === 'edit' ? "Edit Container" : "View Container");
            document.getElementById('container-form').reset();
            itemsList.innerHTML = '';
            expensesList.innerHTML = '';
            document.getElementById('payments-list').innerHTML = ''; // Clear payments too
            
            const submitBtn = document.querySelector('#container-form button[type="submit"]');
            const addButtons = document.querySelectorAll('.btn-freq, #add-exp-container, #btn-add-item, #add-pay-container');
            
            if (mode === 'view') {
                submitBtn.classList.add('hidden');
                addButtons.forEach(btn => btn.classList.add('hidden'));
                document.querySelectorAll('#container-form input, #container-form select, #container-form textarea').forEach(input => input.disabled = true);
            } else {
                submitBtn.classList.remove('hidden');
                submitBtn.innerText = mode === 'edit' ? "Update Container Record" : "Save Container Record";
                addButtons.forEach(btn => btn.classList.remove('hidden'));
                document.querySelectorAll('#container-form input, #container-form select, #container-form textarea').forEach(input => input.disabled = false);
                
                if (mode === 'add') {
                    fetch('?action=get_next_number')
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                document.getElementById('container_number').value = data.next;
                            }
                        });
                    addItemRow();
                    document.getElementById('container_cost').value = 0; // Default to 0 for new container
                }
            }

            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            calculateTotals();
        }

        function closeModal() {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }

        function addItemRow(data = null) {
            const rowId = Date.now() + Math.random();
            const html = `
                <div class="item-row grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 bg-white/40 p-4 rounded-xl border border-slate-200/60 relative group shadow-sm" id="item_${rowId}">
                    <div class="sm:col-span-2 lg:col-span-2 relative">
                        <label class="text-[9px] uppercase font-bold text-slate-400 mb-1 lg:hidden block">Brand Name</label>
                        <input type="text" placeholder="e.g. 18mm, 15mm" class="brand-input input-glass w-full" oninput="suggestBrands(this)" autocomplete="off" value="${data ? data.brand_name : ''}" ${currentMode === 'view' ? 'disabled' : ''}>
                        <div class="brand-suggestions absolute left-0 top-full mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl hidden overflow-hidden" style="z-index:9999"></div>
                    </div>
                    <div>
                        <label class="text-[9px] uppercase font-bold text-slate-400 mb-1 lg:hidden block">Pallets</label>
                        <input type="number" placeholder="0" class="input-glass pallets-input w-full" oninput="calculateTotals()" required value="${data ? data.pallets : ''}" ${currentMode === 'view' ? 'disabled' : ''}>
                    </div>
                    <div>
                        <label class="text-[9px] uppercase font-bold text-slate-400 mb-1 lg:hidden block">Qty/Pallet</label>
                        <input type="number" placeholder="0" class="input-glass qty-input w-full" oninput="calculateTotals()" required value="${data ? data.qty_per_pallet : ''}" ${currentMode === 'view' ? 'disabled' : ''}>
                    </div>
                    <div class="flex flex-row sm:flex-col lg:flex-col justify-between sm:justify-center items-center h-full bg-slate-50 p-2 sm:p-0 rounded-lg border border-slate-100">
                        <span class="text-[8px] uppercase text-slate-400 font-bold">Row Total</span>
                        <span class="row-total-qty font-bold text-sm text-cyan-600">${data ? (data.pallets * data.qty_per_pallet).toLocaleString() : '0'}</span>
                    </div>
                    ${currentMode !== 'view' ? `
                    <button type="button" onclick="removeRow('item_${rowId}')" class="absolute -right-2 -top-2 bg-rose-500 text-white w-7 h-7 rounded-full text-xs items-center justify-center shadow-lg opacity-100 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity flex z-10 transition-all hover:scale-110">
                        <i class="fa-solid fa-times"></i>
                    </button>` : ''}
                </div>
            `;
            itemsList.insertAdjacentHTML('beforeend', html);
        }

        function addExpenseRow(name = '', amount = '') {
            const rowId = Date.now() + Math.random();
            const html = `
                <div class="expense-row grid grid-cols-2 lg:grid-cols-3 gap-3 items-center group relative bg-white/40 p-3 rounded-xl border border-slate-200/60 shadow-sm" id="exp_${rowId}">
                    <div class="col-span-1 lg:col-span-2">
                        <input type="text" placeholder="Expense Name" class="exp-name input-glass w-full" value="${name}" ${currentMode === 'view' ? 'disabled' : ''}>
                    </div>
                    <div class="col-span-1">
                        <input type="number" step="0.01" placeholder="Amount" class="exp-amount input-glass w-full text-right" oninput="calculateTotals()" value="${amount}" ${currentMode === 'view' ? 'disabled' : ''}>
                    </div>
                    ${currentMode !== 'view' ? `
                    <button type="button" onclick="removeRow('exp_${rowId}')" class="absolute -right-2 -top-2 bg-rose-500 text-white w-6 h-6 rounded-full text-[10px] items-center justify-center flex opacity-100 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity shadow-lg hover:scale-110">
                        <i class="fa-solid fa-times"></i>
                    </button>` : ''}
                </div>
            `;
            expensesList.insertAdjacentHTML('beforeend', html);
            calculateTotals();
        }

        function addPaymentRow(data = null) {
            const rowId = 'pay_' + Date.now() + Math.random();
            const autoId = 'TX-' + Math.floor(100000 + Math.random() * 900000); // e.g. TX-859123
            const displayId = data ? data.payment_id : autoId;
            
            // Suggest balance as default if adding new
            let totalExpenses = parseFloat(document.getElementById('container_cost').value || 0);
            document.querySelectorAll('.exp-amount').forEach(el => totalExpenses += parseFloat(el.value || 0));

            let totalPaid = 0;
            document.querySelectorAll('.pay-amount').forEach(el => totalPaid += parseFloat(el.value || 0));
            
            const suggestion = Math.max(0, totalExpenses - totalPaid);
            const defaultAmt = data ? data.amount : (suggestion > 0 ? suggestion.toFixed(2) : '');

            const html = `
                <div class="payment-row grid grid-cols-1 lg:grid-cols-3 gap-2 items-center group relative bg-white/40 p-3 rounded-xl border border-slate-200/60 shadow-sm" id="${rowId}">
                    <input type="hidden" class="pay-id" value="${displayId}">
                    <div class="col-span-1">
                        <select class="pay-method input-glass w-full bg-white" ${currentMode === 'view' ? 'disabled' : ''}>
                            <option value="Cash" ${data && data.method === 'Cash' ? 'selected' : ''}>Cash</option>
                            <option value="Card" ${data && data.method === 'Card' ? 'selected' : ''}>Card</option>
                            <option value="Cheque" ${data && data.method === 'Cheque' ? 'selected' : ''}>Cheque</option>
                        </select>
                    </div>
                    <div class="col-span-1">
                        <input type="number" step="0.01" placeholder="Amount" class="pay-amount input-glass w-full text-right" oninput="calculateTotals()" value="${defaultAmt}" ${currentMode === 'view' ? 'disabled' : ''}>
                    </div>
                    <div class="col-span-1">
                        <input type="text" placeholder="Description" class="pay-type input-glass w-full" value="${data ? data.payment_type : ''}" ${currentMode === 'view' ? 'disabled' : ''}>
                    </div>
                    ${currentMode !== 'view' ? `
                    <button type="button" onclick="removeRow('${rowId}')" class="absolute -right-2 -top-2 bg-rose-500 text-white w-5 h-5 rounded-full text-[8px] items-center justify-center flex opacity-100 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity shadow-lg hover:scale-110">
                        <i class="fa-solid fa-times"></i>
                    </button>` : ''}
                </div>
            `;
            const container = document.getElementById('payments-list');
            container.insertAdjacentHTML('beforeend', html);
            calculateTotals();
        }

        function addFreqExpense(name) {
            addExpenseRow(name);
        }

        function removeRow(id) {
            document.getElementById(id).remove();
            calculateTotals();
        }

        function suggestBrands(input) {
            const row = input.closest('.item-row');
            const suggestionsDiv = row.querySelector('.brand-suggestions');
            const term = input.value.trim();

            if (term.length < 1) {
                suggestionsDiv.classList.add('hidden');
                return;
            }

            fetch(`?action=search_brand&term=${encodeURIComponent(term)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.length > 0) {
                        suggestionsDiv.innerHTML = data.map(name => `
                            <div class="px-3 py-2 text-sm text-slate-300 hover:bg-purple-500/20 hover:text-white cursor-pointer border-b border-white/5 last:border-0 transition-colors flex items-center gap-2"
                                 onmousedown="selectBrand(this, '${name.replace(/'/g, "\\'")}')"
                            >
                                <i class="fa-solid fa-tag text-[9px] text-purple-400"></i>
                                ${name}
                            </div>`).join('');
                        suggestionsDiv.classList.remove('hidden');
                    } else {
                        suggestionsDiv.innerHTML = `<div class="px-3 py-2 text-xs text-slate-600 italic">No existing brands matched — new brand will be created.</div>`;
                        suggestionsDiv.classList.remove('hidden');
                    }
                });
        }

        function selectBrand(el, name) {
            const input = el.closest('.item-row').querySelector('.brand-input');
            input.value = name;
            el.parentElement.classList.add('hidden');
        }

        // Hide all brand suggestion dropdowns on outside click
        document.addEventListener('click', (e) => {
            if (!e.target.classList.contains('brand-input')) {
                document.querySelectorAll('.brand-suggestions').forEach(d => d.classList.add('hidden'));
            }
        });

        function calculateTotals() {
            let totalExpenses = parseFloat(document.getElementById('container_cost').value || 0);
            document.querySelectorAll('.exp-amount').forEach(el => totalExpenses += parseFloat(el.value || 0));

            let totalPaid = 0;
            document.querySelectorAll('.pay-amount').forEach(el => totalPaid += parseFloat(el.value || 0));
            
            // Prevention of overpayment (Robust)
            if (totalPaid > totalExpenses) {
                const activeEl = document.activeElement;
                if (activeEl && activeEl.classList.contains('pay-amount')) {
                    // Adjust the one being typed in
                    const othersPaid = totalPaid - parseFloat(activeEl.value || 0);
                    const allowed = Math.max(0, totalExpenses - othersPaid);
                    activeEl.value = allowed.toFixed(2);
                } else {
                    // Adjust the last payment row if someone changed the cost/expenses
                    const payRows = document.querySelectorAll('.pay-amount');
                    if (payRows.length > 0) {
                        let runningTotal = 0;
                        payRows.forEach((input, index) => {
                            if (index === payRows.length - 1) {
                                input.value = Math.max(0, totalExpenses - runningTotal).toFixed(2);
                            } else {
                                runningTotal += parseFloat(input.value || 0);
                            }
                        });
                    }
                }
                // Re-calculate totalPaid after adjustments
                totalPaid = 0;
                document.querySelectorAll('.pay-amount').forEach(el => totalPaid += parseFloat(el.value || 0));
            }

            let totalQty = 0;
            document.querySelectorAll('.item-row').forEach(row => {
                const p = parseInt(row.querySelector('.pallets-input').value || 0);
                const q = parseInt(row.querySelector('.qty-input').value || 0);
                const rowTotal = p * q;
                row.querySelector('.row-total-qty').innerText = rowTotal.toLocaleString();
                totalQty += rowTotal;
            });

            const damagedQty = parseInt(document.getElementById('damaged_qty').value || 0);
            const netQty = totalQty - damagedQty;
            const perItemCost = (netQty > 0) ? (totalExpenses / netQty) : 0;
            const balanceDue = Math.max(0, totalExpenses - totalPaid);

            document.getElementById('disp-total-expenses').innerText = "Rs. " + totalExpenses.toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('pay-header-total').innerText = "Rs. " + totalExpenses.toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('disp-grand-total-qty').innerText = netQty.toLocaleString();
            document.getElementById('disp-per-item-cost').innerText = "Rs. " + perItemCost.toLocaleString('en-US', {minimumFractionDigits: 2});
            
            const dispPaid = document.getElementById('disp-total-paid');
            const dispBalance = document.getElementById('disp-balance-due');
            if (dispPaid) dispPaid.innerText = "Rs. " + totalPaid.toLocaleString('en-US', {minimumFractionDigits: 2});
            if (dispBalance) {
                dispBalance.innerText = "Rs. " + balanceDue.toLocaleString('en-US', {minimumFractionDigits: 2});
                dispBalance.classList.toggle('text-rose-400', balanceDue > 0);
                dispBalance.classList.toggle('text-emerald-400', balanceDue === 0);
                document.getElementById('label-balance-due').innerText = "Balance Due";
            }
        }

        function saveContainer(e) {
            e.preventDefault();

            // Guard: Prevent overpayment before submission
            let totalExp = parseFloat(document.getElementById('container_cost').value || 0);
            document.querySelectorAll('.exp-amount').forEach(el => totalExp += parseFloat(el.value || 0));
            let totalPd = 0;
            document.querySelectorAll('.pay-amount').forEach(el => totalPd += parseFloat(el.value || 0));
            if (totalPd > totalExp) {
                alert(`Error: Total payments (Rs. ${totalPd.toLocaleString()}) exceed total expenses (Rs. ${totalExp.toLocaleString()}). Please adjust before saving.`);
                return;
            }

            const formData = new FormData();
            formData.append('action', 'save_container');
            formData.append('container_number', document.getElementById('container_number').value);
            formData.append('arrival_date', document.getElementById('arrival_date').value);
            formData.append('country', document.getElementById('country').value);
            formData.append('damaged_qty', document.getElementById('damaged_qty').value);
            formData.append('container_cost', document.getElementById('container_cost').value);

            const items = [];
            document.querySelectorAll('.item-row').forEach(row => {
                items.push({
                    brand: row.querySelector('.brand-input').value,
                    pallets: row.querySelector('.pallets-input').value,
                    qty_per_pallet: row.querySelector('.qty-input').value
                });
            });
            formData.append('items', JSON.stringify(items));

            const expenses = [];
            document.querySelectorAll('.expense-row').forEach(row => {
                expenses.push({
                    name: row.querySelector('.exp-name').value,
                    amount: row.querySelector('.exp-amount').value
                });
            });
            formData.append('expenses', JSON.stringify(expenses));

            const payments = [];
            document.querySelectorAll('.payment-row').forEach(row => {
                payments.push({
                    payment_id: row.querySelector('.pay-id').value,
                    method: row.querySelector('.pay-method').value,
                    amount: row.querySelector('.pay-amount').value,
                    type: row.querySelector('.pay-type').value,
                    desc: '' // Included in type field for simplicity in UI
                });
            });
            formData.append('payments', JSON.stringify(payments));

            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert("Error: " + data.message);
                    }
                });
        }

        function viewContainer(no) {
            fetch(`?action=get_details&container_number=${no}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        openModal('view');
                        populateForm(res.data);
                    } else {
                        alert(res.message);
                    }
                });
        }

        function editContainer(no) {
            fetch(`?action=get_details&container_number=${no}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        openModal('edit');
                        populateForm(res.data);
                    } else {
                        alert(res.message);
                    }
                });
        }

        function populateForm(data) {
            document.getElementById('modal-title').innerText = 'Edit Container: ' + data.container_number;
            document.getElementById('container_number').value = data.container_number;
            document.getElementById('arrival_date').value = data.arrival_date;
            document.getElementById('country').value = data.country || '';
            document.getElementById('damaged_qty').value = data.damaged_qty;
            document.getElementById('container_cost').value = data.container_cost;
            
            itemsList.innerHTML = '';
            data.items.forEach(item => addItemRow(item));
            
            expensesList.innerHTML = '';
            data.expenses.forEach(exp => addExpenseRow(exp.expense_name, exp.amount));

            const paymentsList = document.getElementById('payments-list');
            if (paymentsList) {
                paymentsList.innerHTML = '';
                if (data.payments) data.payments.forEach(pay => addPaymentRow(pay));
            }
            
            calculateTotals();
        }

        function deleteContainer(id, no) {
            if (confirm(`Are you sure you want to delete container ${no}? All associated items and expenses will be removed permanently.`)) {
                const formData = new FormData();
                formData.append('action', 'delete_container');
                formData.append('container_id', id);
                
                fetch('', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        } else {
                            alert("Error: " + data.message);
                        }
                    });
            }
        }

        // Auto-search Debounce
        let searchTimeout;
        document.querySelector('.auto-search').addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 600);
        });

        // Set cursor to end of search input if focused
        const searchInput = document.querySelector('.auto-search');
        if (searchInput === document.activeElement) {
            const val = searchInput.value;
            searchInput.value = '';
            searchInput.value = val;
        }
    </script>
</body>
</html>
