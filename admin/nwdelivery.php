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
    $term = $_GET['term'] ?? '';
    if (empty($term)) {
        // Show top 3 by quantity if no term provided
        $stmt = $pdo->prepare("
            SELECT b.id as brand_id, b.name as brand_name, 
                   ci.id as item_id, ci.total_qty, ci.sold_qty, (ci.total_qty - ci.sold_qty) as available_qty,
                   c.container_number, c.country, c.arrival_date, c.per_item_cost
            FROM container_items ci
            JOIN brands b ON ci.brand_id = b.id
            JOIN containers c ON ci.container_id = c.id
            WHERE (ci.total_qty - ci.sold_qty) > 0
            ORDER BY available_qty DESC
            LIMIT 3
        ");
        $stmt->execute();
    } else {
        $termParam = '%' . $term . '%';
        $stmt = $pdo->prepare("
            SELECT b.id as brand_id, b.name as brand_name, 
                   ci.id as item_id, ci.total_qty, ci.sold_qty, (ci.total_qty - ci.sold_qty) as available_qty,
                   c.container_number, c.country, c.arrival_date, c.per_item_cost
            FROM container_items ci
            JOIN brands b ON ci.brand_id = b.id
            JOIN containers c ON ci.container_id = c.id
            WHERE b.name LIKE ? AND (ci.total_qty - ci.sold_qty) > 0
            ORDER BY available_qty DESC
            LIMIT 10
        ");
        $stmt->execute([$termParam]);
    }
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action == 'create_employee') {
    $name = $_POST['name'];
    $contact = $_POST['contact'];
    $monthly_salary = (float)($_POST['salary'] ?? 0);
    // No longer requiring username/password for staff as only admins have system access.
    $stmt = $pdo->prepare("INSERT INTO users (full_name, contact_number, role) VALUES (?, ?, 'employee')");
    $stmt->execute([$name, $contact]);
    $new_id = $pdo->lastInsertId();
    
    if ($monthly_salary > 0) {
        $stmtSal = $pdo->prepare("INSERT INTO employee_salary_settings (user_id, monthly_salary) VALUES (?, ?)");
        $stmtSal->execute([$new_id, $monthly_salary]);
    }
    
    echo json_encode(['success' => true, 'id' => $new_id, 'name' => $name, 'pic' => null]);
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
        $editing_id = $_POST['editing_id'] ?? null;
        $employees = json_decode($_POST['employees'], true) ?? [];
        $expenses  = json_decode($_POST['expenses'],  true) ?? [];
        $customers = json_decode($_POST['customers'], true) ?? [];
        if (empty($customers) || empty($employees)) throw new Exception("Need at least one employee and one customer.");

        if ($editing_id) {
            // Revert old stock before deleting old items
            $old_items = $pdo->prepare("SELECT di.container_item_id, di.qty FROM delivery_items di JOIN delivery_customers dc ON di.delivery_customer_id = dc.id WHERE dc.delivery_id = ?");
            $old_items->execute([$editing_id]);
            foreach ($old_items->fetchAll() as $it) {
                $pdo->prepare("UPDATE container_items SET sold_qty = GREATEST(0, sold_qty - ?) WHERE id = ?")->execute([$it['qty'], $it['container_item_id']]);
            }
            
            // Delete associated items and employees to refresh them
            $pdo->prepare("DELETE de FROM delivery_items de JOIN delivery_customers dc ON de.delivery_customer_id = dc.id WHERE dc.delivery_id = ?")->execute([$editing_id]);
            $pdo->prepare("DELETE FROM delivery_employees WHERE delivery_id = ?")->execute([$editing_id]);
            $pdo->prepare("DELETE FROM delivery_expenses WHERE delivery_id = ?")->execute([$editing_id]);
            
            // Collect currently submitted dc_ids
            $submitted_dc_ids = [];
            foreach ($customers as $c) {
                if (!empty($c['dc_id'])) $submitted_dc_ids[] = (int)$c['dc_id'];
            }
            
            // Remove customers that are NOT in the current submit list
            if ($submitted_dc_ids) {
                $placeholders = implode(',', array_fill(0, count($submitted_dc_ids), '?'));
                $pdo->prepare("DELETE FROM delivery_customers WHERE delivery_id = ? AND id NOT IN ($placeholders)")->execute(array_merge([$editing_id], $submitted_dc_ids));
            } else {
                $pdo->prepare("DELETE FROM delivery_customers WHERE delivery_id = ?")->execute([$editing_id]);
            }
            
            $delivery_id = $editing_id;
            $pdo->prepare("UPDATE deliveries SET delivery_date = ?, total_expenses = ?, total_sales = '0.00' WHERE id = ?")
                ->execute([$delivery_date, 0, $delivery_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO deliveries (delivery_date, total_expenses, total_sales, created_by) VALUES (?, '0.00', '0.00', ?)");
            $stmt->execute([$delivery_date, $user_id]);
            $delivery_id = $pdo->lastInsertId();
        }

        $total_expenses = 0;
        foreach ($expenses as $exp) {
            $total_expenses += (float)$exp['amount'];
            $pdo->prepare("INSERT INTO delivery_expenses (delivery_id, expense_name, amount) VALUES (?, ?, ?)")->execute([$delivery_id, $exp['name'], $exp['amount']]);
        }
        
        foreach ($employees as $emp_id) {
            $pdo->prepare("INSERT INTO delivery_employees (delivery_id, user_id) VALUES (?, ?)")->execute([$delivery_id, $emp_id]);
        }

        $grand_total_sales = 0;
        foreach ($customers as $index => $c) {
            $customer_subtotal = 0;
            $customer_discount = 0;
            $dc_id = $c['dc_id'] ?? null;
            
            if ($dc_id) {
                $pdo->prepare("UPDATE delivery_customers SET customer_id = ?, subtotal = 0.00, discount = 0.00 WHERE id = ?")
                    ->execute([$c['customer_id'], $dc_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO delivery_customers (delivery_id, customer_id, subtotal, discount) VALUES (?, ?, 0.00, 0.00)");
                $stmt->execute([$delivery_id, $c['customer_id']]);
                $dc_id = $pdo->lastInsertId();
            }

            // Sync Accumulated Proofs (Both New & Existing)
            if ($dc_id && isset($c['existing_bills'])) {
                $kept_bills = is_array($c['existing_bills']) ? $c['existing_bills'] : [];
                
                // 1. Delete ones removed by user
                $delQ = "DELETE FROM delivery_proof_photos WHERE delivery_customer_id = ?";
                if (!empty($kept_bills)) {
                    $delQ .= " AND photo_path NOT IN (" . implode(',', array_fill(0, count($kept_bills), '?')) . ")";
                }
                $pdo->prepare($delQ)->execute(array_merge([$dc_id], $kept_bills));
                
                // 2. Insert any newly uploaded ones not yet in DB for this specific dc_id
                $stmtCheck = $pdo->prepare("SELECT id FROM delivery_proof_photos WHERE delivery_customer_id = ? AND photo_path = ?");
                $stmtInsert = $pdo->prepare("INSERT INTO delivery_proof_photos (delivery_customer_id, photo_path, uploaded_by) VALUES (?, ?, ?)");
                foreach($kept_bills as $billFile) {
                    $stmtCheck->execute([$dc_id, $billFile]);
                    if(!$stmtCheck->fetch()) {
                        $stmtInsert->execute([$dc_id, $billFile, $user_id]);
                    }
                }
            }

            foreach ($c['items'] as $item) {
                $qty = (int)$item['qty'];
                $dmg = (int)($item['damaged_qty'] ?? 0);
                $disc = (float)($item['discount'] ?? 0);
                $line_total = ($qty - $dmg) * (float)$item['selling_price'] - $disc;
                $customer_subtotal += $line_total;
                $customer_discount += $disc;
                
                $stmtStock = $pdo->prepare("UPDATE container_items SET sold_qty = sold_qty + ? WHERE id = ? AND (total_qty - sold_qty) >= ?");
                $stmtStock->execute([$qty, $item['item_id'], $qty]);
                if ($stmtStock->rowCount() === 0) {
                    throw new Exception("Insufficient stock for product. Please check availability.");
                }

                $pdo->prepare("INSERT INTO delivery_items (delivery_customer_id, container_item_id, qty, damaged_qty, cost_price, selling_price, total) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$dc_id, $item['item_id'], $qty, $dmg, (float)$item['cost_price'], (float)$item['selling_price'], $line_total]);
            }
            $pdo->prepare("UPDATE delivery_customers SET subtotal = ?, discount = ? WHERE id = ?")->execute([$customer_subtotal, $customer_discount, $dc_id]);
            $grand_total_sales += $customer_subtotal;
        }
        
        $pdo->prepare("UPDATE deliveries SET total_sales = ?, total_expenses = ? WHERE id = ?")->execute([$grand_total_sales, $total_expenses, $delivery_id]);
        $pdo->prepare("INSERT INTO delivery_ledger (delivery_id, action_type, notes, performed_by) VALUES (?, ?, ?, ?)")
            ->execute([$delivery_id, $editing_id ? 'EDITED' : 'CREATED', "Route details ".($editing_id ? 'modified' : 'started')." for {$delivery_date}.", $user_id]);
        
        $pdo->commit();
        echo json_encode(['success' => true, 'delivery_id' => $delivery_id]);
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

if ($action == 'view_delivery') {
    try {
        $id = (int)($_GET['id'] ?? 0);
        $del = $pdo->prepare("SELECT d.*, u.full_name as created_by_name FROM deliveries d JOIN users u ON d.created_by = u.id WHERE d.id = ?");
        $del->execute([$id]);
        $delivery = $del->fetch(PDO::FETCH_ASSOC);
        if (!$delivery) { echo json_encode(['success' => false, 'message' => 'Not found']); exit; }

        $emps = $pdo->prepare("SELECT u.id, u.full_name, u.contact_number FROM delivery_employees de JOIN users u ON de.user_id = u.id WHERE de.delivery_id = ?");
        $emps->execute([$id]);
        $delivery['employees'] = $emps->fetchAll(PDO::FETCH_ASSOC);

        $exps = $pdo->prepare("SELECT expense_name, amount FROM delivery_expenses WHERE delivery_id = ? ORDER BY id");
        $exps->execute([$id]);
        $delivery['expenses'] = $exps->fetchAll(PDO::FETCH_ASSOC);

        $custs = $pdo->prepare("SELECT dc.id, dc.customer_id, dc.subtotal, dc.discount, dc.status, dc.payment_status, c.name, c.contact_number, c.address FROM delivery_customers dc JOIN customers c ON dc.customer_id = c.id WHERE dc.delivery_id = ? ORDER BY dc.id");
        $custs->execute([$id]);
        $custRows = $custs->fetchAll(PDO::FETCH_ASSOC);

        foreach ($custRows as &$cr) {
            $items = $pdo->prepare("SELECT di.*, b.name as brand_name, c.container_number, (ci.total_qty - ci.sold_qty) as available_qty FROM delivery_items di JOIN container_items ci ON di.container_item_id = ci.id JOIN brands b ON ci.brand_id = b.id JOIN containers c ON ci.container_id = c.id WHERE di.delivery_customer_id = ?");
            $items->execute([$cr['id']]);
            $cr['items'] = $items->fetchAll(PDO::FETCH_ASSOC);

            $payments = $pdo->prepare("SELECT dp.*, b.name as bank_name, b.account_number as bank_acc, cust.name as cheque_payer FROM delivery_payments dp LEFT JOIN banks b ON dp.bank_id = b.id LEFT JOIN customers cust ON dp.cheque_customer_id = cust.id WHERE dp.delivery_customer_id = ? ORDER BY dp.payment_date DESC");
            $payments->execute([$cr['id']]);
            $cr['payments'] = $payments->fetchAll(PDO::FETCH_ASSOC);
            $cr['total_paid'] = array_sum(array_column($cr['payments'], 'amount'));

            $stmtPhotos = $pdo->prepare("SELECT photo_path FROM delivery_proof_photos WHERE delivery_customer_id = ?");
            $stmtPhotos->execute([$cr['id']]);
            $cr['proof_photos'] = $stmtPhotos->fetchAll(PDO::FETCH_COLUMN);
        }
        unset($cr);

        $delivery['customers'] = $custRows;
        echo json_encode(['success' => true, 'data' => $delivery]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action == 'upload_proof_instant') {
    try {
        if (!is_dir('../uploads/bills')) mkdir('../uploads/bills', 0777, true);
        $filenames = [];
        if (isset($_FILES['instant_bills'])) {
            $count = count($_FILES['instant_bills']['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($_FILES['instant_bills']['error'][$i] === UPLOAD_ERR_OK) {
                    $filename = time() . '_' . rand(1000, 9999) . '_proof_' . preg_replace("/[^a-zA-Z0-9.\-]/", "", $_FILES['instant_bills']['name'][$i]);
                    if (move_uploaded_file($_FILES['instant_bills']['tmp_name'][$i], '../uploads/bills/' . $filename)) {
                        $filenames[] = $filename;
                    }
                }
            }
        }
        echo json_encode(['success' => true, 'filenames' => $filenames]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

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
        
        // Update customer payment status if fully paid
        $stmt = $pdo->prepare("SELECT dc.subtotal, dc.discount, (SELECT SUM(amount) FROM delivery_payments WHERE delivery_customer_id = dc.id) as total_paid FROM delivery_customers dc WHERE dc.id = ?");
        $stmt->execute([$dc_id]);
        $status = $stmt->fetch();
        if ($status) {
            $new_status = ($status['total_paid'] >= ($status['subtotal'] - $status['discount'])) ? 'completed' : 'pending';
            $pdo->prepare("UPDATE delivery_customers SET payment_status = ? WHERE id = ?")->execute([$new_status, $dc_id]);
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

if ($action == 'delete_payment') {
    try {
        $pdo->beginTransaction();
        $pay_id = (int)$_POST['id'];
        $reason = $_POST['reason'] ?: 'No reason provided';
        
        $stmt = $pdo->prepare("SELECT dp.*, dc.delivery_id FROM delivery_payments dp JOIN delivery_customers dc ON dp.delivery_customer_id = dc.id WHERE dp.id = ?");
        $stmt->execute([$pay_id]);
        $pay = $stmt->fetch();
        if (!$pay) throw new Exception("Payment record not found.");
        
        $dc_id = $pay['delivery_customer_id'];
        $delivery_id = $pay['delivery_id'];
        $amount = $pay['amount'];
        
        $pdo->prepare("DELETE FROM delivery_payments WHERE id = ?")->execute([$pay_id]);
        
        $stmt = $pdo->prepare("SELECT dc.subtotal, dc.discount, (SELECT SUM(amount) FROM delivery_payments WHERE delivery_customer_id = dc.id) as total_paid FROM delivery_customers dc WHERE dc.id = ?");
        $stmt->execute([$dc_id]);
        $status = $stmt->fetch();
        if ($status) {
            $new_status = ($status['total_paid'] >= ($status['subtotal'] - $status['discount'])) ? 'completed' : 'pending';
            $pdo->prepare("UPDATE delivery_customers SET payment_status = ? WHERE id = ?")->execute([$new_status, $dc_id]);
        }
        
        $pdo->prepare("INSERT INTO delivery_ledger (delivery_id, action_type, notes, performed_by) VALUES (?, 'PAYMENT_DELETED', ?, ?)")
            ->execute([$delivery_id, "Payment of LKR {$amount} deleted. Reason: {$reason}", $user_id]);
            
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
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
$pay_status = $_GET['pay_status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 8;
$offset = ($page - 1) * $limit;
$where = [];
$params = [];
if ($search) { $where[] = "d.id LIKE ?"; $params[] = "%$search%"; }
if ($start_date) { $where[] = "d.delivery_date >= ?"; $params[] = $start_date; }
if ($end_date) { $where[] = "d.delivery_date <= ?"; $params[] = $end_date; }
$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

$tWhere = [];
if ($pay_status == 'complete') {
    $tWhere[] = "got_payments >= total_revenue AND total_revenue > 0";
} elseif ($pay_status == 'pending') {
    $tWhere[] = "(got_payments < total_revenue OR total_revenue = 0)";
}
$tWhereClause = $tWhere ? "WHERE " . implode(" AND ", $tWhere) : "";

$fullQuery = "SELECT * FROM (
    SELECT d.*, 
    (SELECT COUNT(*) FROM delivery_customers WHERE delivery_id = d.id) as customer_count,
    (SELECT GROUP_CONCAT(u.full_name SEPARATOR ', ') FROM delivery_employees de JOIN users u ON de.user_id = u.id WHERE de.delivery_id = d.id) as employee_names,
    (SELECT IFNULL(SUM(subtotal - discount), 0) FROM delivery_customers WHERE delivery_id = d.id) as total_revenue,
    (SELECT IFNULL(SUM(amount), 0) FROM delivery_payments dp JOIN delivery_customers dc ON dp.delivery_customer_id = dc.id WHERE dc.delivery_id = d.id) as got_payments,
    (d.total_sales - d.total_expenses - IFNULL((SELECT SUM(di.qty * di.cost_price) FROM delivery_items di JOIN delivery_customers dc ON di.delivery_customer_id = dc.id WHERE dc.delivery_id = d.id), 0)) as est_profit
    FROM deliveries d $whereClause
) as t $tWhereClause";

// Redoing record count correctly with filtering
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM ($fullQuery) as f");
$stmtCount->execute($params);
$total_records = (int)$stmtCount->fetchColumn();
$total_pages = max(1, ceil($total_records / $limit));

$query = "$fullQuery ORDER BY id DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$deliveries = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Operations | Crystal POS</title>
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
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
        }

        .input-glass:focus {
            border-color: #0891b2;
            background: white;
            box-shadow: 0 0 15px rgba(8, 145, 178, 0.08);
        }

        .table-header {
            background: #1e293b;
            color: white;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

        #customer_blocks .glass-card:focus-within { z-index: 100 !important; position: relative; }
        
        #customer_blocks .order-items .grid:focus-within { z-index: 110 !important; position: relative; }
        
        .brand-results, .customer-results, #emp_results, #bank_results, #chq_cust_results {
            background-color: white !important;
            opacity: 1 !important;
        }

        .brand-results div, .customer-results div, #emp_results div, #bank_results div, #chq_cust_results div {
            padding: 12px 16px;
            transition: all 0.2s;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            position: relative;
            z-index: 120 !important;
        }
        
        .brand-results div:hover, .customer-results div:hover, #emp_results div:hover, #bank_results div:hover, #chq_cust_results div:hover {
            background-color: #f8fafc;
            color: #0891b2;
        }

        .qty-warning {
            border-color: #ef4444 !important;
            background-color: #fef2f2 !important;
            color: #b91c1c !important;
            box-shadow: 0 0 10px rgba(239, 68, 68, 0.1) !important;
        }
    </style>
</head>
<body class="flex flex-col">

    <header class="glass-header sticky top-0 z-40 py-4">
        <div class="px-3 md:px-6 flex items-center justify-between">
            <div class="flex items-center space-x-3 md:space-x-5">
                <a href="dashboard.php" class="text-slate-800 hover:text-cyan-600 transition-colors p-2 md:p-2.5 rounded-2xl hover:bg-slate-100">
                    <i class="fa-solid fa-arrow-left text-lg md:text-xl"></i>
                </a>
                <div>
                    <h1 class="text-xl md:text-2xl font-black text-slate-900 font-['Outfit'] tracking-tight">Deliveries</h1>
                    <p class="hidden md:block text-[10px] uppercase font-black text-slate-400 tracking-widest mt-0.5">Delivery & Logistics Tracker</p>
                </div>
            </div>
            
            <button onclick="openModal()" class="bg-slate-900 hover:bg-black text-white px-4 md:px-6 py-2.5 md:py-3 rounded-xl md:rounded-2xl font-bold text-[10px] md:text-xs uppercase tracking-widest transition-all shadow-xl shadow-slate-900/20 flex items-center gap-2 md:gap-3">
                <div class="w-5 h-5 md:w-6 md:h-6 bg-slate-700/50 rounded-lg flex items-center justify-center border border-slate-600">
                    <i class="fa-solid fa-plus text-[8px] md:text-[10px]"></i>
                </div>
                <span class="hidden sm:inline">New Delivery</span>
                <span class="sm:hidden">New</span>
            </button>
        </div>
    </header>

    <main class="w-full px-6 py-10">

        <!-- Filters -->
        <div class="glass-card p-6 mb-8 border-slate-200/50">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-6 items-end" id="filterForm">
                <div class="md:col-span-5 relative">
                    <label class="text-[10px] uppercase font-black text-slate-600 mb-2 ml-1 block tracking-widest">Search ID</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Enter Trip ID..." class="input-glass w-full h-[48px]" onchange="this.form.submit()">
                </div>
                <div class="md:col-span-2">
                    <label class="text-[10px] uppercase font-black text-slate-600 mb-2 ml-1 block tracking-widest">Start Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="input-glass w-full h-[48px]" onchange="this.form.submit()">
                </div>
                <div class="md:col-span-2">
                    <label class="text-[10px] uppercase font-black text-slate-600 mb-2 ml-1 block tracking-widest">End Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="input-glass w-full h-[48px]" onchange="this.form.submit()">
                </div>
                <div class="md:col-span-2">
                    <label class="text-[10px] uppercase font-black text-slate-600 mb-2 ml-1 block tracking-widest">Pay Status</label>
                    <select name="pay_status" class="input-glass w-full h-[48px] appearance-none cursor-pointer" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="complete" <?php echo $pay_status == 'complete' ? 'selected' : ''; ?>>Complete</option>
                        <option value="pending" <?php echo $pay_status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    </select>
                </div>
                <div class="md:col-span-1">
                    <a href="nwdelivery.php" class="w-full h-[48px] bg-rose-50 text-rose-500 rounded-2xl hover:bg-rose-100 transition-all flex items-center justify-center border border-rose-200" title="Reset Filters">
                        <i class="fa-solid fa-rotate-right"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Data Table -->
        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="table-header">
                            <th class="px-5 py-3.5">DELIVERY ID</th>
                            <th class="px-5 py-3.5">DATE</th>
                            <th class="px-5 py-3.5">ASSIGNED STAFF</th>

                            <th class="px-5 py-3.5">EXPENSES</th>
                            <th class="px-5 py-3.5">REVENUE</th>
                            <th class="px-5 py-3.5">GOT PAYMENTS</th>
                            <th class="px-5 py-3.5">EST. PROFIT</th>
                            <th class="px-5 py-3.5 text-center">SETTLE</th>
                            <th class="px-5 py-3.5 text-center">STATUS</th>
                            <th class="px-5 py-3.5 text-right">ACTION</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach($deliveries as $d): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="px-5 py-3.5 font-black text-indigo-600 text-[11px] text-nowrap">#DEL-<?php echo str_pad($d['id'], 4, '0', STR_PAD_LEFT); ?></td>
                            <td class="px-5 py-3.5 font-bold text-slate-700 text-[12px]"><?php echo date('M d, Y', strtotime($d['delivery_date'])); ?></td>
                            <td class="px-5 py-3.5">
                                <p class="text-[11px] font-bold text-slate-800 leading-tight uppercase tracking-tighter"><?php echo htmlspecialchars($d['employee_names'] ?: 'Unassigned'); ?></p>
                            </td>

                            <td class="px-5 py-3.5 font-bold text-rose-600 text-[12px]">LKR <?php echo number_format($d['total_expenses'], 2); ?></td>
                            <td class="px-5 py-3.5 font-black text-emerald-600 text-[12px] text-nowrap">
                                LKR <?php echo number_format($d['total_revenue'], 2); ?>
                            </td>
                            <td class="px-5 py-3.5 font-black text-emerald-700 text-[12px] text-nowrap">
                                LKR <?php echo number_format($d['got_payments'], 2); ?>
                            </td>
                            <td class="px-5 py-3.5 font-black text-[12px] <?php echo $d['est_profit'] >= 0 ? 'text-indigo-600' : 'text-rose-600'; ?>">
                                LKR <?php echo number_format($d['est_profit'], 2); ?>
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                <button onclick="openPaymentsModal(<?php echo $d['id']; ?>)" class="bg-indigo-600 hover:bg-black text-white px-3.5 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest shadow-lg shadow-indigo-600/10 transition-all">
                                    <i class="fa-solid fa-receipt mr-1"></i> Payments
                                </button>
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                <?php 
                                $isPaid = ($d['got_payments'] >= $d['total_revenue'] && $d['total_revenue'] > 0);
                                ?>
                                <p class="px-2.5 py-1 rounded-full text-[9px] font-black uppercase tracking-wider inline-block <?php echo $isPaid ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'; ?>">
                                    <?php echo $isPaid ? 'complete' : 'pending'; ?>
                                </p>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                    <a href="del_details.php?id=<?php echo $d['id']; ?>" class="bg-slate-900 hover:bg-black text-white px-3.5 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all shadow-lg">
                                        View
                                    </a>
                                    <button onclick="openEditModal(<?php echo $d['id']; ?>)" class="bg-emerald-600 hover:bg-black text-white px-3.5 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all shadow-lg shadow-emerald-600/10">
                                        Edit
                                    </button>
                                    <button onclick="confirmDeleteTrip(<?php echo $d['id']; ?>)" class="bg-rose-600 hover:bg-black text-white px-3.5 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all shadow-lg shadow-rose-600/10">
                                        Delete
                                    </button>
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
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page-1])); ?>" class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold hover:bg-slate-50">Prev</a>
                    <?php endif; ?>
                    <?php if($page < $total_pages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page+1])); ?>" class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold hover:bg-slate-50">Next</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal: Start New Route -->
    <div id="route-modal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="glass-card w-full max-w-5xl max-h-[90vh] flex flex-col overflow-hidden shadow-2xl">
            <div class="p-6 border-b border-white/40 flex items-center justify-between bg-white/20">
                <h3 id="modal-title" class="text-xl font-black font-['Outfit'] text-slate-900">Initialize Delivery Supply</h3>
                <button onclick="closeModal()" class="text-slate-500 hover:text-slate-800"><i class="fa-solid fa-times text-xl"></i></button>
            </div>
            
            <div class="overflow-y-auto p-5 md:p-6 pb-40 space-y-5 custom-scroll">
                <form id="route-form" class="space-y-5">
                    <!-- Base Details -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="text-[10px] uppercase font-black text-slate-700 mb-1.5 ml-1 block tracking-[0.2em]">Scheduled Delivery Date</label>
                            <div class="relative">
                                <i class="fa-solid fa-calendar-day absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-[10px]"></i>
                                <input type="date" id="delivery_date" class="input-glass w-full h-[38px] pl-9 text-xs font-bold text-slate-800" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        <div class="col-span-2 relative">
                            <label class="text-[10px] uppercase font-black text-slate-700 mb-1.5 ml-1 block tracking-[0.2em]">Personnel Assignment</label>
                            <div class="flex items-start gap-4">
                                <div class="relative w-[300px]">
                                    <i class="fa-solid fa-user-plus absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-[10px]"></i>
                                    <input type="text" id="emp_search" placeholder="Search staff members..." class="input-glass w-full h-[38px] pl-9 text-xs font-bold text-slate-800" onkeyup="searchEmployees(this.value)">
                                    <div id="emp_results" class="absolute w-full mt-1 bg-white border border-slate-200 rounded-xl shadow-2xl z-[100] hidden overflow-hidden"></div>
                                </div>
                                <div id="assigned_staff" class="flex flex-wrap gap-2 flex-1"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Expenses Panel -->
                    <div class="p-4 bg-white/40 rounded-[1.5rem] border border-white/60">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-[11px] uppercase font-black text-slate-600 tracking-widest">Delivery Expenses</h4>
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
                            <h4 class="text-[11px] uppercase font-black text-slate-600 tracking-widest">Customer Delivery Queue</h4>
                            <button type="button" onclick="addCustomerBlock()" class="bg-indigo-600 text-white px-5 py-2 rounded-xl font-bold text-[10px] uppercase tracking-widest shadow-lg shadow-indigo-600/20">Add Customer Order</button>
                        </div>
                        <div id="customer_blocks" class="space-y-4"></div>
                    </div>
                </form>
            </div>

            <div class="p-6 border-t border-white/40 flex items-center justify-between bg-white/40">
                <div class="flex space-x-10">
                    <div>
                        <p class="text-[10px] uppercase font-black text-slate-600 tracking-widest mb-1">Delivery Expenses</p>
                        <p id="total_expenses_display" class="text-2xl font-black text-rose-600 tracking-tighter">LKR 0.00</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-black text-slate-600 tracking-widest mb-1">Estimated Revenue</p>
                        <p id="total_sales_display" class="text-2xl font-black text-emerald-600 tracking-tighter">LKR 0.00</p>
                    </div>
                    <div class="border-l-2 border-slate-200 pl-10">
                        <p class="text-[10px] uppercase font-black text-indigo-700 tracking-widest mb-1">Est. Delivery Profit</p>
                        <p id="total_profit_display" class="text-2xl font-black text-indigo-600 tracking-tighter">LKR 0.00</p>
                    </div>
                </div>
                <div class="flex space-x-3">
                    <button onclick="closeModal()" class="px-6 py-3 font-bold text-rose-400 hover:text-rose-600 uppercase text-[10px] tracking-widest">Discard Changes</button>
                    <button onclick="processRouteSave()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-8 py-3.5 rounded-2xl font-black text-xs uppercase tracking-widest shadow-2xl shadow-indigo-600/30 transition-all active:scale-95 flex items-center gap-3">
                        <i class="fa-solid fa-paper-plane text-[10px]"></i>
                        <span>Authorize Delivery</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Details View Modal -->
    <!-- Payments Main Modal -->
    <div id="payments-modal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="glass-card w-full max-w-5xl max-h-[90vh] flex flex-col overflow-hidden shadow-2xl">
            <div class="p-6 border-b border-white/40 flex items-center justify-between bg-white/20">
                <div>
                    <h3 class="text-xl font-black font-['Outfit'] text-slate-900">Delivery Settlements</h3>
                    <p id="payments-modal-subtitle" class="text-[10px] uppercase font-black text-slate-500 tracking-widest"></p>
                </div>
                <button onclick="closeModal('payments-modal')" class="text-slate-500 hover:text-slate-800"><i class="fa-solid fa-times text-xl"></i></button>
            </div>
            
            <div id="payments-modal-content" class="overflow-y-auto p-6 space-y-6 custom-scroll">
                <!-- Content loaded via JS -->
            </div>
        </div>
    </div>

    <!-- Add Payment Sub-Modal -->
    <div id="add-payment-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xl z-[60] flex items-center justify-center p-4 hidden">
        <div class="glass-card w-full max-w-md p-8 shadow-2xl relative">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h3 class="text-xl font-black font-['Outfit'] text-slate-900">Finalize Payment</h3>
                    <p id="add-payment-cust-name" class="text-[10px] uppercase font-black text-slate-400 tracking-widest"></p>
                </div>
                <button type="button" onclick="closeModal('add-payment-modal')" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-slate-800 transition-all"><i class="fa-solid fa-times"></i></button>
            </div>
            
            <form id="payment-form" onsubmit="savePayment(event)" class="space-y-5">
                <input type="hidden" name="dc_id" id="payment_dc_id">
                <input type="hidden" name="type" id="payment_type_val" value="Cash">
                
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                    <div class="pay-method-card bg-indigo-50 border-2 border-indigo-500 rounded-xl p-3 flex flex-col items-center justify-center gap-2 cursor-pointer transition-all hover:bg-indigo-100" onclick="selectPaymentMethod('Cash')" data-type="Cash">
                        <i class="fa-solid fa-money-bill-wave text-indigo-600 text-xl"></i>
                        <span class="text-[10px] font-black uppercase text-indigo-700 tracking-wider">Cash</span>
                    </div>
                    <div class="pay-method-card border-2 border-slate-100 rounded-xl p-3 flex flex-col items-center justify-center gap-2 cursor-pointer transition-all hover:border-slate-300 hover:bg-slate-50" onclick="selectPaymentMethod('Cheque')" data-type="Cheque">
                        <i class="fa-solid fa-money-check-dollar text-slate-500 text-xl"></i>
                        <span class="text-[10px] font-black uppercase text-slate-600 tracking-wider">Cheque</span>
                    </div>
                    <div class="pay-method-card border-2 border-slate-100 rounded-xl p-3 flex flex-col items-center justify-center gap-2 cursor-pointer transition-all hover:border-slate-300 hover:bg-slate-50" onclick="selectPaymentMethod('Account Transfer')" data-type="Account Transfer">
                        <i class="fa-solid fa-building-columns text-slate-500 text-xl"></i>
                        <span class="text-[10px] font-black uppercase text-slate-600 tracking-wider">Bank</span>
                    </div>
                    <div class="pay-method-card border-2 border-slate-100 rounded-xl p-3 flex flex-col items-center justify-center gap-2 cursor-pointer transition-all hover:border-slate-300 hover:bg-slate-50" onclick="selectPaymentMethod('Card')" data-type="Card">
                        <i class="fa-solid fa-credit-card text-slate-500 text-xl"></i>
                        <span class="text-[10px] font-black uppercase text-slate-600 tracking-wider">Card</span>
                    </div>
                </div>

                <div class="bg-rose-50 rounded-xl p-4 mb-4 border border-rose-100 flex justify-between items-center">
                    <span class="text-[10px] font-black uppercase text-rose-500 tracking-widest">Left to Pay</span>
                    <span id="pending_amount_display" class="font-black text-rose-600 text-lg">LKR 0.00</span>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[10px] uppercase font-black text-slate-500 tracking-widest ml-1 mb-1.5 block">Amount (LKR)</label>
                        <input type="number" name="amount" id="payment_amount" step="0.01" required class="input-glass w-full h-[48px] font-black text-emerald-600 text-lg">
                    </div>
                    <div>
                        <label class="text-[10px] uppercase font-black text-slate-500 tracking-widest ml-1 mb-1.5 block">Payment Date</label>
                        <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" class="input-glass w-full h-[48px] font-bold">
                    </div>
                </div>

                <!-- Bank Info (Conditional) -->
                <div id="bank_section" class="hidden space-y-4 pt-2">
                    <div class="relative">
                        <label class="text-[10px] uppercase font-black text-slate-500 tracking-widest ml-1 mb-1.5 block">Target Bank Account</label>
                        <div class="flex gap-2">
                            <div class="relative flex-1">
                                <input type="text" id="bank_search" placeholder="Search saved banks..." class="input-glass w-full h-[48px] font-bold" onkeyup="searchBanks(this.value)">
                                <div id="bank_results" class="absolute w-full mt-1 bg-white border border-slate-200 rounded-xl shadow-2xl z-[100] hidden overflow-hidden"></div>
                            </div>
                            <button type="button" onclick="openNewBankModal()" class="w-[48px] h-[48px] rounded-xl bg-indigo-50 text-indigo-600 border border-indigo-100 hover:bg-indigo-600 hover:text-white transition-all">
                                <i class="fa-solid fa-plus text-sm"></i>
                            </button>
                        </div>
                        <input type="hidden" name="bank_id" id="selected_bank_id">
                    </div>
                </div>

                <!-- Cheque Info (Conditional) -->
                <div id="cheque_section" class="hidden space-y-4 pt-2">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] uppercase font-black text-slate-500 tracking-widest ml-1 mb-1.5 block">Cheque Number</label>
                            <input type="text" name="chq_no" class="input-glass w-full h-[48px] font-bold">
                        </div>
                        <div class="relative">
                            <label class="text-[10px] uppercase font-black text-slate-500 tracking-widest ml-1 mb-1.5 block">Payer (Client)</label>
                            <input type="text" id="chq_cust_search" placeholder="Search..." class="input-glass w-full h-[48px] font-bold" onkeyup="searchChequeCustomers(this.value)">
                            <div id="chq_cust_results" class="absolute w-full mt-1 bg-white border border-slate-200 rounded-xl shadow-2xl z-[100] hidden overflow-hidden"></div>
                            <input type="hidden" name="chq_cust_id" id="selected_chq_cust_id">
                        </div>
                    </div>
                </div>

                <!-- Proof (Conditional) -->
                <div id="proof_section" class="hidden pt-2">
                    <label class="text-[10px] uppercase font-black text-slate-500 tracking-widest ml-1 mb-1.5 block">Payment Proof / Slip</label>
                    <input type="file" name="proof" class="input-glass w-full h-[48px] py-2 text-xs">
                </div>

                <div class="pt-4">
                    <button type="submit" class="w-full bg-slate-900 hover:bg-black text-white py-4 rounded-2xl font-black text-xs uppercase tracking-widest shadow-2xl shadow-slate-900/20 transition-all active:scale-95">
                        Submit Transaction
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- New Bank Sub-Modal -->
    <div id="new-bank-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xl z-[80] flex items-center justify-center p-4 hidden">
        <div class="glass-card w-full max-w-sm p-8 shadow-2xl border-indigo-400/30">
            <h3 class="text-xl font-black text-slate-900 font-['Outfit'] mb-6">New Bank Account</h3>
            <form onsubmit="saveNewBank(event)" class="space-y-5">
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 ml-1 mb-1.5 block">Bank Name</label>
                    <input type="text" name="name" required class="input-glass w-full h-[45px] font-bold uppercase" placeholder="e.g. SAMPATH / BOC">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 ml-1 mb-1.5 block">Account Number</label>
                    <input type="text" name="acc_no" required class="input-glass w-full h-[45px] font-black">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 ml-1 mb-1.5 block">Account Name</label>
                    <input type="text" name="acc_name" required class="input-glass w-full h-[45px] font-bold">
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="closeModal('new-bank-modal')" class="flex-1 py-3 text-[10px] font-black uppercase text-slate-400">Cancel</button>
                    <button type="submit" class="flex-[2] bg-indigo-600 text-white py-3 rounded-2xl text-[10px] font-black uppercase shadow-lg">Save Account</button>
                </div>
            </form>
        </div>
    </div>

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
                <div class="space-y-2">
                    <label class="text-[10px] uppercase font-black text-slate-500 tracking-widest ml-1">Monthly Salary (Optional LKR)</label>
                    <input type="number" min="0" step="0.01" name="salary" class="input-glass w-full h-[52px] font-bold" placeholder="0.00">
                </div>
                <div class="pt-2">
                    <button type="submit" class="w-full bg-indigo-600 hover:bg-black text-white py-4 rounded-2xl font-black text-xs uppercase tracking-widest shadow-2xl shadow-indigo-600/30 transition-all active:scale-95">
                        Enroll & Assign to Delivery
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
        let editingId = null;
        let isDirty = false;

        function openModal() {
            editingId = null;
            isDirty = false;
            document.getElementById('modal-title').innerText = 'Authorize New Delivery';
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

        function openEditModal(id) {
            editingId = id;
            isDirty = false;
            document.getElementById('modal-title').innerText = 'Edit Delivery #' + String(id).padStart(4, '0');
            
            fetch(`?action=view_delivery&id=${id}`)
                .then(r => r.json())
                .then(res => {
                    if (!res.success) return alert(res.message);
                    const d = res.data;
                    
                    routeModal.classList.remove('hidden');
                    document.getElementById('delivery_date').value = d.delivery_date || '';
                    
                    // Clear Previous
                    tripEmployees = [];
                    document.getElementById('assigned_staff').innerHTML = '';
                    document.getElementById('expense_rows').innerHTML = '';
                    document.getElementById('customer_blocks').innerHTML = '';
                    
                    // Populate Staff
                    if (d.employees) {
                        d.employees.forEach(e => addStaff(e.id || null, e.full_name, e.contact_number));
                    }
                    
                    // Populate Expenses
                    if (d.expenses && d.expenses.length > 0) {
                        d.expenses.forEach(e => addQuickExpense(e.expense_name, e.amount));
                    } else {
                        addExpenseRow();
                    }
                    
                    // Populate Customers
                    if (d.customers) {
                        d.customers.forEach(c => {
                            const blockId = addCustomerBlock(c.id);
                            selectCustomer(blockId, c.customer_id, c.name, c.contact_number);
                            
                            const block = document.getElementById(`cust-${blockId}`);
                            
                            if (c.proof_photos && c.proof_photos.length > 0) {
                                block.dataset.existingBills = JSON.stringify(c.proof_photos);
                                renderExistingBills(blockId);
                            }
                            
                            // Clear the auto-added empty row
                            const orderItemsDiv = block.querySelector('.order-items');
                            orderItemsDiv.innerHTML = ''; 
                            
                            if (c.items) {
                                c.items.forEach(item => {
                                    const itemId = addItemRow(blockId);
                                    const row = document.getElementById(`item-${itemId}`);
                                    if(row) {
                                        const elId = row.querySelector('.item-id'); if(elId) elId.value = item.container_item_id || '';
                                        const elSearch = row.querySelector('.item-search'); if(elSearch) elSearch.value = item.brand_name || '';
                                        const elQty = row.querySelector('.item-qty'); if(elQty) elQty.value = item.qty || 0;
                                        const elMaxQty = row.querySelector('.max-qty'); if(elMaxQty) elMaxQty.value = (parseFloat(item.available_qty) || 0) + (parseFloat(item.qty) || 0);
                                        const elDmg = row.querySelector('.item-dmg'); if(elDmg) elDmg.value = item.damaged_qty || 0;
                                        const elPrice = row.querySelector('.item-price'); if(elPrice) elPrice.value = item.selling_price || 0;
                                        const elDiscount = row.querySelector('.item-discount'); if(elDiscount) elDiscount.value = item.discount_amount || 0;
                                        const elCost = row.querySelector('.cost-price'); if(elCost) elCost.value = item.cost_price || 0;
                                        
                                        const stockDiv = row.querySelector('.stock-info');
                                        if(stockDiv) {
                                            const bgs = stockDiv.querySelectorAll('span');
                                            if(bgs.length >= 2) { 
                                                bgs[0].innerHTML = `<i class="fa-solid fa-box-archive mr-1"></i> Stock: ${item.available_qty || 0} PKTS`;
                                                bgs[1].innerHTML = `<i class="fa-solid fa-coins mr-1"></i> Unit Cost: LKR ${item.cost_price || 0}`;
                                                stockDiv.classList.remove('hidden');
                                            }
                                        }
                                    }
                                });
                            }
                        });
                    }
                    
                    calculateTotals();
                    
                    setTimeout(() => { isDirty = false; }, 100);
                }).catch(err => {
                    console.error("Error loading delivery details:", err);
                    alert("Failed to load delivery details. Please check connection.");
                });
        }

        function closeModal() {
            if (!routeModal.classList.contains('hidden')) {
                const hasItems = document.querySelectorAll('.order-items .grid').length > 0;
                const hasExpenses = document.querySelectorAll('#expense_rows .grid').length > 0;
                
                if (hasItems || hasExpenses) {
                    if (!confirm('You have unsaved changes in this delivery. Are you sure you want to discard them?')) {
                        return;
                    }
                }
            }
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
                            html += `<div class="p-3 hover:bg-indigo-50/50 cursor-pointer text-sm font-bold text-slate-700 flex items-center border-b border-white/5 transition-colors" onmousedown="addStaff(${e.id}, '${e.full_name}', '${e.contact_number}')">
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

        function addQuickExpense(name, amount = '') {
            const id = Date.now() + Math.random();
            const html = `
                <div id="exp-${id}" class="grid grid-cols-1 md:grid-cols-12 gap-3 md:gap-3 justify-items-center md:items-center animate-[fadeIn_0.3s_ease] bg-slate-50/50 p-2 md:p-0 rounded-xl md:bg-transparent md:rounded-none">
                    <div class="col-span-1 md:col-span-8 w-full">
                        <input type="text" value="${name}" class="input-glass w-full h-[38px] exp-name text-xs font-bold">
                    </div>
                    <div class="col-span-1 md:col-span-3 w-full flex items-center gap-2">
                        <span class="md:hidden text-[10px] font-black uppercase text-slate-400">Amt:</span>
                        <input type="number" value="${amount}" placeholder="0.00" class="input-glass w-full h-[38px] exp-amt text-xs font-bold" onkeyup="calculateTotals()" autofocus>
                    </div>
                    <div class="col-span-1 md:col-span-1 w-full md:w-auto text-center font-bold text-slate-300 hover:text-rose-500 cursor-pointer bg-white md:bg-transparent rounded-lg py-2 md:py-0 border border-slate-100 md:border-0" onclick="document.getElementById('exp-${id}').remove(); calculateTotals();">
                        <i class="fa-solid fa-times text-xs"></i> <span class="md:hidden text-[10px] uppercase tracking-widest ml-1">Remove Expense</span>
                    </div>
                </div>
            `;
            document.getElementById('expense_rows').insertAdjacentHTML('beforeend', html);
        }

        function addExpenseRow() {
            const id = Date.now();
            const html = `
                <div id="exp-${id}" class="grid grid-cols-1 md:grid-cols-12 gap-3 md:gap-3 justify-items-center md:items-center animate-[fadeIn_0.3s_ease] bg-slate-50/50 p-2 md:p-0 rounded-xl md:bg-transparent md:rounded-none">
                    <div class="col-span-1 md:col-span-8 w-full">
                        <input type="text" placeholder="Expense description" class="input-glass w-full h-[38px] exp-name text-xs">
                    </div>
                    <div class="col-span-1 md:col-span-3 w-full flex items-center gap-2">
                        <span class="md:hidden text-[10px] font-black uppercase text-slate-400">Amt:</span>
                        <input type="number" placeholder="0.00" class="input-glass w-full h-[38px] exp-amt text-xs" onkeyup="calculateTotals()">
                    </div>
                    <div class="col-span-1 md:col-span-1 w-full md:w-auto text-center font-bold text-slate-300 hover:text-rose-500 cursor-pointer bg-white md:bg-transparent rounded-lg py-2 md:py-0 border border-slate-100 md:border-0" onclick="document.getElementById('exp-${id}').remove(); calculateTotals();">
                        <i class="fa-solid fa-times text-xs"></i> <span class="md:hidden text-[10px] uppercase tracking-widest ml-1">Remove Expense</span>
                    </div>
                </div>
            `;
            document.getElementById('expense_rows').insertAdjacentHTML('beforeend', html);
        }

        function addCustomerBlock(dcId = null) {
            const id = (Date.now() + Math.random()).toString().replace('.', '');
            const html = `
                <div id="cust-${id}" class="glass-card p-3 md:p-4 border border-slate-200 relative animate-[fadeIn_0.4s_ease] mb-4" data-dc-id="${dcId || ''}" data-customer-id="" data-existing-bill="">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
                        <div class="flex items-center gap-3 flex-1 min-w-0 w-full">
                            <div class="w-10 h-10 bg-indigo-50 border-2 border-indigo-500/20 rounded-2xl flex items-center justify-center text-indigo-600 shadow-sm flex-shrink-0">
                                <i class="fa-solid fa-user-tag text-xs"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <input type="text" placeholder="Search or assign customer..." class="input-glass w-full h-[46px] text-sm font-bold cust-search" onkeyup="searchCustomers(this.value, '${id}')">
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-2 flex-shrink-0 justify-end w-full md:w-auto">
                             <input type="file" multiple class="hidden customer-bill-input" id="bill-input-${id}" onchange="handleBillSelect(this, '${id}')">
                             
                             <div id="bill-label-${id}" class="flex items-center">
                                 <button type="button" onclick="document.getElementById('bill-input-${id}').click()" title="Attach Bill" class="h-[46px] px-4 md:px-5 rounded-xl md:rounded-2xl bg-slate-900 text-white hover:bg-black transition-all flex items-center shadow-lg shadow-slate-900/10 group">
                                    <i class="fa-solid fa-file-invoice-dollar mr-2 group-hover:scale-110 transition-transform"></i>
                                    <span class="text-[10px] font-black uppercase tracking-widest">Upload Bill</span>
                                 </button>
                             </div>
                             
                             <button type="button" onclick="if(confirm('Are you sure you want to remove this customer and all their items from the delivery?')) { document.getElementById('cust-${id}').remove(); calculateTotals(); }" title="Remove Customer" class="w-[46px] h-[46px] rounded-xl md:rounded-2xl bg-rose-50 text-rose-400 hover:text-rose-600 hover:bg-rose-100 transition-all border border-rose-100 flex items-center justify-center shadow-sm">
                                 <i class="fa-solid fa-trash-can text-sm"></i>
                             </button>
                        </div>
                    </div>
                    <div class="hidden customer-results absolute w-full left-0 top-[110px] md:top-[80px] z-[100] bg-white border border-slate-200 rounded-2xl shadow-2xl p-2 max-w-md mx-6"></div>
                    
                    <div class="selected-customer-info mb-6 hidden">
                        <div class="bg-emerald-500/10 p-3.5 text-emerald-700 text-sm font-bold flex justify-between items-center border border-emerald-500/20 rounded-xl mb-3 shadow-inner">
                            <span class="client-name-display"><i class="fa-solid fa-user-check mr-2"></i> Client Active: </span>
                        </div>
                        <div class="flex flex-wrap gap-3 items-center">
                            <div class="existing-proofs flex flex-wrap gap-3 empty:hidden"></div>
                            <div class="new-proofs flex flex-wrap gap-3 empty:hidden"></div>
                            <button type="button" onclick="document.getElementById('bill-input-${id}').click()" class="h-[60px] w-[60px] rounded-2xl border-2 border-dashed border-slate-300 flex items-center justify-center text-slate-400 hover:border-emerald-500 hover:text-emerald-500 hover:bg-emerald-50 transition-all group" title="Add More Proofs">
                                <i class="fa-solid fa-plus text-lg group-hover:scale-110 transition-transform"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="space-y-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[10px] uppercase font-black text-slate-600 tracking-widest">Current Order Items</span>
                            <button type="button" onclick="addItemRow('${id}')" class="text-[10px] font-black text-emerald-600 uppercase tracking-widest bg-emerald-50 px-3 py-1.5 rounded-lg border border-emerald-100">+ Add Item</button>
                        </div>
                        
                        <div class="hidden md:grid grid-cols-12 gap-2 px-1 mb-1">
                            <div class="col-span-4"><span class="text-[8px] uppercase font-black text-slate-400 tracking-wider">Product</span></div>
                            <div class="col-span-1"><span class="text-[8px] uppercase font-black text-slate-400 tracking-wider">Qty</span></div>
                            <div class="col-span-2"><span class="text-[8px] uppercase font-black text-slate-400 tracking-wider">Selling</span></div>
                            <div class="col-span-2"><span class="text-[8px] uppercase font-black text-red-600 tracking-wider">Damaged</span></div>
                            <div class="col-span-2"><span class="text-[8px] uppercase font-black text-slate-400 tracking-wider">Discount</span></div>
                        </div>

                        <div class="order-items space-y-3 md:space-y-1"></div>
                        
                        <div class="pt-3 border-t border-slate-100 flex justify-between items-center">
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Order Subtotal</span>
                            <span class="customer-subtotal text-sm md:text-base font-black text-slate-900">LKR 0.00</span>
                        </div>
                    </div>
                </div>
            `;
            document.getElementById('customer_blocks').insertAdjacentHTML('beforeend', html);
            addItemRow(id);
            return id;
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
                            html += `<div class="p-3 hover:bg-slate-50 cursor-pointer border-b border-white/5 last:border-0" onmousedown="selectCustomer(${blockId}, ${c.id}, '${c.name}', '${c.contact_number}')">
                                <p class="text-sm font-black text-slate-800 uppercase tracking-tight">${c.name}</p>
                                <p class="text-[10px] text-slate-500 uppercase font-black tracking-widest">${c.contact_number}</p>
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
            info.querySelector('.client-name-display').innerHTML = `<i class="fa-solid fa-user-check mr-2"></i> ${name} (${contact})`;
            info.classList.remove('hidden');
            block.querySelector('.customer-results').classList.add('hidden');
            block.querySelector('.cust-search').value = name;
        }

        function addItemRow(blockId) {
            const id = (Date.now() + Math.random()).toString().replace('.', '');
            const html = `
                <div id="item-${id}" class="space-y-1">
                    <div class="grid grid-cols-12 gap-2 md:gap-2 items-center">
                        <div class="col-span-12 md:col-span-3 relative">
                            <input type="text" placeholder="Product..." class="input-glass w-full h-[36px] text-[11px] font-bold item-search" onkeyup="searchBrands(this.value, '${id}')" onfocus="searchBrands(this.value, '${id}')">
                            <div class="brand-results hidden absolute w-full mt-1 bg-white border border-slate-200 rounded-xl shadow-2xl z-[100] p-1"></div>
                            <input type="hidden" class="item-id">
                            <input type="hidden" class="cost-price">
                            <input type="hidden" class="max-qty">
                        </div>
                        <div class="col-span-3 md:col-span-2">
                            <input type="number" placeholder="Qty" class="input-glass w-full h-[36px] text-xs font-bold item-qty" onkeyup="calculateTotals()">
                        </div>
                        <div class="col-span-3 md:col-span-2">
                            <input type="number" placeholder="0.00" class="input-glass w-full h-[36px] text-xs font-bold item-price" onkeyup="calculateTotals()">
                        </div>
                        <div class="col-span-2 md:col-span-2">
                            <input type="number" placeholder="Dmg" class="input-glass w-full h-[36px] text-[10px] font-bold item-dmg border-red-100 text-red-600" onkeyup="calculateTotals()" title="Damaged Qty">
                        </div>
                        <div class="col-span-3 md:col-span-2">
                            <input type="number" placeholder="0.00" class="input-glass w-full h-[36px] text-[10px] font-bold item-discount" onkeyup="calculateTotals()" title="Discount">
                        </div>
                        <div class="col-span-1 text-center text-slate-300 hover:text-rose-500 cursor-pointer" onmousedown="document.getElementById('item-${id}').remove(); calculateTotals();">
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
            return id;
        }

        function selectBrand(itemId, id, name, qty, cost) {
            const row = document.getElementById(`item-${itemId}`);
            row.querySelector('.item-id').value = id;
            row.querySelector('.item-search').value = `${name} (Stock: ${qty})`;
            row.querySelector('.cost-price').value = cost;
            row.querySelector('.max-qty').value = qty;
            
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

        function searchBrands(term, itemId) {
            const row = document.getElementById(`item-${itemId}`);
            const resultsDiv = row.querySelector('.brand-results');
            
            fetch(`?action=search_brand_stock&term=${encodeURIComponent(term)}`)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    if(!term && data.length > 0) {
                        html += '<p class="px-2 py-1.5 text-[8px] font-black text-slate-400 uppercase tracking-widest bg-slate-50 mb-1 rounded">Stock Recommendations</p>';
                    }
                    data.forEach(b => {
                        html += `<div class="p-2 hover:bg-slate-50 cursor-pointer border-b border-slate-50 last:border-0" onmousedown="selectBrand('${itemId}', ${b.item_id}, '${b.brand_name}', ${b.available_qty}, ${b.per_item_cost})">
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

        function handleBillSelect(input, id) {
            if (!input.files || input.files.length === 0) return;
            
            const addBtn = input.nextElementSibling || document.getElementById(`cust-${id}`).querySelector('.fa-plus').parentElement;
            const origHTML = addBtn.innerHTML;
            addBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-lg text-emerald-500"></i>';
            addBtn.disabled = true;
            
            const formData = new FormData();
            formData.append('action', 'upload_proof_instant');
            for(let i=0; i<input.files.length; i++) {
                formData.append('instant_bills[]', input.files[i]);
            }
            
            fetch('nwdelivery.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    addBtn.innerHTML = origHTML;
                    addBtn.disabled = false;
                    input.value = '';
                    
                    if(res.success && res.filenames.length > 0) {
                        const block = document.getElementById(`cust-${id}`);
                        let existing = block.dataset.existingBills ? JSON.parse(block.dataset.existingBills) : [];
                        existing = existing.concat(res.filenames);
                        block.dataset.existingBills = JSON.stringify(existing);
                        renderExistingBills(id);
                        isDirty = true;
                    } else if (!res.success) {
                        alert(res.message || "Failed to upload proofs.");
                    }
                })
                .catch(() => {
                    addBtn.innerHTML = origHTML;
                    addBtn.disabled = false;
                    alert("Upload request failed.");
                });
        }

        function removeStoredBill(id, idx) {
            if(!confirm("Remove this specific bill image from the customer?")) return;
            const block = document.getElementById(`cust-${id}`);
            let existing = block.dataset.existingBills ? JSON.parse(block.dataset.existingBills) : [];
            existing.splice(idx, 1);
            block.dataset.existingBills = JSON.stringify(existing);
            renderExistingBills(id);
            isDirty = true;
        }

        function renderExistingBills(id) {
            const block = document.getElementById(`cust-${id}`);
            const container = block.querySelector('.existing-proofs');
            let existing = block.dataset.existingBills ? JSON.parse(block.dataset.existingBills) : [];
            
            let html = '';
            existing.forEach((photo, i) => {
                html += `
                    <div class="h-[60px] w-[60px] rounded-2xl border-2 border-indigo-200 overflow-hidden shadow-sm group relative animate-[scaleIn_0.2s_ease]">
                        <a href="../uploads/bills/${photo}" target="_blank" class="block w-full h-full">
                            <img src="../uploads/bills/${photo}" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-300" alt="Bill">
                            <div class="absolute inset-0 bg-indigo-900/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                <i class="fa-solid fa-expand text-white text-sm"></i>
                            </div>
                        </a>
                        <button type="button" onclick="removeStoredBill('${id}', ${i})" class="absolute -top-1 -right-1 w-5 h-5 bg-rose-500 text-white rounded-full flex items-center justify-center shadow-lg hover:bg-rose-600 transition-colors transform scale-0 group-hover:scale-100" title="Remove Bill">
                            <i class="fa-solid fa-xmark text-[10px]"></i>
                        </button>
                    </div>`;
            });
            container.innerHTML = html;
        }

        function resetBillLabel(id) {
            const lbl = document.getElementById(`bill-label-${id}`);
            lbl.innerHTML = `
                <button type="button" onclick="document.getElementById('bill-input-${id}').click()" title="Attach Bill" class="h-[46px] px-5 rounded-2xl bg-slate-900 text-white hover:bg-black transition-all flex items-center shadow-lg shadow-slate-900/10 group animate-[scaleIn_0.2s_ease]">
                   <i class="fa-solid fa-file-invoice-dollar mr-2 group-hover:scale-110 transition-transform"></i>
                   <span class="text-[10px] font-black uppercase tracking-widest">Upload Bill</span>
                </button>
            `;
        }

        function calculateTotals() {
            let totalExp = 0;
            document.querySelectorAll('.exp-amt').forEach(i => totalExp += (parseFloat(i.value) || 0));
            document.getElementById('total_expenses_display').innerText = `LKR ${totalExp.toLocaleString()}`;

            let totalRev = 0;
            let totalCost = 0;
            
            document.querySelectorAll('#customer_blocks .glass-card').forEach(block => {
                let customerSubtotal = 0;
                block.querySelectorAll('.order-items .grid').forEach(row => {
                    const qtyInput = row.querySelector('.item-qty');
                    const q = parseFloat(qtyInput.value) || 0;
                    const maxQ = parseFloat(row.querySelector('.max-qty').value) || 999999;
                    const p = parseFloat(row.querySelector('.item-price').value) || 0;
                    const cp = parseFloat(row.querySelector('.cost-price').value) || 0;
                    const dmg = parseFloat(row.querySelector('.item-dmg').value) || 0;
                    const disc = parseFloat(row.querySelector('.item-discount').value) || 0;

                    // Warning for over-stock
                    if (q > maxQ) {
                        qtyInput.classList.add('qty-warning');
                        qtyInput.title = `Warning: Only ${maxQ} available in stock!`;
                    } else {
                        qtyInput.classList.remove('qty-warning');
                        qtyInput.title = "";
                    }
                    
                    const lineTotal = ((q - dmg) * p) - disc;
                    customerSubtotal += lineTotal;
                    totalRev += lineTotal;
                    totalCost += (q * cp);
                });
                const subtotalEl = block.querySelector('.customer-subtotal');
                if(subtotalEl) subtotalEl.innerText = `LKR ${customerSubtotal.toLocaleString()}`;
            });
            
            const estProfit = totalRev - totalCost - totalExp;
            document.getElementById('total_sales_display').innerText = `LKR ${totalRev.toLocaleString()}`;
            document.getElementById('total_profit_display').innerText = `LKR ${estProfit.toLocaleString()}`;
            
            // Color coding for profit
            const profitEl = document.getElementById('total_profit_display');
            if(estProfit < 0) profitEl.classList.replace('text-indigo-600', 'text-rose-600');
            else profitEl.classList.replace('text-rose-600', 'text-indigo-600');
        }

        function processRouteSave() {
            const btn = event.currentTarget;
            const originalHtml = btn.innerHTML;
            
            const formData = new FormData();
            
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
            document.querySelectorAll('#customer_blocks .glass-card').forEach((b, index) => {
                const dcId = b.dataset.dcId;
                const cid = b.dataset.customerId;
                const items = [];
                b.querySelectorAll('.order-items .grid').forEach(r => {
                    const iid = r.querySelector('.item-id').value;
                    const q = r.querySelector('.item-qty').value;
                    const p = r.querySelector('.item-price').value;
                    const cp = r.querySelector('.cost-price').value;
                    const dmg = r.querySelector('.item-dmg').value;
                    const disc = r.querySelector('.item-discount').value;
                    if(iid && q && p) items.push({item_id: iid, qty: q, selling_price: p, cost_price: cp, damaged_qty: dmg, discount: disc});
                });
                
                const existingBills = b.dataset.existingBills ? JSON.parse(b.dataset.existingBills) : [];
                
                if(cid && items.length) {
                    custs.push({dc_id: dcId, customer_id: cid, items, existing_bills: existingBills});
                }
            });

            if(!emps.length || !custs.length) return alert('Assign at least one staff and one customer order.');

            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;

            formData.append('action', 'save_delivery');
            formData.append('delivery_date', date);
            if (editingId) formData.append('editing_id', editingId);
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

        function openPaymentsModal(id) {
            document.getElementById('payments-modal-subtitle').innerText = 'Trip #TRP-' + String(id).padStart(4, '0');
            fetch(`?action=view_delivery&id=${id}`)
                .then(r => r.json())
                .then(res => {
                    if (!res.success) return alert(res.message);
                    const d = res.data;
                    const container = document.getElementById('payments-modal-content');
                    
                    let html = '';
                    d.customers.forEach(c => {
                        const total = parseFloat(c.subtotal) - parseFloat(c.discount);
                        const paid = parseFloat(c.total_paid);
                        const pending = total - paid;
                        
                        const safeName = c.name.replace(/'/g, "\\'").replace(/"/g, "&quot;");
                        
                        html += `
                            <div class="glass-card p-6 border-slate-200/50 bg-white/40">
                                <div class="flex flex-col md:flex-row md:items-center gap-8 mb-8">
                                    <div class="flex items-center gap-4 min-w-[240px]">
                                        <div class="w-12 h-12 bg-indigo-50 border-2 border-indigo-500/20 rounded-2xl flex items-center justify-center text-indigo-600 shadow-sm flex-shrink-0">
                                            <i class="fa-solid fa-store text-lg"></i>
                                        </div>
                                        <div>
                                            <h4 class="text-xl font-black text-slate-900 font-['Outfit'] tracking-tight">${c.name}</h4>
                                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">${c.address}</p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex gap-12 border-l border-slate-100 pl-8">
                                        <div class="text-left">
                                            <p class="text-[9px] uppercase font-black text-slate-400 mb-1">Total Bill</p>
                                            <p class="text-sm font-black text-slate-900">LKR ${total.toLocaleString()}</p>
                                        </div>
                                        <div class="text-left border-l border-slate-100 pl-8">
                                            <p class="text-[9px] uppercase font-black text-emerald-500 mb-1">Paid</p>
                                            <p class="text-sm font-black text-emerald-600">LKR ${paid.toLocaleString()}</p>
                                        </div>
                                        <div class="text-left border-l border-slate-100 pl-8">
                                            <p class="text-[9px] uppercase font-black text-rose-500 mb-1">Pending</p>
                                            <p class="text-sm font-black text-rose-600">LKR ${pending.toLocaleString()}</p>
                                        </div>
                                    </div>
                                    
                                    <div class="md:ml-auto">
                                        <button onclick="openAddPayment(${c.id}, '${safeName}', ${pending}, ${c.customer_id})" class="bg-indigo-600 hover:bg-black text-white px-8 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-2xl shadow-indigo-600/20 transition-all flex items-center gap-3 group">
                                            <i class="fa-solid fa-plus-circle group-hover:rotate-90 transition-transform"></i>
                                            <span>New Payment</span>
                                        </button>
                                    </div>
                                </div>

                                <div class="border-t border-slate-100 pt-5">
                                    <h5 class="text-[9px] uppercase font-black text-slate-400 tracking-[0.2em] mb-4">Transaction History</h5>
                                    ${c.payments && c.payments.length ? `
                                        <div class="overflow-x-auto">
                                            <table class="w-full text-left">
                                                <thead>
                                                    <tr class="text-[9px] uppercase font-black text-slate-400 border-b border-slate-100">
                                                        <th class="pb-3 px-2">Type</th>
                                                        <th class="pb-3 px-2">Date</th>
                                                        <th class="pb-3 px-2">Bank Details</th>
                                                        <th class="pb-3 px-2 text-center">Proof</th>
                                                        <th class="pb-3 px-2 text-right">Total</th>
                                                        <th class="pb-3 px-2 text-center">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-50">
                                                    ${c.payments.map(p => `
                                                        <tr class="text-[11px] font-bold text-slate-700 hover:bg-slate-50/50 transition-colors">
                                                            <td class="py-3 px-2">
                                                                <span class="bg-indigo-50 text-indigo-700 px-2 py-1 rounded text-[9px] uppercase font-black">${p.payment_type}</span>
                                                            </td>
                                                            <td class="py-3 px-2 text-slate-500">${new Date(p.payment_date).toLocaleDateString()}</td>
                                                            <td class="py-3 px-2">
                                                                ${p.bank_name ? `
                                                                    <p class="text-slate-900 leading-none mb-1">${p.bank_name}</p>
                                                                    <p class="text-[9px] text-slate-400 font-bold uppercase">${p.bank_acc}</p>
                                                                ` : '<span class="text-slate-300 italic font-medium">N/A</span>'}
                                                                ${p.cheque_payer ? `<p class="text-[9px] text-indigo-500 font-black mt-1 uppercase tracking-tighter">Payer: ${p.cheque_payer}</p>` : ''}
                                                            </td>
                                                            <td class="py-3 px-2 text-center">
                                                                ${p.proof_image ? `
                                                                    <a href="../uploads/payments/${p.proof_image}" target="_blank" class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 hover:bg-indigo-600 hover:text-white transition-all inline-flex items-center justify-center border border-indigo-100 shadow-sm" title="View Proof">
                                                                        <i class="fa-solid fa-image text-xs"></i>
                                                                    </a>
                                                                ` : '<span class="text-[9px] text-slate-300 font-bold uppercase">N/A</span>'}
                                                            </td>
                                                            <td class="py-3 px-2 text-right font-black text-slate-900 leading-tight">LKR ${parseFloat(p.amount).toLocaleString()}</td>
                                                            <td class="py-3 px-2 text-center">
                                                                <button onclick="deletePayment(${p.id})" class="text-rose-400 hover:text-rose-600 transition-all">
                                                                    <i class="fa-solid fa-trash-can"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    `).join('')}
                                                </tbody>
                                            </table>
                                        </div>
                                    ` : '<p class="text-[10px] font-bold text-slate-400 italic py-2 text-center">No transactions recorded for this customer.</p>'}
                                </div>
                            </div>
                        `;
                    });
                    
                    container.innerHTML = html;
                    document.getElementById('payments-modal').classList.remove('hidden');
                }).catch(err => {
                    console.error("Error loading payments:", err);
                    alert("Failed to load payments details. Please check your connection.");
                });
        }

        let currentCustomerInfo = { id: null, name: '' };
        function openAddPayment(dcId, name, pending, custId) {
            currentCustomerInfo = { id: custId, name: name };
            document.getElementById('payment_dc_id').value = dcId;
            document.getElementById('add-payment-cust-name').innerText = name;
            document.getElementById('payment_amount').value = pending;
            document.getElementById('pending_amount_display').innerText = 'LKR ' + parseFloat(pending).toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('add-payment-modal').classList.remove('hidden');
            selectPaymentMethod('Cash'); // Re-initialize as Cash by default
        }

        function selectPaymentMethod(type) {
            document.getElementById('payment_type_val').value = type;
            
            const cards = document.querySelectorAll('.pay-method-card');
            cards.forEach(card => {
                const cardType = card.dataset.type;
                if (cardType === type) {
                    card.className = "pay-method-card bg-indigo-50 border-2 border-indigo-500 rounded-xl p-3 flex flex-col items-center justify-center gap-2 cursor-pointer transition-all hover:bg-indigo-100";
                    card.querySelector('i').className = card.querySelector('i').className.replace('text-slate-500', 'text-indigo-600');
                    card.querySelector('span').className = card.querySelector('span').className.replace('text-slate-600', 'text-indigo-700');
                } else {
                    card.className = "pay-method-card border-2 border-slate-100 rounded-xl p-3 flex flex-col items-center justify-center gap-2 cursor-pointer transition-all hover:border-slate-300 hover:bg-slate-50";
                    card.querySelector('i').className = card.querySelector('i').className.replace('text-indigo-600', 'text-slate-500');
                    card.querySelector('span').className = card.querySelector('span').className.replace('text-indigo-700', 'text-slate-600');
                }
            });

            document.getElementById('bank_section').classList.toggle('hidden', type !== 'Account Transfer' && type !== 'Cheque');
            document.getElementById('cheque_section').classList.toggle('hidden', type !== 'Cheque');
            document.getElementById('proof_section').classList.toggle('hidden', type !== 'Account Transfer' && type !== 'Cheque');
            
            if (type === 'Cheque' && currentCustomerInfo.id) {
                document.getElementById('selected_chq_cust_id').value = currentCustomerInfo.id;
                document.getElementById('chq_cust_search').value = currentCustomerInfo.name;
            }
        }

        function searchBanks(term) {
            if(term.length < 2) return document.getElementById('bank_results').classList.add('hidden');
            fetch(`?action=search_bank&term=${term}`)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    if(data.length) {
                        data.forEach(b => {
                            html += `<div class="p-3 hover:bg-indigo-50 cursor-pointer border-b border-slate-50 last:border-0" onmousedown="selectBank(${b.id}, '${b.name}', '${b.account_number}')">
                                <p class="text-xs font-black text-slate-800">${b.name}</p>
                                <p class="text-[9px] text-slate-400 font-bold uppercase">${b.account_number} &bull; ${b.account_name}</p>
                            </div>`;
                        });
                    } else {
                        html = `<div class="p-3 text-center">
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">No bank found for "${term}"</p>
                            <button type="button" onclick="openNewBankModalPreFilled('${term.replace(/'/g, "\\'")}')" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-colors">
                                <i class="fa-solid fa-plus mr-1"></i>Create New Bank
                            </button>
                        </div>`;
                    }
                    const res = document.getElementById('bank_results');
                    res.innerHTML = html;
                    res.classList.remove('hidden');
                });
        }

        function selectBank(id, name, accNo) {
            document.getElementById('selected_bank_id').value = id;
            document.getElementById('bank_search').value = name + ' (' + accNo + ')';
            document.getElementById('bank_results').classList.add('hidden');
        }

        function openNewBankModal() {
            document.getElementById('new-bank-modal').classList.remove('hidden');
        }

        function openNewBankModalPreFilled(name) {
            document.getElementById('bank_results').classList.add('hidden');
            const modal = document.getElementById('new-bank-modal');
            modal.classList.remove('hidden');
            // Pre-fill bank name field if it exists
            const nameField = modal.querySelector('[name="name"]');
            if(nameField) nameField.value = name;
        }

        function saveNewBank(e) {
            e.preventDefault();
            const fd = new FormData(e.target);
            fd.append('action', 'create_bank');
            fetch('nwdelivery.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.success) {
                        const accNo = e.target.querySelector('[name="acc_no"]').value;
                        selectBank(res.id, res.name, accNo);
                        closeModal('new-bank-modal');
                    }
                });
        }

        function searchChequeCustomers(term) {
            if(term.length < 2) return document.getElementById('chq_cust_results').classList.add('hidden');
            fetch(`?action=search_cheque_customer&term=${term}`)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    data.forEach(c => {
                        html += `<div class="p-3 hover:bg-slate-50 cursor-pointer border-b border-slate-50 last:border-0" onmousedown="selectChqCust(${c.id}, '${c.name.replace(/'/g, "\\'")}')">
                            <p class="text-xs font-black text-slate-800 uppercase">${c.name}</p>
                        </div>`;
                    });
                    const res = document.getElementById('chq_cust_results');
                    res.innerHTML = html || '<div class="p-3 text-center text-[10px] text-slate-400 font-bold italic">No results</div>';
                    res.classList.remove('hidden');
                });
        }

        function selectChqCust(id, name) {
            document.getElementById('selected_chq_cust_id').value = id;
            document.getElementById('chq_cust_search').value = name;
            document.getElementById('chq_cust_results').classList.add('hidden');
        }

        function savePayment(e) {
            e.preventDefault();
            const btn = e.submitter;
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;

            const fd = new FormData(e.target);
            fd.append('action', 'save_payment');

            fetch('nwdelivery.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.success) {
                        location.reload();
                    } else {
                        alert(res.message);
                        btn.innerHTML = originalHtml;
                        btn.disabled = false;
                    }
                });
        }

        // Updated closeModal to support generic modal IDs
        function closeModal(id) {
            if (typeof id === 'string' && id.trim() !== '') {
                const el = document.getElementById(id);
                if (el) el.classList.add('hidden');
                return;
            }
            // Check dirty flag for route-modal
            if (!routeModal.classList.contains('hidden') && isDirty) {
                if (!confirm('You have unsaved changes in this delivery. Are you sure you want to discard them?')) {
                    return;
                }
            }
            routeModal.classList.add('hidden');
            detailsModal.classList.add('hidden');
            isDirty = false;
        }

        function confirmDeleteTrip(id) {
            if(confirm(`Are you sure you want to PERMANENTLY DELETE Delivery #DEL-${id}? All associated data and stocks will be affected.`)) {
                const formData = new FormData();
                formData.append('action', 'delete_delivery');
                formData.append('id', id);
                
                fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                }).then(r => r.json()).then(res => {
                    if(res.success) location.reload();
                    else alert(res.message);
                }).catch(err => {
                    console.error("Error deleting delivery:", err);
                    alert("Failed to delete. Please check your connection.");
                });
            }
        }
        function deletePayment(id) {
            const reason = prompt("Please provide a reason for deleting this payment:");
            if (reason === null) return; // Cancelled
            
            if (confirm("Are you sure you want to delete this payment record? This action will be logged in the ledger.")) {
                const fd = new FormData();
                fd.append('action', 'delete_payment');
                fd.append('id', id);
                fd.append('reason', reason);
                
                fetch('nwdelivery.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if(res.success) location.reload();
                        else alert(res.message);
                    });
            }
        }

        // Track changes globally
        document.getElementById('route-form').addEventListener('input', () => { isDirty = true; });
        document.getElementById('route-form').addEventListener('change', () => { isDirty = true; });
    </script>
</body>
</html>
