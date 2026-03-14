<?php
require_once '../auth.php';
require_once '../config.php';
checkAuth();

if (!isAdmin()) {
    header('Location: ../login.php');
    exit;
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
            $due_date = date('Y-m-d', strtotime($date . ' + 12 days'));
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
            } else {
                echo json_encode(['success' => false]);
            }
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Fetch Core Data
$stmt = $pdo->prepare("SELECT d.*, u.full_name as creator_name FROM deliveries d JOIN users u ON d.created_by = u.id WHERE d.id = ?");
$stmt->execute([$id]);
$delivery = $stmt->fetch();
if (!$delivery) die("Delivery Trip not found.");

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
    
    $c['total_paid'] = array_sum(array_column($c['payments'], 'amount'));
}

$stmt = $pdo->prepare("SELECT * FROM delivery_expenses WHERE delivery_id = ?");
$stmt->execute([$id]);
$expenses = $stmt->fetchAll();

// Calculations for header
$total_revenue = array_sum(array_column($customers, 'subtotal'));
$total_paid = 0;
foreach($customers as $c) $total_paid += $c['total_paid'];
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
        .input-glass { background: rgba(255, 255, 255, 0.6); border: 1px solid #e2e8f0; padding: 10px 16px; border-radius: 14px; outline: none; transition: all 0.3s; font-size: 13px; }
        .input-glass:focus { border-color: #0891b2; background: white; box-shadow: 0 0 15px rgba(8, 145, 178, 0.08); }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .tab-active { color: #0891b2; border-bottom: 2px solid #0891b2; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .animate-slide-up { animation: slideUp 0.4s ease forwards; }
    </style>
</head>
<body class="flex flex-col pb-20">

    <header class="glass-header sticky top-0 z-40 py-4">
        <div class="px-4 md:px-8 flex items-center justify-between">
            <div class="flex items-center space-x-5">
                <a href="nwdelivery.php" class="text-slate-800 hover:text-cyan-600 transition-colors p-2.5 rounded-2xl hover:bg-slate-100">
                    <i class="fa-solid fa-arrow-left text-xl"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-black text-slate-900 font-['Outfit'] tracking-tight">Trip #<?php echo str_pad($id, 4, '0', STR_PAD_LEFT); ?></h1>
                    <p class="text-[10px] uppercase font-black text-slate-500 tracking-widest mt-0.5"><?php echo date('M d, Y', strtotime($delivery['delivery_date'])); ?> &bull; Managed by <?php echo $delivery['creator_name']; ?></p>
                </div>
            </div>
            
            <div class="flex items-center gap-8">
                <div class="hidden md:flex flex-col items-end">
                    <p class="text-[11px] uppercase font-black text-slate-500 tracking-widest">Total Value</p>
                    <p class="text-xl font-black text-slate-900 font-['Outfit']">LKR <?php echo number_format($total_revenue, 2); ?></p>
                </div>
                <div class="w-px h-10 bg-slate-200"></div>
                <div class="flex flex-col items-end">
                    <p class="text-[11px] uppercase font-black text-emerald-500 tracking-widest">Received</p>
                    <p class="text-xl font-black text-emerald-600 font-['Outfit']">LKR <?php echo number_format($total_paid, 2); ?></p>
                </div>
                <div class="w-px h-10 bg-slate-200"></div>
                <div class="flex flex-col items-end">
                    <p class="text-[11px] uppercase font-black text-rose-500 tracking-widest">Pending</p>
                    <p class="text-xl font-black text-rose-600 font-['Outfit']">LKR <?php echo number_format($pending_payment, 2); ?></p>
                </div>
            </div>
        </div>
    </header>

    <main class="w-full px-4 md:px-8 py-10 grid grid-cols-1 md:grid-cols-12 gap-8">
        
        <!-- Left Column: Delivery Summary & Expenses -->
        <div class="md:col-span-4 space-y-8">
            <div class="glass-card p-6 animate-slide-up">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-sm font-black text-slate-500 uppercase tracking-widest">Expenses</h3>
                    <button onclick="openExpenseModal()" class="text-xs bg-slate-900 text-white px-4 py-2 rounded-xl font-black uppercase tracking-wider hover:bg-black transition-all">+ Add New</button>
                </div>
                <div class="space-y-3">
                    <?php if(empty($expenses)): ?>
                        <p class="text-center text-xs text-slate-500 italic">No expenses recorded yet.</p>
                    <?php endif; ?>
                    <?php foreach($expenses as $e): ?>
                    <div class="flex items-center justify-between p-3 bg-white/50 rounded-2xl border border-white/50 group">
                        <div>
                            <p class="text-sm font-black text-slate-900 leading-none mb-1"><?php echo $e['expense_name']; ?></p>
                            <p class="text-[11px] font-black text-slate-500 font-['Outfit']">LKR <?php echo number_format($e['amount'], 2); ?></p>
                        </div>
                        <button onclick="deleteExpense(<?php echo $e['id']; ?>)" class="text-slate-200 hover:text-rose-500 opacity-0 group-hover:opacity-100 transition-all"><i class="fa-solid fa-trash-can text-xs"></i></button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-6 pt-6 border-t border-slate-100 flex items-center justify-between">
                    <p class="text-xs font-black text-slate-500 uppercase tracking-widest">Total Expenses Cost</p>
                    <p class="text-xl font-black text-rose-600 font-['Outfit']">LKR <?php echo number_format($delivery['total_expenses'], 2); ?></p>
                </div>
            </div>

            <div class="glass-card p-6 border-cyan-100 bg-cyan-50/30">
                <h4 class="text-xs font-black text-cyan-700 uppercase tracking-widest mb-4">Payment Status</h4>
                <div class="flex items-end gap-2">
                    <p class="text-4xl font-black text-slate-900 font-['Outfit']">
                        <?php 
                        $due_perc = round(($total_revenue > 0) ? ($pending_payment / $total_revenue) * 100 : 0);
                        echo $due_perc . "%";
                        ?>
                    </p>
                    <p class="text-[11px] font-black text-slate-500 mb-2 uppercase">Due</p>
                </div>
                <div class="w-full h-2 bg-slate-200 rounded-full mt-4 overflow-hidden">
                    <div class="h-full bg-cyan-500 rounded-full" style="width: <?php echo $due_perc; ?>%"></div>
                </div>
            </div>
        </div>

        <!-- Right Column: Customer Orders List -->
        <div class="md:col-span-8 space-y-8">
            <?php foreach($customers as $c): ?>
            <div class="glass-card overflow-hidden animate-slide-up" style="animation-delay: 0.1s">
                <!-- Customer Header -->
                <div class="p-6 bg-white/40 border-b border-white flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-slate-900 rounded-2xl flex items-center justify-center text-white shadow-xl shadow-slate-900/10">
                            <i class="fa-solid fa-store text-lg"></i>
                        </div>
                        <div>
                            <h2 class="text-xl font-black text-slate-900 font-['Outfit'] tracking-tight capitalize"><?php echo $c['customer_name']; ?></h2>
                            <p class="text-[11px] font-black text-slate-600 uppercase tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-phone text-[9px]"></i> <?php echo $c['contact_number']; ?>
                                <span class="text-slate-300">|</span>
                                <i class="fa-solid fa-location-dot text-[9px]"></i> <?php echo $c['address']; ?>
                            </p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-[11px] font-black text-slate-500 uppercase tracking-widest">Total</p>
                        <p class="text-2xl font-black text-slate-900 font-['Outfit']">LKR <?php echo number_format($c['subtotal'], 2); ?></p>
                    </div>
                </div>

                <!-- Body: Items & Damages -->
                <div class="p-6 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Items Table -->
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <h4 class="text-xs font-black text-slate-500 uppercase tracking-widest">Items</h4>
                                <button onclick="uploadBill(<?php echo $c['id']; ?>)" class="bg-indigo-600 text-white px-5 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-widest shadow-lg shadow-indigo-600/20 hover:bg-black transition-all flex items-center gap-2">
                                    <i class="fa-solid fa-cloud-arrow-up text-xs"></i>
                                    <span>Upload Bill</span>
                                </button>
                            </div>
                            <div class="space-y-2">
                                <?php foreach($c['items'] as $item): ?>
                                <div class="p-3 bg-white/30 rounded-2xl border border-white/50 flex items-center justify-between">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-black text-slate-900 truncate"><?php echo $item['brand_name']; ?></p>
                                        <p class="text-[10px] font-black text-slate-500 uppercase tracking-tighter">Qty: <?php echo $item['qty']; ?> &bull; <?php echo $item['container_number']; ?></p>
                                    </div>
                                    <div class="flex items-center gap-4 ml-4">
                                        <div class="text-right">
                                            <label class="text-[9px] font-black text-rose-500 uppercase block mb-1">Damage</label>
                                            <input type="number" value="<?php echo $item['damaged_qty']; ?>" class="w-14 h-8 bg-white border border-slate-300 rounded-lg text-sm font-black text-center focus:border-rose-500 outline-none transition-all" onchange="updateDamage(<?php echo $item['id']; ?>, this.value)">
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Discount Field -->
                            <div class="pt-4 flex items-center justify-between border-t border-slate-200">
                                <p class="text-xs font-black text-slate-500 uppercase tracking-widest">Discount</p>
                                <div class="flex items-center gap-2">
                                    <p class="text-[10px] font-black text-slate-400">LKR</p>
                                    <input type="number" step="0.01" value="<?php echo $c['discount']; ?>" class="w-24 input-glass h-9 text-right font-black text-slate-900 text-sm" onchange="updateDiscount(<?php echo $c['id']; ?>, this.value)">
                                </div>
                            </div>
                        </div>

                        <!-- Payments Section -->
                        <div class="bg-emerald-50/40 p-6 rounded-[2rem] border border-emerald-100/80 space-y-5 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-xl bg-emerald-600/10 flex items-center justify-center text-emerald-600">
                                        <i class="fa-solid fa-receipt text-xs"></i>
                                    </div>
                                    <h4 class="text-xs font-black text-emerald-900 uppercase tracking-widest">Payments</h4>
                                </div>
                                <button onclick="openPaymentModal(<?php echo $c['id']; ?>, '<?php echo addslashes($c['customer_name']); ?>')" class="text-[10px] font-black text-emerald-700 bg-white px-4 py-2 rounded-xl flex items-center gap-2 border border-emerald-200 hover:bg-emerald-600 hover:text-white transition-all shadow-sm">
                                    <i class="fa-solid fa-plus-circle text-xs"></i>
                                    <span>Add Payment</span>
                                </button>
                            </div>

                            <div class="space-y-2 max-h-[250px] overflow-y-auto custom-scroll pr-1">
                                <?php if(empty($c['payments'])): ?>
                                    <p class="text-center py-8 text-xs text-slate-400 font-bold italic">No payments logged yet.</p>
                                <?php endif; ?>
                                <?php foreach($c['payments'] as $p): ?>
                                <div class="p-3 bg-white rounded-2xl border border-slate-200 shadow-sm relative overflow-hidden group">
                                    <div class="flex items-center justify-between relative z-10">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-xl bg-slate-900 text-white flex items-center justify-center text-xs">
                                                <i class="fa-solid <?php 
                                                    echo match($p['payment_type']) {
                                                        'Cash' => 'fa-money-bill-wave',
                                                        'Account Transfer' => 'fa-building-columns',
                                                        'Cheque' => 'fa-money-check-dollar',
                                                        'Card' => 'fa-credit-card',
                                                        default => 'fa-receipt'
                                                    };
                                                ?>"></i>
                                            </div>
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <p class="text-sm font-black text-slate-900">LKR <?php echo number_format($p['amount'], 2); ?></p>
                                                    <span class="text-[9px] bg-slate-100 px-2 py-0.5 rounded font-black text-slate-600 uppercase"><?php echo $p['payment_type']; ?></span>
                                                </div>
                                                <p class="text-xs text-slate-600 font-black">
                                                    Paid: <?php echo date('M d', strtotime($p['payment_date'])); ?>
                                                    <?php if($p['due_date']): ?>
                                                        &bull; Due: <span class="text-indigo-600"><?php 
                                                            $days = round((strtotime($p['due_date']) - strtotime($delivery['delivery_date'])) / 86400);
                                                            echo "After $days Days";
                                                        ?></span>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Extended info for Bank/Cheque -->
                                    <?php if($p['bank_name']): ?>
                                        <div class="mt-2 pt-2 border-t border-slate-100 flex items-center gap-2">
                                            <i class="fa-solid fa-landmark text-xs text-slate-400"></i>
                                            <p class="text-[11px] font-black text-slate-600"><?php echo $p['bank_name']; ?> &bull; <?php echo $p['account_number'] ?: $p['cheque_number']; ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <?php if($p['cheque_payer']): ?>
                                        <p class="text-[11px] font-black text-slate-600 mt-1"><i class="fa-solid fa-user-pen mr-1"></i> Payee: <?php echo $p['cheque_payer']; ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="flex items-center justify-between font-black">
                                <div class="text-right">
                                    <p class="text-[10px] text-slate-500 uppercase mb-1">Balance Due</p>
                                    <p class="text-lg <?php echo ($c['subtotal'] - $c['discount'] - $c['total_paid'] > 0) ? 'text-rose-600' : 'text-emerald-600'; ?> font-['Outfit']">
                                        LKR <?php echo number_format(max(0, $c['subtotal'] - $c['discount'] - $c['total_paid']), 2); ?>
                                    </p>
                                </div>
                                <?php if($c['payment_status'] !== 'completed'): ?>
                                <button onclick="markPaymentDone(<?php echo $c['id']; ?>)" class="bg-emerald-600 hover:bg-black text-white px-6 py-3 rounded-2xl text-xs uppercase font-bold transition-all shadow-xl shadow-emerald-600/10">Mark Order Cleared</button>
                                <?php else: ?>
                                <span class="bg-emerald-100 text-emerald-700 px-4 py-2 rounded-xl text-[11px] uppercase font-black tracking-widest flex items-center gap-2"><i class="fa-solid fa-check-circle"></i> Fully Paid</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </main>

    <!-- Modals Section -->
    
    <!-- Expense Modal -->
    <div id="expense-modal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="glass-card w-full max-w-md p-8 animate-slide-up shadow-2xl">
            <div class="flex items-center justify-between mb-8 text-slate-900 font-['Outfit']">
                <h3 class="text-xl font-black italic">Trip Disbursement</h3>
                <button onclick="closeModal('expense-modal')" class="text-slate-400 hover:text-slate-900"><i class="fa-solid fa-times"></i></button>
            </div>
            <form id="expense-form" class="space-y-6">
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1 mb-2 block">Purpose of Fund</label>
                    <input type="text" name="name" required placeholder="Fuel, Driver Meal, Toll, etc." class="input-glass w-full h-[52px] font-bold">
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1 mb-2 block">Amount Disbursed (LKR)</label>
                    <input type="number" name="amount" required step="0.01" class="input-glass w-full h-[52px] font-black text-rose-600 text-lg">
                </div>
                <button type="submit" class="w-full bg-slate-900 text-white py-4 rounded-2xl font-black uppercase tracking-widest hover:bg-black transition-all shadow-xl shadow-slate-900/20 active:scale-95">Record Expenditure</button>
            </form>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="payment-modal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="glass-card w-full max-w-2xl p-8 animate-slide-up shadow-2xl">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h3 class="text-xl font-black text-slate-900 font-['Outfit'] tracking-tighter">Register Payment Received</h3>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest" id="modal-cust-label"></p>
                </div>
                <button onclick="closeModal('payment-modal')" class="text-slate-400 hover:text-slate-900"><i class="fa-solid fa-times text-xl"></i></button>
            </div>
            
            <form id="payment-form" class="space-y-6">
                <input type="hidden" name="dc_id" id="payment_dc_id">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1 mb-2 block">Payment Instrument</label>
                        <select name="type" id="payment_type_select" class="input-glass w-full h-[52px] font-bold" onchange="togglePaymentFields()">
                            <option value="Cash">Cash Currency</option>
                            <option value="Account Transfer">Bank Ledger Transfer</option>
                            <option value="Cheque">Banker's Cheque</option>
                            <option value="Card"> Card</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1 mb-2 block">Transaction Amount (LKR)</label>
                        <input type="number" name="amount" required step="0.01" class="input-glass w-full h-[52px] font-black text-emerald-600 text-lg">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1 mb-2 block">Transaction Date</label>
                        <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" class="input-glass w-full h-[52px] font-bold">
                    </div>
                </div>

                <!-- Bank Fields -->
                <div id="bank_fields_container" class="hidden space-y-6">
                    <div class="p-5 bg-cyan-50/50 rounded-3xl border border-cyan-100 flex items-center gap-4 relative">
                        <div class="flex-1 relative">
                            <label class="text-[9px] font-black text-cyan-600 uppercase tracking-widest ml-1 mb-2 block">Target Bank Account</label>
                            <input type="text" id="bank_search" placeholder="Search saved banks..." class="input-glass w-full h-[45px] font-bold" onkeyup="searchBanks(this.value)">
                            <input type="hidden" name="bank_id" id="selected_bank_id">
                            <div id="bank_results" class="absolute w-full mt-1 bg-white border border-slate-100 rounded-2xl shadow-2xl z-50 hidden overflow-hidden"></div>
                        </div>
                        <button type="button" onclick="openNewBankModal()" class="mt-6 w-12 h-12 bg-white text-cyan-500 border border-cyan-200 rounded-2xl flex items-center justify-center hover:bg-cyan-500 hover:text-white transition-all"><i class="fa-solid fa-plus"></i></button>
                    </div>
                </div>

                <!-- Cheque Fields -->
                <div id="cheque_fields_container" class="hidden space-y-6">
                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1 mb-2 block">Cheque Serial Number</label>
                            <input type="text" name="chq_no" placeholder="CHQ-XXXXXX" class="input-glass w-full h-[52px] font-bold">
                        </div>
                        <div class="relative">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1 mb-2 block">Drawee (From Customer)</label>
                            <input type="text" id="chq_cust_search" placeholder="Search Registry..." class="input-glass w-full h-[52px] font-bold" onkeyup="searchChequeCustomers(this.value)">
                            <input type="hidden" name="chq_cust_id" id="selected_chq_cust_id">
                            <div id="chq_cust_results" class="absolute w-full mt-1 bg-white border border-slate-100 rounded-2xl shadow-2xl z-50 hidden overflow-hidden"></div>
                        </div>
                    </div>
                </div>

                <!-- Proof Image -->
                <div id="proof_upload_container" class="hidden">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1 mb-2 block">Digital Proof Instrument (Slip/Cheque Photo)</label>
                    <div class="flex items-center justify-center w-full">
                        <label class="flex flex-col items-center justify-center w-full h-32 border-2 border-slate-200 border-dashed rounded-3xl cursor-pointer bg-white/40 hover:bg-white/80 transition-all group">
                            <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                <i class="fa-solid fa-cloud-arrow-up text-2xl text-slate-300 group-hover:text-cyan-500 transition-colors mb-2"></i>
                                <p class="text-[11px] font-bold text-slate-400 group-hover:text-cyan-600">Click to upload document proof</p>
                            </div>
                            <input type="file" name="proof" class="hidden" />
                        </label>
                    </div>
                </div>

                <div class="pt-4">
                    <button type="submit" class="w-full bg-emerald-600 text-white py-4 rounded-3xl font-black uppercase tracking-widest hover:bg-black transition-all shadow-xl shadow-emerald-600/20 active:scale-95">Finalize Payment Entry</button>
                </div>
            </form>
        </div>
    </div>

    <!-- New Bank Modal -->
    <div id="new-bank-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xl z-[60] flex items-center justify-center p-4 hidden">
        <div class="glass-card w-full max-w-sm p-8 animate-slide-up shadow-2xl border-cyan-400/30">
            <h3 class="text-xl font-black text-slate-900 font-['Outfit'] italic mb-6">Enroll New Account</h3>
            <form id="new-bank-form" class="space-y-6">
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1 mb-2 block">Bank Institution</label>
                    <input type="text" id="new_bank_name" name="name" required class="input-glass w-full h-[45px] font-bold uppercase" placeholder="BOC / SAMPATH / etc.">
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1 mb-2 block">Account Identity Number</label>
                    <input type="text" name="acc_no" required class="input-glass w-full h-[45px] font-black" placeholder="XXXX-XXXX-XXXX">
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1 mb-2 block">Account Holder Designation</label>
                    <input type="text" name="acc_name" required class="input-glass w-full h-[45px] font-bold" placeholder="Legal Name of Holder">
                </div>
                <div class="flex gap-4 pt-2">
                    <button type="button" onclick="closeModal('new-bank-modal')" class="flex-1 py-3 text-[10px] font-black uppercase text-slate-400">Abort</button>
                    <button type="submit" class="flex-[2] bg-cyan-600 text-white py-3 rounded-2xl text-[10px] font-black uppercase shadow-lg shadow-cyan-600/20">Authorize Account</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal & Selection Logic
        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        function togglePaymentFields() {
            const type = document.getElementById('payment_type_select').value;
            const bankFields = document.getElementById('bank_fields_container');
            const chqFields = document.getElementById('cheque_fields_container');
            const proofUpload = document.getElementById('proof_upload_container');

            bankFields.classList.toggle('hidden', type !== 'Account Transfer' && type !== 'Cheque');
            chqFields.classList.toggle('hidden', type !== 'Cheque');
            proofUpload.classList.toggle('hidden', type !== 'Account Transfer' && type !== 'Cheque');
        }

        // AJAX Handlers
        function updateDamage(diId, val) {
            fetch(`?action=update_damage&id=<?php echo $id; ?>`, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `di_id=${diId}&damaged_qty=${val}`
            }).then(r => r.json()).then(res => {
                if(!res.success) alert(res.message);
                else location.reload();
            });
        }

        function updateDiscount(dcId, val) {
            fetch(`?action=update_discount&id=<?php echo $id; ?>`, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `dc_id=${dcId}&discount=${val}`
            }).then(r => r.json()).then(res => {
                if(!res.success) alert(res.message);
                else location.reload();
            });
        }

        function deleteExpense(expId) {
            if(!confirm('Authorize permanent removal of this expenditure?')) return;
            fetch(`?action=delete_expense&id=<?php echo $id; ?>`, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `exp_id=${expId}`
            }).then(r => r.json()).then(res => {
                if(res.success) location.reload();
            });
        }

        function markPaymentDone(dcId) {
            if(!confirm('Mark this customer order as FULLY SETTLED?')) return;
            fetch(`?action=mark_complete&id=<?php echo $id; ?>`, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `dc_id=${dcId}`
            }).then(r => r.json()).then(res => {
                if(res.success) location.reload();
            });
        }

        // Bank Search
        function searchBanks(term) {
            if(term.length < 2) return document.getElementById('bank_results').classList.add('hidden');
            fetch(`?action=search_bank&id=<?php echo $id; ?>&term=${term}`)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    if(data.length > 0) {
                        data.forEach(b => {
                            html += `<div class="p-3 hover:bg-cyan-50 cursor-pointer border-b border-slate-50 last:border-0" onclick="selectBank(${b.id}, '${b.name}')">
                                <p class="text-xs font-black text-slate-800">${b.name}</p>
                                <p class="text-[9px] text-slate-400 font-bold uppercase">${b.account_number} &bull; ${b.account_name}</p>
                            </div>`;
                        });
                    } else {
                        html = `<div class="p-4 text-center text-[10px] text-slate-400 font-bold italic">No matching accounts.</div>`;
                    }
                    const res = document.getElementById('bank_results');
                    res.innerHTML = html;
                    res.classList.remove('hidden');
                });
        }

        function selectBank(id, name) {
            document.getElementById('selected_bank_id').value = id;
            document.getElementById('bank_search').value = name;
            document.getElementById('bank_results').classList.add('hidden');
        }

        function openNewBankModal() {
            document.getElementById('new_bank_name').value = document.getElementById('bank_search').value;
            openModal('new-bank-modal');
        }

        // Cheque Customer Search
        function searchChequeCustomers(term) {
            if(term.length < 2) return document.getElementById('chq_cust_results').classList.add('hidden');
            fetch(`?action=search_cheque_customer&id=<?php echo $id; ?>&term=${term}`)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    data.forEach(c => {
                        html += `<div class="p-3 hover:bg-slate-50 cursor-pointer border-b border-slate-50" onclick="selectChqCust(${c.id}, '${c.name.replace(/'/g, "\\'")}')">
                            <p class="text-xs font-black text-slate-800 uppercase">${c.name}</p>
                        </div>`;
                    });
                    const res = document.getElementById('chq_cust_results');
                    res.innerHTML = html || `<div class="p-4 text-center"><p class="text-[10px] text-slate-400 font-bold mb-3 italic">Customer not in registry.</p> <a href="manage_customers.php" class="text-[9px] bg-slate-900 text-white px-3 py-1.5 rounded-lg font-black uppercase">Create New</a></div>`;
                    res.classList.remove('hidden');
                });
        }

        function selectChqCust(id, name) {
            document.getElementById('selected_chq_cust_id').value = id;
            document.getElementById('chq_cust_search').value = name;
            document.getElementById('chq_cust_results').classList.add('hidden');
        }

        // Submit Forms
        document.getElementById('expense-form').onsubmit = function(e) {
            e.preventDefault();
            fetch(`?action=add_expense&id=<?php echo $id; ?>`, { method: 'POST', body: new FormData(this) })
                .then(r => r.json()).then(res => { if(res.success) location.reload(); });
        };

        document.getElementById('new-bank-form').onsubmit = function(e) {
            e.preventDefault();
            fetch(`?action=create_bank&id=<?php echo $id; ?>`, { method: 'POST', body: new FormData(this) })
                .then(r => r.json()).then(res => { 
                    if(res.success) {
                        selectBank(res.id, res.name);
                        closeModal('new-bank-modal');
                    }
                });
        };

        document.getElementById('payment-form').onsubmit = function(e) {
            e.preventDefault();
            fetch(`?action=save_payment&id=<?php echo $id; ?>`, { method: 'POST', body: new FormData(this) })
                .then(r => r.json()).then(res => { if(res.success) location.reload(); else alert(res.message); });
        };

        function openExpenseModal() { openModal('expense-modal'); }
        function openPaymentModal(dcId, name) {
            document.getElementById('payment_dc_id').value = dcId;
            document.getElementById('modal-cust-label').innerText = "For: " + name;
            openModal('payment-modal');
        }

        function uploadBill(dcId) {
            const f = document.createElement('input');
            f.type = 'file';
            f.onchange = ev => {
                const fd = new FormData();
                fd.append('bill', ev.target.files[0]);
                fd.append('dc_id', dcId);
                fd.append('action', 'upload_bill');
                fetch('?id=<?php echo $id; ?>', { method: 'POST', body: fd }).then(r => r.json()).then(res => { if(res.success) alert('Digital Bill Encoded Successfully.'); });
            };
            f.click();
        }
    </script>
</body>
</html>
