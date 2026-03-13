<?php
require_once '../db.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: nwdelivery.php');
    exit;
}

// Fetch Delivery Details
$stmt = $pdo->prepare("
    SELECT d.*, u.full_name as creator_name 
    FROM deliveries d
    JOIN users u ON d.created_by = u.id
    WHERE d.id = ?
");
$stmt->execute([$id]);
$delivery = $stmt->fetch();

if (!$delivery) {
    die("Delivery not found.");
}

// Fetch Staff
$stmt = $pdo->prepare("
    SELECT u.full_name, u.contact_number
    FROM delivery_employees de
    JOIN users u ON de.user_id = u.id
    WHERE de.delivery_id = ?
");
$stmt->execute([$id]);
$staff = $stmt->fetchAll();

// Fetch Expenses
$stmt = $pdo->prepare("SELECT * FROM delivery_expenses WHERE delivery_id = ?");
$stmt->execute([$id]);
$expenses = $stmt->fetchAll();

// Fetch Field Expenses
$stmt = $pdo->prepare("
    SELECT dex.*, u.full_name as added_by_name
    FROM delivery_field_expenses dex
    JOIN users u ON dex.added_by = u.id
    WHERE dex.delivery_id = ?
");
$stmt->execute([$id]);
$field_expenses = $stmt->fetchAll();

// Fetch Customers and their Items
$stmt = $pdo->prepare("
    SELECT dc.*, c.name as customer_name, c.contact_number, c.address
    FROM delivery_customers dc
    JOIN customers c ON dc.customer_id = c.id
    WHERE dc.delivery_id = ?
");
$stmt->execute([$id]);
$delivery_customers = $stmt->fetchAll();

foreach ($delivery_customers as &$dc) {
    // Items
    $stmt = $pdo->prepare("
        SELECT di.*, b.name as brand_name, ci.id as stock_id, c.container_number
        FROM delivery_items di
        JOIN container_items ci ON di.container_item_id = ci.id
        JOIN brands b ON ci.brand_id = b.id
        JOIN containers c ON ci.container_id = c.id
        WHERE di.delivery_customer_id = ?
    ");
    $stmt->execute([$dc['id']]);
    $dc['items'] = $stmt->fetchAll();

    // Payments
    $stmt = $pdo->prepare("
        SELECT dp.*, b.name as bank_name, u.full_name as recorder_name
        FROM delivery_payments dp
        LEFT JOIN banks b ON dp.bank_id = b.id
        JOIN users u ON dp.recorded_by = u.id
        WHERE dp.delivery_customer_id = ?
        ORDER BY dp.payment_date DESC
    ");
    $stmt->execute([$dc['id']]);
    $dc['payments'] = $stmt->fetchAll();

    $dc['paid_amount'] = array_sum(array_column($dc['payments'], 'amount'));
}

// Fetch Banks for dropdown
$banks = $pdo->query("SELECT * FROM banks ORDER BY name")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Details #<?php echo $id; ?> - Antigravity Glass</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@100;300;400;500;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Outfit', sans-serif; background: #f8fafc; color: #1e293b; }
        .glass-card { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.8); }
        .input-glass { background: rgba(255, 255, 255, 0.5); border: 1px solid rgba(226, 232, 240, 0.8); border-radius: 12px; transition: all 0.3s ease; }
        .input-glass:focus { background: white; border-color: #6366f1; ring: 4px rgba(99, 102, 241, 0.1); outline: none; }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: transparent; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade { animation: fadeIn 0.4s ease forwards; }
    </style>
</head>
<body class="min-h-screen">
    <div class="flex">
        <!-- Sidebar -->
        <div class="w-72 min-h-screen p-6 bg-white border-r border-slate-100 hidden lg:block">
            <div class="flex items-center gap-3 mb-10 px-2">
                <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center text-white shadow-lg shadow-indigo-200">
                    <i class="fa-solid fa-truck-fast"></i>
                </div>
                <div>
                    <h1 class="font-black text-lg tracking-tight">ANTIGRAVITY</h1>
                    <p class="text-[10px] text-slate-400 font-bold tracking-[0.2em] uppercase">Logistics</p>
                </div>
            </div>
            
            <nav class="space-y-1">
                <a href="dashboard.php" class="flex items-center gap-3 px-4 py-3 text-slate-500 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl font-bold text-sm transition-all">
                    <i class="fa-solid fa-grid-2"></i> Dashboard
                </a>
                <a href="nwdelivery.php" class="flex items-center gap-3 px-4 py-3 text-indigo-600 bg-indigo-50 rounded-xl font-bold text-sm transition-all border border-indigo-100/50">
                    <i class="fa-solid fa-truck-ramp-box"></i> Deliveries
                </a>
                <a href="ledger.php" class="flex items-center gap-3 px-4 py-3 text-slate-500 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl font-bold text-sm transition-all">
                    <i class="fa-solid fa-book"></i> Ledger
                </a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="flex-1 max-h-screen overflow-y-auto custom-scroll p-4 md:p-8">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
                <div class="flex items-center gap-4">
                    <a href="nwdelivery.php" class="w-10 h-10 bg-white border border-slate-200 rounded-xl flex items-center justify-center text-slate-400 hover:text-indigo-600 hover:border-indigo-100 transition-all">
                        <i class="fa-solid fa-arrow-left"></i>
                    </a>
                    <div>
                        <h2 class="text-2xl font-black text-slate-800 tracking-tight">Manifest #<?php echo sprintf('%05d', $id); ?></h2>
                        <p class="text-sm text-slate-400 font-bold flex items-center gap-2">
                            <i class="fa-solid fa-calendar"></i> <?php echo date('F j, Y', strtotime($delivery['delivery_date'])); ?>
                            <span class="mx-2 opacity-20">|</span>
                            <span class="uppercase tracking-widest text-[10px] bg-slate-100 px-2 py-0.5 rounded"><?php echo $delivery['status']; ?></span>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button class="bg-indigo-600 text-white px-6 py-3 rounded-2xl font-bold text-sm shadow-xl shadow-indigo-200 hover:scale-105 active:scale-95 transition-all">
                        Complete Trip
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-12 gap-6">
                <!-- Left Column: Summary & Expenses -->
                <div class="col-span-12 lg:col-span-4 space-y-6">
                    <!-- Crew Card -->
                    <div class="glass-card rounded-[2rem] p-6 shadow-sm">
                        <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-users text-indigo-500"></i> Trip Crew
                        </h3>
                        <div class="space-y-3">
                            <?php foreach($staff as $s): ?>
                            <div class="flex items-center gap-3 p-3 bg-white/50 rounded-2xl border border-white/80">
                                <div class="w-10 h-10 bg-slate-900 text-white rounded-xl flex items-center justify-center text-xs font-black">
                                    <?php echo strtoupper(substr($s['full_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <p class="text-sm font-black text-slate-800 leading-none mb-1"><?php echo strtoupper($s['full_name']); ?></p>
                                    <p class="text-[10px] text-slate-400 font-bold"><?php echo $s['contact_number']; ?></p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Financial Summary -->
                    <div class="glass-card rounded-[2rem] p-6 shadow-sm overflow-hidden relative">
                        <div class="absolute -right-4 -top-4 w-24 h-24 bg-indigo-50 rounded-full opacity-50"></div>
                        <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center gap-2 relative z-10">
                            <i class="fa-solid fa-chart-pie text-indigo-500"></i> Trip Economics
                        </h3>
                        <div class="space-y-4 relative z-10">
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-bold text-slate-500">Total Sales</span>
                                <span class="text-lg font-black text-slate-900">LKR <?php echo number_format($delivery['total_sales'], 2); ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-bold text-slate-500">Base Expenses</span>
                                <span class="text-sm font-black text-rose-500">- LKR <?php echo number_format($delivery['total_expenses'], 2); ?></span>
                            </div>
                            <hr class="border-slate-100">
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-black text-slate-800 text-indigo-600">Net Projection</span>
                                <span class="text-xl font-black text-indigo-600">LKR <?php echo number_format($delivery['total_sales'] - $delivery['total_expenses'], 2); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Expenses List -->
                    <div class="glass-card rounded-[2rem] p-6 shadow-sm">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest flex items-center gap-2">
                                <i class="fa-solid fa-receipt text-indigo-500"></i> Disbursements
                            </h3>
                            <button onclick="openExpenseModal()" class="w-8 h-8 bg-indigo-50 text-indigo-600 rounded-lg flex items-center justify-center text-xs border border-indigo-100 hover:bg-indigo-600 hover:text-white transition-all">
                                <i class="fa-solid fa-plus"></i>
                            </button>
                        </div>
                        <div class="space-y-2">
                            <?php if(empty($expenses)): ?>
                                <p class="text-[10px] text-slate-400 font-bold uppercase py-2 text-center">No expenses recorded</p>
                            <?php endif; ?>
                            <?php foreach($expenses as $e): ?>
                            <div class="flex items-center justify-between p-3 bg-white/30 rounded-xl group">
                                <span class="text-xs font-bold text-slate-600"><?php echo $e['expense_name']; ?></span>
                                <div class="flex items-center gap-3">
                                    <span class="text-xs font-black text-slate-800">LKR <?php echo number_format($e['amount'], 2); ?></span>
                                    <button onclick="deleteExpense(<?php echo $e['id']; ?>)" class="text-slate-300 hover:text-rose-500 opacity-0 group-hover:opacity-100 transition-all"><i class="fa-solid fa-trash-can text-[10px]"></i></button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Customer Orders -->
                <div class="col-span-12 lg:col-span-8 space-y-6 pb-20">
                    <?php foreach($delivery_customers as $dc): ?>
                    <div class="glass-card rounded-[2rem] overflow-hidden shadow-sm animate-fade">
                        <!-- Customer Header -->
                        <div class="p-6 bg-white/40 border-b border-white/60 flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 bg-indigo-50 border-2 border-indigo-500/20 rounded-2xl flex items-center justify-center text-indigo-600">
                                    <i class="fa-solid fa-user-tag text-lg"></i>
                                </div>
                                <div>
                                    <h4 class="text-lg font-black text-slate-800 leading-tight"><?php echo strtoupper($dc['customer_name']); ?></h4>
                                    <div class="flex items-center gap-3 mt-1">
                                        <span class="text-[10px] font-black text-slate-400 flex items-center gap-1 uppercase tracking-widest"><i class="fa-solid fa-phone text-[8px]"></i> <?php echo $dc['contact_number']; ?></span>
                                        <span class="text-[10px] font-black text-slate-400 flex items-center gap-1 uppercase tracking-widest"><i class="fa-solid fa-location-dot text-[8px]"></i> <?php echo $dc['address']; ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Balance Due</div>
                                <div class="text-xl font-black text-rose-500">LKR <?php echo number_format($dc['subtotal'] - $dc['paid_amount'], 2); ?></div>
                            </div>
                        </div>

                        <!-- Order Items -->
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h5 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] flex items-center gap-2">
                                    <i class="fa-solid fa-box-open text-indigo-400"></i> Order Items
                                </h5>
                                <div class="flex items-center gap-2">
                                    <?php 
                                        $firstItem = $dc['items'][0] ?? null;
                                        if($firstItem && $firstItem['bill_image']): 
                                    ?>
                                        <a href="../uploads/bills/<?php echo $firstItem['bill_image']; ?>" target="_blank" class="text-[9px] font-black text-emerald-600 bg-emerald-50 px-3 py-1.5 rounded-lg border border-emerald-100 flex items-center gap-2 hover:bg-emerald-600 hover:text-white transition-all">
                                            <i class="fa-solid fa-image"></i> View Bill
                                        </a>
                                    <?php endif; ?>
                                    <button onclick="openBillUpload(<?php echo $dc['id']; ?>)" class="text-[9px] font-black bg-slate-900 text-white px-3 py-1.5 rounded-lg flex items-center gap-2 hover:bg-slate-800">
                                        <i class="fa-solid fa-file-invoice"></i> <?php echo ($firstItem && $firstItem['bill_image']) ? 'Update Bill' : 'Upload Bill'; ?>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="space-y-3">
                                <?php foreach($dc['items'] as $item): ?>
                                <div class="grid grid-cols-12 gap-4 items-center p-3 bg-white/40 border border-slate-100 rounded-2xl">
                                    <div class="col-span-5">
                                        <p class="text-xs font-black text-slate-800"><?php echo $item['brand_name']; ?></p>
                                        <p class="text-[9px] text-slate-400 font-bold uppercase tracking-widest mt-0.5"><?php echo $item['container_number']; ?></p>
                                    </div>
                                    <div class="col-span-2 text-center">
                                        <span class="text-[10px] font-black text-slate-500 uppercase block mb-0.5">Quantity</span>
                                        <span class="text-xs font-black text-slate-800"><?php echo $item['qty']; ?> PKTS</span>
                                    </div>
                                    <div class="col-span-2 text-center group relative">
                                        <span class="text-[10px] font-black text-slate-500 uppercase block mb-0.5">Damaged</span>
                                        <div class="flex items-center justify-center gap-2">
                                            <span class="text-xs font-black <?php echo $item['damaged_qty'] > 0 ? 'text-rose-500' : 'text-slate-300'; ?>"><?php echo $item['damaged_qty']; ?></span>
                                            <button onclick="editDamage(<?php echo $item['id']; ?>, <?php echo $item['qty']; ?>)" class="text-slate-300 hover:text-rose-500"><i class="fa-solid fa-circle-exclamation text-[10px]"></i></button>
                                        </div>
                                    </div>
                                    <div class="col-span-3 text-right">
                                        <span class="text-[10px] font-black text-slate-500 uppercase block mb-0.5">Total</span>
                                        <span class="text-sm font-black text-slate-800">LKR <?php echo number_format($item['total'], 2); ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Payments Section -->
                        <div class="p-6 bg-slate-50/50 border-t border-slate-100">
                            <div class="flex items-center justify-between mb-4">
                                <h5 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] flex items-center gap-2">
                                    <i class="fa-solid fa-credit-card text-indigo-400"></i> Payment History
                                </h5>
                                <button onclick="openPaymentModal(<?php echo $dc['id']; ?>, '<?php echo addslashes($dc['customer_name']); ?>')" class="text-[9px] font-black text-indigo-600 bg-indigo-50 px-3 py-1.5 rounded-lg border border-indigo-100 flex items-center gap-2 hover:bg-indigo-600 hover:text-white transition-all">
                                    <i class="fa-solid fa-plus-circle"></i> Add Payment
                                </button>
                            </div>

                            <div class="space-y-2">
                                <?php if(empty($dc['payments'])): ?>
                                    <div class="text-center py-4 text-[10px] text-slate-400 font-bold uppercase tracking-widest">No payments recorded yet</div>
                                <?php else: ?>
                                    <?php foreach($dc['payments'] as $p): ?>
                                    <div class="flex items-center justify-between p-3 bg-white border border-slate-100 rounded-xl shadow-sm">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-lg bg-green-50 text-green-600 flex items-center justify-center text-xs">
                                                <i class="fa-solid fa-check-circle"></i>
                                            </div>
                                            <div>
                                                <div class="flex items-center gap-3">
                                                    <span class="text-xs font-black text-slate-800"><?php echo $p['payment_type']; ?></span>
                                                    <span class="text-[9px] font-bold text-slate-400"><?php echo date('M j, Y', strtotime($p['payment_date'])); ?></span>
                                                </div>
                                                <p class="text-[9px] text-slate-400 font-medium">
                                                    <?php 
                                                        if($p['payment_type'] == 'Cheque') echo "{$p['bank_name']} - #{$p['cheque_number']}";
                                                        elseif($p['payment_type'] == 'Account Transfer') echo "Bank: {$p['bank_name']}";
                                                    ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <span class="text-sm font-black text-slate-800">LKR <?php echo number_format($p['amount'], 2); ?></span>
                                            <?php if($p['proof_image']): ?>
                                                <a href="../uploads/payments/<?php echo $p['proof_image']; ?>" target="_blank" class="block text-[8px] font-black text-indigo-500 uppercase">View Proof <i class="fa-solid fa-external-link text-[7px]"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <!-- Payment Modal -->
    <div id="payment-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="glass-card w-full max-w-md rounded-[2.5rem] shadow-2xl overflow-hidden animate-fade">
            <div class="p-6 border-b border-white/40 flex items-center justify-between bg-white/20">
                <h3 class="text-xl font-black text-slate-900 tracking-tight">Record Payment</h3>
                <button onclick="closePaymentModal()" class="text-slate-500 hover:text-slate-800"><i class="fa-solid fa-times text-xl"></i></button>
            </div>
            <form id="payment-form" class="p-6 space-y-4">
                <input type="hidden" name="delivery_customer_id" id="modal_dc_id">
                
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1.5 ml-1 block">Customer</label>
                    <input type="text" id="modal_cust_name" class="input-glass w-full h-[40px] px-4 text-sm font-bold bg-slate-100 border-none cursor-default" readonly>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[10px] uppercase font-black text-slate-400 mb-1.5 ml-1 block tracking-widest">Payment Type</label>
                        <select name="payment_type" id="payment_type" class="input-glass w-full h-[40px] px-4 text-xs font-bold" onchange="togglePaymentFields()">
                            <option value="Cash">Cash</option>
                            <option value="Card">Card</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Account Transfer">Bank Transfer</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase font-black text-slate-400 mb-1.5 ml-1 block tracking-widest">Amount</label>
                        <input type="number" name="amount" step="0.01" required placeholder="0.00" class="input-glass w-full h-[40px] px-4 text-sm font-black text-indigo-600">
                    </div>
                </div>

                <!-- Conditional Fields -->
                <div id="bank_field" class="hidden">
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1.5 ml-1 block tracking-widest">Select Bank</label>
                    <div class="flex gap-2">
                        <select name="bank_id" class="input-glass flex-1 h-[40px] px-4 text-xs font-bold">
                            <option value="">-- Choose Bank --</option>
                            <?php foreach($banks as $b): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo $b['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="openAddBank()" class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-xl border border-indigo-100 flex items-center justify-center">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>
                </div>

                <div id="cheque_field" class="hidden">
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1.5 ml-1 block tracking-widest">Cheque Number</label>
                    <input type="text" name="cheque_number" placeholder="CHQ-XXXXXX" class="input-glass w-full h-[40px] px-4 text-xs font-bold">
                </div>

                <div id="proof_field" class="hidden">
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1.5 ml-1 block tracking-widest">Proof of Payment (Optional)</label>
                    <input type="file" name="proof_image" class="block w-full text-[10px] text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-indigo-50 file:text-indigo-600 hover:file:bg-indigo-100 cursor-pointer">
                </div>

                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1.5 ml-1 block tracking-widest">Payment Date</label>
                    <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" class="input-glass w-full h-[40px] px-4 text-sm font-bold text-slate-700">
                </div>

                <button type="submit" class="w-full bg-slate-900 text-white py-4 rounded-[1.5rem] font-black text-xs uppercase tracking-widest shadow-xl shadow-slate-200 hover:bg-slate-800 transition-all">
                    Register Payment
                </button>
            </form>
        </div>
    </div>

    <!-- Edit Damage Modal -->
    <div id="damage-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="glass-card w-full max-w-sm rounded-[2.5rem] shadow-2xl overflow-hidden animate-fade">
            <div class="p-6 border-b border-white/40 flex items-center justify-between bg-rose-50/50">
                <h3 class="text-xl font-black text-rose-900 tracking-tight">Record Damage</h3>
                <button onclick="closeDamageModal()" class="text-rose-500 hover:text-rose-800"><i class="fa-solid fa-times text-xl"></i></button>
            </div>
            <form id="damage-form" class="p-6 space-y-4">
                <input type="hidden" name="delivery_item_id" id="modal_di_id">
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1.5 ml-1 block tracking-widest">Total Qty Sent</label>
                    <input type="text" id="modal_max_qty" readonly class="input-glass w-full h-[40px] px-4 text-sm font-bold bg-slate-100 border-none">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1.5 ml-1 block tracking-widest">Damaged Qty</label>
                    <input type="number" name="damaged_qty" id="modal_damaged_qty" required class="input-glass w-full h-[40px] px-4 text-lg font-black text-rose-600">
                </div>
                <button type="submit" class="w-full bg-rose-600 text-white py-4 rounded-[1.5rem] font-black text-xs uppercase tracking-widest shadow-xl shadow-rose-200 hover:bg-rose-700 transition-all">
                    Update Inventory
                </button>
            </form>
        </div>
    </div>

    <!-- Expense Modal -->
    <div id="expense-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="glass-card w-full max-w-sm rounded-[2.5rem] shadow-2xl overflow-hidden animate-fade">
            <div class="p-6 border-b border-white/40 flex items-center justify-between bg-white/20">
                <h3 class="text-xl font-black text-slate-900 tracking-tight">Add Expense</h3>
                <button onclick="closeExpenseModal()" class="text-slate-500 hover:text-slate-800"><i class="fa-solid fa-times text-xl"></i></button>
            </div>
            <form id="expense-form" class="p-6 space-y-4">
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1.5 ml-1 block tracking-widest">Purpose</label>
                    <input type="text" name="name" required placeholder="e.g. Fuel, Lunch" class="input-glass w-full h-[45px] px-4 text-sm font-bold">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1.5 ml-1 block tracking-widest">Amount</label>
                    <input type="number" name="amount" step="0.01" required placeholder="0.00" class="input-glass w-full h-[45px] px-4 text-lg font-black text-indigo-600">
                </div>
                <button type="submit" class="w-full bg-slate-900 text-white py-4 rounded-[1.5rem] font-black text-xs uppercase tracking-widest shadow-xl shadow-slate-200 hover:bg-slate-800 transition-all">
                    Record Expense
                </button>
            </form>
        </div>
    </div>

    <!-- Add Bank Modal -->
    <div id="bank-modal" class="fixed inset-0 bg-slate-900/80 backdrop-blur-md z-[60] hidden flex items-center justify-center p-4">
        <div class="glass-card w-full max-w-xs rounded-[2rem] shadow-2xl overflow-hidden">
            <div class="p-5 border-b border-white/40 flex items-center justify-between bg-white/20">
                <h3 class="text-lg font-black text-slate-900 tracking-tight">Add New Bank</h3>
                <button onclick="closeBankModal()" class="text-slate-500 hover:text-slate-800"><i class="fa-solid fa-times"></i></button>
            </div>
            <form id="bank-form" class="p-5 space-y-4">
                <input type="text" name="bank_name" placeholder="Bank Name (e.g. BOC, Sampath)" required class="input-glass w-full h-[40px] px-4 text-sm font-bold uppercase">
                <button type="submit" class="w-full bg-indigo-600 text-white py-3 rounded-xl font-bold text-xs uppercase tracking-widest">Save Bank</button>
            </form>
        </div>
    </div>

    <script>
        // Modal toggles
        const paymentModal = document.getElementById('payment-modal');
        const damageModal = document.getElementById('damage-modal');
        const bankModal = document.getElementById('bank-modal');

        function openPaymentModal(dcId, custName) {
            document.getElementById('modal_dc_id').value = dcId;
            document.getElementById('modal_cust_name').value = custName;
            paymentModal.classList.remove('hidden');
        }

        function closePaymentModal() {
            paymentModal.classList.add('hidden');
            document.getElementById('payment-form').reset();
            togglePaymentFields();
        }

        function togglePaymentFields() {
            const type = document.getElementById('payment_type').value;
            const bankField = document.getElementById('bank_field');
            const chequeField = document.getElementById('cheque_field');
            const proofField = document.getElementById('proof_field');

            bankField.classList.toggle('hidden', type !== 'Cheque' && type !== 'Account Transfer');
            chequeField.classList.toggle('hidden', type !== 'Cheque');
            proofField.classList.toggle('hidden', type !== 'Account Transfer');
        }

        function editDamage(diId, maxQty) {
            document.getElementById('modal_di_id').value = diId;
            document.getElementById('modal_max_qty').value = maxQty + " PKTS";
            damageModal.classList.remove('hidden');
        }

        function closeDamageModal() {
            damageModal.classList.add('hidden');
        }

        function openAddBank() {
            bankModal.classList.remove('hidden');
        }

        function closeBankModal() {
            bankModal.classList.add('hidden');
        }

        // Form Submissions
        document.getElementById('bank-form').onsubmit = function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch('del_details.php?action=add_bank', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if(res.success) {
                        const selects = document.querySelectorAll('select[name="bank_id"]');
                        selects.forEach(s => {
                            const opt = new Option(res.name, res.id);
                            s.add(opt);
                            s.value = res.id;
                        });
                        closeBankModal();
                        this.reset();
                    }
                });
        };

        document.getElementById('payment-form').onsubmit = function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch('del_details.php?action=add_payment', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if(res.success) location.reload();
                    else alert(res.message);
                });
        };

        document.getElementById('damage-form').onsubmit = function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch('del_details.php?action=update_damage', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if(res.success) location.reload();
                    else alert(res.message);
                });
        };

        function openBillUpload(dcId) {
            const input = document.createElement('input');
            input.type = 'file';
            input.onchange = e => {
                const file = e.target.files[0];
                const formData = new FormData();
                formData.append('bill_image', file);
                formData.append('dc_id', dcId);
                fetch('del_details.php?action=upload_bill', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(res => {
                        if(res.success) alert('Bill uploaded successfully!');
                    });
            };
            input.click();
        }
        function openExpenseModal() {
            document.getElementById('expense-modal').classList.remove('hidden');
        }

        function closeExpenseModal() {
            document.getElementById('expense-modal').classList.add('hidden');
        }

        document.getElementById('expense-form').onsubmit = function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch('del_details.php?action=add_expense&id=<?php echo $id; ?>', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if(res.success) location.reload();
                    else alert(res.message);
                });
        };

        function deleteExpense(expId) {
            if(confirm('Delete this expense?')) {
                fetch(`del_details.php?action=delete_expense&exp_id=${expId}`, { method: 'POST' })
                    .then(r => r.json())
                    .then(res => {
                        if(res.success) location.reload();
                    });
            }
        }
    </script>
</body>
</html>
<?php
// PHP Actions
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    header('Content-Type: application/json');

    if ($action == 'add_bank') {
        $name = $_POST['bank_name'];
        $stmt = $pdo->prepare("INSERT INTO banks (name) VALUES (?)");
        $stmt->execute([$name]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'name' => $name]);
        exit;
    }

    if ($action == 'add_payment') {
        $dc_id = $_POST['delivery_customer_id'];
        $amount = $_POST['amount'];
        $type = $_POST['payment_type'];
        $bank_id = $_POST['bank_id'] ?: null;
        $cheque = $_POST['cheque_number'] ?: null;
        $date = $_POST['payment_date'];
        
        $proof = null;
        if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] == 0) {
            $proof = time() . '_' . $_FILES['proof_image']['name'];
            move_uploaded_file($_FILES['proof_image']['tmp_name'], '../uploads/payments/' . $proof);
        }

        $stmt = $pdo->prepare("
            INSERT INTO delivery_payments 
            (delivery_customer_id, amount, payment_type, bank_id, cheque_number, proof_image, payment_date, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$dc_id, $amount, $type, $bank_id, $cheque, $proof, $date, $_SESSION['user_id']]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action == 'update_damage') {
        $di_id = $_POST['delivery_item_id'];
        $dmg = $_POST['damaged_qty'];

        // Get current damaged and container item id
        $stmt = $pdo->prepare("SELECT damaged_qty, container_item_id FROM delivery_items WHERE id = ?");
        $stmt->execute([$di_id]);
        $curr = $stmt->fetch();

        $diff = $dmg - $curr['damaged_qty'];

        // Update delivery item
        $stmt = $pdo->prepare("UPDATE delivery_items SET damaged_qty = ? WHERE id = ?");
        $stmt->execute([$dmg, $di_id]);

        // Update global stock damage (containers table or container_items?)
        // In this schema, we track damaged_qty in container_items maybe? 
        // Let's check container_items table structure. It has total_qty and sold_qty.
        // Usually, damaged items should be deducted from sold or recorded separately.
        // containers table has a total damaged_qty column.
        
        $stmt = $pdo->prepare("
            UPDATE containers c
            JOIN container_items ci ON ci.container_id = c.id
            SET c.damaged_qty = c.damaged_qty + ?
            WHERE ci.id = ?
        ");
        $stmt->execute([$diff, $curr['container_item_id']]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action == 'upload_bill') {
        $dc_id = $_POST['dc_id'];
        if (isset($_FILES['bill_image']) && $_FILES['bill_image']['error'] == 0) {
            $bill = time() . '_bill_' . $_FILES['bill_image']['name'];
            move_uploaded_file($_FILES['bill_image']['tmp_name'], '../uploads/bills/' . $bill);
            
            // We'll update the first item's bill image or we need a specific table?
            // User said "attach each bill image". Usually there's one bill per customer order.
            // Let's update all items for this customer in this delivery?
            $stmt = $pdo->prepare("UPDATE delivery_items SET bill_image = ? WHERE delivery_customer_id = ?");
            $stmt->execute([$bill, $dc_id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Upload failed.']);
        }
        exit;
    }

    if ($action == 'add_expense') {
        $del_id = $_GET['id'];
        $name = $_POST['name'];
        $amount = $_POST['amount'];
        $stmt = $pdo->prepare("INSERT INTO delivery_expenses (delivery_id, expense_name, amount) VALUES (?, ?, ?)");
        $stmt->execute([$del_id, $name, $amount]);
        
        // Update delivery total expenses
        $stmt = $pdo->prepare("UPDATE deliveries SET total_expenses = (SELECT COALESCE(SUM(amount), 0) FROM delivery_expenses WHERE delivery_id = ?) WHERE id = ?");
        $stmt->execute([$del_id, $del_id]);
        
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action == 'delete_expense') {
        $exp_id = $_GET['exp_id'];
        
        // Get delivery id first
        $stmt = $pdo->prepare("SELECT delivery_id FROM delivery_expenses WHERE id = ?");
        $stmt->execute([$exp_id]);
        $del_id = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("DELETE FROM delivery_expenses WHERE id = ?");
        $stmt->execute([$exp_id]);
        
        // Update delivery total expenses
        $stmt = $pdo->prepare("UPDATE deliveries SET total_expenses = (SELECT COALESCE(SUM(amount), 0) FROM delivery_expenses WHERE delivery_id = ?) WHERE id = ?");
        $stmt->execute([$del_id, $del_id]);
        
        echo json_encode(['success' => true]);
        exit;
    }
}
