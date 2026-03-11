<?php
require_once '../auth.php';
checkAuth();

$username = $_SESSION['username'];
$user_id  = $_SESSION['user_id'];
$delivery_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$delivery_id) { header('Location: dashboard.php'); exit; }

// Verify employee is assigned or user is admin
$stmt = $pdo->prepare("SELECT d.status, u.role FROM deliveries d CROSS JOIN users u WHERE d.id = ? AND u.id = ?");
$stmt->execute([$delivery_id, $user_id]);
$access_check = $stmt->fetch();

if (!$access_check) { die("Access denied."); }

// If not admin, check if explicitly assigned to this delivery
if ($access_check['role'] !== 'admin') {
    $assignStmt = $pdo->prepare("SELECT 1 FROM delivery_employees WHERE delivery_id = ? AND user_id = ?");
    $assignStmt->execute([$delivery_id, $user_id]);
    if (!$assignStmt->fetch()) { die("Access denied. You are not assigned to this delivery."); }
}

$action = $_POST['action'] ?? $_GET['action'] ?? null;

// ── AJAX: save_damages ──────────────────────────────────────────────────────
if ($action === 'save_damages') {
    $damages = json_decode($_POST['damages'] ?? '[]', true);
    foreach ($damages as $row) {
        $item_id = (int)$row['item_id'];
        $dqty    = max(0, (int)$row['damaged_qty']);
        // Upsert
        $chk = $pdo->prepare("SELECT id FROM delivery_item_damages WHERE delivery_item_id = ?");
        $chk->execute([$item_id]);
        if ($existing = $chk->fetch()) {
            $pdo->prepare("UPDATE delivery_item_damages SET damaged_qty=?, recorded_by=?, recorded_at=NOW() WHERE id=?")
                ->execute([$dqty, $user_id, $existing['id']]);
        } else {
            $pdo->prepare("INSERT INTO delivery_item_damages (delivery_item_id, damaged_qty, recorded_by) VALUES (?,?,?)")
                ->execute([$item_id, $dqty, $user_id]);
        }
    }
    echo json_encode(['success' => true]); exit;
}

// ── AJAX: add_field_expense ─────────────────────────────────────────────────
if ($action === 'add_field_expense') {
    $name   = trim($_POST['name'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    if (!$name || $amount <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid input']); exit; }
    $pdo->prepare("INSERT INTO delivery_field_expenses (delivery_id, expense_name, amount, added_by) VALUES (?,?,?,?)")
        ->execute([$delivery_id, $name, $amount, $user_id]);
    echo json_encode(['success' => true]); exit;
}

// ── AJAX: upload_proof ──────────────────────────────────────────────────────
if ($action === 'upload_proof') {
    $dc_id = (int)($_POST['dc_id'] ?? 0);
    if (!$dc_id || !isset($_FILES['photo'])) { echo json_encode(['success' => false, 'message' => 'No file']); exit; }
    $file = $_FILES['photo'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'])) { echo json_encode(['success' => false, 'message' => 'Invalid type']); exit; }
    $filename = 'proof_' . $dc_id . '_' . time() . '.' . $ext;
    $dest = '../uploads/delivery_proofs/' . $filename;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        $pdo->prepare("INSERT INTO delivery_proof_photos (delivery_customer_id, photo_path, uploaded_by) VALUES (?,?,?)")
            ->execute([$dc_id, $filename, $user_id]);
        echo json_encode(['success' => true, 'path' => $filename]); exit;
    }
    echo json_encode(['success' => false, 'message' => 'Upload failed']); exit;
}

// ── AJAX: set_customer_delivered ───────────────────────────────────────────
if ($action === 'set_customer_delivered') {
    $dc_id = (int)($_POST['dc_id'] ?? 0);
    if (!$dc_id) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }
    $pdo->prepare("UPDATE delivery_customers SET status = 'delivered' WHERE id = ?")->execute([$dc_id]);
    echo json_encode(['success' => true]); exit;
}

// ── Fetch Delivery Header ────────────────────────────────────────────────────
$del = $pdo->prepare("SELECT d.*, u.full_name as created_by_name FROM deliveries d JOIN users u ON d.created_by = u.id WHERE d.id = ?");
$del->execute([$delivery_id]);
$delivery = $del->fetch(PDO::FETCH_ASSOC);

// ── Given Expenses (admin-set) ───────────────────────────────────────────────
$exps = $pdo->prepare("SELECT expense_name, amount FROM delivery_expenses WHERE delivery_id = ? ORDER BY id");
$exps->execute([$delivery_id]);
$given_expenses = $exps->fetchAll(PDO::FETCH_ASSOC);
$given_total = array_sum(array_column($given_expenses, 'amount'));

// ── Field Expenses (employee-added) ─────────────────────────────────────────
$fexps = $pdo->prepare("SELECT fe.id, fe.expense_name, fe.amount, u.full_name as added_by_name FROM delivery_field_expenses fe JOIN users u ON fe.added_by = u.id WHERE fe.delivery_id = ? ORDER BY fe.added_at");
$fexps->execute([$delivery_id]);
$field_expenses = $fexps->fetchAll(PDO::FETCH_ASSOC);
$field_total = array_sum(array_column($field_expenses, 'amount'));

// ── Customers & Items ────────────────────────────────────────────────────────
$custs = $pdo->prepare("SELECT dc.id, dc.subtotal, dc.status, c.name, c.contact_number, c.address FROM delivery_customers dc JOIN customers c ON dc.customer_id = c.id WHERE dc.delivery_id = ? ORDER BY dc.id");
$custs->execute([$delivery_id]);
$customers = $custs->fetchAll(PDO::FETCH_ASSOC);

foreach ($customers as &$cr) {
    // Items with damage info
    $items = $pdo->prepare("
        SELECT di.id as item_id, di.qty, di.selling_price, di.total, di.cost_price,
               b.name as brand_name, con.container_number,
               COALESCE(dmg.damaged_qty, 0) as damaged_qty
        FROM delivery_items di
        JOIN container_items ci ON di.container_item_id = ci.id
        JOIN brands b ON ci.brand_id = b.id
        JOIN containers con ON ci.container_id = con.id
        LEFT JOIN delivery_item_damages dmg ON dmg.delivery_item_id = di.id
        WHERE di.delivery_customer_id = ?
    ");
    $items->execute([$cr['id']]);
    $cr['items'] = $items->fetchAll(PDO::FETCH_ASSOC);

    // Proof photos
    $photos = $pdo->prepare("SELECT photo_path FROM delivery_proof_photos WHERE delivery_customer_id = ? ORDER BY uploaded_at DESC");
    $photos->execute([$cr['id']]);
    $cr['photos'] = $photos->fetchAll(PDO::FETCH_COLUMN);
}
unset($cr);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery #DEL-<?php echo str_pad($delivery_id, 4, '0', STR_PAD_LEFT); ?> | Crystal POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;900&display=swap');
        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(rgba(15,23,42,0.9), rgba(15,23,42,0.9)), url('../assests/bg.webp') no-repeat center center fixed;
            background-size: cover; color: white; min-height: 100vh;
        }
        .glass-header { background: rgba(15,23,42,0.7); backdrop-filter: blur(14px); border-bottom: 1px solid rgba(255,255,255,0.08); }
        .glass-card { background: rgba(255,255,255,0.05); backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; }
        .input-sm { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; color: white; padding: 6px 10px; font-size: 12px; width: 100%; outline: none; }
        .input-sm:focus { border-color: rgba(139,92,246,0.5); }
        .dmg-input { background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.2); border-radius: 6px; color: #fca5a5; padding: 4px 8px; font-size: 12px; width: 60px; text-align: center; outline: none; }

        /* Print styles — only show the targeted customer invoice */
        @media print {
            body { background: white !important; color: black !important; margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            header, main, footer, .no-print { display: none !important; }
            .print-invoice-area { display: block !important; position: absolute; left: 0; top: 0; width: 100%; z-index: 9999; visibility: visible !important; }
            .print-invoice-area * { visibility: visible !important; }
            .print-invoice-wrapper { display: block !important; }
            @page { margin: 1cm; size: a4; }
        }
    </style>
</head>
<body class="min-h-screen flex flex-col">

<!-- Header -->
<header class="glass-header sticky top-0 z-50 py-4 mb-6 no-print">
    <div class="px-4 sm:px-7 flex items-center justify-between gap-3">
        <a href="<?php echo $access_check['role'] === 'admin' ? '../admin/nwdelivery.php' : 'dashboard.php'; ?>" class="text-slate-400 hover:text-white flex items-center gap-2 font-bold text-xs sm:text-sm uppercase tracking-widest shrink-0">
            <i class="fa-solid fa-arrow-left"></i> <span class="hidden sm:inline">Back</span>
        </a>

        <div class="hidden lg:block text-center shrink-0">
            <p class="text-[10px] uppercase tracking-[0.2em] text-slate-500">Delivery Reference</p>
            <p class="text-sm font-black text-white">#DEL-<?php echo str_pad($delivery_id, 4, '0', STR_PAD_LEFT); ?></p>
        </div>

        <!-- Live Sales & Expenses Tracking -->
        <div class="flex items-center gap-3 sm:gap-8 ml-auto mr-1 sm:mr-4">
            <div class="text-right">
                <p class="text-[8px] sm:text-[9px] uppercase tracking-tighter sm:tracking-widest text-slate-500 mb-0.5">Net Sales</p>
                <p class="text-[11px] sm:text-sm font-black text-emerald-400">Rs. <span id="header_sales_val"><?php 
                    $header_sales = 0;
                    foreach($customers as $c) {
                        foreach($c['items'] as $it) $header_sales += ($it['qty'] - $it['damaged_qty']) * $it['selling_price'];
                    }
                    echo number_format($header_sales, 2);
                ?></span></p>
            </div>
            <div class="h-7 w-[1px] bg-white/10 hidden sm:block"></div>
            <div class="text-right">
                <p class="text-[8px] sm:text-[9px] uppercase tracking-tighter sm:tracking-widest text-slate-500 mb-0.5">Exp Detail</p>
                <?php $exp_bal = $given_total - $field_total; ?>
                <div id="header_exp_wrap" class="flex flex-col items-end">
                    <p class="text-[11px] sm:text-sm font-black <?php echo $exp_bal >= 0 ? 'text-emerald-400' : 'text-rose-400'; ?> leading-tight" id="header_exp_val">
                        Rs. <?php echo number_format($field_total, 2); ?>
                    </p>
                    <p class="text-[7px] sm:text-[9px] font-bold <?php echo $exp_bal >= 0 ? 'text-slate-500' : 'text-rose-400'; ?>" id="header_exp_status">
                        <?php echo $exp_bal >= 0 ? 'Budget OK' : 'Over Rs.' . number_format(abs($exp_bal), 0); ?>
                    </p>
                </div>
            </div>

            <?php if ($access_check['role'] === 'admin'): ?>
            <div class="h-7 w-[1px] bg-white/10 hidden md:block"></div>
            <div class="text-right hidden md:block">
                <p class="text-[8px] sm:text-[9px] uppercase tracking-widest text-slate-500 mb-0.5">Delivery Profit</p>
                <p class="text-[11px] sm:text-sm font-black text-cyan-400">
                    Rs. <?php 
                        $total_profit = 0;
                        foreach($customers as $c) {
                            foreach($c['items'] as $it) {
                                $net = ($it['qty'] - $it['damaged_qty']);
                                $total_profit += ($net * ($it['selling_price'] - $it['cost_price']));
                            }
                        }
                        echo number_format($total_profit, 2);
                    ?>
                </p>
            </div>
            <?php endif; ?>
        </div>

        <span class="px-2 sm:px-3 py-1 rounded-xl text-[8px] sm:text-[10px] font-bold uppercase tracking-wider shrink-0
            <?php echo $delivery['status'] === 'completed' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-amber-500/20 text-amber-400'; ?>">
            <?php echo ucfirst($delivery['status']); ?>
        </span>
    </div>
</header>

<main class="px-4 sm:px-7 pb-12 w-full space-y-6 flex-1">

    <!-- Summary Strip -->
    <div class="glass-card p-5 grid grid-cols-2 sm:grid-cols-4 gap-4 no-print">
        <div>
            <p class="text-[9px] uppercase text-slate-500 tracking-widest mb-1">Date</p>
            <p class="text-sm font-bold"><?php echo date('M d, Y', strtotime($delivery['delivery_date'])); ?></p>
        </div>
        <div>
            <p class="text-[9px] uppercase text-slate-500 tracking-widest mb-1">Created By</p>
            <p class="text-sm font-bold"><?php echo htmlspecialchars($delivery['created_by_name']); ?></p>
        </div>
        <div>
            <p class="text-[9px] uppercase text-slate-500 tracking-widest mb-1">Expected Sales</p>
            <p class="text-sm font-black text-emerald-400">Rs. <?php echo number_format($delivery['total_sales'], 2); ?></p>
        </div>
        <div>
            <p class="text-[9px] uppercase text-slate-500 tracking-widest mb-1">Customers</p>
            <p class="text-sm font-black text-cyan-400"><?php echo count($customers); ?></p>
        </div>
    </div>

    <!-- Customer Cards -->
    <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.3em] pl-1 no-print">Customer Invoices</h3>

    <?php foreach ($customers as $cidx => $c): 
        $is_delivered = ($c['status'] === 'delivered');
    ?>
    <div class="glass-card overflow-hidden relative group" id="cust_card_<?php echo $c['id']; ?>">
        
        <!-- Delivered Overlay -->
        <div class="delivered-overlay absolute inset-0 bg-emerald-500/10 pointer-events-none transition-all duration-500 <?php echo $is_delivered ? 'opacity-100' : 'opacity-0'; ?>" id="overlay_<?php echo $c['id']; ?>">
            <div class="absolute top-4 right-4 bg-emerald-500 text-white px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest shadow-lg shadow-emerald-500/20">
                <i class="fa-solid fa-check-double mr-1"></i> Delivered
            </div>
        </div>

        <!-- Customer Card Header -->
        <div class="p-5 border-b border-white/5 bg-white/[0.02] flex flex-wrap items-center justify-between gap-3 no-print">
            <div>
                <h4 class="text-base font-bold text-white"><?php echo htmlspecialchars($c['name']); ?></h4>
                <div class="flex flex-wrap gap-x-4 mt-1">
                    <span class="text-[11px] text-slate-400"><i class="fa-solid fa-phone text-cyan-500 mr-1"></i><?php echo htmlspecialchars($c['contact_number']); ?></span>
                    <span class="text-[11px] text-slate-400"><i class="fa-solid fa-location-dot text-rose-500 mr-1"></i><?php echo htmlspecialchars($c['address']); ?></span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <!-- Print Invoice -->
                <button onclick="printInvoice(<?php echo $c['id']; ?>)"
                    class="flex items-center gap-1.5 px-3 py-1.5 bg-slate-700/60 hover:bg-slate-600 text-slate-300 hover:text-white rounded-lg text-[11px] font-bold transition-all"
                    title="Print Customer Invoice">
                    <i class="fa-solid fa-print"></i> Print Invoice
                </button>
                <!-- Photo Upload -->
                <label class="flex items-center gap-1.5 px-3 py-1.5 bg-purple-500/20 hover:bg-purple-500/30 text-purple-400 hover:text-white rounded-lg text-[11px] font-bold cursor-pointer transition-all">
                    <i class="fa-solid fa-camera"></i> Add Bill Photo
                    <input type="file" accept="image/*" class="hidden" onchange="uploadProof(this, <?php echo $c['id']; ?>)">
                </label>
                <!-- Mark Delivered -->
                <?php if (!$is_delivered): ?>
                <button onclick="markDelivered(<?php echo $c['id']; ?>, this)"
                    class="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-400 hover:text-white rounded-lg text-[11px] font-bold transition-all"
                    id="del_btn_<?php echo $c['id']; ?>">
                    <i class="fa-solid fa-truck-ramp-box"></i> Mark Delivered
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Proof Photos Strip -->
        <?php if (!empty($c['photos'])): ?>
        <div class="flex gap-2 px-5 py-3 border-b border-white/5 flex-wrap no-print">
            <?php foreach ($c['photos'] as $photo): ?>
            <img src="../uploads/delivery_proofs/<?php echo htmlspecialchars($photo); ?>"
                 class="w-16 h-16 object-cover rounded-xl border border-white/10 cursor-pointer hover:scale-105 transition-transform"
                 onclick="window.open(this.src)">
            <?php endforeach; ?>
            <div id="newphoto_<?php echo $c['id']; ?>"></div>
        </div>
        <?php else: ?>
        <div id="newphoto_<?php echo $c['id']; ?>" class="flex gap-2 px-5 py-2 no-print"></div>
        <?php endif; ?>

        <!-- Items Table -->
        <!-- Items Section -->
        <div>
            <!-- Desktop Table View -->
            <div class="hidden lg:block overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="bg-white/[0.03] text-[9px] uppercase tracking-widest text-slate-500 border-b border-white/5">
                            <th class="px-4 py-2.5 text-left">Brand</th>
                            <th class="px-4 py-2.5 text-left">Container</th>
                            <th class="px-4 py-2.5 text-center">Assigned</th>
                            <th class="px-4 py-2.5 text-center text-rose-400">Damaged</th>
                            <th class="px-4 py-2.5 text-center text-emerald-400">Net Qty</th>
                            <?php if ($access_check['role'] === 'admin'): ?>
                            <th class="px-4 py-2.5 text-right">Cost</th>
                            <th class="px-4 py-2.5 text-right">Profit</th>
                            <?php endif; ?>
                            <th class="px-4 py-2.5 text-right">Sell Price</th>
                            <th class="px-4 py-2.5 text-right">Net Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.03]">
                        <?php foreach ($c['items'] as $it):
                            $net_qty   = $it['qty'] - $it['damaged_qty'];
                            $net_total = $net_qty * $it['selling_price'];
                        ?>
                        <tr class="hover:bg-white/[0.02]" data-item-context="<?php echo $it['item_id']; ?>">
                            <td class="px-4 py-3 font-medium text-white">
                                <?php echo htmlspecialchars($it['brand_name']); ?>
                            </td>
                            <td class="px-4 py-3 text-slate-500 text-[10px] font-bold">
                                <?php echo htmlspecialchars($it['container_number']); ?>
                            </td>
                            <td class="px-4 py-3 text-center font-bold text-slate-300"><?php echo $it['qty']; ?></td>
                            <td class="px-4 py-3 text-center">
                                <input type="number" min="0" max="<?php echo $it['qty']; ?>"
                                       value="<?php echo $it['damaged_qty']; ?>"
                                       class="dmg-input no-print"
                                       data-item-id="<?php echo $it['item_id']; ?>"
                                       data-sell="<?php echo $it['selling_price']; ?>"
                                       data-assigned="<?php echo $it['qty']; ?>"
                                       oninput="recalcRow(this)">
                            </td>
                            <td class="px-4 py-3 text-center font-bold text-emerald-400 net-qty"><?php echo $net_qty; ?></td>
                            
                            <?php if ($access_check['role'] === 'admin'): 
                                $profit_val = ($it['selling_price'] - $it['cost_price']);
                                $profit_pct = ($it['cost_price'] > 0) ? ($profit_val / $it['cost_price']) * 100 : 0;
                            ?>
                            <td class="px-4 py-3 text-right text-slate-500 font-medium">Rs. <?php echo number_format($it['cost_price'], 2); ?></td>
                            <td class="px-4 py-3 text-right font-bold <?php echo $profit_pct >= 0 ? 'text-emerald-500/80' : 'text-rose-500/80'; ?>">
                                <?php echo number_format($profit_pct, 1); ?>%
                            </td>
                            <?php endif; ?>

                            <td class="px-4 py-3 text-right text-slate-400">Rs. <?php echo number_format($it['selling_price'], 2); ?></td>
                            <td class="px-4 py-3 text-right font-black text-emerald-400 net-total">Rs. <?php echo number_format($net_total, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card View -->
            <div class="lg:hidden space-y-3 px-4 pb-4">
                <?php foreach ($c['items'] as $it):
                    $net_qty   = $it['qty'] - $it['damaged_qty'];
                    $net_total = $net_qty * $it['selling_price'];
                ?>
                <div class="glass-card p-4 border border-white/5 relative bg-white/[0.02]" data-item-context="<?php echo $it['item_id']; ?>">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <p class="text-[10px] uppercase tracking-widest text-slate-500 mb-1">Brand Name</p>
                            <p class="text-sm font-black text-white"><?php echo htmlspecialchars($it['brand_name']); ?></p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] uppercase tracking-widest text-slate-500 mb-1">Unit Price</p>
                            <p class="text-xs font-bold text-slate-400">Rs. <?php echo number_format($it['selling_price'], 2); ?></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div class="p-2 rounded-lg bg-white/5 border border-white/5 text-center">
                            <p class="text-[8px] uppercase tracking-tighter text-slate-500 mb-1">Assigned</p>
                            <p class="text-xs font-bold text-slate-300"><?php echo $it['qty']; ?></p>
                        </div>
                        <div class="p-2 rounded-lg bg-rose-500/5 border border-rose-500/10 text-center">
                            <p class="text-[8px] uppercase tracking-tighter text-rose-400 mb-1">Damaged</p>
                            <input type="number" min="0" max="<?php echo $it['qty']; ?>"
                                   value="<?php echo $it['damaged_qty']; ?>"
                                   class="dmg-input w-full bg-transparent text-rose-400 font-bold border-none p-0 text-center text-xs"
                                   data-item-id="<?php echo $it['item_id']; ?>"
                                   data-sell="<?php echo $it['selling_price']; ?>"
                                   data-assigned="<?php echo $it['qty']; ?>"
                                   oninput="recalcRow(this)">
                        </div>
                        <div class="p-2 rounded-lg bg-emerald-500/5 border border-emerald-500/10 text-center">
                            <p class="text-[8px] uppercase tracking-tighter text-emerald-400 mb-1">Net Qty</p>
                            <p class="text-xs font-bold text-emerald-400 net-qty"><?php echo $net_qty; ?></p>
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-t border-white/5 flex justify-between items-center">
                        <span class="text-[10px] uppercase tracking-widest text-slate-500">Item Subtotal</span>
                        <span class="text-sm font-black text-emerald-400 net-total">Rs. <?php echo number_format($net_total, 2); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Footer Totals -->
            <div class="border-t border-white/10 bg-white/[0.02] no-print">
                <div class="px-6 py-3 flex justify-between items-center bg-white/[0.01]">
                    <span class="text-[10px] uppercase font-black text-slate-500 tracking-widest">Customer Final Sales</span>
                    <div class="flex items-center gap-6">
                        <?php 
                            $cust_net = array_sum(array_map(fn($i) => ($i['qty'] - ($i['damaged_qty'] ?? 0)) * $i['selling_price'], $c['items']));
                            if ($access_check['role'] === 'admin'): 
                                $cust_cost = array_sum(array_map(fn($i) => ($i['qty'] - ($i['damaged_qty'] ?? 0)) * $i['cost_price'], $c['items']));
                                $cust_profit = $cust_net - $cust_cost;
                        ?>
                        <div class="text-right">
                            <p class="text-[8px] uppercase font-bold text-slate-600">Admin Profit</p>
                            <p class="text-xs font-bold text-emerald-500/80">+Rs. <?php echo number_format($cust_profit, 2); ?></p>
                        </div>
                        <?php endif; ?>
                        <span class="text-lg font-black text-emerald-400 cust-net-total">
                            Rs. <?php echo number_format($cust_net, 2); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Save Damages Button -->
        <div class="px-5 py-3 border-t border-white/5 flex justify-end no-print">
            <button onclick="saveDamages(<?php echo $c['id']; ?>, this)"
                class="flex items-center gap-2 px-4 py-2 bg-rose-500/20 hover:bg-rose-500/30 text-rose-400 hover:text-white rounded-lg text-[11px] font-bold transition-all">
                <i class="fa-solid fa-save"></i> Save Damages
            </button>
        </div>

    </div>
    <?php endforeach; ?>

    <!-- ── Expenses Section ────────────────────────────────────────────────── -->
    <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.3em] pl-1 no-print mt-8">Trip Expenses</h3>

    <div class="glass-card p-5 space-y-5 no-print">

        <!-- Given Expenses (read-only) -->
        <div>
            <p class="text-[9px] uppercase font-bold text-slate-600 tracking-widest mb-3">Admin Given Expenses</p>
            <?php if (!empty($given_expenses)): ?>
            <div class="space-y-2">
                <?php foreach ($given_expenses as $ge): ?>
                <div class="flex justify-between items-center px-3 py-2 bg-white/[0.03] rounded-lg border border-white/5">
                    <span class="text-xs text-slate-300"><?php echo htmlspecialchars($ge['expense_name']); ?></span>
                    <span class="text-xs font-bold text-amber-400">Rs. <?php echo number_format($ge['amount'], 2); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-xs text-slate-600 italic">No admin expenses set.</p>
            <?php endif; ?>
        </div>

        <!-- Field Expenses -->
        <div>
            <div class="flex items-center justify-between mb-3">
                <p class="text-[9px] uppercase font-bold text-slate-600 tracking-widest">Field Expenses (Your Additions)</p>
                <div class="flex flex-wrap gap-2 items-center">
                    <button onclick="addQuickExpense('Fuel')" class="text-[10px] px-2 py-1 rounded bg-white/5 hover:bg-white/10 text-slate-400 hover:text-cyan-400 border border-white/5 transition-all flex items-center gap-1.5 no-print">
                        <i class="fa-solid fa-gas-pump"></i> Fuel
                    </button>
                    <button onclick="addQuickExpense('Meals')" class="text-[10px] px-2 py-1 rounded bg-white/5 hover:bg-white/10 text-slate-400 hover:text-cyan-400 border border-white/5 transition-all flex items-center gap-1.5 no-print">
                        <i class="fa-solid fa-utensils"></i> Meals
                    </button>
                    <button onclick="addQuickExpense('Accommodation')" class="text-[10px] px-2 py-1 rounded bg-white/5 hover:bg-white/10 text-slate-400 hover:text-cyan-400 border border-white/5 transition-all flex items-center gap-1.5 no-print">
                        <i class="fa-solid fa-bed"></i> Accommodation
                    </button>
                    <div class="h-4 w-[1px] bg-white/10 mx-1 no-print"></div>
                    <button onclick="showAddExpenseForm()" class="text-[10px] font-bold text-cyan-400 hover:text-white flex items-center gap-1 transition-colors no-print">
                        <i class="fa-solid fa-plus"></i> Add Expense
                    </button>
                </div>
            </div>
            <div id="field_expense_list" class="space-y-2">
                <?php foreach ($field_expenses as $fe): ?>
                <div class="flex justify-between items-center px-3 py-2 bg-cyan-500/5 rounded-lg border border-cyan-500/10">
                    <span class="text-xs text-slate-300"><?php echo htmlspecialchars($fe['expense_name']); ?></span>
                    <div class="flex items-center gap-3">
                        <span class="text-[10px] text-slate-500">by <?php echo htmlspecialchars($fe['added_by_name']); ?></span>
                        <span class="text-xs font-bold text-cyan-400">Rs. <?php echo number_format($fe['amount'], 2); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <!-- Add Expense Form (hidden by default) -->
            <div id="add_expense_form" class="hidden mt-3 flex gap-2 items-end">
                <div class="flex-1">
                    <label class="text-[9px] uppercase text-slate-500 font-bold block mb-1">Expense Name</label>
                    <input type="text" id="new_exp_name" class="input-sm" placeholder="e.g. Fuel, Toll">
                </div>
                <div class="w-32">
                    <label class="text-[9px] uppercase text-slate-500 font-bold block mb-1">Amount (Rs.)</label>
                    <input type="number" id="new_exp_amount" class="input-sm" placeholder="0.00" step="0.01">
                </div>
                <button onclick="submitExpense()" class="px-4 py-[9px] bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-400 hover:text-white rounded-lg text-[11px] font-bold transition-all whitespace-nowrap">
                    <i class="fa-solid fa-check"></i> Save
                </button>
                <button onclick="document.getElementById('add_expense_form').classList.add('hidden')"
                    class="px-3 py-[9px] text-slate-500 hover:text-white text-[11px] font-bold">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>
        </div>

        <!-- Expense Summary -->
        <div class="border-t border-white/5 pt-4 grid grid-cols-3 gap-3">
            <div class="bg-white/[0.03] rounded-xl p-3 text-center border border-white/5">
                <p class="text-[9px] uppercase text-slate-500 tracking-widest mb-1">Given</p>
                <p class="text-sm font-black text-amber-400">Rs. <?php echo number_format($given_total, 2); ?></p>
            </div>
            <div class="bg-white/[0.03] rounded-xl p-3 text-center border border-white/5">
                <p class="text-[9px] uppercase text-slate-500 tracking-widest mb-1">Field</p>
                <p class="text-sm font-black text-cyan-400" id="field_total_disp">Rs. <?php echo number_format($field_total, 2); ?></p>
            </div>
            <div class="bg-white/[0.03] rounded-xl p-3 text-center border border-white/5">
                <p class="text-[9px] uppercase text-slate-500 tracking-widest mb-1">Balance</p>
                <?php $balance = $given_total - $field_total; ?>
                <p class="text-sm font-black <?php echo $balance >= 0 ? 'text-emerald-400' : 'text-rose-400'; ?>" id="balance_disp">
                    Rs. <?php echo number_format(abs($balance), 2); ?><?php echo $balance < 0 ? ' <span class="text-[9px] font-bold">(Over)</span>' : ''; ?>
                </p>
            </div>
        </div>
    </div>

</main>

<footer class="py-8 text-center opacity-20 no-print">
    <p class="text-[10px] uppercase tracking-widest font-black">&copy; 2024 Crystal POS</p>
</footer>

<!-- ── Print Only Area ─────────────────────────────────────────────────────── -->
<div id="print_container" class="print-invoice-area hidden">
    <?php foreach ($customers as $cidx => $c): ?>
    <div id="invoice_<?php echo $c['id']; ?>" class="print-invoice-wrapper hidden" style="background:white; color:black; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding:10px;">
        <div style="border-bottom:4px solid #000; padding-bottom:15px; margin-bottom:20px;">
            <table style="width:100%;">
                <tr>
                    <td>
                        <h1 style="margin:0; font-size:32px; font-weight:900; letter-spacing:-1px;">CRYSTAL GLASS & POS</h1>
                        <p style="margin:4px 0; font-size:12px; color:#333; font-weight:bold;">Delivery Center: No. 88, Glass Avenue, Colombo</p>
                        <p style="margin:0; font-size:12px; color:#333;">Hotline: 011-2003004 | Email: orders@crystalglass.lk</p>
                    </td>
                    <td style="text-align:right; vertical-align:top;">
                        <h2 style="margin:0; font-size:20px; font-weight:900; color:#000; border:2px solid #000; display:inline-block; padding:5px 15px; text-transform:uppercase;">Invoice</h2>
                        <p style="margin:10px 0 0; font-weight:900; font-size:14px;">NO: #INV-<?php echo str_pad($delivery_id, 4, '0', STR_PAD_LEFT); ?>-<?php echo $cidx+1; ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <table style="width:100%; margin-bottom:25px;">
            <tr>
                <td style="width:60%; vertical-align:top;">
                    <p style="margin:0; font-size:11px; text-transform:uppercase; color:#666; font-weight:bold;">Billed To:</p>
                    <p style="margin:5px 0; font-size:18px; font-weight:900; color:#000;"><?php echo htmlspecialchars($c['name']); ?></p>
                    <p style="margin:0; font-size:13px; color:#333; line-height:1.4; max-width:300px;"><?php echo htmlspecialchars($c['address']); ?></p>
                    <p style="margin:5px 0; font-size:13px; font-weight:bold;">Mob: <?php echo htmlspecialchars($c['contact_number']); ?></p>
                </td>
                <td style="width:40%; text-align:right; vertical-align:top;">
                    <div style="background:#f8f9fa; padding:10px; border-radius:5px;">
                        <table style="width:100%; font-size:12px;">
                            <tr><td style="color:#666;">Issued Date</td><td style="text-align:right; font-weight:bold;"><?php echo date('d-m-Y'); ?></td></tr>
                            <tr><td style="color:#666;">Issued Time</td><td style="text-align:right; font-weight:bold;"><?php echo date('h:i A'); ?></td></tr>
                            <tr><td colspan="2"><hr style="border:0; border-top:1px solid #ddd; margin:5px 0;"></td></tr>
                            <tr><td style="color:#666;">Delivery Date</td><td style="text-align:right; font-weight:bold;"><?php echo date('d-m-Y', strtotime($delivery['delivery_date'])); ?></td></tr>
                            <tr><td style="color:#666;">Ref No</td><td style="text-align:right; font-weight:bold;">DEL-<?php echo $delivery_id; ?></td></tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        <table style="width:100%; border-collapse:collapse; margin-bottom:30px;">
            <thead>
                <tr style="background:#000; color:#fff;">
                    <th style="padding:12px; text-align:left; font-size:12px; text-transform:uppercase;">Product / Brand Description</th>
                    <th style="padding:12px; text-align:center; font-size:12px; text-transform:uppercase; width:80px;">Qty</th>
                    <th style="padding:12px; text-align:right; font-size:12px; text-transform:uppercase; width:120px;">Price (Rs.)</th>
                    <th style="padding:12px; text-align:right; font-size:12px; text-transform:uppercase; width:120px;">Total (Rs.)</th>
                </tr>
            </thead>
            <tbody id="inv_rows_<?php echo $c['id']; ?>">
                <?php 
                $p_total = 0;
                foreach ($c['items'] as $it): 
                    $net = $it['qty'] - $it['damaged_qty'];
                    $p_total += ($net * $it['selling_price']);
                ?>
                <tr style="border-bottom:1px solid #eee; <?php echo $net <= 0 ? 'display:none;' : ''; ?>" data-inv-row data-item-id="<?php echo $it['item_id']; ?>" data-sell="<?php echo $it['selling_price']; ?>">
                    <td style="padding:12px; font-size:13px; font-weight:bold;"><?php echo htmlspecialchars($it['brand_name']); ?></td>
                    <td style="padding:12px; text-align:center; font-size:13px; font-weight:bold;" class="inv-qty"><?php echo $net; ?></td>
                    <td style="padding:12px; text-align:right; font-size:13px;"><?php echo number_format($it['selling_price'], 2); ?></td>
                    <td style="padding:12px; text-align:right; font-size:13px; font-weight:bold;" class="inv-total"><?php echo number_format($net * $it['selling_price'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="padding:15px 10px 5px; text-align:right; font-weight:bold; font-size:13px; color:#555;">Sub Total</td>
                    <td style="padding:15px 12px 5px; text-align:right; font-size:14px; color:#555;" class="inv-subtotal-disp">Rs. <?php echo number_format($p_total, 2); ?></td>
                </tr>
                <tr>
                    <td colspan="3" style="padding:15px 10px; text-align:right; font-weight:900; font-size:16px; border-top:2px solid #000; text-transform:uppercase; vertical-align:middle;">Final Total</td>
                    <td style="padding:15px 12px; text-align:right; font-weight:900; font-size:20px; border-top:2px solid #000; background:#f8f9fa; white-space:nowrap; vertical-align:middle;" id="inv_total_<?php echo $c['id']; ?>">
                        Rs. <span class="inv-total-final"><?php echo number_format($p_total, 2); ?></span>
                    </td>
                </tr>
            </tfoot>
        </table>

        <div style="margin-top:60px; display:flex; justify-content:space-between;">
            <div style="width:200px; text-align:center;">
                <div style="height:40px; border-bottom:1px solid #000;"></div>
                <p style="margin:5px 0; font-size:11px; font-weight:bold; text-transform:uppercase;">Customer Signature</p>
            </div>
            <div style="width:200px; text-align:center;">
                <div style="height:40px; border-bottom:1px solid #000;"></div>
                <p style="margin:5px 0; font-size:11px; font-weight:bold; text-transform:uppercase;">Authorized By</p>
            </div>
        </div>

        <div style="margin-top:50px; text-align:center; color:#777; font-size:10px; border-top:1px dotted #ccc; padding-top:10px;">
            <p>This is a computer generated invoice. No signature required. Thank you for your business!</p>
            <p style="margin-top:5px; font-weight:bold;">Powered by Crystal POS | <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
const DELIVERY_ID = <?php echo $delivery_id; ?>;
const GIVEN_TOTAL = <?php echo $given_total; ?>;
let fieldTotal    = <?php echo $field_total; ?>;

// ── Recalculate row totals live ─────────────────────────────────────────────
function recalcRow(input) {
    const assigned = parseInt(input.dataset.assigned);
    const sell     = parseFloat(input.dataset.sell);
    let   dmg      = Math.min(parseInt(input.value || 0), assigned);
    if (dmg < 0) dmg = 0;
    input.value = dmg;
    const net = assigned - dmg;

    // Sync Item Contexts (Both Mobile Cards & Desktop Table Rows)
    const itemId = input.dataset.itemId;
    const itemContexts = document.querySelectorAll(`[data-item-context="${itemId}"]`);
    
    itemContexts.forEach(ctx => {
        // Sync other input if it exists
        const otherInput = ctx.querySelector('.dmg-input');
        if (otherInput && otherInput !== input) {
            otherInput.value = dmg;
        }
        
        ctx.querySelectorAll('.net-qty').forEach(el => el.textContent = net);
        ctx.querySelectorAll('.net-total').forEach(el => {
            el.textContent = 'Rs. ' + (net * sell).toLocaleString('en-US', {minimumFractionDigits:2});
        });
    });

    // Update card total
    const card = input.closest('[id^="cust_card_"]');
    let sum = 0;
    card.querySelectorAll('.net-total').forEach(td => {
        sum += parseFloat(td.textContent.replace('Rs. ', '').replace(/,/g,''));
    });
    card.querySelector('.cust-net-total').textContent = 'Rs. ' + sum.toLocaleString('en-US', {minimumFractionDigits:2});

    // Update Global Header Sales
    let globalSales = 0;
    document.querySelectorAll('.cust-net-total').forEach(el => {
        globalSales += parseFloat(el.textContent.replace('Rs. ', '').replace(/,/g,''));
    });
    document.getElementById('header_sales_val').textContent = globalSales.toLocaleString('en-US', {minimumFractionDigits:2});

    // Update print invoice qty/total for this item
    const cardId = card.id.replace('cust_card_', '');
    const invRow = document.querySelector(`#print_container #inv_rows_${cardId} [data-item-id="${input.dataset.itemId}"]`);
    if (invRow) {
        invRow.querySelector('.inv-qty').textContent = net;
        invRow.querySelector('.inv-total').textContent = (net * sell).toLocaleString('en-US', {minimumFractionDigits:2});
        // Toggle row visibility: if net is 0, hide it from invoice
        invRow.style.display = (net <= 0) ? 'none' : 'table-row';
    }
    // Update invoice grand total
    updateInvoiceTotal(cardId);
}

function updateInvoiceTotal(cardId) {
    let t = 0;
    document.querySelectorAll(`#print_container #inv_rows_${cardId} [data-inv-row]`).forEach(r => {
        const qty  = parseInt(r.querySelector('.inv-qty').textContent);
        const sell = parseFloat(r.dataset.sell);
        t += qty * sell;
    });
    const totalEl = document.getElementById(`inv_total_${cardId}`);
    if (totalEl) {
        const val = t.toLocaleString('en-US', {minimumFractionDigits:2});
        totalEl.querySelector('.inv-total-final').textContent = val;
        const sub = totalEl.closest('table').querySelector('.inv-subtotal-disp');
        if(sub) sub.textContent = 'Rs. ' + val;
    }
}

// ── Save Damages ───────────────────────────────────────────────────────────
function saveDamages(custCardId, btn) {
    const card    = document.getElementById('cust_card_' + custCardId);
    const inputs  = card.querySelectorAll('.dmg-input');
    const damages = [];
    inputs.forEach(inp => {
        damages.push({ item_id: inp.dataset.itemId, damaged_qty: inp.value || 0 });
    });
    btn.disabled = true;
    const oldHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i>Saving...';

    const fd = new FormData();
    fd.append('action', 'save_damages');
    fd.append('damages', JSON.stringify(damages));

    fetch('?id=' + DELIVERY_ID, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            btn.innerHTML = '<i class="fa-solid fa-check mr-2"></i>Saved!';
            btn.classList.replace('bg-rose-500/20', 'bg-emerald-500/20');
            btn.classList.replace('text-rose-400', 'text-emerald-400');
            setTimeout(() => {
                btn.innerHTML = oldHtml;
                btn.classList.replace('bg-emerald-500/20', 'bg-rose-500/20');
                btn.classList.replace('text-emerald-400', 'text-rose-400');
                btn.disabled = false;
            }, 2000);
        } else {
            alert('Error saving damages');
            btn.innerHTML = oldHtml;
            btn.disabled = false;
        }
    });
}

// ── Add Field Expense ──────────────────────────────────────────────────────
function showAddExpenseForm() {
    document.getElementById('add_expense_form').classList.remove('hidden');
    document.getElementById('new_exp_name').focus();
}

function addQuickExpense(name) {
    const form = document.getElementById('add_expense_form');
    form.classList.remove('hidden');
    document.getElementById('new_exp_name').value = name;
    document.getElementById('new_exp_amount').focus();
}

function submitExpense() {
    const name   = document.getElementById('new_exp_name').value.trim();
    const amount = parseFloat(document.getElementById('new_exp_amount').value);
    if (!name || isNaN(amount) || amount <= 0) {
        alert("Enter valid name and amount.");
        return;
    }
    submitExpenseData(name, amount);
    // Extra cleanup for form
    document.getElementById('new_exp_name').value   = '';
    document.getElementById('new_exp_amount').value = '';
    document.getElementById('add_expense_form').classList.add('hidden');
}

function submitExpenseData(name, amount) {
    const fd = new FormData();
    fd.append('action', 'add_field_expense');
    fd.append('name', name);
    fd.append('amount', amount);
    fetch('?id=' + DELIVERY_ID, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (!res.success) { alert(res.message); return; }
        // Add row to field expense list
        const list = document.getElementById('field_expense_list');
        const row = document.createElement('div');
        row.className = 'flex justify-between items-center px-3 py-2 bg-cyan-500/5 rounded-lg border border-cyan-500/10';
        row.innerHTML = `<span class="text-xs text-slate-300">${name}</span><div class="flex items-center gap-3"><span class="text-[10px] text-slate-500">by <?php echo htmlspecialchars($username); ?></span><span class="text-xs font-bold text-cyan-400">Rs. ${amount.toLocaleString('en-US',{minimumFractionDigits:2})}</span></div>`;
        list.appendChild(row);
        // Update totals
        fieldTotal += amount;
        document.getElementById('field_total_disp').textContent = 'Rs. ' + fieldTotal.toLocaleString('en-US',{minimumFractionDigits:2});
        const bal = GIVEN_TOTAL - fieldTotal;
        const bd  = document.getElementById('balance_disp');
        const hVal = document.getElementById('header_exp_val');
        const hStat = document.getElementById('header_exp_status');
        
        const fmt = n => n.toLocaleString('en-US',{minimumFractionDigits:2});
        
        bd.textContent = 'Rs. ' + fmt(Math.abs(bal)) + (bal < 0 ? ' (Over)' : '');
        bd.className   = 'text-sm font-black ' + (bal >= 0 ? 'text-emerald-400' : 'text-rose-400');
        
        hVal.textContent = 'Rs. ' + fmt(fieldTotal);
        hVal.className = 'text-xs sm:text-sm font-black ' + (bal >= 0 ? 'text-emerald-400' : 'text-rose-400');
        
        hStat.textContent = (bal >= 0 ? 'Budget OK' : 'Over Rs.' + fmt(Math.abs(bal)));
        hStat.className = 'text-[7px] sm:text-[9px] font-bold ' + (bal >= 0 ? 'text-slate-500' : 'text-rose-400');
    });
}

// ── Upload Proof Photo ─────────────────────────────────────────────────────
function uploadProof(input, dc_id) {
    if (!input.files || !input.files[0]) return;
    const fd = new FormData();
    fd.append('action',  'upload_proof');
    fd.append('dc_id',   dc_id);
    fd.append('photo',   input.files[0]);
    fetch('?id=' + DELIVERY_ID, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            const container = document.getElementById('newphoto_' + dc_id);
            const img = document.createElement('img');
            img.src = '../uploads/delivery_proofs/' + res.path;
            img.className = 'w-16 h-16 object-cover rounded-xl border border-white/10 cursor-pointer hover:scale-105 transition-transform';
            img.onclick = () => window.open(img.src);
            container.parentElement.insertBefore(img, container);
        } else {
            alert('Upload failed: ' + res.message);
        }
    });
}

function markDelivered(dcId, btn) {
    if (!confirm("Are you sure this customer signature is obtained and items delivered?")) return;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i>Processing...';
    
    const fd = new FormData();
    fd.append('action', 'set_customer_delivered');
    fd.append('dc_id', dcId);
    
    fetch('?id=' + DELIVERY_ID, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            btn.classList.add('hidden');
            document.getElementById('overlay_' + dcId).classList.remove('opacity-0');
            document.getElementById('overlay_' + dcId).classList.add('opacity-100');
            // Flash a success message
        } else {
            alert(res.message || 'Error occurred');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-truck-ramp-box"></i> Mark Delivered';
        }
    });
}

// ── Print Invoice ──────────────────────────────────────────────────────────
function printInvoice(custId) {
    const printArea = document.getElementById('print_container');
    const invoiceWrapper = document.getElementById('invoice_' + custId);
    
    if (!invoiceWrapper) {
        console.error("Invoice wrapper not found for ID:", custId);
        return;
    }

    // Hide all other invoices in the print area
    document.querySelectorAll('.print-invoice-wrapper').forEach(el => el.classList.add('hidden'));
    
    // Show the target one and the container
    printArea.classList.remove('hidden');
    invoiceWrapper.classList.remove('hidden');
    
    // Minimal delay to ensure browser handles display changes
    setTimeout(() => {
        window.print();
        // Hide after print dialog closes
        setTimeout(() => {
            printArea.classList.add('hidden');
            invoiceWrapper.classList.add('hidden');
        }, 500);
    }, 50);
}
</script>
</body>
</html>
