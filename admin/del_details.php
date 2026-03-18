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

        if ($action == 'search_cheque_customer') {
            $term = '%' . $_GET['term'] . '%';
            $stmt = $pdo->prepare("SELECT id, name FROM customers WHERE name LIKE ? LIMIT 5");
            $stmt->execute([$term]);
            echo json_encode($stmt->fetchAll());
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
            $chq_cust_id = $_POST['chq_cust_id'] ?: null;
            
            $proof = null;
            if (isset($_FILES['proof']) && $_FILES['proof']['error'] == 0) {
                $proof = time() . '_' . $_FILES['proof']['name'];
                if (!is_dir('../uploads/payments')) mkdir('../uploads/payments', 0777, true);
                move_uploaded_file($_FILES['proof']['tmp_name'], '../uploads/payments/' . $proof);
            }

            $stmt = $pdo->prepare("INSERT INTO delivery_payments (delivery_customer_id, amount, payment_type, bank_id, cheque_number, proof_image, payment_date, due_date, cheque_customer_id, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$dc_id, $amount, $type, $bank_id, $chq_no, $proof, $date, $due_date, $chq_cust_id, $_SESSION['user_id']]);
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

    $stmt = $pdo->prepare("SELECT dp.*, b.name as bank_name, b.account_number, b.account_name, cust.name as cheque_payer FROM delivery_payments dp LEFT JOIN banks b ON dp.bank_id = b.id LEFT JOIN customers cust ON dp.cheque_customer_id = cust.id WHERE dp.delivery_customer_id = ? ORDER BY dp.payment_date DESC");
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
                $card_status_class = 'bg-emerald-50/70 border-emerald-100';
            } elseif ((float)$c['total_paid'] > 0) {
                $status_label = 'PENDING';
                $status_class = 'bg-yellow-100 border-yellow-300 text-yellow-700';
                $card_status_class = 'bg-yellow-50/70 border-yellow-100';
            } else {
                $status_label = 'NOT PAID';
                $status_class = 'bg-rose-100 border-rose-300 text-rose-700';
                $card_status_class = 'bg-rose-50/70 border-rose-100';
            }
            ?>
            <div class="glass-card overflow-hidden animate-slide-up print-pure-black <?php echo $card_status_class; ?>">
                <div class="p-6 bg-slate-50/50 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-black text-slate-900 font-['Outfit'] tracking-tight capitalize"><?php echo $c['customer_name']; ?></h2>
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
                        <table class="w-full text-sm print-table">
                            <thead>
                                <tr class="text-[10px] uppercase font-black text-slate-400 border-b border-slate-100 print-pure-black">
                                    <th class="py-2 px-2">Brand / Product</th>
                                    <th class="py-2 px-2">Container</th>
                                    <th class="py-2 px-2 text-center">Qty</th>
                                    <th class="py-2 px-2 text-center text-rose-600">Dmg</th>
                                    <th class="py-2 px-2 text-right">Unit Price</th>
                                    <th class="py-2 px-2 text-right">Line Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach($c['items'] as $item): ?>
                                <tr class="print-pure-black">
                                    <td class="py-4 px-2 font-black text-slate-800"><?php echo $item['brand_name']; ?></td>
                                    <td class="py-4 px-2 font-bold text-slate-500 text-[10px]"><?php echo $item['container_number']; ?></td>
                                    <td class="py-4 px-2 text-center font-black"><?php echo $item['qty']; ?></td>
                                    <td class="py-4 px-2 text-center">
                                        <input type="number" value="<?php echo $item['damaged_qty']; ?>" class="w-12 h-8 text-center bg-transparent border-0 font-bold focus:ring-0" onchange="updateDamage(<?php echo $item['id']; ?>, this.value)">
                                    </td>
                                    <td class="py-4 px-2 text-right font-bold text-slate-600">LKR <?php echo number_format($item['selling_price'], 2); ?></td>
                                    <td class="py-4 px-2 text-right font-black text-slate-900">LKR <?php echo number_format($item['total'], 2); ?></td>
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
                                <button onclick="openPaymentModal(<?php echo $c['id']; ?>, '<?php echo addslashes($c['customer_name']); ?>')" class="text-[9px] bg-emerald-600 text-white px-3 py-1.5 rounded-lg font-black uppercase no-print">+ Add</button>
                            </div>
                            <div class="space-y-2">
                                <?php foreach($c['payments'] as $p): ?>
                                <div class="p-3 bg-white border border-slate-100 rounded-xl flex items-center justify-between shadow-sm print-pure-black">
                                    <div>
                                        <p class="text-xs font-black text-slate-800">LKR <?php echo number_format($p['amount'], 2); ?></p>
                                        <p class="text-[9px] font-black text-slate-400 uppercase"><?php echo $p['payment_type']; ?> &bull; <?php echo date('M d, Y', strtotime($p['payment_date'])); ?></p>
                                    </div>
                                    <?php if($p['payment_type'] == 'Cheque'): ?>
                                    <div class="text-right">
                                        <p class="text-[9px] font-black text-indigo-600 uppercase">CHQ: <?php echo $p['cheque_number']; ?></p>
                                        <p class="text-[8px] font-bold text-slate-400">DUE: <?php echo date('M d', strtotime($p['due_date'])); ?></p>
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
                <div class="px-6 pb-6 space-y-4">
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
                <div class="space-y-3 mb-6">
                    <?php if(empty($expenses)): ?>
                        <p class="text-center text-xs text-slate-400 italic">Nil.</p>
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
    
    <!-- Expense Modal -->
    <div id="expense-modal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden no-print">
        <div class="glass-card w-full max-w-md p-8 animate-slide-up shadow-2xl">
            <h3 class="text-xl font-black text-slate-900 font-['Outfit'] mb-6">New Expenditure</h3>
            <form id="expense-form" class="space-y-6">
                <div>
                    <label class="text-[10px] font-black text-slate-400 tracking-widest ml-1 mb-2 block">EXPENDITURE NAME</label>
                    <input type="text" name="name" required placeholder="Fuel, Driver, etc." class="input-glass w-full h-[52px]">
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-400 tracking-widest ml-1 mb-2 block">AMOUNT (LKR)</label>
                    <input type="number" name="amount" required step="0.01" class="input-glass w-full h-[52px]">
                </div>
                <button type="submit" class="w-full bg-slate-900 text-white py-4 rounded-2xl font-black uppercase tracking-widest">Save Expense</button>
            </form>
        </div>
    </div>

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
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-1 mb-2 block">Type</label>
                        <select name="type" id="payment_type_select" class="input-glass w-full h-[52px]" onchange="togglePaymentFields()">
                            <option value="Cash">Cash</option>
                            <option value="Account Transfer">Bank Transfer</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Card">Card</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-1 mb-2 block">Amount</label>
                        <input type="number" name="amount" required step="0.01" class="input-glass w-full h-[52px]">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-1 mb-2 block">Date</label>
                        <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" class="input-glass w-full h-[52px]">
                    </div>
                </div>

                <div id="bank_fields_container" class="hidden">
                    <label class="text-[10px] font-black text-slate-400 uppercase ml-1 mb-2 block">Select Bank</label>
                    <div class="relative">
                        <input type="text" id="bank_search" placeholder="Search saved banks..." class="input-glass w-full h-[52px]" onkeyup="searchBanks(this.value)">
                        <input type="hidden" name="bank_id" id="selected_bank_id">
                        <div id="bank_results" class="absolute w-full mt-1 bg-white border border-slate-100 rounded-xl shadow-2xl z-50 hidden"></div>
                    </div>
                </div>

                <div id="cheque_fields_container" class="hidden space-y-6">
                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-1 mb-2 block">Cheque Number</label>
                            <input type="text" name="chq_no" class="input-glass w-full h-[52px]">
                        </div>
                        <div class="relative">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-1 mb-2 block">Cheque Customer</label>
                            <input type="text" id="chq_cust_search" placeholder="Search Registry..." class="input-glass w-full h-[52px]" onkeyup="searchChequeCustomers(this.value)">
                            <input type="hidden" name="chq_cust_id" id="selected_chq_cust_id">
                            <div id="chq_cust_results" class="absolute w-full mt-1 bg-white border border-slate-100 rounded-xl shadow-2xl z-50 hidden"></div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full bg-emerald-600 text-white py-4 rounded-3xl font-black uppercase">Finalize Payment</button>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        function togglePaymentFields() {
            const type = document.getElementById('payment_type_select').value;
            document.getElementById('bank_fields_container').classList.toggle('hidden', type !== 'Account Transfer' && type !== 'Cheque');
            document.getElementById('cheque_fields_container').classList.toggle('hidden', type !== 'Cheque');
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
                    data.forEach(b => {
                        html += `<div class="p-3 hover:bg-slate-50 cursor-pointer text-xs font-bold" onclick="selectBank(${b.id}, '${b.name}')">${b.name}</div>`;
                    });
                    const res = document.getElementById('bank_results'); res.innerHTML = html; res.classList.remove('hidden');
                });
        }
        function selectBank(id, name) {
            document.getElementById('selected_bank_id').value = id;
            document.getElementById('bank_search').value = name;
            document.getElementById('bank_results').classList.add('hidden');
        }

        function searchChequeCustomers(term) {
            if(term.length < 2) return document.getElementById('chq_cust_results').classList.add('hidden');
            fetch(`?action=search_cheque_customer&id=<?php echo $id; ?>&term=${term}`)
                .then(r => r.json()).then(data => {
                    let html = '';
                    data.forEach(c => {
                        html += `<div class="p-3 hover:bg-slate-50 cursor-pointer text-xs font-bold" onclick="selectChqCust(${c.id}, '${c.name.replace(/'/g, "\\'")}')">${c.name}</div>`;
                    });
                    const res = document.getElementById('chq_cust_results'); res.innerHTML = html; res.classList.remove('hidden');
                });
        }
        function selectChqCust(id, name) {
            document.getElementById('selected_chq_cust_id').value = id;
            document.getElementById('chq_cust_search').value = name;
            document.getElementById('chq_cust_results').classList.add('hidden');
        }

        document.getElementById('expense-form').onsubmit = function(e) {
            e.preventDefault();
            fetch(`?action=add_expense&id=<?php echo $id; ?>`, { method: 'POST', body: new FormData(this) })
                .then(r => r.json()).then(res => { if(res.success) location.reload(); });
        };

        document.getElementById('payment-form').onsubmit = function(e) {
            e.preventDefault();
            fetch(`?action=save_payment&id=<?php echo $id; ?>`, { method: 'POST', body: new FormData(this) })
                .then(r => r.json()).then(res => { if(res.success) location.reload(); });
        };

        function openExpenseModal() { openModal('expense-modal'); }
        function openPaymentModal(dcId, name) {
            document.getElementById('payment_dc_id').value = dcId;
            document.getElementById('modal-cust-label').innerText = "Customer: " + name;
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
        function markPaymentDone(dcId) {
            if(!confirm('Mark as finished?')) return;
            fetch(`?action=mark_complete&id=<?php echo $id; ?>`, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `dc_id=${dcId}` })
                .then(r => r.json()).then(res => { if(res.success) location.reload(); });
        }
    </script>
</body>
</html>
