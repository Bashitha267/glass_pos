<?php
require_once '../auth.php';
require_once '../config.php';
checkAuth();

if (!isAdmin()) {
    header('Location: ../login.php');
    exit;
}

function resolveUploadImagePath($rawPath) {
    if (!$rawPath) {
        return null;
    }

    $rawPath = trim($rawPath);
    if ($rawPath === '') {
        return null;
    }

    if (preg_match('/^https?:\/\//i', $rawPath) || strpos($rawPath, '../') === 0 || strpos($rawPath, './') === 0 || strpos($rawPath, '/') === 0) {
        return $rawPath;
    }

    if (file_exists('../uploads/bills/' . $rawPath)) {
        return '../uploads/bills/' . $rawPath;
    }

    if (file_exists('../uploads/payments/' . $rawPath)) {
        return '../uploads/payments/' . $rawPath;
    }

    return '../uploads/bills/' . $rawPath;
}

$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: nwdelivery.php');
    exit;
}

// AJAX Handlers
$action = $_GET['action'] ?? $_POST['action'] ?? null;
if ($action) {
    header('Content-Type: application/json');
    try {
        if ($action == 'update_damage') {
            $di_id = $_POST['di_id'];
            $dmg = (int)$_POST['damaged_qty'];
            $stmt = $pdo->prepare("UPDATE delivery_items SET damaged_qty = ? WHERE id = ?");
            $stmt->execute([$dmg, $di_id]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action == 'update_discount') {
            $dc_id = $_POST['dc_id'];
            $discount = (float)$_POST['discount'];
            $stmt = $pdo->prepare("UPDATE delivery_customers SET discount = ? WHERE id = ?");
            $stmt->execute([$discount, $dc_id]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action == 'add_expense') {
            $name = $_POST['name'];
            $amount = (float)$_POST['amount'];
            $stmt = $pdo->prepare("INSERT INTO delivery_expenses (delivery_id, expense_name, amount) VALUES (?, ?, ?)");
            $stmt->execute([$id, $name, $amount]);
            // Update summary
            $pdo->prepare("UPDATE deliveries SET total_expenses = (SELECT SUM(amount) FROM delivery_expenses WHERE delivery_id = ?) WHERE id = ?")->execute([$id, $id]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action == 'delete_expense') {
            $exp_id = $_POST['exp_id'];
            $stmt = $pdo->prepare("DELETE FROM delivery_expenses WHERE id = ?");
            $stmt->execute([$exp_id]);
            $pdo->prepare("UPDATE deliveries SET total_expenses = (SELECT COALESCE(SUM(amount), 0) FROM delivery_expenses WHERE delivery_id = ?) WHERE id = ?")->execute([$id, $id]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action == 'mark_complete') {
            $dc_id = $_POST['dc_id'];
            $stmt = $pdo->prepare("UPDATE delivery_customers SET payment_status = 'completed' WHERE id = ?");
            $stmt->execute([$dc_id]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action == 'search_bank') {
            $term = '%' . $_GET['term'] . '%';
            $stmt = $pdo->prepare("SELECT * FROM banks WHERE name LIKE ? LIMIT 5");
            $stmt->execute([$term]);
            echo json_encode($stmt->fetchAll());
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
            $dc_id = $_POST['dc_id'];
            $type = $_POST['type'];
            $amount = (float)$_POST['amount'];
            $date = $_POST['date'];
            $due_date = ($type == 'Cheque') ? date('Y-m-d', strtotime($date . ' + 12 days')) : null;
            $bank_id = $_POST['bank_id'] ?: null;
            $chq_no = $_POST['chq_no'] ?: null;
            $chq_payer = $_POST['chq_payer'] ?: null;
            
            $proof = null;
            if (isset($_FILES['proof']) && $_FILES['proof']['error'] == 0) {
                $proof = time() . '_' . $_FILES['proof']['name'];
                if (!is_dir('../uploads/payments')) mkdir('../uploads/payments', 0777, true);
                move_uploaded_file($_FILES['proof']['tmp_name'], '../uploads/payments/' . $proof);
            }

            $stmt = $pdo->prepare("INSERT INTO delivery_payments (delivery_customer_id, amount, payment_type, bank_id, cheque_number, proof_image, payment_date, due_date, cheque_payer_name, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$dc_id, $amount, $type, $bank_id, $chq_no, $proof, $date, $due_date, $chq_payer, $_SESSION['user_id']]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action == 'upload_bill') {
            $dc_id = $_POST['dc_id'];
            if (isset($_FILES['bill']) && $_FILES['bill']['error'] == 0) {
                $bill = time() . '_bill_' . $_FILES['bill']['name'];
                if (!is_dir('../uploads/bills')) mkdir('../uploads/bills', 0777, true);
                move_uploaded_file($_FILES['bill']['tmp_name'], '../uploads/bills/' . $bill);
                $stmt = $pdo->prepare("UPDATE delivery_items SET bill_image = ? WHERE delivery_customer_id = ?");
                $stmt->execute([$bill, $dc_id]);
                echo json_encode(['success' => true]);
            } else { echo json_encode(['success' => false]); }
            exit;
        }
        if ($action == 'delete_customer_order') {
            $dc_id = (int)$_POST['dc_id'];
            $pdo->beginTransaction();
            try {
                // Return items to stock
                $itemsStmt = $pdo->prepare("SELECT container_item_id, qty FROM delivery_items WHERE delivery_customer_id = ?");
                $itemsStmt->execute([$dc_id]);
                $items = $itemsStmt->fetchAll();
                foreach ($items as $it) {
                    $pdo->prepare("UPDATE container_items SET sold_qty = GREATEST(0, sold_qty - ?) WHERE id = ?")
                        ->execute([$it['qty'], $it['container_item_id']]);
                }
                
                // Delete related records
                $pdo->prepare("DELETE FROM delivery_items WHERE delivery_customer_id = ?")->execute([$dc_id]);
                $pdo->prepare("DELETE FROM delivery_payments WHERE delivery_customer_id = ?")->execute([$dc_id]);
                $pdo->prepare("DELETE FROM delivery_proof_photos WHERE delivery_customer_id = ?")->execute([$dc_id]);
                $pdo->prepare("DELETE FROM delivery_customers WHERE id = ?")->execute([$dc_id]);
                
                // Recalculate trip total_sales
                $pdo->prepare("UPDATE deliveries SET total_sales = (SELECT COALESCE(SUM(subtotal - discount), 0) FROM delivery_customers WHERE delivery_id = ?) WHERE id = ?")
                    ->execute([$id, $id]);
                    
                $pdo->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
        }
    } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit; }
}

// Fetch Core Data
$stmt = $pdo->prepare("SELECT d.*, u.full_name as creator_name FROM deliveries d JOIN users u ON d.created_by = u.id WHERE d.id = ?");
$stmt->execute([$id]);
$delivery = $stmt->fetch();
if (!$delivery) die("Delivery Trip not found.");

// Fetch Employees
$stmtEmps = $pdo->prepare("SELECT u.full_name, u.contact_number FROM delivery_employees de JOIN users u ON de.user_id = u.id WHERE de.delivery_id = ?");
$stmtEmps->execute([$id]);
$employees = $stmtEmps->fetchAll();

$stmt = $pdo->prepare("SELECT dc.*, c.name as customer_name, c.contact_number, c.address FROM delivery_customers dc JOIN customers c ON dc.customer_id = c.id WHERE dc.delivery_id = ?");
$stmt->execute([$id]);
$customers = $stmt->fetchAll();

foreach ($customers as &$c) {
    $stmt = $pdo->prepare("SELECT di.*, b.name as brand_name, ci.container_id, con.container_number FROM delivery_items di JOIN container_items ci ON di.container_item_id = ci.id JOIN brands b ON ci.brand_id = b.id JOIN containers con ON ci.container_id = con.id WHERE di.delivery_customer_id = ?");
    $stmt->execute([$c['id']]);
    $c['items'] = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT dp.*, b.name as bank_name, b.account_number, b.account_name, dp.cheque_payer_name as cheque_payer FROM delivery_payments dp LEFT JOIN banks b ON dp.bank_id = b.id WHERE dp.delivery_customer_id = ? ORDER BY dp.payment_date DESC");
    $stmt->execute([$c['id']]);
    $c['payments'] = $stmt->fetchAll();
    
    // Fetch Proof Photos
    $stmtPhotos = $pdo->prepare("SELECT * FROM delivery_proof_photos WHERE delivery_customer_id = ?");
    $stmtPhotos->execute([$c['id']]);
    $c['proof_photos'] = $stmtPhotos->fetchAll();
    
    $c['total_paid'] = array_sum(array_column($c['payments'], 'amount'));
}
unset($c);

$stmt = $pdo->prepare("SELECT * FROM delivery_expenses WHERE delivery_id = ?");
$stmt->execute([$id]);
$expenses = $stmt->fetchAll();

$total_revenue = array_sum(array_column($customers, 'subtotal'));
$total_discount = 0;
foreach ($customers as $c) {
    $total_discount += (float)$c['discount'];
}
$total_paid = 0; foreach($customers as $c) $total_paid += $c['total_paid'];
$pending_payment = $total_revenue - $total_paid;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Logistics #<?php echo $id; ?> | Crystal POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background: url('../assests/glass_bg.png') no-repeat center center fixed; background-size: cover; color: #1e293b; min-height: 100vh; }
        .glass-header { background: rgba(248, 250, 252, 0.96); backdrop-filter: blur(20px); border-bottom: 1px solid rgba(226, 232, 240, 0.8); box-shadow: 0 4px 20px -5px rgba(0, 0, 0, 0.05); }
        .glass-card { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(20px); border: 1px solid white; border-radius: 24px; box-shadow: 0 10px 30px -5px rgba(0,0,0,0.04); }
        .input-glass { background: rgba(255, 255, 255, 0.6); border: 1px solid #e2e8f0; padding: 10px 16px; border-radius: 14px; outline: none; transition: all 0.3s; font-size: 13px; font-weight: 600; }
        .input-glass:focus { border-color: #0891b2; background: white; box-shadow: 0 0 15px rgba(8, 145, 178, 0.08); }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .animate-slide-up { animation: slideUp 0.4s ease forwards; }

        /* Improve readability on light and tinted cards */
        .glass-card .text-slate-400 { color: #475569 !important; }
        .glass-card .text-slate-500 { color: #334155 !important; }
        .glass-card .text-slate-600 { color: #1f2937 !important; }
        .glass-card th,
        .glass-card td { color: #1f2937; }

        /* Keep labels readable on dark sections */
        .bg-slate-900 .text-slate-300 { color: #e2e8f0 !important; }
        .bg-slate-900 .text-slate-400 { color: #cbd5e1 !important; }

        @media print {
            body { background: #fff !important; color: #000 !important; margin: 0; padding: 0; font-size: 11px !important; }
            .no-print,
            .screen-only,
            #expense-modal,
            #payment-modal,
            #new-bank-modal,
            button,
            a[href="nwdelivery.php"] {
                display: none !important;
            }
            .print-invoice {
                display: block !important;
                padding: 12px;
            }
            .print-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 6px;
                margin-bottom: 10px;
            }
            .print-table th,
            .print-table td {
                border: 1px solid #000;
                padding: 4px 6px;
                text-align: left;
                vertical-align: top;
                font-size: 10px;
            }
            .print-table th {
                font-weight: 700;
                background: #fff !important;
            }
            .print-section-title {
                font-weight: 700;
                margin: 10px 0 4px;
                text-transform: uppercase;
            }
            .print-customer-block {
                margin-top: 12px;
                page-break-inside: avoid;
            }
            .print-summary td {
                font-weight: 700;
            }
            .print-invoice-head {
                display: block !important;
            }
            .footer-analysis {
                display: block !important;
            }

            /* Compact PDF layout */
            main {
                padding: 4px !important;
                gap: 8px !important;
            }
            .glass-card {
                margin-bottom: 8px !important;
                border-radius: 8px !important;
            }
            .p-6,
            .p-8 {
                padding: 8px !important;
            }
            .py-8 {
                padding-top: 6px !important;
                padding-bottom: 6px !important;
            }
            .px-4,
            .px-6,
            .px-8 {
                padding-left: 6px !important;
                padding-right: 6px !important;
            }
            .space-y-8 > :not([hidden]) ~ :not([hidden]) {
                margin-top: 8px !important;
            }
            .space-y-6 > :not([hidden]) ~ :not([hidden]) {
                margin-top: 6px !important;
            }

            /* No proof photos in printed PDF */
            .print-hide-proofs {
                display: none !important;
            }
        }
        .footer-analysis { display: none; }
        .print-invoice-head { display: none; }
        .print-invoice { display: none; }
    </style>
</head>
<body class="flex flex-col pb-20">

    <header class="glass-header sticky top-0 z-40 py-4 no-print">
        <div class="px-3 md:px-8 flex items-center justify-between">
            <div class="flex items-center space-x-3 md:space-x-5">
                <a href="nwdelivery.php" class="text-slate-800 hover:text-cyan-600 transition-colors p-2 md:p-2.5 rounded-2xl hover:bg-slate-100">
                    <i class="fa-solid fa-arrow-left text-lg md:text-xl"></i>
                </a>
                <div>
                    <h1 class="text-xl md:text-2xl font-black text-slate-900 font-['Outfit'] tracking-tight">Delivery Registry</h1>
                    <p class="text-[9px] md:text-[10px] uppercase font-black text-slate-500 tracking-widest mt-0.5">Trip Details #<?php echo str_pad($id, 4, '0', STR_PAD_LEFT); ?></p>
                </div>
            </div>
            
            <div class="flex items-center gap-2 md:gap-6">
                <div class="hidden sm:flex flex-col items-end border-r border-slate-200 pr-6">
                    <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest">REGISTRY DATE</p>
                    <p class="text-lg font-black text-slate-900"><?php echo date('Y-m-d', strtotime($delivery['delivery_date'])); ?></p>
                </div>
                <button onclick="window.print()" class="bg-slate-900 text-white px-4 md:px-6 py-2.5 md:py-3 rounded-xl md:rounded-2xl font-black text-[10px] md:text-xs uppercase tracking-widest hover:bg-black transition-all shadow-xl shadow-slate-900/20 flex items-center gap-2">
                    <i class="fa-solid fa-print"></i>
                    <span class="hidden xs:inline">Print Report</span>
                    <span class="xs:hidden">Print</span>
                </button>
            </div>
        </div>
    </header>

    <!-- Invoice-Style Print Header -->
    <header class="print-invoice-head">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <h1 style="font-size: 26pt; font-weight: 900; margin: 0; letter-spacing: -1px;">CRYSTAL POS</h1>
                <p style="font-size: 10pt; font-weight: 800; text-transform: uppercase; letter-spacing: 2px;">Delivery Log & Invoice Registry</p>
                <div style="margin-top: 15px;">
                    <p style="font-size: 9pt; margin: 0;"><b>STAFF ASSIGNED:</b> 
                        <?php echo implode(', ', array_column($employees, 'full_name')); ?>
                    </p>
                    <p style="font-size: 9pt; margin: 4px 0 0 0;"><b>OPERATIONAL OVERHEAD:</b> 
                        LKR <?php echo number_format($delivery['total_expenses'], 2); ?>
                    </p>
                </div>
            </div>
            <div style="text-align: right;">
                <h2 style="font-size: 16pt; font-weight: 900; margin: 0;">TRIP #<?php echo str_pad($id, 4, '0', STR_PAD_LEFT); ?></h2>
                <p style="font-size: 10pt; font-weight: 700; margin: 5px 0 0 0;"><?php echo date('l, d F Y', strtotime($delivery['delivery_date'])); ?></p>
                <p style="font-size: 8pt; margin: 10px 0 0 0; opacity: 0.6;">System Generated: <?php echo date('Y-m-d H:i:s'); ?></p>
            </div>
        </div>
    </header>

    <main class="w-full px-4 md:px-8 py-8 grid grid-cols-1 md:grid-cols-12 gap-8">
        
        <!-- Summary Cards (Visible in Print as well) -->
        <div class="md:col-span-12 grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
            <div class="glass-card p-5 border-slate-100 print-pure-black">
                <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest print-label">Route Date</p>
                <p class="text-xl font-black text-slate-900"><?php echo date('D, M d, Y', strtotime($delivery['delivery_date'])); ?></p>
            </div>
            <div class="glass-card p-5 border-emerald-100 bg-emerald-50/20 print-pure-black">
                <p class="text-[10px] uppercase font-black text-emerald-600 tracking-widest print-label">Total Cash Collected</p>
                <p class="text-xl font-black text-emerald-600">LKR <?php echo number_format($total_paid, 2); ?></p>
            </div>
            <div class="glass-card p-5 border-rose-100 bg-rose-50/20 print-pure-black">
                <p class="text-[10px] uppercase font-black text-rose-600 tracking-widest print-label">Pending Collection</p>
                <p class="text-xl font-black text-rose-600">LKR <?php echo number_format($pending_payment, 2); ?></p>
            </div>
            <div class="glass-card p-5 border-slate-900 bg-slate-900 text-white print-pure-black">
                <p class="text-[10px] uppercase font-black text-slate-300 tracking-widest mb-1 print-label">Staff Assigned</p>
                <div class="flex flex-col gap-1">
                    <?php if(empty($employees)): ?>
                        <p class="text-sm font-bold opacity-50 italic">No staff assigned.</p>
                    <?php endif; ?>
                    <?php foreach($employees as $emp): ?>
                    <p class="text-sm font-black"><?php echo $emp['full_name']; ?> <span class="opacity-50 text-[10px] ml-1"><?php echo $emp['contact_number']; ?></span></p>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Details Sections -->
        <div class="md:col-span-8 space-y-8">
            <h3 class="text-xs font-black font-black uppercase tracking-widest mb-2 no-print">Customer Order Breakdowns</h3>
            <?php foreach($customers as $c): ?>
            <?php
            $customer_total = max(0, (float)$c['subtotal'] - (float)$c['discount']);
            $customer_pending = max(0, $customer_total - (float)$c['total_paid']);
            if ($customer_pending <= 0 && $customer_total > 0) {
                $status_label = 'PAID';
                $status_class = 'bg-emerald-100 border-emerald-300 text-emerald-700';
                $card_status_class = 'bg-emerald-100/30 border-emerald-100';
            } elseif ((float)$c['total_paid'] > 0) {
                $status_label = 'PENDING';
                $status_class = 'bg-yellow-100 border-yellow-300 text-yellow-700';
                $card_status_class = 'bg-yellow-100/30 border-yellow-100';
            } else {
                $status_label = 'NOT PAID';
                $status_class = 'bg-rose-100 border-rose-300 text-rose-700';
                $card_status_class = 'bg-rose-100/30 border-rose-100';
            }
            ?>
            <div class="glass-card overflow-hidden animate-slide-up print-pure-black <?php echo $card_status_class; ?>">
                <div class="p-6 bg-slate-50/50 border-b border-slate-100 flex items-center justify-between group">
                    <div>
                        <div class="flex items-center gap-3">
                            <h2 class="text-xl font-black text-slate-900 font-['Outfit'] tracking-tight capitalize"><?php echo $c['customer_name']; ?></h2>
                        </div>
                        <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mt-1">
                            <?php echo $c['address']; ?> &bull; <i class="fa-solid fa-phone mr-1"></i> <?php echo $c['contact_number']; ?>
                        </p>
                    </div>
                    <div class="text-right">
                        <span class="inline-block px-3 py-1 rounded-lg border text-[10px] font-black uppercase tracking-widest mb-2 <?php echo $status_class; ?>">
                            <?php echo $status_label; ?>
                        </span>
                        <p class="text-[10px] uppercase font-black text-slate-400">Section Subtotal</p>
                        <p class="text-xl font-black text-slate-900">LKR <?php echo number_format($c['subtotal'], 2); ?></p>
                    </div>
                </div>
                
                <div class="p-6 space-y-8">
                    <!-- Items -->
                    <div>
                        <div class="flex items-center justify-between mb-4 no-print">
                            <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Product Details</h4>
                            <button onclick="uploadBill(<?php echo $c['id']; ?>)" class="text-[8px] bg-slate-100 text-slate-600 px-3 py-1.5 rounded-lg font-black uppercase tracking-widest">Change Digital Bill</button>
                        </div>
                        <table class="w-full text-sm print-table border-separate border-spacing-y-1">
                            <thead>
                                <tr class="text-[10px] uppercase font-black text-slate-600 bg-slate-100/80 rounded-xl overflow-hidden print-pure-black">
                                    <th class="py-3 px-4 rounded-l-xl">Brand / Product</th>
                                    <th class="py-3 px-2">Container</th>
                                    <th class="py-3 px-2 text-center">Qty</th>
                                    <th class="py-3 px-2 text-center text-rose-600">Dmg</th>
                                    <th class="py-3 px-2 text-right">Unit Price</th>
                                    <th class="py-3 px-4 text-right rounded-r-xl">Line Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($c['items'] as $item): ?>
                                <tr class="print-pure-black bg-white/40 hover:bg-indigo-50/50 transition-colors group">
                                    <td class="py-4 px-4 font-black text-slate-800 rounded-l-xl border-l border-y border-transparent group-hover:border-indigo-100"><?php echo $item['brand_name']; ?></td>
                                    <td class="py-4 px-2 font-bold text-slate-500 text-[10px] border-y border-transparent group-hover:border-indigo-100"><?php echo $item['container_number']; ?></td>
                                    <td class="py-4 px-2 text-center font-black border-y border-transparent group-hover:border-indigo-100"><?php echo $item['qty']; ?></td>
                                    <td class="py-4 px-2 text-center border-y border-transparent group-hover:border-indigo-100">
                                        <input type="number" value="<?php echo $item['damaged_qty']; ?>" class="w-12 h-8 text-center bg-transparent border-0 font-bold focus:ring-0" onchange="updateDamage(<?php echo $item['id']; ?>, this.value)">
                                    </td>
                                    <td class="py-4 px-2 text-right font-bold text-slate-600 border-y border-transparent group-hover:border-indigo-100">LKR <?php echo number_format($item['selling_price'], 2); ?></td>
                                    <td class="py-4 px-4 text-right font-black text-slate-900 rounded-r-xl border-r border-y border-transparent group-hover:border-indigo-100">LKR <?php echo number_format($item['total'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Payments & Discount Row -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 print-pure-black">
                        <div class="space-y-4">
                            <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest print-label">Financial Adjustments</h4>
                            <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100 flex items-center justify-between print-pure-black">
                                <p class="text-xs font-black text-slate-500 uppercase tracking-widest">Special Discount</p>
                                <div class="flex items-center gap-2">
                                    <span class="text-[10px] font-black text-slate-400">LKR</span>
                                    <input type="number" step="0.01" value="<?php echo $c['discount']; ?>" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 w-24 text-right font-black text-sm outline-none no-print" onchange="updateDiscount(<?php echo $c['id']; ?>, this.value)">
                                    <span class="print-only text-sm font-black"><?php echo number_format($c['discount'], 2); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between mb-2">
                                <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest print-label">Payment History</h4>
                                <?php if ($customer_pending > 0): ?>
                                    <button onclick="openPaymentModal(<?php echo $c['id']; ?>, '<?php echo addslashes($c['customer_name']); ?>')" class="text-[9px] bg-emerald-600 text-white px-3 py-1.5 rounded-lg font-black uppercase no-print">+ Add</button>
                                <?php else: ?>
                                    <button disabled class="text-[9px] bg-slate-300 text-slate-500 px-3 py-1.5 rounded-lg font-black uppercase no-print cursor-not-allowed opacity-50 flex items-center gap-1">
                                        <i class="fa-solid fa-check-double scale-75"></i> Settled
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="space-y-2">
                                <?php foreach($c['payments'] as $p): ?>
                                <div class="p-3 bg-white border border-slate-100 rounded-xl flex items-center justify-between shadow-sm print-pure-black">
                                    <div>
                                        <p class="text-xs font-black text-slate-800">LKR <?php echo number_format($p['amount'], 2); ?></p>
                                        <p class="text-[9px] font-black text-slate-400 uppercase">
                                            <?php echo $p['payment_type']; ?> &bull; <?php echo date('M d, Y', strtotime($p['payment_date'])); ?>
                                            <?php if($p['bank_name']): ?>
                                                &bull; <?php echo htmlspecialchars($p['bank_name'] . ' - ' . $p['account_name']); ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <?php if($p['payment_type'] == 'Cheque'): ?>
                                    <div class="text-right">
                                        <p class="text-[9px] font-black text-indigo-600 uppercase">CHQ: <?php echo $p['cheque_number']; ?><?php if($p['cheque_payer']): ?> &bull; <?php echo $p['cheque_payer']; ?><?php endif; ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Documentation Section -->
                <?php 
                $hasBill = false;
                foreach($c['items'] as $it) { if($it['bill_image']) { $hasBill = $it['bill_image']; break; } }
                $hasBillPath = $hasBill ? resolveUploadImagePath($hasBill) : null;
                $customerProofPaths = [];
                foreach ($c['proof_photos'] as $photo) {
                    $proofPath = resolveUploadImagePath($photo['photo_path']);
                    if ($proofPath) {
                        $customerProofPaths[] = $proofPath;
                    }
                }
                $customerProofPaths = array_values(array_unique($customerProofPaths));
                if(!empty($customerProofPaths) || $hasBillPath): 
                ?>
                <div class="px-6 pb-6 space-y-4 print-hide-proofs">
                    <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest print-label">Encoded Documentation</h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 proof-images">
                        <?php if($hasBillPath): ?>
                        <div class="relative group">
                            <img src="<?php echo htmlspecialchars($hasBillPath); ?>" class="rounded-xl w-full h-32 object-cover border border-slate-200" alt="Digital bill">
                            <span class="absolute top-2 left-2 bg-slate-900/80 text-white text-[8px] font-black px-2 py-1 rounded-lg uppercase">Digital Bill</span>
                        </div>
                        <?php endif; ?>
                        <?php foreach($customerProofPaths as $proofPath): ?>
                        <div class="relative group">
                            <img src="<?php echo htmlspecialchars($proofPath); ?>" class="rounded-xl w-full h-32 object-cover border border-slate-200" alt="Proof photo">
                            <span class="absolute top-2 left-2 bg-emerald-600/80 text-white text-[8px] font-black px-2 py-1 rounded-lg uppercase">Proof Photo</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Footer Stats for Customer -->
                <div class="p-6 bg-slate-900 text-white flex items-center justify-between print-pure-black">
                    <div class="flex items-center gap-8">
                        <div>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Total</p>
                            <p class="text-lg font-black font-['Outfit'] text-white">LKR <?php echo number_format($customer_total, 2); ?></p>
                        </div>
                        <div class="w-px h-8 bg-slate-800"></div>
                        <div>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Pending</p>
                            <p class="text-lg font-black font-['Outfit'] <?php echo ($customer_pending > 0) ? 'text-yellow-300' : 'text-emerald-300'; ?>">LKR <?php echo number_format($customer_pending, 2); ?></p>
                        </div>
                    </div>
                    <button onclick="deleteCustomerOrder(<?php echo $c['id']; ?>)" class="bg-rose-600/10 text-rose-400 hover:bg-rose-600 hover:text-white px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all no-print flex items-center gap-2 border border-rose-500/20 shadow-lg shadow-rose-900/20">
                        <i class="fa-solid fa-trash-can"></i>
                        <span>Delete order</span>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Right Column: Trip Overhead -->
        <div class="md:col-span-4 space-y-8">
            <!-- Expenses List -->
            <div class="glass-card p-6 border-slate-100 print-pure-black">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xs font-black text-slate-500 uppercase tracking-widest print-label">Operational Expenses</h3>
                    <button onclick="openExpenseModal()" class="text-[9px] bg-slate-100 text-slate-600 px-3 py-1.5 rounded-lg font-black uppercase no-print">+ New Exp</button>
                </div>
                <div class="space-y-3 mb-6" id="expenses-list">
                    <?php if(empty($expenses)): ?>
                        <p class="text-center text-xs text-slate-400 italic" id="nil-label">Nil.</p>
                    <?php endif; ?>
                    <?php foreach($expenses as $e): ?>
                    <div class="flex items-center justify-between p-3 bg-white/50 border border-slate-100 rounded-xl group print-pure-black">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-rose-50 flex items-center justify-center text-rose-600 text-[10px]">
                                <i class="fa-solid fa-gas-pump"></i>
                            </div>
                            <span class="text-xs font-black text-slate-800"><?php echo $e['expense_name']; ?></span>
                        </div>
                        <div class="flex items-center gap-4">
                            <span class="text-xs font-black text-slate-900">LKR <?php echo number_format($e['amount'], 2); ?></span>
                            <button onclick="deleteExpense(<?php echo $e['id']; ?>)" class="text-slate-300 hover:text-rose-600 no-print"><i class="fa-solid fa-trash-can text-xs"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <!-- Inline Form Row -->
                    <form id="inline-expense-form" class="hidden animate-slide-up no-print p-3 bg-indigo-50/50 border border-indigo-100 rounded-xl">
                        <div class="flex items-center gap-2">
                             <input type="text" name="name" required placeholder="Expense Name" class="input-glass flex-1 h-[36px] text-[10px]">
                             <input type="number" name="amount" required step="0.01" placeholder="Amount" class="input-glass w-24 h-[36px] text-[10px]">
                             <button type="submit" class="w-8 h-8 rounded-lg bg-slate-900 text-white flex items-center justify-center shadow-lg transition-all active:scale-90"><i class="fa-solid fa-check text-[10px]"></i></button>
                             <button type="button" onclick="toggleExpenseForm()" class="w-8 h-8 rounded-lg bg-white border border-slate-200 text-slate-400 flex items-center justify-center transition-all active:scale-90"><i class="fa-solid fa-times text-[10px]"></i></button>
                        </div>
                    </form>
                </div>
                <div class="pt-5 border-t border-slate-100 flex items-center justify-between font-black">
                    <span class="text-[10px] text-slate-400 uppercase tracking-widest">Total Expenses</span>
                    <span class="text-lg text-rose-600 font-['Outfit']">LKR <?php echo number_format($delivery['total_expenses'], 2); ?></span>
                </div>
            </div>

            <!-- Profit Analysis -->
            <div class="glass-card p-8 bg-slate-900 text-white animate-slide-up print-pure-black" style="animation-delay: 0.2s">
                <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-6 print-label">Trip Net Analysis</h3>
                <div class="space-y-6">
                    <div class="flex justify-between items-center group">
                        <span class="text-xs font-black text-slate-400 group-hover:text-white transition-colors">Total Revenue</span>
                        <span class="text-md font-black">LKR <?php echo number_format($total_revenue, 2); ?></span>
                    </div>
                    <?php
                    // Simplified profit for details page
                    $net_estimated = $total_revenue - $delivery['total_expenses'];
                    ?>
                    <div class="flex justify-between items-center group">
                        <span class="text-xs font-black text-slate-400 group-hover:text-white transition-colors">Total Expenses</span>
                        <span class="text-md font-black text-rose-400">LKR <?php echo number_format($delivery['total_expenses'], 2); ?></span>
                    </div>
                    <div class="h-px bg-slate-800 w-full"></div>
                    <div class="flex justify-between items-center">
                        <div>
                            <span class="text-[10px] font-black text-emerald-400 uppercase tracking-widest block mb-1">Estimated Net Profit from Delivery</span>
                            <span class="text-2xl font-black font-['Outfit'] text-white">LKR <?php echo number_format($net_estimated, 2); ?></span>
                        </div>
                        <div class="w-12 h-12 bg-emerald-500/20 rounded-2xl flex items-center justify-center text-emerald-400">
                            <i class="fa-solid fa-chart-line text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Analysis (Print Only) -->
        <div class="md:col-span-12 footer-analysis space-y-6">
            <h3 class="section-label">Final Registry Reconciliation</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="print-flex-row">
                    <span class="text-xs font-black uppercase text-slate-500">Gross Trip Revenue:</span>
                    <span class="text-lg font-black font-['Outfit']">LKR <?php echo number_format($total_revenue, 2); ?></span>
                </div>
                <div class="print-flex-row">
                    <span class="text-xs font-black uppercase text-emerald-600">Total Payments Secured:</span>
                    <span class="text-lg font-black font-['Outfit'] text-emerald-700">LKR <?php echo number_format($total_paid, 2); ?></span>
                </div>
                <div class="print-flex-row">
                    <span class="text-xs font-black uppercase text-rose-600">Total Operational Fund:</span>
                    <span class="text-lg font-black font-['Outfit'] text-rose-700">LKR <?php echo number_format($delivery['total_expenses'], 2); ?></span>
                </div>
            </div>
            <div class="p-6 bg-slate-50 border border-slate-200 rounded-2xl flex items-center justify-between no-print">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">End of Registry Log #<?php echo $id; ?></p>
                <div class="flex gap-4">
                     <span class="text-[10px] font-black text-slate-900 border-l-2 border-slate-900 pl-4 uppercase">Data Integrity Verified</span>
                </div>
            </div>
        </div>
    </main>

    <!-- Modals (Copied from nwdelivery functionality scope) -->
    

    <!-- Payment Modal -->
    <div id="payment-modal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden no-print">
        <div class="glass-card w-full max-w-2xl p-8 animate-slide-up shadow-2xl">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h3 class="text-xl font-black text-slate-900 font-['Outfit']">Collect Payment</h3>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest" id="modal-cust-label"></p>
                </div>
                <button onclick="closeModal('payment-modal')" class="text-slate-400 hover:text-slate-900"><i class="fa-solid fa-times"></i></button>
            </div>
            
            <form id="payment-form" class="space-y-6">
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

                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-1 mb-2 block">Amount</label>
                        <input type="number" name="amount" required step="0.01" class="input-glass w-full h-[52px]">
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-1 mb-2 block">Date</label>
                        <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" class="input-glass w-full h-[52px]">
                    </div>
                </div>

                <div id="bank_fields_container" class="hidden">
                    <label class="text-[10px] font-black text-slate-400 uppercase ml-1 mb-2 block">Select Bank</label>
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <input type="text" id="bank_search" placeholder="Search saved banks..." class="input-glass w-full h-[52px]" onkeyup="searchBanks(this.value)">
                            <input type="hidden" name="bank_id" id="selected_bank_id">
                            <div id="bank_results" class="absolute w-full mt-1 bg-white border border-slate-100 rounded-xl shadow-2xl z-50 hidden overflow-hidden"></div>
                        </div>
                        <button type="button" onclick="openNewBankModal()" class="w-[52px] h-[52px] rounded-xl bg-indigo-50 text-indigo-600 border border-indigo-100 hover:bg-indigo-600 hover:text-white transition-all">
                            <i class="fa-solid fa-plus text-sm"></i>
                        </button>
                    </div>
                </div>

                <div id="cheque_fields_container" class="hidden space-y-6">
                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-1 mb-2 block">Cheque Number</label>
                            <input type="text" name="chq_no" class="input-glass w-full h-[52px]" placeholder="XXXXXX">
                        </div>
                        <div>
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-1 mb-2 block">Cheque Payer</label>
                            <input type="text" name="chq_payer" placeholder="Enter name (Optional)" class="input-glass w-full h-[52px]">
                        </div>
                    </div>
                </div>

                <div id="proof_section" class="hidden">
                    <label class="text-[10px] font-black text-slate-400 uppercase ml-1 mb-2 block">Payment Proof / Slip</label>
                    <input type="file" name="proof" class="input-glass w-full h-[52px] py-3 text-xs">
                </div>

                <button type="submit" class="w-full bg-emerald-600 text-white py-4 rounded-3xl font-black uppercase">Finalize Payment</button>
            </form>
        </div>
    </div>

    <!-- New Bank Modal -->
    <div id="new-bank-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xl z-[60] flex items-center justify-center p-4 hidden no-print">
        <div class="glass-card w-full max-w-sm p-8 shadow-2xl border-indigo-400/30">
            <h3 class="text-xl font-black text-slate-900 font-['Outfit'] mb-6">New Bank Account</h3>
            <form id="new-bank-form" class="space-y-5">
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

    <script>
        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        function togglePaymentFields() {
            const type = document.getElementById('payment_type_val').value;
            document.getElementById('bank_fields_container').classList.toggle('hidden', type !== 'Account Transfer' && type !== 'Cheque');
            document.getElementById('cheque_fields_container').classList.toggle('hidden', type !== 'Cheque');
            document.getElementById('proof_section').classList.toggle('hidden', type !== 'Account Transfer' && type !== 'Cheque');
        }

        function selectPaymentMethod(type) {
            document.getElementById('payment_type_val').value = type;

            const cards = document.querySelectorAll('.pay-method-card');
            cards.forEach(card => {
                const cardType = card.dataset.type;
                if (cardType === type) {
                    card.className = 'pay-method-card bg-indigo-50 border-2 border-indigo-500 rounded-xl p-3 flex flex-col items-center justify-center gap-2 cursor-pointer transition-all hover:bg-indigo-100';
                    card.querySelector('i').className = card.querySelector('i').className.replace('text-slate-500', 'text-indigo-600');
                    card.querySelector('span').className = card.querySelector('span').className.replace('text-slate-600', 'text-indigo-700');
                } else {
                    card.className = 'pay-method-card border-2 border-slate-100 rounded-xl p-3 flex flex-col items-center justify-center gap-2 cursor-pointer transition-all hover:border-slate-300 hover:bg-slate-50';
                    card.querySelector('i').className = card.querySelector('i').className.replace('text-indigo-600', 'text-slate-500');
                    card.querySelector('span').className = card.querySelector('span').className.replace('text-indigo-700', 'text-slate-600');
                }
            });

            togglePaymentFields();
        }

        function updateDamage(diId, val) {
            fetch(`?action=update_damage&id=<?php echo $id; ?>`, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `di_id=${diId}&damaged_qty=${val}`
            }).then(r => r.json()).then(res => { if(res.success) location.reload(); });
        }

        function updateDiscount(dcId, val) {
            fetch(`?action=update_discount&id=<?php echo $id; ?>`, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `dc_id=${dcId}&discount=${val}`
            }).then(r => r.json()).then(res => { if(res.success) location.reload(); });
        }

        function deleteExpense(expId) {
            if(!confirm('Delete this expense?')) return;
            fetch(`?action=delete_expense&id=<?php echo $id; ?>`, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `exp_id=${expId}`
            }).then(r => r.json()).then(res => { if(res.success) location.reload(); });
        }

        function searchBanks(term) {
            if(term.length < 2) return document.getElementById('bank_results').classList.add('hidden');
            fetch(`?action=search_bank&id=<?php echo $id; ?>&term=${term}`)
                .then(r => r.json()).then(data => {
                    let html = '';
                    if (data.length) {
                        data.forEach(b => {
                            const safeName = String(b.name || '').replace(/'/g, "\\'");
                            const safeAcc = String(b.account_number || '').replace(/'/g, "\\'");
                            html += `<div class="p-3 hover:bg-indigo-50 cursor-pointer border-b border-slate-50 last:border-0" onmousedown="selectBank(${b.id}, '${safeName}', '${safeAcc}')">
                                <p class="text-xs font-black text-slate-800">${b.name}</p>
                                <p class="text-[9px] text-slate-400 font-bold uppercase">${b.account_number || ''} ${b.account_name ? '&bull; ' + b.account_name : ''}</p>
                            </div>`;
                        });
                    } else {
                        const safeTerm = String(term).replace(/'/g, "\\'");
                        html = `<div class="p-3 text-center">
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">No bank found for "${term}"</p>
                            <button type="button" onclick="openNewBankModalPreFilled('${safeTerm}')" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-colors">
                                <i class="fa-solid fa-plus mr-1"></i>Create New Bank
                            </button>
                        </div>`;
                    }
                    const res = document.getElementById('bank_results'); res.innerHTML = html; res.classList.remove('hidden');
                });
        }

        function selectBank(id, name, accNo = '') {
            document.getElementById('selected_bank_id').value = id;
            document.getElementById('bank_search').value = accNo ? `${name} (${accNo})` : name;
            document.getElementById('bank_results').classList.add('hidden');
        }

        function openNewBankModal() {
            document.getElementById('new-bank-modal').classList.remove('hidden');
        }

        function openNewBankModalPreFilled(name) {
            document.getElementById('bank_results').classList.add('hidden');
            const modal = document.getElementById('new-bank-modal');
            modal.classList.remove('hidden');
            const nameField = modal.querySelector('[name="name"]');
            if (nameField) nameField.value = name;
        }

        document.getElementById('new-bank-form').onsubmit = function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'create_bank');
            fetch(`?id=<?php echo $id; ?>`, { method: 'POST', body: fd })
                .then(r => r.json()).then(res => {
                    if (!res.success) {
                        alert(res.message || 'Failed to save bank.');
                        return;
                    }
                    const accNo = this.querySelector('[name="acc_no"]').value;
                    selectBank(res.id, res.name, accNo);
                    closeModal('new-bank-modal');
                    this.reset();
                });
        };



        document.getElementById('inline-expense-form').onsubmit = function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'add_expense');
            fetch(`?id=<?php echo $id; ?>`, { method: 'POST', body: fd })
                .then(r => r.json()).then(res => { if(res.success) location.reload(); });
        };

        document.getElementById('payment-form').onsubmit = function(e) {
            e.preventDefault();
            fetch(`?action=save_payment&id=<?php echo $id; ?>`, { method: 'POST', body: new FormData(this) })
                .then(r => r.json()).then(res => { if(res.success) location.reload(); });
        };

        function toggleExpenseForm() {
            const form = document.getElementById('inline-expense-form');
            form.classList.toggle('hidden');
            if(!form.classList.contains('hidden')) {
                form.querySelector('[name="name"]').focus();
            }
        }
        function openExpenseModal() { toggleExpenseForm(); }
        function openPaymentModal(dcId, name) {
            document.getElementById('payment_dc_id').value = dcId;
            document.getElementById('modal-cust-label').innerText = "Customer: " + name;
            document.getElementById('selected_bank_id').value = '';
            document.getElementById('bank_search').value = '';
            
            const form = document.getElementById('payment-form');
            if (form.querySelector('[name="chq_no"]')) form.querySelector('[name="chq_no"]').value = '';
            if (form.querySelector('[name="chq_payer"]')) form.querySelector('[name="chq_payer"]').value = '';
            
            selectPaymentMethod('Cash');
            openModal('payment-modal');
        }
        function uploadBill(dcId) {
            const f = document.createElement('input'); f.type = 'file';
            f.onchange = ev => {
                const fd = new FormData(); fd.append('bill', ev.target.files[0]); fd.append('dc_id', dcId); fd.append('action', 'upload_bill');
                fetch('?id=<?php echo $id; ?>', { method: 'POST', body: fd }).then(r => r.json()).then(res => { if(res.success) alert('Bill Uploaded.'); });
            };
            f.click();
        }
        function deleteCustomerOrder(dcId) {
            if(!confirm('Are you sure you want to remove this customer order? Items will be returned to stock and all associated records will be deleted.')) return;
            fetch(`?action=delete_customer_order&id=<?php echo $id; ?>`, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `dc_id=${dcId}`
            }).then(r => r.json()).then(res => { if(res.success) location.reload(); });
        }

        function markPaymentDone(dcId) {
            if(!confirm('Mark as finished?')) return;
            fetch(`?action=mark_complete&id=<?php echo $id; ?>`, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `dc_id=${dcId}` })
                .then(r => r.json()).then(res => { if(res.success) location.reload(); });
        }
    </script>
</body>
</html>
