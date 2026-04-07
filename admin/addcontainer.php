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
    $num = (int) $last;
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
        if ($pdo->inTransaction())
            $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// --- Other Purchases Actions ---
if ($action == 'get_next_purchase_number') {
    $stmt = $pdo->query("SELECT purchase_number FROM other_purchases ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    if (!$last) {
        echo json_encode(['success' => true, 'next' => 'P-0001']);
    } else {
        preg_match('/P-(\d+)/', $last, $matches);
        $num = isset($matches[1]) ? (int) $matches[1] : 0;
        $next = 'P-' . str_pad($num + 1, 4, '0', STR_PAD_LEFT);
        echo json_encode(['success' => true, 'next' => $next]);
    }
    exit;
}

if ($action == 'search_buyer') {
    $term = '%' . $_GET['term'] . '%';
    $stmt = $pdo->prepare("SELECT DISTINCT buyer_name FROM other_purchases WHERE buyer_name LIKE ? LIMIT 5");
    $stmt->execute([$term]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    exit;
}

if ($action == 'search_bank') {
    $term = '%' . $_GET['term'] . '%';
    $stmt = $pdo->prepare("SELECT name FROM banks WHERE name LIKE ? LIMIT 5");
    $stmt->execute([$term]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    exit;
}

if ($action == 'save_purchase') {
    try {
        $pdo->beginTransaction();
        $purchase_no = $_POST['purchase_number'];
        $bill_no = $_POST['bill_number'] ?? null;
        $buyer_name = $_POST['buyer_name'] ?? '';
        $discount = (float) ($_POST['discount'] ?? 0);
        $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');

        $items = json_decode($_POST['items'] ?? '[]', true);
        $expenses = json_decode($_POST['expenses'] ?? '[]', true);
        $payments = json_decode($_POST['payments'] ?? '[]', true);

        // calculate totals
        $total_amount = 0;
        foreach ($items as $it) {
            $total_amount += ((int) $it['qty'] * (float) $it['price']);
        }

        $other_exp_total = 0;
        foreach ($expenses as $ex)
            $other_exp_total += (float) ($ex['amount'] ?? 0);

        $grand_total = $total_amount + $other_exp_total - $discount;

        $stmt = $pdo->prepare("INSERT INTO other_purchases (purchase_number, bill_number, buyer_name, total_amount, discount, grand_total, purchase_date, added_by) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
                               ON DUPLICATE KEY UPDATE 
                               bill_number = VALUES(bill_number),
                               buyer_name = VALUES(buyer_name),
                               total_amount = VALUES(total_amount),
                               discount = VALUES(discount),
                               grand_total = VALUES(grand_total),
                               purchase_date = VALUES(purchase_date)");
        $stmt->execute([$purchase_no, $bill_no, $buyer_name, $total_amount, $discount, $grand_total, $purchase_date, $user_id]);

        $stmt = $pdo->prepare("SELECT id FROM other_purchases WHERE purchase_number = ?");
        $stmt->execute([$purchase_no]);
        $purchase_id = $stmt->fetchColumn();

        // Items
        $pdo->prepare("DELETE FROM other_purchase_items WHERE purchase_id = ?")->execute([$purchase_id]);
        foreach ($items as $it) {
            $qty = (float) $it['qty'];
            $sqft = (float) $it['sqft'];
            $price = (float) $it['price'];
            $category = $it['category'] ?? 'Other';
            $lineTotal = $qty * $price;
            if (empty($it['name']) || $qty <= 0)
                continue;
            $pdo->prepare("INSERT INTO other_purchase_items (purchase_id, item_name, category, qty, square_feet, price_per_item, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$purchase_id, $it['name'], $category, $qty, $sqft, $price, $lineTotal]);
        }

        // Expenses (already handled correctly by name/amount)
        $pdo->prepare("DELETE FROM other_purchase_expenses WHERE purchase_id = ?")->execute([$purchase_id]);
        foreach ($expenses as $ex) {
            if (empty($ex['name']) || (float) $ex['amount'] <= 0)
                continue;
            $pdo->prepare("INSERT INTO other_purchase_expenses (purchase_id, expense_name, amount) VALUES (?, ?, ?)")->execute([$purchase_id, $ex['name'], (float) $ex['amount']]);
        }

        // Payments
        $pdo->prepare("DELETE FROM other_purchase_payments WHERE purchase_id = ?")->execute([$purchase_id]);
        foreach ($payments as $py) {
            $amt = (float) $py['amount'];
            if ($amt <= 0)
                continue;

            $method = $py['method'] ?? 'Cash';
            $bank = $py['bank_name'] ?? '';
            $chq = $py['cheque_number'] ?? '';
            $payer = $py['payer_name'] ?? '';
            $desc = $py['description'] ?? '';

            $pdo->prepare("INSERT INTO other_purchase_payments (purchase_id, amount, bank_name, cheque_number, payer_name, method, description) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$purchase_id, $amt, $bank, $chq, $payer, $method, $desc]);
        }

        // Expenses
        $pdo->prepare("DELETE FROM other_purchase_expenses WHERE purchase_id = ?")->execute([$purchase_id]);
        foreach ($expenses as $exp) {
            if (empty($exp['name']) || $exp['amount'] <= 0)
                continue;
            $pdo->prepare("INSERT INTO other_purchase_expenses (purchase_id, expense_name, amount) VALUES (?, ?, ?)")->execute([$purchase_id, $exp['name'], $exp['amount']]);
        }

        // Payments
        $pdo->prepare("DELETE FROM other_purchase_payments WHERE purchase_id = ?")->execute([$purchase_id]);
        foreach ($payments as $pay) {
            if ($pay['amount'] <= 0)
                continue;

            $bank_id = null;
            if ($pay['method'] === 'Account Transfer' || $pay['method'] === 'Cheque') {
                if (!empty($pay['bank_name'])) {
                    $bStmt = $pdo->prepare("SELECT id FROM banks WHERE name = ?");
                    $bStmt->execute([$pay['bank_name']]);
                    $bank_id = $bStmt->fetchColumn();
                    if (!$bank_id) {
                        $pdo->prepare("INSERT INTO banks (name) VALUES (?)")->execute([$pay['bank_name']]);
                        $bank_id = $pdo->lastInsertId();
                    }
                }
            }

            $pdo->prepare("INSERT INTO other_purchase_payments (purchase_id, amount, payment_type, bank_id, cheque_number, payment_date, description, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$purchase_id, $pay['amount'], $pay['method'], $bank_id, $pay['cheque_number'] ?? null, $pay['payment_date'] ?? date('Y-m-d'), $pay['description'] ?? '', $user_id]);
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action == 'get_purchase_details') {
    $id = $_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM other_purchases WHERE id = ?");
    $stmt->execute([$id]);
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($purchase) {
        $stmt = $pdo->prepare("SELECT * FROM other_purchase_items WHERE purchase_id = ?");
        $stmt->execute([$id]);
        $purchase['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM other_purchase_expenses WHERE purchase_id = ?");
        $stmt->execute([$id]);
        $purchase['expenses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT p.*, b.name as bank_name FROM other_purchase_payments p LEFT JOIN banks b ON p.bank_id = b.id WHERE p.purchase_id = ?");
        $stmt->execute([$id]);
        $purchase['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $purchase]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Purchase not found']);
    }
    exit;
}

if ($action == 'delete_purchase') {
    $id = $_POST['id'];
    $stmt = $pdo->prepare("DELETE FROM other_purchases WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => $stmt->rowCount() > 0]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_container') {
    try {
        $pdo->beginTransaction();

        $container_no = $_POST['container_number'];
        $arrival_date = $_POST['arrival_date'];
        $expenses = json_decode($_POST['expenses'], true);
        $items = json_decode($_POST['items'], true);
        $damaged_qty = (int) ($_POST['damaged_qty'] ?? 0);
        $container_cost = (float) ($_POST['container_cost'] ?? 0);
        $country = $_POST['country'] ?? null;

        // 1. Calculate totals
        $other_expenses = 0;
        foreach ($expenses as $exp) {
            $other_expenses += (float) $exp['amount'];
        }
        $total_expenses = $other_expenses + $container_cost;

        $total_qty = 0;
        $total_sqft = 0;
        foreach ($items as $item) {
            $qty = (int) $item['pallets'] * (int) $item['qty_per_pallet'];
            $total_qty += $qty;
            $total_sqft += $qty * (float) ($item['square_feet'] ?? 0);
        }

        $net_qty = $total_qty - $damaged_qty;
        
        // Use total square feet for unit cost if provided, otherwise fallback to net_qty
        if ($total_sqft > 0) {
            $avg_sqft = $total_qty > 0 ? ($total_sqft / $total_qty) : 0;
            $net_sqft = $total_sqft - ($damaged_qty * $avg_sqft);
            $per_item_cost = ($net_sqft > 0) ? ($total_expenses / $net_sqft) : 0;
        } else {
            $per_item_cost = ($net_qty > 0) ? ($total_expenses / $net_qty) : 0;
        }

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
            if (!$brand_name)
                continue;

            // Ensure brand exists
            $stmt = $pdo->prepare("SELECT id FROM brands WHERE name = ?");
            $stmt->execute([$brand_name]);
            $brand_id = $stmt->fetchColumn();

            if (!$brand_id) {
                $stmt = $pdo->prepare("INSERT INTO brands (name) VALUES (?)");
                $stmt->execute([$brand_name]);
                $brand_id = $pdo->lastInsertId();
            }

            $pallets = (int) $item['pallets'];
            $qty_per_pallet = (int) $item['qty_per_pallet'];
            $square_feet = (float) ($item['square_feet'] ?? 0);
            $line_total = $pallets * $qty_per_pallet;

            $stmt = $pdo->prepare("INSERT INTO container_items (container_id, brand_id, pallets, qty_per_pallet, square_feet, total_qty) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$container_id, $brand_id, $pallets, $qty_per_pallet, $square_feet, $line_total]);
        }

        // 4. Handle Expenses
        $pdo->prepare("DELETE FROM container_expenses WHERE container_id = ?")->execute([$container_id]);
        foreach ($expenses as $exp) {
            if (!$exp['name'] || $exp['amount'] <= 0)
                continue;
            $stmt = $pdo->prepare("INSERT INTO container_expenses (container_id, expense_name, amount) VALUES (?, ?, ?)");
            $stmt->execute([$container_id, $exp['name'], $exp['amount']]);

            // Log Expense (truncated description for safety)
            $desc = substr('Added Expense: ' . $exp['name'], 0, 50);
            $lStmt = $pdo->prepare("INSERT INTO container_ledger (container_id, action_type, field_name, new_value, changed_by) VALUES (?, 'EXPENSE', ?, ?, ?)");
            $lStmt->execute([$container_id, $desc, "Rs. " . number_format((float) $exp['amount'], 2), $user_id]);
        }

        // 5. Handle Payments
        $payments = json_decode($_POST['payments'], true);
        $pdo->prepare("DELETE FROM container_payments WHERE container_id = ?")->execute([$container_id]);
        foreach ($payments as $pay) {
            if ($pay['amount'] <= 0)
                continue;
            $stmt = $pdo->prepare("INSERT INTO container_payments (container_id, payment_id, payment_type, method, amount, description) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$container_id, $pay['payment_id'], $pay['type'], $pay['method'], $pay['amount'], $pay['desc']]);

            // Log Payment (truncated description for safety)
            $method_desc = substr('Payment Method: ' . $pay['method'], 0, 50);
            $lStmt = $pdo->prepare("INSERT INTO container_ledger (container_id, action_type, field_name, new_value, changed_by) VALUES (?, 'PAYMENT', ?, ?, ?)");
            $lStmt->execute([$container_id, $method_desc, "Rs. " . number_format((float) $pay['amount'], 2), $user_id]);
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
$current_tab = $_GET['tab'] ?? 'containers';

// Pagination settings
$limit = 8;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

if ($current_tab === 'other') {
    if ($search) {
        $where[] = "(purchase_number LIKE ? OR buyer_name LIKE ? OR bill_number LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($start_date) {
        $where[] = "purchase_date >= ?";
        $params[] = $start_date;
    }
    if ($end_date) {
        $where[] = "purchase_date <= ?";
        $params[] = $end_date;
    }

    if ($payment_status === 'pending') {
        $where[] = "grand_total > (SELECT COALESCE(SUM(amount), 0) FROM other_purchase_payments WHERE purchase_id = other_purchases.id)";
    } elseif ($payment_status === 'completed') {
        $where[] = "grand_total <= (SELECT COALESCE(SUM(amount), 0) FROM other_purchase_payments WHERE purchase_id = other_purchases.id)";
    }

    $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM other_purchases $whereClause");
    $countStmt->execute($params);
    $total_records = $countStmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    $query = "SELECT *, 
              (SELECT GROUP_CONCAT(item_name SEPARATOR ', ') FROM other_purchase_items WHERE purchase_id = other_purchases.id) as item_names,
              (SELECT SUM(qty) FROM other_purchase_items WHERE purchase_id = other_purchases.id) as total_qty,
              (SELECT SUM(qty - sold_qty) FROM other_purchase_items WHERE purchase_id = other_purchases.id) as available_qty,
              COALESCE((SELECT SUM(amount) FROM other_purchase_payments WHERE purchase_id = other_purchases.id), 0) as total_paid
              FROM other_purchases 
              $whereClause 
              ORDER BY id DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
} else {
    // Containers Tab logic
    if ($search) {
        $where[] = "(c.container_number LIKE ? OR b.name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($start_date) {
        $where[] = "c.arrival_date >= ?";
        $params[] = $start_date;
    }
    if ($end_date) {
        $where[] = "c.arrival_date <= ?";
        $params[] = $end_date;
    }

    if ($payment_status === 'pending') {
        $where[] = "c.total_expenses > (SELECT COALESCE(SUM(amount), 0) FROM container_payments WHERE container_id = c.id)";
    } elseif ($payment_status === 'completed') {
        $where[] = "c.total_expenses <= (SELECT COALESCE(SUM(amount), 0) FROM container_payments WHERE container_id = c.id)";
    }

    $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

    $countQuery = "SELECT COUNT(DISTINCT c.id) FROM containers c
                   LEFT JOIN container_items ci ON c.id = ci.container_id
                   LEFT JOIN brands b ON ci.brand_id = b.id
                   $whereClause";
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $total_records = $countStmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    $query = "SELECT c.*, b.name as brand_name,
              (SELECT SUM(total_qty - sold_qty) FROM container_items WHERE container_id = c.id) as available_qty,
              COALESCE((SELECT SUM(amount) FROM container_payments WHERE container_id = c.id), 0) as total_paid
              FROM containers c
              LEFT JOIN (SELECT container_id, MIN(brand_id) as brand_id FROM container_items GROUP BY container_id) ci ON c.id = ci.container_id
              LEFT JOIN brands b ON ci.brand_id = b.id
              $whereClause
              ORDER BY CAST(c.container_number AS UNSIGNED) DESC, c.arrival_date DESC
              LIMIT $limit OFFSET $offset";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
}
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

        .text-glass-muted {
            color: #64748b;
        }

        .border-glass {
            border-color: rgba(203, 213, 225, 0.4);
        }

        /* Tab Styles */
        .tab-btn {
            padding: 12px 24px;
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            color: #64748b;
        }

        .tab-btn.active {
            border-bottom-color: #0891b2;
            color: #0891b2;
            background: rgba(8, 145, 178, 0.05);
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
    </style>
</head>

<body class="flex flex-col">

    <header class="glass-header sticky top-0 z-40 py-3">
        <div class="px-4 sm:px-10 flex items-center justify-between gap-2">
            <div class="flex items-center space-x-3 sm:space-x-4">
                <a href="dashboard.php" class="text-slate-800 hover:text-cyan-600 transition-colors">
                    <i class="fa-solid fa-arrow-left text-lg sm:text-xl"></i>
                </a>
                <h1
                    class="text-lg sm:text-2xl font-bold tracking-tight uppercase text-slate-800">
                    Inventory Registry</h1>
            </div>
            <?php if ($current_tab === 'other'): ?>
                <button id="btn-add-purchase" onclick="openPurchaseModal()"
                    class="bg-indigo-600 hover:bg-indigo-500 text-white px-3 sm:px-5 py-2 lg:py-2.5 rounded-xl font-bold text-xs sm:text-sm uppercase transition-all shadow-lg flex items-center space-x-2">
                    <i class="fa-solid fa-plus text-[10px] sm:text-xs"></i>
                    <span class="hidden xs:inline">Add Purchase</span>
                    <span class="xs:hidden">Add Purchase</span>
                </button>
            <?php else: ?>
                <button id="btn-add-container" onclick="openModal()"
                    class="bg-cyan-600 hover:bg-cyan-500 text-white px-3 sm:px-5 py-2 lg:py-2.5 rounded-xl font-bold text-xs sm:text-sm uppercase transition-all shadow-lg flex items-center space-x-2">
                    <i class="fa-solid fa-plus text-[10px] sm:text-xs text-white"></i>
                    <span class="hidden xs:inline">Add Container</span>
                    <span class="xs:hidden">Add a New Container</span>
                </button>
            <?php endif; ?>
        </div>
        <!-- Tabs -->
        <div class="px-4 sm:px-10 mt-4 flex border-b border-slate-200 overflow-x-auto whitespace-nowrap no-scrollbar scroll-smooth">
            <a href="?tab=containers"
                class="tab-btn <?php echo $current_tab === 'containers' ? 'active' : ''; ?>">Container
                Registry</a>
            <a href="?tab=other"
                class="tab-btn <?php echo $current_tab === 'other' ? 'active' : ''; ?>">Other
                Purchases</a>
        </div>
    </header>

    <!-- MAIN_SECTION_START -->
    <main class="w-full px-6 py-8 sm:py-10">
        <!-- Filters Bar -->
        <div class="glass-card bg-slate-800/80 p-4 sm:p-6 mb-8 border-slate-700">
            <form id="filter-form" method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-7 gap-4 items-end"
                onsubmit="event.preventDefault();">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($current_tab); ?>">
                <div class="sm:col-span-2 lg:col-span-2 relative">
                    <label
                        class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">Search</label>
                    <div class="relative">
                        <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                            placeholder="<?php echo $current_tab === 'other' ? 'ID, Buyer, Item...' : 'ID or Brand...'; ?>"
                            class="px-3 py-2 rounded-xl outline-none transition-all border focus:border-cyan-500 w-full pl-10 bg-slate-900/40 border-slate-700 text-white placeholder:text-slate-500 focus:ring-2 focus:ring-cyan-500/50 auto-search">
                    </div>
                </div>
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">Start
                        Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>"
                        class="px-3 py-2 rounded-xl outline-none transition-all border focus:border-cyan-500 w-full bg-slate-900/40 border-slate-700 text-white focus:ring-2 focus:ring-cyan-500/50">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">End
                        Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>"
                        class="px-3 py-2 rounded-xl outline-none transition-all border focus:border-cyan-500 w-full bg-slate-900/40 border-slate-700 text-white focus:ring-2 focus:ring-cyan-500/50">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">Payment
                        Status</label>
                    <select name="payment_status"
                        class="px-3 py-2 rounded-xl outline-none transition-all border focus:border-cyan-500 w-full bg-slate-900/40 border-slate-700 text-white focus:ring-2 focus:ring-cyan-500/50">
                        <option value="" class="bg-slate-800">All Status</option>
                        <option value="pending" <?php echo $payment_status === 'pending' ? 'selected' : ''; ?>
                            class="bg-slate-800">Pending</option>
                        <option value="completed" <?php echo $payment_status === 'completed' ? 'selected' : ''; ?>
                            class="bg-slate-800">Completed</option>
                    </select>
                </div>
                <div class="flex sm:col-span-2 lg:col-span-1 space-x-2" id="reset-filters-container">
                    <?php if ($search || $start_date || $end_date || $payment_status): ?>
                        <a href="?tab=<?php echo $current_tab; ?>" id="reset-filters-btn"
                            class="bg-rose-500/20 text-rose-400 p-2.5 px-4 rounded-xl hover:bg-rose-500/30 transition-all flex items-center h-[42px] w-full lg:w-auto justify-center"
                            title="Reset Filters">
                            <i class="fa-solid fa-rotate-left mr-2"></i>
                            <span class="text-xs font-bold uppercase tracking-wider">Reset</span>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <!-- List -->
        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left min-w-[1100px] lg:min-w-0">
                    <thead>
                        <?php if ($current_tab === 'other'): ?>
                            <tr
                                class="bg-indigo-700 text-[12px] uppercase tracking-wider text-white border-b border-indigo-800">
                                <th class="px-3 py-4 font-black">Purchase ID</th>
                                <th class="px-3 py-4 font-black">Buyer Name</th>
                                <th class="px-3 py-4 font-black">Item Names</th>
                                <th class="px-3 py-4 font-black">Bill / Invoice</th>
                                <th class="px-3 py-4 font-black">Date</th>
                                <th class="px-3 py-4 font-black text-indigo-100">Total Qty</th>
                                <th class="px-3 py-4 font-black text-white">Avl Qty</th>
                                <th class="px-3 py-4 font-black text-indigo-100">Total</th>
                                <th class="px-3 py-4 font-black text-emerald-400">Paid</th>
                                <th class="px-3 py-4 font-black text-rose-400">Remain</th>
                                <th class="px-3 py-4 font-black">Status</th>
                                <th class="px-3 py-4 text-center font-black">Action</th>
                            </tr>
                        <?php else: ?>
                            <tr
                                class="bg-slate-700 text-[12px] uppercase tracking-wider text-white border-b border-slate-800">
                                <th class="px-3 py-4 font-black">Container ID</th>
                                <th class="px-3 py-4 font-black">Brand</th>
                                <th class="px-3 py-4 font-black">Country</th>
                                <th class="px-3 py-4 font-black">Date</th>
                                <th class="px-3 py-4 font-black">Total Qty</th>
                                <th class="px-3 py-4 font-black">Avl Qty</th>
                                <th class="px-3 py-4 font-black text-amber-400">Damaged</th>
                                <th class="px-3 py-4 font-black text-emerald-400 whitespace-nowrap">Cost Per Sqft</th>
                                <th class="px-3 py-4 font-black whitespace-nowrap text-slate-100">Total Expenses</th>
                                <th class="px-3 py-4 font-black text-emerald-400 whitespace-nowrap">Total Paid</th>
                                <th class="px-3 py-4 font-black text-rose-400 whitespace-nowrap">Remain</th>
                                <th class="px-3 py-4 text-center font-black">Action</th>
                            </tr>
                        <?php endif; ?>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="12" class="px-6 py-10 text-center text-slate-500 italic">No records found
                                    matching your
                                    criteria.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($records as $r):
                            if ($current_tab === 'other'):
                                $isFullyPaid = ((float) $r['total_paid'] >= (float) $r['grand_total'] && (float) $r['grand_total'] > 0);
                                $rowClass = $isFullyPaid ? 'bg-indigo-50/30 font-bold' : 'bg-white hover:bg-slate-50';
                                ?>
                                <tr class="<?php echo $rowClass; ?> transition-colors border-b border-slate-100">
                                    <td class="px-3 py-4 text-sm font-bold text-indigo-600">
                                        <?php echo htmlspecialchars($r['purchase_number']); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm font-bold text-slate-800">
                                        <?php echo htmlspecialchars($r['buyer_name']); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm font-medium text-slate-600 truncate max-w-[150px]"
                                        title="<?php echo htmlspecialchars($r['item_names'] ?: '-'); ?>">
                                        <?php echo htmlspecialchars($r['item_names'] ?: '-'); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm text-slate-500 italic">
                                        <?php echo htmlspecialchars($r['bill_number'] ?: '-'); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm text-slate-500">
                                        <?php echo date('Y-m-d', strtotime($r['purchase_date'])); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm font-semibold text-slate-700">
                                        <?php echo number_format($r['total_qty']); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm font-bold text-center">
                                        <?php if ($r['available_qty'] <= 0): ?>
                                            <span class="text-[12px] font-black text-rose-700 uppercase tracking-widest">Out
                                                of Stock</span>
                                        <?php else: ?>
                                            <span class="text-indigo-600"><?php echo number_format($r['available_qty']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm font-bold text-slate-800">Rs.
                                        <?php echo number_format($r['grand_total'], 2); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm font-bold text-emerald-600">Rs.
                                        <?php echo number_format($r['total_paid'], 2); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm font-bold text-rose-600">Rs.
                                        <?php echo number_format($r['grand_total'] - $r['total_paid'], 2); ?>
                                    </td>
                                    <td class="px-3 py-4">
                                        <button
                                            onclick="openOtherPurchaseHistory(<?php echo $r['id']; ?>, '<?php echo addslashes($r['buyer_name']); ?>', <?php echo $r['grand_total'] - $r['total_paid']; ?>)"
                                            class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest transition-all <?php echo $isFullyPaid ? 'bg-blue-600 text-white' : ($r['total_paid'] > 0 ? 'bg-amber-400 text-slate-900' : 'bg-yellow-400 text-slate-900'); ?>">
                                            <?php echo $isFullyPaid ? 'PAID' : ($r['total_paid'] > 0 ? 'PARTIAL' : 'PENDING'); ?>
                                        </button>
                                    </td>
                                    <td class="px-3 py-4 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <button onclick="editPurchase(<?php echo $r['id']; ?>)"
                                                class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest hover:bg-black transition-all">
                                                Update
                                            </button>
                                            <button
                                                onclick="deletePurchase(<?php echo $r['id']; ?>, '<?php echo $r['purchase_number']; ?>')"
                                                class="bg-rose-600 text-white px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest hover:bg-black transition-all">
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php else:
                                $isFullyPaid = ($r['total_paid'] >= $r['total_expenses'] && $r['total_expenses'] > 0);
                                $rowClass = $isFullyPaid ? 'bg-green-100/40 hover:bg-green-100 font-bold' : 'odd:bg-gray-50/40 even:bg-gray-100/40 hover:bg-cyan-500/5';
                                ?>
                                <tr class="<?php echo $rowClass; ?> transition-colors">
                                    <td class="px-3 py-4 text-sm font-bold text-cyan-600 whitespace-nowrap">
                                        <?php echo htmlspecialchars($r['container_number']); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm font-bold text-slate-800">
                                        <?php echo htmlspecialchars($r['brand_name'] ?? '-'); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm font-medium text-slate-500">
                                        <?php echo htmlspecialchars($r['country'] ?? '-'); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm text-slate-600 whitespace-nowrap">
                                        <?php echo date('Y-m-d', strtotime($r['arrival_date'])); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm font-semibold text-slate-700">
                                        <?php echo number_format($r['total_qty']); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm font-bold text-center">
                                        <?php if ($r['available_qty'] <= 0): ?>
                                            <span class="text-[10px] font-black text-rose-600 uppercase tracking-widest">Out of
                                                Stock</span>
                                        <?php else: ?>
                                            <span class="text-cyan-600"><?php echo number_format($r['available_qty']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm font-bold text-amber-600">
                                        <?php echo number_format($r['damaged_qty']); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm font-bold text-emerald-600 whitespace-nowrap">Rs.
                                        <?php echo number_format($r['per_item_cost'], 2); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm font-bold text-slate-800 whitespace-nowrap">Rs.
                                        <?php echo number_format($r['total_expenses'], 2); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm font-bold text-emerald-600 whitespace-nowrap">Rs.
                                        <?php echo number_format($r['total_paid'], 2); ?>
                                    </td>
                                    <td class="px-3 py-4 text-sm font-bold text-rose-600 whitespace-nowrap">Rs.
                                        <?php echo number_format($r['total_expenses'] - $r['total_paid'], 2); ?>
                                    </td>
                                    <td class="px-3 py-4 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <button onclick="editContainer('<?php echo $r['container_number']; ?>')"
                                                class="bg-emerald-600 text-white px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest hover:bg-black transition-all shadow-lg shadow-emerald-600/10">
                                                Update
                                            </button>
                                            <button
                                                onclick="deleteContainer(<?php echo $r['id']; ?>, '<?php echo $r['container_number']; ?>')"
                                                class="bg-rose-600 text-white px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest hover:bg-black transition-all shadow-lg shadow-rose-600/10">
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Support -->
            <?php if ($total_pages > 1): ?>
                <div id="pagination-container"
                    class="px-4 sm:px-6 py-4 bg-slate-50 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="text-[10px] sm:text-xs text-slate-500 uppercase font-bold tracking-wider">
                        Showing <span class="text-slate-800"><?php echo $offset + 1; ?></span> to <span
                            class="text-slate-800"><?php echo min($offset + $limit, $total_records); ?></span> of <span
                            class="text-slate-800"><?php echo $total_records; ?></span> entries
                    </div>
                    <div class="flex items-center space-x-1 sm:space-x-2">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>"
                                class="p-2 sm:px-4 sm:py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg text-xs font-bold transition-all"><i
                                    class="fa-solid fa-chevron-left"></i></a>
                        <?php endif; ?>

                        <div class="flex items-center space-x-1">
                            <?php
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            for ($i = $start; $i <= $end; $i++):
                                ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                                    class="w-8 h-8 sm:w-10 sm:h-10 flex items-center justify-center rounded-lg text-xs font-bold transition-all <?php echo $page == $i ? 'bg-cyan-600 text-white shadow-lg shadow-cyan-900/20' : 'bg-white hover:bg-slate-50 border border-slate-200 text-slate-500'; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </div>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>"
                                class="p-2 sm:px-4 sm:py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg text-xs font-bold transition-all"><i
                                    class="fa-solid fa-chevron-right"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal Backdrop -->
    <div id="modal-container"
        class="fixed inset-0 bg-black/40 backdrop-blur-[3px] z-50 flex items-center justify-center p-2 sm:p-4 hidden">
        <div
            class="container-modal w-full max-w-6xl max-h-[95vh] overflow-y-auto rounded-[20px] sm:rounded-[30px] shadow-2xl">
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
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Container
                                Number</label>
                            <input type="text" name="container_number" id="container_number" class="input-glass"
                                required placeholder="0001" readonly>
                        </div>
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Arrival
                                Date</label>
                            <input type="date" name="arrival_date" id="arrival_date" class="input-glass" required
                                value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Country
                                (Optional)</label>
                            <input type="text" name="country" id="country" class="input-glass"
                                placeholder="e.g. China, Dubai">
                        </div>
                    </div>

                    <!-- Items Section -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Container
                                Items</label>
                            <button id="btn-add-item" type="button" onclick="addItemRow()"
                                class="text-cyan-600 hover:text-cyan-500 text-xs font-bold uppercase tracking-widest">+
                                Add Item</button>
                        </div>
                        <div id="items-list-header"
                            class="hidden lg:grid grid-cols-6 gap-3 px-4 py-2 mb-2 bg-slate-100 rounded-lg border border-slate-200">
                            <span
                                class="text-[10px] uppercase font-bold text-slate-600 tracking-widest col-span-2">Brand
                                Name</span>
                            <span class="text-[10px] uppercase font-bold text-slate-600 tracking-widest">Pallets</span>
                            <span class="text-[10px] uppercase font-bold text-slate-600 tracking-widest">Qty /
                                Pallet</span>
                            <span class="text-[10px] uppercase font-bold text-slate-600 tracking-widest">Square Feet</span>
                            <span
                                class="text-[10px] uppercase font-bold text-slate-600 tracking-widest text-center">Total</span>
                        </div>
                        <div id="items-list" class="space-y-3">
                            <!-- Dynamic Item Rows -->
                        </div>
                    </div>

                    <!-- Expenses Section -->
                    <div class="space-y-6">
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-cyan-600 tracking-wider">Base Container
                                Cost</label>
                            <input type="number" step="0.01" name="container_cost" id="container_cost"
                                class="input-glass border-cyan-500/20" oninput="calculateTotals()" placeholder="0.00">
                        </div>

                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Other
                                    Expenses</label>
                                <div class="flex space-x-2">
                                    <button type="button" onclick="addFreqExpense('Transport')" class="btn-freq">+
                                        Transport</button>
                                    <button type="button" onclick="addFreqExpense('Duty Charge')" class="btn-freq">+
                                        Duty Charge</button>
                                </div>
                            </div>
                            <div id="expenses-list" class="space-y-3">
                                <!-- Dynamic Expense Rows -->
                            </div>
                            <div id="add-exp-container" class="pt-2">
                                <button type="button" onclick="addExpenseRow()"
                                    class="w-full py-4 border-2 border-dashed  rounded-2xl  text-cyan-400 border-cyan-400/50 bg-cyan-400/5 hover:text-cyan-600 hover:border-cyan-600/50 hover:bg-cyan-600/5 transition-all flex items-center justify-center space-x-2 font-bold uppercase text-xs">
                                    <i class="fa-solid fa-plus-circle"></i>
                                    <span>Add Extra Expense</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Payments Section -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Payments
                                History</label>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Total to Pay:
                                <span id="pay-header-total" class="text-slate-800">Rs. 0.00</span>
                            </p>
                        </div>
                        <div id="payments-list" class="space-y-3">
                            <!-- Dynamic Payment Rows -->
                        </div>
                        <div id="add-pay-container" class="pt-2">
                            <button type="button" onclick="addPaymentRow()"
                                class="w-full py-4 border-2 border-dashed  rounded-2xl text-emerald-400 border-emerald-400/50 bg-emerald-400/5 hover:text-emerald-600 hover:border-emerald-600/50 hover:bg-emerald-600/5 transition-all flex items-center justify-center space-x-2 font-bold uppercase text-xs">
                                <i class="fa-solid fa-plus-circle"></i>
                                <span>Add New Payment</span>
                            </button>
                        </div>
                    </div>

                    <!-- Footer Info (Damaged, Totals) -->
                    <div class="pt-6 border-t border-slate-200 grid grid-cols-1 lg:grid-cols-5 gap-4 items-center">
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-amber-600 tracking-wider">Damaged Qty</label>
                            <input type="number" name="damaged_qty" id="damaged_qty"
                                class="input-glass border-amber-500/20 w-full" oninput="calculateTotals()"
                                placeholder="0">
                        </div>
                        <div
                            class="lg:col-span-4 glass-card bg-white/60 p-4 flex flex-col sm:flex-row justify-around items-center gap-4">
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
                                <p id="label-balance-due" class="text-[9px] uppercase font-bold text-rose-600 mb-1">
                                    Balance Due</p>
                                <p id="disp-balance-due" class="text-base font-bold text-rose-600">Rs. 0</p>
                            </div>
                            <div class="text-center px-4 flex-1">
                                <p class="text-[9px] uppercase font-bold text-cyan-600 mb-1">Unit Cost (Sqft)</p>
                                <p id="disp-per-item-cost" class="text-base font-bold text-cyan-600">Rs. 0</p>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row justify-end pt-1 gap-3">
                        <button type="button" onclick="closeModal()"
                            class="sm:hidden order-2 bg-slate-100 text-slate-600 font-bold py-3 px-6 rounded-2xl border border-slate-200">Cancel</button>
                        <button type="button" onclick="closeModal()"
                            class="order-1 bg-gradient-to-r from-cyan-600 to-blue-700 hover:from-cyan-500 hover:to-blue-600 text-white font-bold py-3 px-8 sm:px-12 rounded-2xl shadow-xl shadow-cyan-900/10 transition-all active:scale-95 text-sm sm:text-base">
                            Done
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Other Purchase Modal Backdrop -->
    <div id="purchase-modal-container"
        class="fixed inset-0 bg-black/40 backdrop-blur-[3px] z-50 flex items-center justify-center p-2 sm:p-4 hidden">
        <div
            class="container-modal w-full max-w-6xl max-h-[95vh] overflow-y-auto rounded-[20px] sm:rounded-[30px] shadow-2xl">
            <div class="p-4 sm:p-8">
                <div class="flex items-center justify-between mb-6 sm:mb-8 text-slate-800">
                    <div>
                        <h2 id="p-modal-title" class="text-3xl sm:text-4xl font-black text-slate-800 tracking-tight">Add New Purchase</h2>
                    </div>
                    <button onclick="closePurchaseModal()" class="text-slate-400 hover:text-slate-600">
                        <i class="fa-solid fa-times text-2xl"></i>
                    </button>
                </div>

                <form id="purchase-form" onsubmit="savePurchase(event)" class="space-y-6">
                    <input type="hidden" name="action" value="save_purchase">
                    <input type="hidden" id="p_id" name="id">

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-10">
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Purchase
                                Number</label>
                            <input type="text" name="purchase_number" id="p_purchase_number" class="input-glass"
                                readonly required>
                        </div>
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Date</label>
                            <input type="date" name="purchase_date" id="p_purchase_date" class="input-glass" required
                                value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Bill/Invoice
                                (Optional)</label>
                            <input type="text" name="bill_number" id="p_bill_number" class="input-glass"
                                placeholder="Inv-001">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-1 gap-6">
                        <div class="flex flex-col space-y-2 relative">
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Buyer Name</label>
                            <input type="text" name="buyer_name" id="p_buyer_name" class="input-glass" required
                                placeholder="Search or Type Buyer..." oninput="suggestBuyers(this)" autocomplete="off">
                            <div id="buyer-suggestions"
                                class="absolute left-0 top-full mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl hidden z-[100] max-h-40 overflow-y-auto">
                            </div>
                        </div>
                    </div>

                    <!-- Purchase Items Section -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Purchase
                                Items</label>
                            <button type="button" onclick="addPurchaseItemRow()"
                                class="text-indigo-600 hover:text-indigo-500 text-xs font-bold uppercase tracking-widest">+
                                Add Item</button>
                        </div>
                        <div id="p-items-list" class="space-y-3"></div>
                    </div>

                    <!-- Expenses for Purchase -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Purchase
                                Expenses</label>
                            <button type="button" onclick="addPurchaseExpenseRow()"
                                class="text-cyan-600 hover:text-cyan-500 text-xs font-bold uppercase tracking-widest">+
                                Add Expense</button>
                        </div>
                        <div id="p-expenses-list" class="space-y-3"></div>
                    </div>

                    <!-- Payments for Purchase -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <label class="text-xs uppercase font-bold text-slate-500 tracking-wider">Payments
                                History</label>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Paid: <span
                                    id="p-total-paid-disp" class="text-emerald-600">Rs. 0.00</span> | Bal: <span
                                    id="p-bal-due-disp" class="text-rose-600">Rs. 0.00</span></p>
                        </div>
                        <div id="p-payments-list" class="space-y-3"></div>
                        <button type="button" onclick="addPurchasePaymentRow()"
                            class="w-full py-3 border-2 border-dashed border-emerald-300 bg-emerald-50/30 text-emerald-600 rounded-xl font-bold uppercase text-xs hover:bg-emerald-50 transition-all">+
                            Add New Payment</button>
                    </div>

                    <div
                        class="flex flex-col md:flex-row justify-between items-end gap-4 pt-6 border-t border-slate-100 mt-4">
                        <div class="flex flex-wrap gap-10 md:gap-16 items-end w-full md:w-auto">
                            <div class="flex flex-col space-y-2">
                                <label class="text-xs uppercase font-black text-slate-500">Invoice Discount</label>
                                <input type="number" step="0.01" name="discount" id="p_discount"
                                    class="input-glass text-right w-44 h-[60px] text-xl font-black" oninput="calculatePurchaseTotals()"
                                    value="0">
                            </div>
                            <div class="flex flex-col space-y-2 px-6 border-l-2 border-slate-100">
                                <label class="text-xs uppercase font-black text-slate-400">Purchases Total</label>
                                <div id="p_disp_sub_total" class="text-2xl font-black text-slate-800 tracking-tight">Rs. 0.00</div>
                            </div>
                            <div class="flex flex-col space-y-2 px-6 border-l-2 border-slate-100">
                                <label class="text-xs uppercase font-black text-amber-500">Total Expenses</label>
                                <div id="p_disp_total_expenses" class="text-2xl font-black text-amber-600 tracking-tight">Rs. 0.00</div>
                            </div>
                            <div class="flex flex-col space-y-3 px-10 bg-indigo-50/50 rounded-[40px] py-4 border-2 border-indigo-100 shadow-sm ml-6">
                                <label class="text-xs uppercase font-black text-indigo-500">Grand Total Amount</label>
                                <div id="p_disp_grand_total" class="text-4xl font-black text-indigo-700 tracking-tighter">Rs. 0.00</div>
                            </div>
                        </div>
                        <div class="flex gap-3 h-[42px] w-full md:w-auto justify-end">
                            <button type="button" onclick="closePurchaseModal()"
                                class="px-6 py-3 bg-slate-100 text-slate-500 rounded-2xl font-bold hover:bg-slate-200 transition-colors">Cancel</button>
                            <button type="submit"
                                class="px-8 py-3 bg-indigo-600 text-white rounded-2xl font-bold shadow-xl shadow-indigo-900/10 hover:bg-indigo-700 transition-all flex items-center whitespace-nowrap">Save
                                Purchase</button>
                        </div>
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
                document.querySelectorAll('#container-form input, #container-form select, #container-form textarea').forEach(input => input.disabled = true);
                addButtons.forEach(btn => btn.classList.add('hidden'));
            } else {
                document.querySelectorAll('#container-form input, #container-form select, #container-form textarea').forEach(input => input.disabled = false);
                addButtons.forEach(btn => btn.classList.remove('hidden'));

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
                <div class="item-row grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 bg-white/40 p-4 rounded-xl border border-slate-200/60 relative group shadow-sm" id="item_${rowId}">
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
                    <div>
                        <label class="text-[9px] uppercase font-bold text-slate-400 mb-1 lg:hidden block">Square Feet</label>
                        <input type="number" step="0.001" placeholder="0.00" class="input-glass sqft-input w-full" oninput="calculateTotals()" required value="${data ? data.square_feet : ''}" ${currentMode === 'view' ? 'disabled' : ''}>
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
            document.getElementById('container-form').dispatchEvent(new Event('input', { bubbles: true }));
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
            document.getElementById('container-form').dispatchEvent(new Event('input', { bubbles: true }));
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
            document.getElementById('container-form').dispatchEvent(new Event('input', { bubbles: true }));
        }

        function addFreqExpense(name) {
            addExpenseRow(name);
        }

        function removeRow(id) {
            document.getElementById(id).remove();
            calculateTotals();
            document.getElementById('container-form').dispatchEvent(new Event('input', { bubbles: true }));
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
                            <div class="px-3 py-2 text-sm text-purple-900 hover:bg-purple-500/20 hover:text-purple-700 cursor-pointer border-b border-white/5 last:border-0 transition-colors flex items-center gap-2"
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
            document.getElementById('container-form').dispatchEvent(new Event('input', { bubbles: true }));
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
            let totalSqft = 0;
            document.querySelectorAll('.item-row').forEach(row => {
                const p = parseInt(row.querySelector('.pallets-input').value || 0);
                const q = parseInt(row.querySelector('.qty-input').value || 0);
                const s = parseFloat(row.querySelector('.sqft-input').value || 0);
                const rowTotal = p * q;
                row.querySelector('.row-total-qty').innerText = rowTotal.toLocaleString();
                totalQty += rowTotal;
                totalSqft += rowTotal * s;
            });

            const damagedQty = parseInt(document.getElementById('damaged_qty').value || 0);
            const netQty = totalQty - damagedQty;
            
            let perItemCost = 0;
            if (totalSqft > 0) {
                const avgSqft = totalQty > 0 ? (totalSqft / totalQty) : 0;
                const netSqft = totalSqft - (damagedQty * avgSqft);
                perItemCost = (netSqft > 0) ? (totalExpenses / netSqft) : 0;
            } else {
                perItemCost = (netQty > 0) ? (totalExpenses / netQty) : 0;
            }
            
            const balanceDue = Math.max(0, totalExpenses - totalPaid);

            document.getElementById('disp-total-expenses').innerText = "Rs. " + totalExpenses.toLocaleString('en-US', { minimumFractionDigits: 2 });
            document.getElementById('pay-header-total').innerText = "Rs. " + totalExpenses.toLocaleString('en-US', { minimumFractionDigits: 2 });
            document.getElementById('disp-grand-total-qty').innerText = netQty.toLocaleString();
            document.getElementById('disp-per-item-cost').innerText = "Rs. " + perItemCost.toLocaleString('en-US', { minimumFractionDigits: 2 });

            const dispPaid = document.getElementById('disp-total-paid');
            const dispBalance = document.getElementById('disp-balance-due');
            if (dispPaid) dispPaid.innerText = "Rs. " + totalPaid.toLocaleString('en-US', { minimumFractionDigits: 2 });
            if (dispBalance) {
                dispBalance.innerText = "Rs. " + balanceDue.toLocaleString('en-US', { minimumFractionDigits: 2 });
                dispBalance.classList.toggle('text-rose-400', balanceDue > 0);
                dispBalance.classList.toggle('text-emerald-400', balanceDue === 0);
                document.getElementById('label-balance-due').innerText = "Balance Due";
            }
        }

        function refreshTable() {
            fetch(window.location.href)
                .then(r => r.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newTbody = doc.querySelector('tbody');
                    if (newTbody) {
                        document.querySelector('tbody').innerHTML = newTbody.innerHTML;
                    }
                });
        }

        let autoSaveTimer;
        document.getElementById('container-form').addEventListener('input', function () {
            if (currentMode === 'view') return;
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(() => {
                saveContainer(null, true);
            }, 800);
        });

        document.getElementById('container-form').addEventListener('change', function () {
            if (currentMode === 'view') return;
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(() => {
                saveContainer(null, true);
            }, 800);
        });

        function saveContainer(e = null, isAutoSave = false) {
            if (e) e.preventDefault();

            // Guard: Prevent overpayment before submission
            let totalExp = parseFloat(document.getElementById('container_cost').value || 0);
            document.querySelectorAll('.exp-amount').forEach(el => totalExp += parseFloat(el.value || 0));
            let totalPd = 0;
            document.querySelectorAll('.pay-amount').forEach(el => totalPd += parseFloat(el.value || 0));
            if (totalPd > totalExp) {
                if (!isAutoSave) alert(`Error: Total payments (Rs. ${totalPd.toLocaleString()}) exceed total expenses (Rs. ${totalExp.toLocaleString()}). Please adjust before saving.`);
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
                    qty_per_pallet: row.querySelector('.qty-input').value,
                    square_feet: row.querySelector('.sqft-input').value
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
                        if (!isAutoSave) {
                            location.reload();
                        } else {
                            refreshTable(); // Update background table implicitly
                        }
                    } else {
                        if (!isAutoSave) alert("Error: " + data.message);
                    }
                })
                .catch(err => console.error("Auto-save error:", err));
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

        // Auto-search AJAX implementation
        let mainSearchTimeout;
        const filterForm = document.getElementById('filter-form');

        function handleAjaxSearch(providedUrl = null) {
            clearTimeout(mainSearchTimeout);
            mainSearchTimeout = setTimeout(() => {
                const url = providedUrl || ('?' + new URLSearchParams(new FormData(filterForm)).toString());

                // Keep URL updated for copy-pasting/refreshing
                window.history.replaceState({}, '', url);

                fetch(url)
                    .then(r => r.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');

                        // Update main table
                        const newTbody = doc.querySelector('tbody');
                        if (newTbody) {
                            document.querySelector('tbody').innerHTML = newTbody.innerHTML;
                        }

                        // Update Pagination
                        const oldPagination = document.getElementById('pagination-container');
                        const newPagination = doc.getElementById('pagination-container');
                        if (oldPagination && newPagination) {
                            oldPagination.outerHTML = newPagination.outerHTML;
                        } else if (!oldPagination && newPagination) {
                            document.querySelector('.overflow-x-auto').insertAdjacentHTML('afterend', newPagination.outerHTML);
                        } else if (oldPagination && !newPagination) {
                            oldPagination.remove();
                        }

                        // Update Reset button container
                        const oldResetBtn = document.getElementById('reset-filters-container');
                        const newResetBtn = doc.getElementById('reset-filters-container');
                        if (oldResetBtn && newResetBtn) {
                            oldResetBtn.innerHTML = newResetBtn.innerHTML;
                        }
                    });
            }, providedUrl ? 0 : 350); // Fast 350ms delay for smooth typing
        }

        // Hook up search input specifically for input event prioritizing letter-by-letter live search
        document.querySelector('.auto-search').addEventListener('input', () => handleAjaxSearch());

        // Hook up date/select filters for change event natively without full refresh
        filterForm.querySelectorAll('input[type="date"], select').forEach(el => {
            el.addEventListener('change', () => handleAjaxSearch());
        });

        // Intercept pagination anchors and reset buttons to be smooth AJAX as well
        document.addEventListener('click', function (e) {
            const pageLink = e.target.closest('#pagination-container a');
            if (pageLink) {
                e.preventDefault();
                handleAjaxSearch(pageLink.getAttribute('href'));
            }
            const resetLink = e.target.closest('#reset-filters-btn');
            if (resetLink) {
                e.preventDefault();
                filterForm.reset();
                filterForm.querySelectorAll('input:not([type="hidden"]), select').forEach(i => i.value = '');
                handleAjaxSearch(resetLink.getAttribute('href'));
            }
        });
        // --- Other Purchases Logic ---
        const purchaseModal = document.getElementById('purchase-modal-container');
        const pExpensesList = document.getElementById('p-expenses-list');
        const pPaymentsList = document.getElementById('p-payments-list');

        function openPurchaseModal(mode = 'add') {
            document.getElementById('p-modal-title').innerText = mode === 'add' ? "Add New Purchase" : "Edit Purchase";
            document.getElementById('purchase-form').reset();
            document.getElementById('p_id').value = '';
            document.getElementById('p-items-list').innerHTML = '';
            pExpensesList.innerHTML = '';
            pPaymentsList.innerHTML = '';

            if (mode === 'add') {
                fetch('?action=get_next_purchase_number')
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('p_purchase_number').value = data.next;
                        }
                    });
            }

            purchaseModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            calculatePurchaseTotals();
        }

        function closePurchaseModal() {
            purchaseModal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }

        function addPurchaseItemRow(name = '', qty = '', sqft = '', price = '', category = 'Other') {
            const rowId = 'p_item_' + Date.now() + Math.random();
            const isGlass = category === 'Glass';
            const html = `
                <div class="flex flex-row items-end gap-x-4 bg-slate-50/50 p-6 sm:p-7 rounded-3xl border-2 border-slate-100 relative group w-full p-item-row" id="${rowId}">
                    <div class="w-36 flex flex-col space-y-2">
                        <label class="text-xs uppercase font-black text-slate-500 ml-1">Category</label>
                        <select class="p_item_category input-glass h-[52px] text-base font-bold" onchange="togglePurchaseSqft(this)">
                            <option value="Other" ${category === 'Other' ? 'selected' : ''}>Other</option>
                            <option value="Glass" ${category === 'Glass' ? 'selected' : ''}>Glass</option>
                        </select>
                    </div>
                    <div class="flex-1 flex flex-col space-y-2 min-w-[150px]">
                        <label class="text-xs uppercase font-black text-slate-500 ml-1">Item Name</label>
                        <input type="text" class="p_item_name input-glass h-[52px] text-base" value="${name}" required placeholder="Name">
                    </div>
                    <div class="w-28 flex flex-col space-y-2">
                        <label class="text-xs uppercase font-black text-slate-500 ml-1">Qty (Pcs)</label>
                        <input type="number" class="p_item_qty input-glass h-[52px] text-base text-center font-bold" value="${qty}" required oninput="calculatePurchaseTotals()" placeholder="0">
                    </div>
                    <div class="w-28 p_sqft_container flex flex-col space-y-2 ${isGlass ? '' : 'hidden'}">
                        <label class="text-xs uppercase font-black text-slate-500 ml-1">Sqft</label>
                        <input type="number" step="0.001" class="p_item_sqft input-glass h-[52px] text-base text-center font-bold" value="${sqft}" oninput="calculatePurchaseTotals()" placeholder="0.00">
                    </div>
                    <div class="w-40 flex flex-col space-y-2">
                        <label class="text-xs uppercase font-black text-slate-500 ml-1">Price/Unit</label>
                        <input type="number" step="0.01" class="p_item_price input-glass h-[52px] text-lg text-right font-black text-indigo-600" value="${price}" required oninput="calculatePurchaseTotals()" placeholder="0.00">
                    </div>
                    <div class="w-44 p_cost_sqft_container flex flex-col space-y-2 ${isGlass ? '' : 'hidden'}">
                        <label class="text-xs uppercase font-black text-cyan-600 ml-1">Cost/Sqft</label>
                        <div class="p_disp_cost_sqft h-[52px] flex items-center justify-end px-4 bg-white rounded-2xl border-2 border-cyan-100 font-black text-cyan-600 text-base whitespace-nowrap overflow-hidden">0.00</div>
                    </div>
                    <div class="w-52 flex flex-col space-y-2">
                        <label class="text-xs uppercase font-black text-indigo-600 ml-1">Line Total</label>
                        <div class="p_disp_line_total h-[52px] flex items-center justify-end px-4 bg-white rounded-2xl border-2 border-indigo-100 font-black text-indigo-600 text-base shadow-sm whitespace-nowrap overflow-hidden">0.00</div>
                    </div>
                    <button type="button" onclick="document.getElementById('${rowId}').remove(); calculatePurchaseTotals();" class="absolute -right-4 -top-4 bg-rose-500 text-white w-9 h-9 rounded-full text-sm items-center justify-center flex shadow-xl hover:scale-110 active:scale-95 transition-all">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
            `;
            document.getElementById('p-items-list').insertAdjacentHTML('beforeend', html);
        }

        function togglePurchaseSqft(select) {
            const row = select.closest('.group');
            const sqftCont = row.querySelector('.p_sqft_container');
            const costSqftCont = row.querySelector('.p_cost_sqft_container');
            
            if (select.value === 'Glass') {
                sqftCont.classList.remove('hidden');
                costSqftCont.classList.remove('hidden');
            } else {
                sqftCont.classList.add('hidden');
                costSqftCont.classList.add('hidden');
                row.querySelector('.p_item_sqft').value = '';
            }
            calculatePurchaseTotals();
        }

        function addPurchaseExpenseRow(name = '', amount = '') {
            const rowId = 'p_exp_' + Date.now() + Math.random();
            const html = `
                <div class="grid grid-cols-2 lg:grid-cols-3 gap-3 items-center group relative bg-white/40 p-3 rounded-xl border border-slate-200/60 shadow-sm" id="${rowId}">
                    <div class="col-span-1 lg:col-span-2">
                        <input type="text" name="expense_names[]" placeholder="Expense Name" class="input-glass w-full" value="${name}" required>
                    </div>
                    <div class="col-span-1">
                        <input type="number" step="0.01" name="expense_amounts[]" placeholder="Amount" class="input-glass w-full text-right" oninput="calculatePurchaseTotals()" value="${amount}" required>
                    </div>
                    <button type="button" onclick="document.getElementById('${rowId}').remove(); calculatePurchaseTotals();" class="absolute -right-2 -top-2 bg-rose-500 text-white w-6 h-6 rounded-full text-[10px] items-center justify-center flex shadow-lg hover:scale-110">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
            `;
            pExpensesList.insertAdjacentHTML('beforeend', html);
        }

        function addPurchasePaymentRow(data = null) {
            const rowId = 'p_pay_' + Date.now() + Math.random();
            const method = data ? data.method : 'Cash';
            const html = `
                <div class="bg-white/50 p-6 sm:p-8 rounded-[30px] border-2 border-slate-200 relative group space-y-5" id="${rowId}">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                        <div>
                            <label class="text-xs uppercase font-black text-slate-500 ml-1">Method</label>
                            <select class="p_pay_method input-glass w-full text-base h-[52px] font-bold" onchange="togglePurchasePayFields(this)" required>
                                <option value="Cash" ${method === 'Cash' ? 'selected' : ''}>Cash</option>
                                <option value="Account Transfer" ${method === 'Account Transfer' ? 'selected' : ''}>Account Transfer</option>
                                <option value="Cheque" ${method === 'Cheque' ? 'selected' : ''}>Cheque</option>
                            </select>
                        </div>
                        <div class="p_pay_bank_cont ${method !== 'Cash' ? '' : 'hidden'}">
                            <label class="text-xs uppercase font-black text-slate-500 ml-1">Bank Name</label>
                            <input type="text" class="p_pay_bank input-glass w-full text-base h-[52px]" placeholder="Search saved banks..." oninput="suggestBanks(this)" value="${data ? data.bank_name : ''}" autocomplete="off">
                            <div class="bank-suggestions absolute left-0 top-full mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl hidden z-[100] max-h-40 overflow-y-auto"></div>
                        </div>
                        <div>
                            <label class="text-xs uppercase font-black text-slate-500 ml-1">Amount</label>
                            <input type="number" step="0.01" class="p_pay_amount input-glass w-full text-right font-black text-lg h-[52px] text-emerald-600" oninput="calculatePurchaseTotals()" value="${data ? data.amount : ''}" required>
                        </div>
                        <div>
                            <label class="text-xs uppercase font-black text-slate-500 ml-1">Date</label>
                            <input type="date" class="p_pay_date input-glass w-full text-base h-[52px] font-bold" value="${data ? data.payment_date : '<?php echo date('Y-m-d'); ?>'}" required>
                        </div>
                    </div>
                    
                    <div class="p_pay_extra_fields ${method === 'Cheque' ? '' : 'hidden'} grid grid-cols-1 md:grid-cols-2 gap-8 pb-2 transition-all">
                        <div>
                            <label class="text-xs uppercase font-black text-slate-500 ml-1">Cheque Number</label>
                            <input type="text" class="p_pay_chq_no input-glass w-full text-base h-[52px] font-bold" placeholder="XXXXXX" value="${data ? data.cheque_number : ''}">
                        </div>
                        <div>
                            <label class="text-xs uppercase font-black text-slate-500 ml-1">Payer Name</label>
                            <input type="text" class="p_pay_payer input-glass w-full text-base h-[52px]" placeholder="Optional" value="${data ? data.payer_name : ''}">
                        </div>
                    </div>

                    <div class="pb-1">
                        <label class="text-xs uppercase font-black text-slate-500 ml-1">Description / Memo</label>
                        <input type="text" class="p_pay_desc input-glass w-full text-base h-[52px]" placeholder="Optional notes..." value="${data ? data.description : ''}">
                    </div>

                    <button type="button" onclick="document.getElementById('${rowId}').remove(); calculatePurchaseTotals();" class="absolute -right-4 -top-4 bg-rose-500 text-white w-9 h-9 rounded-full text-sm flex items-center justify-center shadow-xl hover:scale-110 active:scale-95 transition-all">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
            `;
            pPaymentsList.insertAdjacentHTML('beforeend', html);
        }

        function togglePurchasePayFields(select) {
            const row = select.closest('.group');
            const method = select.value;
            row.querySelector('.p_pay_bank_cont').classList.toggle('hidden', method === 'Cash');
            row.querySelector('.p_pay_extra_fields').classList.toggle('hidden', method !== 'Cheque');
        }

        function calculatePurchaseTotals() {
            let subtotal = 0;
            const items = document.querySelectorAll('#p-items-list .p-item-row');
            items.forEach(row => {
                const category = row.querySelector('.p_item_category').value;
                const qty = parseFloat(row.querySelector('.p_item_qty').value) || 0;
                const sqft = parseFloat(row.querySelector('.p_item_sqft').value) || 0;
                const price = parseFloat(row.querySelector('.p_item_price').value) || 0;

                const lineTotal = qty * price;
                row.querySelector('.p_disp_line_total').innerText = 'Rs. ' + lineTotal.toLocaleString(undefined, { minimumFractionDigits: 2 });

                if (category === 'Glass' && sqft > 0) {
                    const costSqft = lineTotal / sqft;
                    row.querySelector('.p_disp_cost_sqft').innerText = costSqft.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }

                subtotal += lineTotal;
            });

            document.getElementById('p_disp_sub_total').innerText = 'Rs. ' + subtotal.toLocaleString(undefined, { minimumFractionDigits: 2 });

            const discount = parseFloat(document.getElementById('p_discount').value) || 0;
            const discountedSubtotal = subtotal - discount;

            // Add expenses
            let expenses = 0;
            document.querySelectorAll('input[name="expense_amounts[]"]').forEach(input => {
                expenses += parseFloat(input.value) || 0;
            });

            document.getElementById('p_disp_total_expenses').innerText = 'Rs. ' + expenses.toLocaleString(undefined, { minimumFractionDigits: 2 });

            const grandTotal = discountedSubtotal + expenses;
            document.getElementById('p_disp_grand_total').innerText = 'Rs. ' + grandTotal.toLocaleString(undefined, { minimumFractionDigits: 2 });

            // Add payments
            let paid = 0;
            document.querySelectorAll('.p_pay_amount').forEach(input => {
                paid += parseFloat(input.value) || 0;
            });

            document.getElementById('p-total-paid-disp').innerText = 'Rs. ' + paid.toLocaleString(undefined, { minimumFractionDigits: 2 });
            document.getElementById('p-bal-due-disp').innerText = 'Rs. ' + (grandTotal - paid).toLocaleString(undefined, { minimumFractionDigits: 2 });
        }

        function suggestBuyers(input) {
            const val = input.value;
            const resBox = document.getElementById('buyer-suggestions');
            if (val.length < 1) { resBox.classList.add('hidden'); return; }

            fetch(`?action=search_buyer&term=${encodeURIComponent(val)}`)
                .then(r => r.json())
                .then(data => {
                    resBox.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(name => {
                            const div = document.createElement('div');
                            div.className = 'px-4 py-2 hover:bg-slate-50 cursor-pointer text-sm font-medium border-b border-slate-50 last:border-0 text-purple-900';
                            div.innerText = name;
                            div.onclick = () => { input.value = name; resBox.classList.add('hidden'); };
                            resBox.appendChild(div);
                        });
                        resBox.classList.remove('hidden');
                    } else {
                        resBox.classList.add('hidden');
                    }
                });
        }

        function suggestBanks(input) {
            const val = input.value;
            // Create suggestion box if not exists
            let resBox = input.parentNode.querySelector('.bank-suggestions');
            if (!resBox) {
                resBox = document.createElement('div');
                resBox.className = 'bank-suggestions absolute left-0 top-full mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl hidden z-[100] max-h-40 overflow-y-auto';
                input.parentNode.style.position = 'relative';
                input.parentNode.appendChild(resBox);
            }

            if (val.length < 1) { resBox.classList.add('hidden'); return; }

            fetch(`?action=search_bank&term=${encodeURIComponent(val)}`)
                .then(r => r.json())
                .then(data => {
                    resBox.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(b => {
                            const div = document.createElement('div');
                            div.className = 'px-4 py-2 hover:bg-slate-50 cursor-pointer text-sm font-medium border-b border-slate-50 last:border-0 text-purple-900';
                            div.innerHTML = `<p class="text-xs font-black text-slate-800 uppercase">${b.name}</p><p class="text-[9px] font-bold text-slate-400">ACC: ${b.account_number || 'N/A'}</p>`;
                            div.onclick = () => { input.value = b.name; resBox.classList.add('hidden'); };
                            resBox.appendChild(div);
                        });
                        resBox.classList.remove('hidden');
                    } else {
                        resBox.innerHTML = `<div class="p-3 text-center"><p class="text-[9px] font-black text-slate-400 uppercase mb-2">Not found</p><button type="button" onclick="openOpCreateBankModal('${val.replace(/'/g, "\\'")}')" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-2 rounded-xl text-[10px] font-black uppercase">Create New</button></div>`;
                        resBox.classList.remove('hidden');
                    }
                });
        }

        function savePurchase(e) {
            e.preventDefault();
            const formData = new FormData();
            formData.append('action', 'save_purchase');
            formData.append('id', document.getElementById('p_id').value);
            formData.append('purchase_number', document.getElementById('p_purchase_number').value);
            formData.append('purchase_date', document.getElementById('p_purchase_date').value);
            formData.append('bill_number', document.getElementById('p_bill_number').value);
            formData.append('buyer_name', document.getElementById('p_buyer_name').value);
            formData.append('discount', document.getElementById('p_discount').value);

            const p_items = [];
            document.querySelectorAll('#p-items-list .p-item-row').forEach(row => {
                p_items.push({
                    category: row.querySelector('.p_item_category').value,
                    name: row.querySelector('.p_item_name').value,
                    qty: row.querySelector('.p_item_qty').value,
                    sqft: row.querySelector('.p_item_sqft').value,
                    price: row.querySelector('.p_item_price').value
                });
            });
            formData.append('items', JSON.stringify(p_items));

            const expenses = [];
            document.querySelectorAll('#p-expenses-list .grid').forEach(row => {
                expenses.push({
                    name: row.querySelector('input[name="expense_names[]"]').value,
                    amount: row.querySelector('input[name="expense_amounts[]"]').value
                });
            });
            formData.append('expenses', JSON.stringify(expenses));

            const payments = [];
            document.querySelectorAll('#p-payments-list .group').forEach(row => {
                payments.push({
                    method: row.querySelector('.p_pay_method').value,
                    bank_name: row.querySelector('.p_pay_bank').value,
                    amount: row.querySelector('.p_pay_amount').value,
                    payment_date: row.querySelector('.p_pay_date').value,
                    cheque_number: row.querySelector('.p_pay_chq_no').value,
                    payer_name: row.querySelector('.p_pay_payer').value,
                    description: row.querySelector('.p_pay_desc').value
                });
            });
            formData.append('payments', JSON.stringify(payments));

            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        location.reload();
                    } else {
                        alert(res.message);
                    }
                });
        }

        function editPurchase(id) {
            fetch(`?action=get_purchase_details&id=${id}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        openPurchaseModal('edit');
                        const d = res.data;
                        document.getElementById('p_id').value = d.id;
                        document.getElementById('p_purchase_number').value = d.purchase_number;
                        document.getElementById('p_purchase_date').value = d.purchase_date;
                        document.getElementById('p_bill_number').value = d.bill_number;
                        document.getElementById('p_buyer_name').value = d.buyer_name;
                        document.getElementById('p_discount').value = d.discount;

                        document.getElementById('p-items-list').innerHTML = '';
                        d.items.forEach(it => addPurchaseItemRow(it.item_name, it.qty, it.square_feet, it.price_per_item, it.category));

                        pExpensesList.innerHTML = '';
                        d.expenses.forEach(ex => addPurchaseExpenseRow(ex.expense_name, ex.amount));

                        pPaymentsList.innerHTML = '';
                        d.payments.forEach(py => addPurchasePaymentRow({
                            method: py.method,
                            bank_name: py.bank_name,
                            amount: py.amount,
                            payment_date: py.payment_date || py.created_at,
                            cheque_number: py.cheque_number,
                            payer_name: py.payer_name,
                            description: py.description
                        }));

                        calculatePurchaseTotals();
                    }
                });
        }

        function deletePurchase(id, no) {
            if (confirm(`Are you sure you want to delete purchase ${no}?`)) {
                const formData = new FormData();
                formData.append('action', 'delete_purchase');
                formData.append('id', id);
                fetch('', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) location.reload();
                        else alert(res.message);
                    });
            }
        }
    </script>

    <!-- Payment Modals for Other Purchases -->
    <!-- Modal: Add Payment -->
    <div id="op-add-payment-modal"
        class="fixed inset-0 bg-slate-900/80 backdrop-blur-md z-[100] flex items-center justify-center p-4 hidden">
        <div
            class="bg-white/95 backdrop-blur-xl border border-slate-200 w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-3xl p-1 text-slate-800 shadow-2xl">
            <div class="p-6">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h3 class="text-xl font-black text-slate-900 font-['Outfit']">Record New Payment</h3>
                        <p id="op-add-payment-cust-name"
                            class="text-[10px] uppercase font-black text-indigo-500 tracking-widest mt-1">SUPPLIER /
                            BUYER NAME</p>
                    </div>
                    <button onclick="closeOtherPurchaseAddPayment()"
                        class="text-slate-400 hover:text-rose-500 transition-colors"><i
                            class="fa-solid fa-times text-2xl"></i></button>
                </div>

                <form id="op-payment-form" class="space-y-6">
                    <input type="hidden" name="tab" value="given">
                    <input type="hidden" name="ref_id" id="op_payment_ref_id">

                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2 sm:col-span-1">
                            <label
                                class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Amount
                                (LKR)</label>
                            <input type="number" step="0.01" name="amount" id="op_payment_amount"
                                class="input-glass w-full" required>
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <label
                                class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Payment
                                Type</label>
                            <select name="type" id="op_payment_type"
                                class="input-glass w-full appearance-none cursor-pointer"
                                onchange="toggleOpPaymentFields()" required>
                                <option value="Cash">Cash</option>
                                <option value="Account Transfer">Account Transfer</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Card">Card</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2 sm:col-span-1">
                            <label
                                class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Transaction
                                Date</label>
                            <input type="date" name="date" class="input-glass w-full"
                                value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <!-- Bank Section -->
                    <div id="op_bank_section" class="hidden space-y-4 animate-[fadeIn_0.3s_ease]">
                        <div class="relative">
                            <label
                                class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Select
                                Bank Account</label>
                            <input type="text" placeholder="Search saved banks..." class="input-glass w-full"
                                onkeyup="searchOpBanks(this.value)">
                            <div id="op_bank_results"
                                class="hidden absolute w-full top-[110%] left-0 z-30 bg-white/95 backdrop-blur-xl border border-slate-200 rounded-2xl shadow-2xl p-2 max-h-[200px] overflow-y-auto">
                            </div>
                            <input type="hidden" name="bank_id" id="op_selected_bank_id">
                        </div>
                        <div id="op_selected_bank_info"
                            class="hidden bg-indigo-50 p-4 rounded-2xl border border-indigo-100 flex items-center justify-between">
                            <div>
                                <p id="op_disp_bank_name" class="text-sm font-black text-indigo-900"></p>
                                <p id="op_disp_bank_acc" class="text-[10px] font-bold text-indigo-400"></p>
                            </div>
                            <button type="button" onclick="clearOpBank()"
                                class="text-rose-500 hover:scale-110 transition-transform"><i
                                    class="fa-solid fa-circle-xmark"></i></button>
                        </div>
                    </div>

                    <!-- Cheque Section -->
                    <div id="op_cheque_section" class="hidden space-y-4 animate-[fadeIn_0.3s_ease]">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="col-span-2 sm:col-span-1">
                                <label
                                    class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Cheque
                                    Number</label>
                                <input type="text" name="chq_no" class="input-glass w-full" placeholder="XXXXXX">
                            </div>
                            <div class="col-span-2 sm:col-span-1">
                                <label
                                    class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Cheque
                                    Payer</label>
                                <input type="text" name="chq_payer" placeholder="Enter payer name (Optional)"
                                    class="input-glass w-full">
                            </div>
                        </div>
                    </div>

                    <!-- Proof Section -->
                    <div id="op_proof_section" class="hidden animate-[fadeIn_0.3s_ease]">
                        <label
                            class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Payment
                            Proof (Image)</label>
                        <div class="relative">
                            <input type="file" name="proof" id="op_payment_proof" class="hidden" accept="image/*"
                                onchange="previewOpProof(this)">
                            <button type="button" onclick="document.getElementById('op_payment_proof').click()"
                                class="w-full flex items-center justify-center gap-3 p-8 border-2 border-dashed border-slate-300 rounded-2xl hover:bg-slate-50 hover:border-indigo-400 transition-all text-slate-400 group">
                                <i
                                    class="fa-solid fa-cloud-arrow-up text-3xl group-hover:scale-110 transition-transform"></i>
                                <span class="text-[10px] font-black uppercase tracking-widest">Click to upload
                                    scan/photo</span>
                            </button>
                            <div id="op_proof_preview"
                                class="hidden mt-4 relative rounded-2xl overflow-hidden border border-slate-100">
                                <img src="" alt="Proof Preview" class="w-full h-auto">
                                <button type="button" onclick="clearOpProof()"
                                    class="absolute top-3 right-3 bg-rose-500 text-white w-8 h-8 rounded-full flex items-center justify-center shadow-lg"><i
                                        class="fa-solid fa-times"></i></button>
                            </div>
                        </div>
                    </div>

                    <button type="submit"
                        class="w-full bg-slate-900 hover:bg-black text-white py-5 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-xl transition-all active:scale-[0.98]">
                        Confirm Save Payment
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: History -->
    <div id="op-history-modal"
        class="fixed inset-0 bg-slate-900/80 backdrop-blur-md z-[90] flex items-center justify-center p-4 hidden">
        <div
            class="bg-white/95 backdrop-blur-xl border border-slate-200 w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-3xl p-8 text-slate-800 shadow-2xl relative">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h3 class="text-xl font-black text-slate-900 font-['Outfit']">Payment History</h3>
                    <p id="op-history-cust-name"
                        class="text-[10px] uppercase font-black text-indigo-500 tracking-widest mt-1">SUPPLIER</p>
                </div>
                <div class="flex items-center gap-4">
                    <button id="op-add-btn-in-history" onclick=""
                        class="bg-emerald-600 text-white hover:bg-black px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-colors shadow-lg shadow-emerald-600/20">
                        <i class="fa-solid fa-plus mr-1"></i> Add Payment
                    </button>
                    <button onclick="closeOtherPurchaseHistory()"
                        class="text-slate-400 hover:text-rose-500 transition-colors"><i
                            class="fa-solid fa-times text-2xl"></i></button>
                </div>
            </div>
            <div id="op-history-content" class="overflow-x-auto min-h-[300px]">
                <!-- History Table Area -->
            </div>
        </div>
    </div>

    <!-- Quick Add Bank Modal -->
    <div id="op-create-bank-modal"
        class="fixed inset-0 bg-slate-900/90 backdrop-blur-md z-[110] hidden items-center justify-center p-4">
        <div
            class="bg-white/95 backdrop-blur-xl border border-slate-200 w-full max-w-md p-7 text-slate-800 rounded-3xl shadow-2xl">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h4 class="text-lg font-black text-slate-900 font-['Outfit']">Add New Bank</h4>
                    <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mt-1">Register a new bank
                        account</p>
                </div>
                <button type="button" onclick="closeOpCreateBankModal()"
                    class="text-slate-400 hover:text-rose-500 transition-colors"><i
                        class="fa-solid fa-times text-xl"></i></button>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Bank
                        Name</label>
                    <input type="text" id="op_new_bank_name" class="input-glass w-full"
                        placeholder="e.g. Bank of Ceylon">
                </div>
                <div>
                    <label
                        class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Account
                        Number</label>
                    <input type="text" id="op_new_bank_acc_no" class="input-glass w-full" placeholder="e.g. 0023456789">
                </div>
                <div>
                    <label
                        class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Account
                        Name</label>
                    <input type="text" id="op_new_bank_acc_name" class="input-glass w-full"
                        placeholder="e.g. Crystal Distributors">
                </div>
                <button type="button" onclick="saveOpNewBank()"
                    class="w-full mt-2 bg-indigo-600 hover:bg-indigo-700 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest transition-colors shadow-lg shadow-indigo-600/20">
                    <i class="fa-solid fa-floppy-disk mr-2"></i>Save Bank
                </button>
            </div>
        </div>
    </div>

    <script>
        // OTHER PURCHASES PAYMENT MANAGEMENT LOGIC 
        // Hooked into managePayments.php to prevent duplication!
        function openOtherPurchaseHistory(refId, name, pending) {
            document.getElementById('op-history-cust-name').innerText = name;

            // Wire the internal add payment button 
            const addBtn = document.getElementById('op-add-btn-in-history');
            if (pending > 0) {
                addBtn.style.display = 'inline-flex';
                addBtn.onclick = function () { openOtherPurchaseAddPayment(refId, name, pending); };
            } else {
                addBtn.style.display = 'none'; // fully paid
            }

            const container = document.getElementById('op-history-content');
            container.innerHTML = '<div class="flex items-center justify-center py-20"><i class="fa-solid fa-spinner fa-spin text-3xl text-indigo-500"></i></div>';
            document.getElementById('op-history-modal').classList.remove('hidden');

            fetch(`managePayments.php?action=get_history&ref_id=${refId}&tab=given`)
                .then(r => r.json())
                .then(res => {
                    if (!res.success) return alert(res.message);
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
                                    ${p.bank_name ? `<p class="text-slate-900 leading-none mb-1 font-black">${p.bank_name}</p><p class="text-[10px] text-slate-600 font-bold uppercase">${p.bank_acc}</p>` : '<span class="text-slate-400 italic font-medium">N/A</span>'}
                                    ${p.cheque_payer ? `<p class="text-[10px] text-indigo-600 font-black mt-1 uppercase tracking-tighter">Payer: ${p.cheque_payer}</p>` : ''}
                                </td>
                                <td class="py-4 px-2 text-center">
                                    ${p.proof_image ? `<a href="../uploads/payments/${p.proof_image}" target="_blank" class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 hover:bg-emerald-600 hover:text-white transition-all inline-flex items-center justify-center border border-emerald-200 shadow-sm" title="View Proof"><i class="fa-solid fa-image text-xs"></i></a>` : '<span class="text-[10px] text-slate-400 font-bold uppercase">N/A</span>'}
                                </td>
                                <td class="py-4 px-2 text-center">
                                    <span class="text-[12px] text-slate-700 font-black">${p.cheque_number || '-'}</span>
                                </td>
                                <td class="py-4 px-2 text-right font-black text-slate-900 leading-tight">LKR ${parseFloat(p.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                                <td class="py-4 px-2 text-center">
                                    <button onclick="deleteOtherPurchasePayment(${p.id})" class="text-rose-500 hover:text-rose-700 transition-all p-2 hover:bg-rose-100 rounded-lg"><i class="fa-solid fa-trash-can"></i></button>
                                </td>
                            </tr>
                        `;
                    });

                    if (res.data.length === 0) html += '<tr><td colspan="7" class="py-10 text-center text-slate-500 italic font-bold">No transactions recorded.</td></tr>';

                    html += '</tbody></table>';
                    container.innerHTML = html;
                });
        }

        function closeOtherPurchaseHistory() {
            document.getElementById('op-history-modal').classList.add('hidden');
        }

        function deleteOtherPurchasePayment(id) {
            if (!confirm('Are you sure you want to delete this payment record?')) return;
            fetch('managePayments.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=delete_payment&id=${id}&tab=given`
            }).then(r => r.json()).then(data => {
                if (data.success) location.reload(); else alert(data.message);
            });
        }

        function openOtherPurchaseAddPayment(refId, name, pending) {
            document.getElementById('op_payment_ref_id').value = refId;
            document.getElementById('op-add-payment-cust-name').innerText = name;
            document.getElementById('op_payment_amount').value = pending > 0 ? pending.toFixed(2) : '';
            document.getElementById('op-add-payment-modal').classList.remove('hidden');
            toggleOpPaymentFields();
        }

        function closeOtherPurchaseAddPayment() {
            document.getElementById('op-add-payment-modal').classList.add('hidden');
            document.getElementById('op-payment-form').reset();
            clearOpBank();
            clearOpProof();
        }

        function toggleOpPaymentFields() {
            const type = document.getElementById('op_payment_type').value;
            document.getElementById('op_bank_section').classList.toggle('hidden', type !== 'Account Transfer' && type !== 'Cheque');
            document.getElementById('op_cheque_section').classList.toggle('hidden', type !== 'Cheque');
            document.getElementById('op_proof_section').classList.toggle('hidden', type !== 'Account Transfer' && type !== 'Cheque');
        }

        function searchOpBanks(term) {
            const res = document.getElementById('op_bank_results');
            if (term.length < 2) return res.classList.add('hidden');
            fetch(`managePayments.php?action=search_bank&term=${term}`)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    data.forEach(b => {
                        html += `<div class="p-3 hover:bg-slate-100 cursor-pointer rounded-xl border-b border-slate-100 last:border-0" onclick="selectOpBank(${b.id}, '${b.name}', '${b.account_number}')">
                            <p class="text-xs font-black text-slate-800 uppercase">${b.name}</p><p class="text-[9px] font-bold text-slate-400">ACC: ${b.account_number}</p></div>`;
                    });
                    if (!data.length) html = `<div class="p-3 text-center"><p class="text-[9px] font-black text-slate-400 uppercase mb-3">Not found</p><button type="button" onclick="openOpCreateBankModal('${term}')" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-2.5 rounded-xl text-[10px] font-black uppercase">Create New</button></div>`;
                    res.innerHTML = html; res.classList.remove('hidden');
                });
        }

        function selectOpBank(id, name, acc) {
            document.getElementById('op_selected_bank_id').value = id;
            document.getElementById('op_disp_bank_name').innerText = name;
            document.getElementById('op_disp_bank_acc').innerText = acc;
            document.getElementById('op_selected_bank_info').classList.remove('hidden');
            document.getElementById('op_bank_results').classList.add('hidden');
        }

        function clearOpBank() {
            document.getElementById('op_selected_bank_id').value = '';
            document.getElementById('op_selected_bank_info').classList.add('hidden');
        }

        function openOpCreateBankModal(prefill) {
            document.getElementById('op_new_bank_name').value = prefill || '';
            document.getElementById('op-create-bank-modal').classList.remove('hidden');
            document.getElementById('op-create-bank-modal').classList.add('flex');
            document.getElementById('op_bank_results').classList.add('hidden');
        }
        function closeOpCreateBankModal() {
            document.getElementById('op-create-bank-modal').classList.add('hidden');
            document.getElementById('op-create-bank-modal').classList.remove('flex');
        }
        function saveOpNewBank() {
            const name = document.getElementById('op_new_bank_name').value;
            const acc_no = document.getElementById('op_new_bank_acc_no').value;
            const acc_name = document.getElementById('op_new_bank_acc_name').value;
            if (!name) return alert('Name required');
            const fd = new FormData(); fd.append('action', 'create_bank'); fd.append('name', name); fd.append('acc_no', acc_no); fd.append('acc_name', acc_name);
            fetch('managePayments.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
                if (data.success) { selectOpBank(data.id, name, acc_no); closeOpCreateBankModal(); } else alert('Error');
            });
        }

        function previewOpProof(input) {
            if (input.files && input.files[0]) {
                const r = new FileReader();
                r.onload = e => { document.querySelector('#op_proof_preview img').src = e.target.result; document.getElementById('op_proof_preview').classList.remove('hidden'); }
                r.readAsDataURL(input.files[0]);
            }
        }
        function clearOpProof() {
            document.getElementById('op_payment_proof').value = ''; document.getElementById('op_proof_preview').classList.add('hidden');
        }

        document.getElementById('op-payment-form').onsubmit = function (e) {
            e.preventDefault();
            const fd = new FormData(this); fd.append('action', 'save_payment');
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> PROCESSING...';
            fetch('managePayments.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
                if (data.success) location.reload(); else { alert(data.message); btn.disabled = false; btn.innerText = 'Confirm Save Payment'; }
            });
        }
    </script>
</body>

</html>