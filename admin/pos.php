<?php
require_once '../auth.php';
require_once '../config.php';
checkAuth();
if (!isAdmin()) { header('Location: ../sale/dashboard.php'); exit; }
$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? null;

// â”€â”€ AJAX: search customer â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'search_customer') {
    $term = '%' . ($_GET['term'] ?? '') . '%';
    $s = $pdo->prepare("SELECT id, name, contact_number, address FROM customers WHERE name LIKE ? OR contact_number LIKE ? LIMIT 6");
    $s->execute([$term, $term]);
    echo json_encode($s->fetchAll(PDO::FETCH_ASSOC)); exit;
}

// â”€â”€ AJAX: create customer â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'create_customer') {
    $s = $pdo->prepare("INSERT INTO customers (name, contact_number, address) VALUES (?, ?, ?)");
    $s->execute([$_POST['name'], $_POST['contact'], $_POST['address'] ?? '']);
    echo json_encode(['success'=>true,'id'=>$pdo->lastInsertId(),'name'=>$_POST['name'],'contact'=>$_POST['contact']]); exit;
}

// â”€â”€ AJAX: search brand stock â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'search_brand_stock') {
    $term = $_GET['term'] ?? '';
    $termParam = '%' . $term . '%';
    
    // Search from Container Items (Glass)
    $query1 = "
        SELECT b.id as brand_id, b.name as brand_name, 
               ci.id as item_id, ci.pallets, ci.qty_per_pallet, (ci.total_qty - ci.sold_qty) as available_qty,
               c.container_number, c.country, c.arrival_date, c.per_item_cost as cost_price,
               'container' as item_source
        FROM container_items ci
        JOIN brands b ON ci.brand_id = b.id
        JOIN containers c ON ci.container_id = c.id
        WHERE (ci.total_qty - ci.sold_qty) > 0
    ";
    
    if (!empty($term)) {
        $query1 .= " AND b.name LIKE ?";
    }

    // Search from Other Purchases
    $query2 = "
        SELECT 0 as brand_id, opi.item_name as brand_name,
               opi.id as item_id, 0 as pallets, 0 as qty_per_pallet, (opi.qty - opi.sold_qty) as available_qty,
               op.purchase_number as container_number, 'Direct' as country, op.purchase_date as arrival_date, opi.price_per_item as cost_price,
               'other' as item_source
        FROM other_purchase_items opi
        JOIN other_purchases op ON opi.purchase_id = op.id
        WHERE (opi.qty - opi.sold_qty) > 0
    ";
    
    if (!empty($term)) {
        $query2 .= " AND opi.item_name LIKE ?";
    }

    $fullQuery = "($query1) UNION ALL ($query2) ORDER BY available_qty DESC LIMIT 10";
    $stmt = $pdo->prepare($fullQuery);
    
    $params = [];
    if (!empty($term)) {
        $params[] = $termParam;
        $params[] = $termParam;
    }
    
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// â”€â”€ AJAX: search bank â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'search_bank') {
    $t = '%'.($_GET['term']??'').'%';
    $s = $pdo->prepare("SELECT * FROM banks WHERE name LIKE ? LIMIT 5");
    $s->execute([$t]);
    echo json_encode($s->fetchAll(PDO::FETCH_ASSOC)); exit;
}

// â”€â”€ AJAX: create bank â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'create_bank') {
    $s = $pdo->prepare("INSERT INTO banks (name, account_number, account_name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE account_number=?, account_name=?");
    $s->execute([$_POST['name'], $_POST['acc_no'], $_POST['acc_name'], $_POST['acc_no'], $_POST['acc_name']]);
    $id = $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM banks WHERE name='".$_POST['name']."'")->fetchColumn();
    echo json_encode(['success'=>true,'id'=>$id,'name'=>$_POST['name']]); exit;
}

// â”€â”€ AJAX: save POS sale â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'save_pos_sale') {
    try {
        $pdo->beginTransaction();
        $editing_id = !empty($_POST['editing_id']) ? (int)$_POST['editing_id'] : null;
        $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $sale_date   = $_POST['sale_date'];
        $items       = json_decode($_POST['items'], true) ?? [];
        $bill_disc   = (float)($_POST['bill_discount'] ?? 0);
        $bill_disc_t = $_POST['bill_discount_type'] ?? 'fixed';
        $pay_method  = $_POST['payment_method'] ?? 'Later Payment';

        // Items can be empty for auto-saving drafts
        // if (empty($items)) throw new Exception("At least one item is required.");

        if ($editing_id) {
            // Revert old stock
            $old = $pdo->prepare("SELECT item_id, item_source, qty FROM pos_sale_items WHERE sale_id=?");
            $old->execute([$editing_id]);
            foreach ($old->fetchAll() as $oi) {
                if ($oi['item_source'] === 'container') {
                    $pdo->prepare("UPDATE container_items SET sold_qty=GREATEST(0,sold_qty-?) WHERE id=?")->execute([$oi['qty'],$oi['item_id']]);
                } else {
                    $pdo->prepare("UPDATE other_purchase_items SET sold_qty=GREATEST(0,sold_qty-?) WHERE id=?")->execute([$oi['qty'],$oi['item_id']]);
                }
            }
            $pdo->prepare("DELETE FROM pos_sale_items WHERE sale_id=?")->execute([$editing_id]);
            $sale_id = $editing_id;
        } else {
            $bill_id = 'POS-'.date('Ymd').'-'.str_pad(rand(1,9999),4,'0',STR_PAD_LEFT);
            $ins = $pdo->prepare("INSERT INTO pos_sales (bill_id, sale_date, customer_id, created_by, payment_method, payment_status) VALUES (?,?,?,?,?,?)");
            $ins->execute([$bill_id, $sale_date, $customer_id, $user_id, $pay_method, ($pay_method==='Later Payment'?'pending':'pending')]);
            $sale_id = $pdo->lastInsertId();
        }

        $subtotal = 0; $item_disc = 0;
        foreach ($items as $it) {
            $qty  = (int)$it['qty'];
            $dmg  = (int)($it['damaged_qty'] ?? 0);
            $sp   = (float)$it['selling_price'];
            $isc  = (float)($it['item_discount'] ?? 0);
            $cp   = (float)$it['cost_price'];
            $lt   = (($qty - $dmg) * $sp) - $isc;
            $subtotal  += $lt;
            $item_disc += $isc;

            $source = $it['item_source'] ?? 'container';
            if ($source === 'container') {
                $stk = $pdo->prepare("UPDATE container_items SET sold_qty=sold_qty+? WHERE id=? AND (total_qty-sold_qty)>=?");
            } else {
                $stk = $pdo->prepare("UPDATE other_purchase_items SET sold_qty=sold_qty+? WHERE id=? AND (qty-sold_qty)>=?");
            }
            $stk->execute([$qty, $it['item_id'], $qty]);
            if ($stk->rowCount() === 0) throw new Exception("Insufficient stock for ".htmlspecialchars($it['brand_name']??'item') . " ($source)");

            $pdo->prepare("INSERT INTO pos_sale_items (sale_id, item_id, item_source, qty, damaged_qty, cost_price, selling_price, item_discount, line_total) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$sale_id, $it['item_id'], $source, $qty, $dmg, $cp, $sp, $isc, $lt]);
        }

        $bill_disc_val = ($bill_disc_t === 'percent') ? ($subtotal * $bill_disc / 100) : $bill_disc;
        $grand_total   = max(0, $subtotal - $bill_disc_val);

        if ($editing_id) {
            $pdo->prepare("UPDATE pos_sales SET sale_date=?, customer_id=?, subtotal=?, item_discount=?, bill_discount=?, bill_discount_type=?, grand_total=?, payment_method=?, updated_at=NOW() WHERE id=?")
                ->execute([$sale_date, $customer_id, $subtotal, $item_disc, $bill_disc_val, $bill_disc_t, $grand_total, $pay_method, $sale_id]);
        } else {
            $pdo->prepare("UPDATE pos_sales SET subtotal=?, item_discount=?, bill_discount=?, bill_discount_type=?, grand_total=? WHERE id=?")
                ->execute([$subtotal, $item_disc, $bill_disc_val, $bill_disc_t, $grand_total, $sale_id]);
        }

        $action_type = $editing_id ? 'EDITED' : 'CREATED';
        $note = ($editing_id ? 'Sale updated' : 'New sale created') . " â€” Bill Total: LKR ".number_format($grand_total,2);
        $pdo->prepare("INSERT INTO pos_sale_audits (sale_id, action_type, notes, changed_by) VALUES (?,?,?,?)")
            ->execute([$sale_id, $action_type, $note, $user_id]);

        $bill_id_out = $pdo->query("SELECT bill_id FROM pos_sales WHERE id=$sale_id")->fetchColumn();
        $pdo->commit();
        echo json_encode(['success'=>true,'sale_id'=>$sale_id,'bill_id'=>$bill_id_out,'grand_total'=>$grand_total]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// â”€â”€ AJAX: save payment â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'save_pos_payment') {
    try {
        $sale_id   = (int)$_POST['sale_id'];
        $type      = $_POST['type'];
        $amount    = (float)$_POST['amount'];
        $date      = $_POST['date'];
        $bank_id   = !empty($_POST['bank_id']) ? $_POST['bank_id'] : null;
        $chq_no    = $_POST['chq_no'] ?? null;
        $chq_payer = $_POST['chq_payer'] ?? null;
        $proof     = null;
        if (isset($_FILES['proof']) && $_FILES['proof']['error']==0) {
            $proof = time().'_'.$_FILES['proof']['name'];
            if (!is_dir('../uploads/payments')) mkdir('../uploads/payments',0777,true);
            move_uploaded_file($_FILES['proof']['tmp_name'], '../uploads/payments/'.$proof);
        }
        $pdo->prepare("INSERT INTO pos_sale_payments (sale_id, amount, payment_type, bank_id, cheque_number, cheque_payer_name, proof_image, payment_date, recorded_by) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$sale_id, $amount, $type, $bank_id, $chq_no, $chq_payer, $proof, $date, $user_id]);

        // check if fully paid
        $row = $pdo->query("SELECT grand_total, (SELECT SUM(amount) FROM pos_sale_payments WHERE sale_id=$sale_id) as paid FROM pos_sales WHERE id=$sale_id")->fetch();
        $status = ($row && $row['paid'] >= $row['grand_total']) ? 'completed' : 'pending';
        // determine method
        $pmts = $pdo->query("SELECT DISTINCT payment_type FROM pos_sale_payments WHERE sale_id=$sale_id")->fetchAll(PDO::FETCH_COLUMN);
        $method = count($pmts)>1 ? 'Multiple' : ($pmts[0] ?? $type);
        $pdo->prepare("UPDATE pos_sales SET payment_status=?, payment_method=? WHERE id=?")->execute([$status, $method, $sale_id]);
        $pdo->prepare("INSERT INTO pos_sale_audits (sale_id, action_type, notes, changed_by) VALUES (?,?,?,?)")
            ->execute([$sale_id, 'PAYMENT_ADDED', "Payment of LKR ".number_format($amount,2)." via $type added.", $user_id]);
        echo json_encode(['success'=>true,'payment_status'=>$status]);
    } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// â”€â”€ AJAX: get sale (for edit/print) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'get_pos_sale') {
    $id = (int)($_GET['id'] ?? 0);
    $sale = $pdo->query("SELECT ps.*, c.name as customer_name, c.contact_number, u.full_name as created_by_name FROM pos_sales ps LEFT JOIN customers c ON ps.customer_id=c.id JOIN users u ON ps.created_by=u.id WHERE ps.id=$id")->fetch(PDO::FETCH_ASSOC);
    if (!$sale) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
    $sale['items'] = $pdo->query("
        SELECT psi.*, 
        CASE WHEN psi.item_source = 'container' THEN b.name ELSE opi.item_name END as brand_name,
        CASE WHEN psi.item_source = 'container' THEN c.country ELSE 'Direct' END as country,
        CASE WHEN psi.item_source = 'container' THEN c.container_number ELSE op.purchase_number END as container_number,
        CASE WHEN psi.item_source = 'container' THEN ci.pallets ELSE 0 END as pallets,
        CASE WHEN psi.item_source = 'container' THEN ci.qty_per_pallet ELSE 0 END as qty_per_pallet,
        CASE WHEN psi.item_source = 'container' THEN (ci.total_qty-ci.sold_qty) ELSE (opi.qty-opi.sold_qty) END as available_qty 
        FROM pos_sale_items psi 
        LEFT JOIN container_items ci ON psi.item_id=ci.id AND psi.item_source='container'
        LEFT JOIN brands b ON ci.brand_id=b.id 
        LEFT JOIN containers c ON ci.container_id=c.id 
        LEFT JOIN other_purchase_items opi ON psi.item_id=opi.id AND psi.item_source='other'
        LEFT JOIN other_purchases op ON opi.purchase_id=op.id
        WHERE psi.sale_id=$id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $sale['payments'] = $pdo->query("SELECT psp.*, b.name as bank_name FROM pos_sale_payments psp LEFT JOIN banks b ON psp.bank_id=b.id WHERE psp.sale_id=$id ORDER BY psp.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $sale['total_paid'] = array_sum(array_column($sale['payments'], 'amount'));
    echo json_encode(['success'=>true,'data'=>$sale]); exit;
}

// â”€â”€ AJAX: delete sale â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'delete_pos_sale') {
    try {
        $pdo->beginTransaction();
        $id = (int)$_POST['id'];
        $old = $pdo->prepare("SELECT item_id, item_source, qty FROM pos_sale_items WHERE sale_id=?");
        $old->execute([$id]);
        foreach ($old->fetchAll() as $oi) {
            if ($oi['item_source'] === 'container') {
                $pdo->prepare("UPDATE container_items SET sold_qty=GREATEST(0,sold_qty-?) WHERE id=?")->execute([$oi['qty'],$oi['item_id']]);
            } else {
                $pdo->prepare("UPDATE other_purchase_items SET sold_qty=GREATEST(0,sold_qty-?) WHERE id=?")->execute([$oi['qty'],$oi['item_id']]);
            }
        }
        $pdo->prepare("INSERT INTO pos_sale_audits (sale_id, action_type, notes, changed_by) VALUES (?,?,?,?)")->execute([$id,'DELETED',"Sale #$id deleted.",$user_id]);
        $pdo->prepare("DELETE FROM pos_sales WHERE id=?")->execute([$id]);
        $pdo->commit();
        echo json_encode(['success'=>true]);
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point of Sale | Sahan Picture & Mirror</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background: url('../assests/glass_bg.png') no-repeat center center fixed; background-size: cover; color: #1e293b; min-height: 100vh; }
        .glass-header { background: rgba(248,250,252,0.96); backdrop-filter: blur(20px); border-bottom: 1px solid rgba(226,232,240,0.8); box-shadow: 0 4px 20px -5px rgba(0,0,0,0.05); }
        .glass-card { background: rgba(255,255,255,0.88); backdrop-filter: blur(20px); border: 1px solid white; border-radius: 24px; box-shadow: 0 10px 30px -5px rgba(0,0,0,0.04); }
        .input-glass { background: rgba(255,255,255,0.6); border: 1px solid #e2e8f0; padding: 10px 14px; border-radius: 12px; outline: none; transition: all 0.3s; font-size: 14px; font-weight: 700; color: #0f172a; }
        .input-glass:focus { border-color: #0891b2; background: white; box-shadow: 0 0 15px rgba(8,145,178,0.08); }
        .table-header { background: #1e293b; color: white; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; }
        .custom-scroll::-webkit-scrollbar { width: 6px; } .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .dropdown-results { background: white !important; opacity: 1 !important; }
        .dropdown-results div { padding: 10px 14px; transition: all 0.2s; border-bottom: 1px solid #f1f5f9; cursor: pointer; }
        .dropdown-results div:hover { background: #f8fafc; color: #0891b2; }
        .pay-method-card { transition: all 0.2s; }
        .pay-method-card.active { background: #eef2ff; border-color: #6366f1 !important; }
        @media print {
            body * { visibility: hidden; }
            #invoice-print, #invoice-print * { visibility: visible; }
            #invoice-print { position: absolute; left: 0; top: 0; width: 100%; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="flex flex-col">
<header class="glass-header sticky top-0 z-40 py-3">
    <div class="px-5 flex items-center justify-between">
        <div class="flex items-center space-x-3 md:space-x-5">
            <a href="dashboard.php" class="text-slate-800 hover:text-cyan-600 transition-colors p-2 rounded-2xl hover:bg-slate-100">
                <i class="fa-solid fa-arrow-left text-lg"></i>
            </a>
            <div>
                <h1 class="text-xl md:text-2xl font-black text-slate-900 font-['Outfit'] tracking-tight">Point of Sale</h1>
                <p class="hidden md:block text-[11px] uppercase font-black text-slate-500 tracking-widest mt-0.5">Direct Sales Terminal</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="pos_sales_history.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2.5 rounded-xl font-bold text-[10px] uppercase tracking-widest transition-all flex items-center gap-2">
                <i class="fa-solid fa-clock-rotate-left"></i><span class="hidden sm:inline">History</span>
            </a>
            
            <div id="save-indicator" class="hidden bg-emerald-500 text-white px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest flex items-center gap-2 shadow-sm border border-emerald-400/50">
              <i class="fa-solid fa-check-double scale-90"></i><span>Saved</span>
            </div>
            <div id="save-error" class="hidden bg-rose-500 text-white px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest flex items-center gap-2 shadow-sm border border-rose-400/50">
              <i class="fa-solid fa-circle-exclamation"></i><span>Error</span>
            </div>
            <div id="saving-indicator" class="hidden bg-slate-100 text-slate-500 px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest flex items-center gap-2 animate-pulse border border-slate-200">
               <i class="fa-solid fa-cloud-arrow-up text-[11px]"></i><span>Saving...</span>
            </div>

            <button onclick="newSale()" class="bg-slate-900 hover:bg-black text-white px-4 md:px-6 py-2.5 rounded-xl font-bold text-[10px] uppercase tracking-widest transition-all shadow-xl flex items-center gap-2">
                <i class="fa-solid fa-plus text-[10px]"></i><span>Create New</span>
            </button>
        </div>
    </div>
</header>
<main class="px-5 py-8 w-full">
  <div class="glass-card p-5 mb-4 flex flex-col min-h-[calc(100vh-170px)]" id="sale-form-card">
    <!-- SALE DATE -->
    <div class="flex flex-col md:flex-row gap-4 mb-6 items-start md:items-center justify-between">
      <div class="flex items-center gap-4">
        <div>
          <label class="text-[12px] uppercase font-black text-slate-700 mb-2 ml-1 block tracking-widest">Sale Date</label>
          <input type="date" id="sale_date" class="input-glass h-[44px]" value="<?php echo date('Y-m-d'); ?>" onchange="autoSave()">
        </div>
        <div id="editing-badge" class="hidden mt-5 px-3 py-1.5 bg-amber-100 border border-amber-300 rounded-xl text-[10px] font-black text-amber-700 uppercase tracking-widest"></div>
      </div>
      <button onclick="cancelEdit()" id="cancel-edit-btn" class="hidden mt-5 text-rose-500 text-[10px] font-black uppercase tracking-widest hover:text-rose-700"><i class="fa-solid fa-xmark mr-1"></i>Cancel Edit</button>
    </div>
    <!-- CUSTOMER SEARCH -->
    <div class="mb-6">
      <label class="text-[12px] uppercase font-black text-slate-700 mb-2 ml-1 block tracking-widest">Customer</label>
      <div class="relative max-w-lg">
        <i class="fa-solid fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
        <input type="text" id="cust_search" placeholder="Search by name or contact number..." class="input-glass w-full h-[44px] pl-9"
               oninput="searchCustomer(this.value)" autocomplete="off">
        <input type="hidden" id="selected_customer_id">
        <div id="cust_results" class="dropdown-results absolute w-full mt-1 border border-slate-200 rounded-xl shadow-2xl z-[100] hidden overflow-hidden"></div>
      </div>
      <p id="selected_customer_label" class="text-xs font-bold text-emerald-600 mt-1.5 ml-1 hidden"></p>
    </div>
    <!-- ITEM SEARCH -->
    <div class="mb-4">
      <div class="flex items-center justify-between mb-2">
        <label class="text-[12px] uppercase font-black text-slate-700 tracking-widest">Items Search</label>
      </div>
      <div class="relative max-w-lg mb-4">
        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
        <input type="text" id="item_search" placeholder="Search items by brand name..." class="input-glass w-full h-[44px] pl-9"
               oninput="searchItems(this.value)" autocomplete="off">
        <div id="item_results" class="dropdown-results absolute w-full mt-1 border border-slate-200 rounded-xl shadow-2xl z-[100] hidden overflow-hidden max-h-72 overflow-y-auto"></div>
      </div>
      <!-- ITEMS TABLE -->
      <div class="overflow-x-auto rounded-2xl border border-slate-100">
        <table class="w-full text-left" id="items-table">
          <thead>
            <tr class="table-header">
              <th class="px-4 py-3 text-[12px]">Item Details</th>
              <th class="px-4 py-3 text-[12px]">Selling Price</th>
              <th class="px-4 py-3 text-[12px]">Qty</th>
              <th class="px-4 py-3 text-[12px]">Item Disc</th>
              <th class="px-4 py-3 text-[12px]">Damaged</th>
              <th class="px-4 py-3 text-[12px]">Line Total</th>
              <th class="px-4 py-3 text-center text-[12px]">Del</th>
            </tr>
          </thead>
          <tbody id="items-body" class="divide-y divide-slate-100">
            <tr id="empty-row"><td colspan="7" class="px-4 py-8 text-center text-slate-400 font-bold text-xs uppercase tracking-widest italic">No items added yet. Search above to add.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <!-- TOTALS & DISCOUNT FOOTER -->
    <div class="mt-auto glass-card p-4 border-slate-200 flex flex-col lg:flex-row items-center justify-between gap-4">
      <!-- Left: Discount Controls -->
      <div class="flex flex-col gap-2 w-full lg:w-auto">
        <label class="text-[11px] uppercase font-black text-slate-600 tracking-widest ml-1">Bill Discount</label>
        <div class="flex items-center gap-2">
          <div class="flex p-0.5 bg-slate-100/50 rounded-xl border border-slate-200">
            <button onclick="applyBillDisc(5,'percent')" class="disc-btn px-3 py-1 rounded-lg text-[9px] font-black uppercase tracking-wider hover:bg-white hover:shadow-sm transition-all">5%</button>
            <button onclick="applyBillDisc(10,'percent')" class="disc-btn px-3 py-1 rounded-lg text-[9px] font-black uppercase tracking-wider hover:bg-white hover:shadow-sm transition-all">10%</button>
            <button onclick="applyBillDisc(20,'percent')" class="disc-btn px-3 py-1 rounded-lg text-[9px] font-black uppercase tracking-wider hover:bg-white hover:shadow-sm transition-all">20%</button>
            <button onclick="applyBillDisc(0,'fixed')" class="px-3 py-1 text-rose-500 text-[9px] font-black uppercase tracking-wider hover:bg-rose-50 rounded-lg transition-all">Clear</button>
          </div>
          <div class="flex items-center gap-1.5">
            <input type="number" min="0" step="0.01" id="bill_disc_val" placeholder="Custom" class="input-glass w-20 h-[34px] text-[10px] py-1 px-2">
            <select id="bill_disc_type" class="input-glass h-[34px] text-[10px] py-1 px-1">
              <option value="fixed">LKR</option>
              <option value="percent">%</option>
            </select>
            <button onclick="applyCustomBillDisc()" class="px-3 py-1.5 bg-indigo-600 text-white rounded-lg text-[9px] font-black uppercase tracking-widest hover:bg-indigo-700 transition-all h-[34px]">Apply</button>
          </div>
        </div>
      </div>

      <!-- Right: Summary & Action -->
      <div class="flex items-center gap-6 w-full lg:w-auto justify-between lg:justify-end">
        <div class="flex gap-4 items-center">
          <div class="text-right">
            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Subtotal</p>
            <p id="disp-subtotal" class="text-sm font-black text-slate-700">LKR 0.00</p>
          </div>
          <div class="text-right">
            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Discount</p>
            <p id="disp-total-disc" class="text-sm font-black text-rose-500">- LKR 0.00</p>
          </div>
          <div class="h-8 w-[1px] bg-slate-200"></div>
          <div class="text-right">
            <p class="text-[11px] font-black text-slate-500 uppercase tracking-widest">Grand Total</p>
            <p id="disp-grand" class="text-2xl font-black text-emerald-600">LKR 0.00</p>
          </div>
        </div>
        <button onclick="openPaymentModal()" class="bg-slate-900 hover:bg-black text-white px-6 py-3 rounded-xl font-black text-[10px] uppercase tracking-widest shadow-lg transition-all active:scale-95 flex items-center gap-2">
          <i class="fa-solid fa-credit-card"></i> Pay Now
        </button>
      </div>
    </div>
  </div>
</main>
<!-- MODALS -->
<!-- Customer Create Modal -->
<div id="modal-customer" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xl z-[70] flex items-center justify-center p-4 hidden">
  <div class="glass-card w-full max-w-md p-8 shadow-2xl">
    <div class="flex items-center justify-between mb-6">
      <h3 class="text-xl font-black font-['Outfit'] text-slate-900">New Customer</h3>
      <button onclick="closeModal('modal-customer')" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-slate-800"><i class="fa-solid fa-times"></i></button>
    </div>
    <form onsubmit="saveNewCustomer(event)" class="space-y-4">
      <div><label class="text-[12px] uppercase font-black text-slate-600 tracking-widest ml-1 mb-1.5 block">Name</label>
        <input type="text" name="name" id="new_cust_name" required class="input-glass w-full h-[46px]" placeholder="Customer name"></div>
      <div><label class="text-[12px] uppercase font-black text-slate-600 tracking-widest ml-1 mb-1.5 block">Contact</label>
        <input type="text" name="contact" id="new_cust_contact" class="input-glass w-full h-[46px]" placeholder="07XXXXXXXX"></div>
      <div><label class="text-[12px] uppercase font-black text-slate-600 tracking-widest ml-1 mb-1.5 block">Address (Optional)</label>
        <textarea name="address" class="input-glass w-full min-h-[80px]" placeholder="Address..."></textarea></div>
      <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white py-3.5 rounded-2xl font-black text-xs uppercase tracking-widest shadow-xl transition-all">Register & Select</button>
    </form>
  </div>
</div>

<!-- Payment Modal -->
<div id="modal-payment" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xl z-[60] flex items-center justify-center p-4 hidden">
  <div class="glass-card w-full max-w-md p-8 shadow-2xl">
    <div class="flex items-center justify-between mb-6">
      <div><h3 class="text-xl font-black font-['Outfit'] text-slate-900">Payment</h3>
        <p id="pay-modal-bill" class="text-[11px] uppercase font-black text-slate-500 tracking-widest"></p></div>
      <button onclick="closeModal('modal-payment')" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-slate-800"><i class="fa-solid fa-times"></i></button>
    </div>
    <div id="pay-modal-body">
      <!-- Later payment button -->
      <button onclick="saveLaterPayment()" class="w-full mb-4 border-2 border-dashed border-slate-300 text-slate-500 hover:border-amber-400 hover:text-amber-600 hover:bg-amber-50 py-3 rounded-2xl font-black text-xs uppercase tracking-widest transition-all flex items-center justify-center gap-2">
        <i class="fa-solid fa-clock"></i> Later Payment (Save as Pending)
      </button>
      <div class="bg-emerald-50 rounded-xl p-4 mb-4 border border-emerald-100 flex justify-between items-center">
        <span class="text-[12px] font-black uppercase text-emerald-600 tracking-widest">Grand Total</span>
        <span id="pay-grand-disp" class="font-black text-emerald-700 text-2xl">LKR 0.00</span>
      </div>
      <div class="grid grid-cols-4 gap-2 mb-5">
        <div class="pay-method-card active border-2 border-indigo-400 bg-indigo-50 rounded-xl p-2.5 flex flex-col items-center gap-1.5 cursor-pointer" onclick="selectPM('Cash')" data-type="Cash">
          <i class="fa-solid fa-money-bill-wave text-indigo-600 text-lg"></i><span class="text-[10px] font-black uppercase text-indigo-700">Cash</span></div>
        <div class="pay-method-card border-2 border-slate-100 rounded-xl p-2.5 flex flex-col items-center gap-1.5 cursor-pointer hover:bg-slate-50" onclick="selectPM('Cheque')" data-type="Cheque">
          <i class="fa-solid fa-money-check-dollar text-slate-500 text-lg"></i><span class="text-[10px] font-black uppercase text-slate-600">Cheque</span></div>
        <div class="pay-method-card border-2 border-slate-100 rounded-xl p-2.5 flex flex-col items-center gap-1.5 cursor-pointer hover:bg-slate-50" onclick="selectPM('Account Transfer')" data-type="Account Transfer">
          <i class="fa-solid fa-building-columns text-slate-500 text-lg"></i><span class="text-[10px] font-black uppercase text-slate-600">Bank</span></div>
        <div class="pay-method-card border-2 border-slate-100 rounded-xl p-2.5 flex flex-col items-center gap-1.5 cursor-pointer hover:bg-slate-50" onclick="selectPM('Card')" data-type="Card">
          <i class="fa-solid fa-credit-card text-slate-500 text-lg"></i><span class="text-[10px] font-black uppercase text-slate-600">Card</span></div>
      </div>
      <input type="hidden" id="pm_type_val" value="Cash">
      <div class="grid grid-cols-2 gap-4 mb-4">
        <div><label class="text-[12px] uppercase font-black text-slate-600 tracking-widest ml-1 mb-1.5 block">Amount (LKR)</label>
          <input type="number" min="0" step="0.01" id="pm_amount" class="input-glass w-full h-[46px] font-black text-emerald-600 text-lg"></div>
        <div><label class="text-[12px] uppercase font-black text-slate-600 tracking-widest ml-1 mb-1.5 block">Date</label>
          <input type="date" id="pm_date" class="input-glass w-full h-[46px]" value="<?php echo date('Y-m-d'); ?>"></div>
      </div>
      <!-- Bank section -->
      <div id="pm-bank-sec" class="hidden mb-4">
        <label class="text-[12px] uppercase font-black text-slate-600 tracking-widest ml-1 mb-1.5 block">Bank</label>
        <div class="flex gap-2 relative">
          <input type="text" id="pm_bank_search" placeholder="Search bank..." class="input-glass flex-1 h-[44px]" onkeyup="searchBankPOS(this.value)">
          <button type="button" onclick="openNewBankModal()" class="w-[44px] h-[44px] rounded-xl bg-indigo-50 text-indigo-600 border border-indigo-100 hover:bg-indigo-600 hover:text-white transition-all"><i class="fa-solid fa-plus text-sm"></i></button>
          <div id="pm_bank_results" class="dropdown-results absolute top-full left-0 w-full mt-1 border border-slate-200 rounded-xl shadow-2xl z-[100] hidden overflow-hidden"></div>
        </div>
        <input type="hidden" id="pm_bank_id">
      </div>
      <!-- Cheque section -->
      <div id="pm-cheque-sec" class="hidden mb-4 grid grid-cols-2 gap-3">
        <div><label class="text-[12px] uppercase font-black text-slate-600 tracking-widest ml-1 mb-1.5 block">Cheque No.</label>
          <input type="text" id="pm_chq_no" class="input-glass w-full h-[44px]"></div>
        <div><label class="text-[12px] uppercase font-black text-slate-600 tracking-widest ml-1 mb-1.5 block">Payer</label>
          <input type="text" id="pm_chq_payer" class="input-glass w-full h-[44px]" placeholder="Optional"></div>
      </div>
      <button onclick="submitPayment()" class="w-full bg-slate-900 hover:bg-black text-white py-4 rounded-2xl font-black text-xs uppercase tracking-widest shadow-2xl transition-all active:scale-95">
        Confirm Payment
      </button>
    </div>
  </div>
</div>

<!-- New Bank Modal -->
<div id="modal-new-bank" class="fixed inset-0 bg-slate-900/70 backdrop-blur-xl z-[80] flex items-center justify-center p-4 hidden">
  <div class="glass-card w-full max-w-sm p-8 shadow-2xl">
    <h3 class="text-xl font-black text-slate-900 font-['Outfit'] mb-6">New Bank Account</h3>
    <form onsubmit="saveNewBank(event)" class="space-y-4">
      <div><label class="text-[10px] uppercase font-black text-slate-400 ml-1 mb-1.5 block">Bank Name</label>
        <input type="text" name="name" required class="input-glass w-full h-[45px] font-bold uppercase" placeholder="e.g. SAMPATH / BOC"></div>
      <div><label class="text-[10px] uppercase font-black text-slate-400 ml-1 mb-1.5 block">Account Number</label>
        <input type="text" name="acc_no" required class="input-glass w-full h-[45px] font-black"></div>
      <div><label class="text-[10px] uppercase font-black text-slate-400 ml-1 mb-1.5 block">Account Name</label>
        <input type="text" name="acc_name" required class="input-glass w-full h-[45px] font-bold"></div>
      <div class="flex gap-3 pt-2">
        <button type="button" onclick="closeModal('modal-new-bank')" class="flex-1 py-3 text-[10px] font-black uppercase text-slate-400">Cancel</button>
        <button type="submit" class="flex-[2] bg-indigo-600 text-white py-3 rounded-2xl text-[10px] font-black uppercase shadow-lg">Save Account</button>
      </div>
    </form>
  </div>
</div>

<!-- Invoice Print Area -->
<div id="invoice-print" class="hidden p-8" style="font-family: Arial, sans-serif; color: #000; background: #fff; max-width: 600px; margin: 0 auto;">
  <div style="text-align:center; border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 12px;">
    <h2 style="font-size:20px; font-weight:900; margin:0;">Sahan Picture & Mirror</h2>
    <p style="font-size:11px; margin:4px 0;">Point of Sale Invoice</p>
  </div>
  <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:12px;">
    <div><strong>Bill ID:</strong> <span id="inv-bill-id"></span><br><strong>Date:</strong> <span id="inv-date"></span></div>
    <div style="text-align:right;"><strong>Customer:</strong><br><span id="inv-customer"></span></div>
  </div>
  <table style="width:100%; border-collapse:collapse; font-size:12px; margin-bottom:12px;">
    <thead><tr style="border-bottom:2px solid #000; background:#f0f0f0;">
      <th style="padding:6px 4px; text-align:left;">Brand</th>
      <th style="padding:6px 4px; text-align:center;">Qty</th>
      <th style="padding:6px 4px; text-align:right;">Unit Price</th>
      <th style="padding:6px 4px; text-align:right;">Discount</th>
      <th style="padding:6px 4px; text-align:right;">Total</th>
    </tr></thead>
    <tbody id="inv-items-body"></tbody>
  </table>
  <div style="border-top:1px solid #000; padding-top:8px; font-size:12px;">
    <div style="display:flex;justify-content:space-between;"><span>Subtotal</span><span id="inv-subtotal"></span></div>
    <div style="display:flex;justify-content:space-between;"><span>Discount</span><span id="inv-discount"></span></div>
    <div style="display:flex;justify-content:space-between; font-weight:900; font-size:16px; border-top:2px solid #000; margin-top:6px; padding-top:6px;"><span>Grand Total</span><span id="inv-grand"></span></div>
  </div>
  <div style="margin-top:12px; padding-top:8px; border-top:1px dashed #000; font-size:12px;">
    <div style="display:flex;justify-content:space-between;"><span><strong>Payment Method:</strong></span><span id="inv-method"></span></div>
    <div style="display:flex;justify-content:space-between;"><span><strong>Amount Paid:</strong></span><span id="inv-paid"></span></div>
    <div style="display:flex;justify-content:space-between;"><span><strong>Status:</strong></span><span id="inv-status"></span></div>
  </div>
  <div style="text-align:center; margin-top:20px; font-size:10px; color:#555;">Thank you for your purchase!</div>
</div>

<script>
let saleItems = [];
let billDiscVal = 0;
let billDiscType = 'fixed';
let currentSaleId = null;
let currentBillId = null;
let selectedCustomerId = null;
let customerSearchTimeout = null;
let lastItemResults = [];
let itemSearchTimeout = null;

//  Customer Search â”€
function searchCustomer(term) {
  clearTimeout(customerSearchTimeout);
  const res = document.getElementById('cust_results');
  if (term.length < 1) { res.classList.add('hidden'); return; }
  customerSearchTimeout = setTimeout(() => {
    fetch(`?action=search_customer&term=${encodeURIComponent(term)}`)
      .then(r=>r.json()).then(data => {
        let html = '';
        if (data.length > 0) {
          data.forEach(c => {
            html += `<div onmousedown="selectCustomer(${c.id},'${escJs(c.name)}','${escJs(c.contact_number||'')}')">
              <div class="flex items-center gap-3">
                <div class="w-8 h-8 bg-indigo-50 rounded-lg border border-indigo-100 flex items-center justify-center text-indigo-500 text-[12px]"><i class="fa-solid fa-user"></i></div>
                <div><p class="text-sm font-bold text-slate-800">${c.name}</p><p class="text-[11px] text-slate-500 font-bold">${c.contact_number||''}</p></div>
              </div>
            </div>`;
          });
        } else {
          html = `<div class="text-center p-5">
            <p class="text-[11px] font-black text-slate-500 uppercase tracking-widest mb-4">No customer found</p>
            <button type="button" onmousedown="openNewCustomerModal('${escJs(term)}')" class="w-full bg-emerald-600 hover:bg-black text-white py-3 rounded-xl font-black text-[11px] uppercase tracking-widest transition-all">
              <i class="fa-solid fa-plus mr-2"></i>Create Customer
            </button>
          </div>`;
        }
        res.innerHTML = html; res.classList.remove('hidden');
      });
  }, 200);
}

function selectCustomer(id, name, contact) {
  selectedCustomerId = id;
  document.getElementById('selected_customer_id').value = id;
  document.getElementById('cust_search').value = name + (contact ? '  ' + contact : '');
  document.getElementById('cust_results').classList.add('hidden');
  const lbl = document.getElementById('selected_customer_label');
  lbl.textContent = ' ' + name; lbl.classList.remove('hidden');
  autoSave(); // Auto-save when customer changes
}

function openNewCustomerModal(term) {
  document.getElementById('new_cust_name').value = term || '';
  if (/^\d{7,}$/.test(term)) {
    document.getElementById('new_cust_contact').value = term;
    document.getElementById('new_cust_name').value = '';
  }
  document.getElementById('cust_results').classList.add('hidden');
  openModal('modal-customer');
}

function saveNewCustomer(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action', 'create_customer');
  fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(res => {
    if (res.success) {
      selectCustomer(res.id, res.name, res.contact);
      closeModal('modal-customer');
      e.target.reset();
    } else alert(res.message);
  });
}

//  Item Search â”€
function searchItems(term) {
  clearTimeout(itemSearchTimeout);
  const res = document.getElementById('item_results');
  itemSearchTimeout = setTimeout(() => {
    fetch(`?action=search_brand_stock&term=${encodeURIComponent(term)}`)
      .then(r=>r.json()).then(data => {
        lastItemResults = data;
        if (!data.length) { res.classList.add('hidden'); return; }
        let html = '';
        data.forEach(it => {
          html += `<div onmousedown="addItem(${it.item_id})">
            <div class="flex items-center justify-between gap-4 p-1">
              <div>
                <p class="text-sm font-black text-slate-800">${it.brand_name}</p>
                <p class="text-[11px] text-slate-500 font-bold">${it.country||''} &bull; ${it.container_number}</p>
                <p class="text-[11px] font-black text-indigo-600 mt-1">Cost: LKR ${fmtN(it.per_item_cost)}</p>
              </div>
              <div class="text-right">
                <p class="text-[11px] font-black text-emerald-600 uppercase">Avail: ${it.available_qty}</p>
                <p class="text-[11px] text-slate-500 font-bold">${it.pallets} pallets</p>
              </div>
            </div>
          </div>`;
        });
        res.innerHTML = html; res.classList.remove('hidden');
      });
  }, 200);
}

function addItem(itemId) {
  const it = lastItemResults.find(i => i.item_id == itemId);
  if (!it) return;
  document.getElementById('item_results').classList.add('hidden');
  document.getElementById('item_search').value = '';
  const id = Date.now() + '' + Math.floor(Math.random()*100);
  saleItems.push({
    rowId:id, 
    item_id:it.item_id, 
    item_source: it.item_source,
    brand_name:it.brand_name, 
    available_qty:parseInt(it.available_qty), 
    pallets:it.pallets, 
    country:it.country, 
    cost_price:parseFloat(it.cost_price)||0, 
    selling_price:0, 
    qty:1, 
    damaged_qty:0, 
    item_discount:0, 
    line_total:0
  });
  renderItemsTable();
}

function renderItemsTable() {
  const body = document.getElementById('items-body');
  body.innerHTML = '';
  if (saleItems.length === 0) {
    body.innerHTML = `<tr id="empty-row"><td colspan="7" class="px-4 py-12 text-center text-slate-500 font-bold text-sm uppercase tracking-widest italic">No items added yet. Search above to add.</td></tr>`;
    calcTotals();
    autoSave();
    return;
  }
  saleItems.forEach((it, idx) => {
    const row = document.createElement('tr');
    row.className = 'hover:bg-slate-50/50 transition-colors border-b border-slate-100';
    row.innerHTML = `
      <td class="px-4 py-3">
        <div class="flex flex-col">
          <span class="text-sm font-black text-slate-800">${it.brand_name}</span>
          <div class="flex items-center gap-2 mt-1">
            <span class="text-[11px] uppercase font-black text-slate-500 tracking-widest">${it.country || ''}</span>
            <span class="text-[11px] font-black text-indigo-600 bg-indigo-50 px-1.5 rounded-md border border-indigo-100">@ Cost LKR ${fmt(it.cost_price)}</span>
            <span class="text-[11px] font-black text-slate-500">Pallets: ${it.pallets || 0}</span>
          </div>
        </div>
      </td>
      <td class="px-4 py-3 min-w-[140px]">
        <input type="number" step="0.01" class="input-glass w-full h-[40px] text-sm font-black text-right" value="${it.selling_price}" 
               onchange="updateItem('${it.rowId}', 'selling_price', this.value)">
      </td>
      <td class="px-4 py-3 min-w-[110px]">
        <div class="relative pt-4">
          <span class="absolute top-0 left-0 text-[10px] font-black text-rose-600 uppercase">Avl: ${it.available_qty}</span>
          <input type="number" class="input-glass w-full h-[40px] text-sm font-black" value="${it.qty}" 
                 onchange="updateItem('${it.rowId}', 'qty', this.value)">
        </div>
      </td>
      <td class="px-4 py-3 min-w-[140px]">
        <input type="number" step="0.01" class="input-glass w-full h-[40px] text-sm font-black text-rose-500 text-right" value="${it.item_discount}" 
               onchange="updateItem('${it.rowId}', 'item_discount', this.value)">
      </td>
      <td class="px-4 py-3 min-w-[110px]">
        <input type="number" class="input-glass w-full h-[40px] text-sm font-black" value="${it.damaged_qty}" 
                 onchange="updateItem('${it.rowId}', 'damaged_qty', this.value)">
      </td>
      <td class="px-4 py-3">
        <span class="text-sm font-black text-slate-900" data-total-for="${it.rowId}">LKR ${fmt(it.line_total)}</span>
      </td>
      <td class="px-4 py-3 text-center">
        <button onclick="removeItem('${it.rowId}')" class="text-slate-400 hover:text-rose-600 transition-colors bg-slate-50 w-8 h-8 rounded-full flex items-center justify-center border border-slate-100">
          <i class="fa-solid fa-trash-can text-sm"></i>
        </button>
      </td>
    `;
    body.appendChild(row);
  });
  calcTotals();
  autoSave();
}

function updateItem(rowId, field, val) {
  const it = saleItems.find(i=>i.rowId==rowId);
  if (!it) return;
  const numVal = parseFloat(val);
  it[field] = isNaN(numVal) ? 0 : numVal;
  if (field==='qty' || field==='damaged_qty' || field==='selling_price' || field==='item_discount') {
    it.line_total = ((it.qty - it.damaged_qty) * it.selling_price) - it.item_discount;
  }
  // Surgically update the line total display without re-rendering the whole row
  const ltEl = document.querySelector(`[data-total-for="${rowId}"]`);
  if (ltEl) ltEl.textContent = 'LKR ' + fmt(it.line_total);

  calcTotals();
  autoSave();
}

function removeItem(rowId) {
  saleItems = saleItems.filter(i=>i.rowId!==rowId);
  renderItemsTable();
}

function calcTotals() {
  let sub = 0, iDisc = 0;
  saleItems.forEach(it => {
    const lt = ((it.qty - it.damaged_qty) * it.selling_price) - it.item_discount;
    sub += lt; iDisc += it.item_discount;
  });
  const bd = billDiscType==='percent' ? (sub * billDiscVal/100) : billDiscVal;
  const grand = Math.max(0, sub - bd);
  const elSub = document.getElementById('disp-subtotal'); if(elSub) elSub.textContent = 'LKR ' + fmt(sub);
  const elDisc = document.getElementById('disp-total-disc'); if(elDisc) elDisc.textContent = '- LKR ' + fmt(iDisc + bd);
  const elGrand = document.getElementById('disp-grand'); if(elGrand) elGrand.textContent = 'LKR ' + fmt(grand);
  const elPayGrand = document.getElementById('pay-grand-disp'); if(elPayGrand) elPayGrand.textContent = 'LKR ' + fmt(grand);
}

function applyBillDisc(val, type) {
  billDiscVal = val; billDiscType = type;
  document.getElementById('bill_disc_val').value = val || '';
  document.getElementById('bill_disc_type').value = type;
  calcTotals();
  autoSave();
  document.querySelectorAll('.disc-btn').forEach(b=>b.classList.remove('!bg-indigo-600','!text-white','!border-indigo-600'));
}

function applyCustomBillDisc() {
  billDiscVal = parseFloat(document.getElementById('bill_disc_val').value)||0;
  billDiscType = document.getElementById('bill_disc_type').value;
  calcTotals();
  autoSave();
}
</script>
<script>
//  Payment Modal 
function openPaymentModal() {
  if (saleItems.length === 0) { alert('Add at least one item first.'); return; }
  document.getElementById('pm_amount').value = '';
  document.getElementById('pm_bank_id').value = '';
  document.getElementById('pm_bank_search').value = '';
  document.getElementById('pm_chq_no').value = '';
  document.getElementById('pm_chq_payer').value = '';
  selectPM('Cash');
  openModal('modal-payment');
}

function selectPM(type) {
  document.getElementById('pm_type_val').value = type;
  document.querySelectorAll('.pay-method-card').forEach(c => {
    if (c.dataset.type === type) c.classList.add('active','!border-indigo-400','!bg-indigo-50');
    else c.classList.remove('active','!border-indigo-400','!bg-indigo-50');
  });
  document.getElementById('pm-bank-sec').classList.toggle('hidden', type !== 'Account Transfer');
  document.getElementById('pm-cheque-sec').classList.toggle('hidden', type !== 'Cheque');
}

function saveLaterPayment() {
  closeModal('modal-payment');
  doSaveSale('Later Payment');
}

function submitPayment() {
  const type  = document.getElementById('pm_type_val').value;
  const amount = parseFloat(document.getElementById('pm_amount').value);
  if (!amount || amount <= 0) { alert('Enter a valid payment amount.'); return; }
  // first save the sale, then add payment record
  doSaveSale(type, function(saleId) {
    const fd = new FormData();
    fd.append('action', 'save_pos_payment');
    fd.append('sale_id', saleId);
    fd.append('type', type);
    fd.append('amount', amount);
    fd.append('date', document.getElementById('pm_date').value);
    fd.append('bank_id', document.getElementById('pm_bank_id').value || '');
    fd.append('chq_no', document.getElementById('pm_chq_no').value || '');
    fd.append('chq_payer', document.getElementById('pm_chq_payer').value || '');
    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(res => {
      if (!res.success) { alert('Payment error: ' + res.message); return; }
      closeModal('modal-payment');
      showToast('Payment recorded! Status: ' + res.payment_status);
      printInvoice(saleId, function() { newSale(); });
    });
  });
}

//  Auto-Save Functionality 
let autoSaveTimeout = null;
function autoSave() {
  clearTimeout(autoSaveTimeout);
  autoSaveTimeout = setTimeout(() => {
    doSaveSale('Later Payment', null, true);
  }, 2000); // Trigger save after 2 seconds of inactivity
}

//  Save Sale 
function doSaveSale(payMethod, callback, silent=false) {
  const custId = document.getElementById('selected_customer_id').value;
  if (saleItems.length === 0 && !custId) return;
  
  if (silent) document.getElementById('saving-indicator').classList.remove('hidden');
  
  const fd = new FormData();
  fd.append('action', 'save_pos_sale');
  if (currentSaleId) fd.append('editing_id', currentSaleId);
  fd.append('customer_id', document.getElementById('selected_customer_id').value || '');
  fd.append('sale_date', document.getElementById('sale_date').value);
  fd.append('payment_method', payMethod);
  fd.append('bill_discount', billDiscVal);
  fd.append('bill_discount_type', billDiscType);
  fd.append('items', JSON.stringify(saleItems.map(it => ({
    item_id:it.item_id, 
    item_source: it.item_source,
    brand_name:it.brand_name, 
    qty:it.qty,
    damaged_qty:it.damaged_qty, 
    cost_price:it.cost_price,
    selling_price:it.selling_price, 
    item_discount:it.item_discount
  }))));
  fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(res => {
    document.getElementById('saving-indicator').classList.add('hidden');
    if (!res.success) { 
       console.error('Save failed:', res.message);
       document.getElementById('save-error').classList.remove('hidden');
       setTimeout(() => document.getElementById('save-error').classList.add('hidden'), 3000);
       if(!silent) alert('Error: ' + res.message); 
       return; 
    }
    currentSaleId = res.sale_id;
    currentBillId = res.bill_id;
    if (silent) {
       document.getElementById('save-indicator').classList.remove('hidden');
       setTimeout(() => document.getElementById('save-indicator').classList.add('hidden'), 2000);
    } else if (payMethod === 'Later Payment') {
      showToast('Sale saved as Pending (Later Payment).');
      printInvoice(res.sale_id, function() { newSale(); });
    } else if (callback) {
      callback(res.sale_id);
    }
  });
}

//  Print Invoice 
function printInvoice(saleId, afterPrint) {
  fetch(`?action=get_pos_sale&id=${saleId}`).then(r=>r.json()).then(res => {
    if (!res.success) return;
    const d = res.data;
    document.getElementById('inv-bill-id').textContent = d.bill_id;
    document.getElementById('inv-date').textContent = d.sale_date;
    document.getElementById('inv-customer').textContent = d.customer_name || 'Walk-in Customer';
    let rows = '';
    d.items.forEach(it => {
      rows += `<tr style="border-bottom:1px solid #ddd;">
        <td style="padding:5px 4px;">${it.brand_name}</td>
        <td style="padding:5px 4px; text-align:center;">${it.qty - it.damaged_qty}</td>
        <td style="padding:5px 4px; text-align:right;">LKR ${fmtN(it.selling_price)}</td>
        <td style="padding:5px 4px; text-align:right;">LKR ${fmtN(it.item_discount)}</td>
        <td style="padding:5px 4px; text-align:right;">LKR ${fmtN(it.line_total)}</td>
      </tr>`;
    });
    document.getElementById('inv-items-body').innerHTML = rows;
    const totalDisc = parseFloat(d.item_discount||0) + parseFloat(d.bill_discount||0);
    document.getElementById('inv-subtotal').textContent = 'LKR ' + fmtN(d.subtotal);
    document.getElementById('inv-discount').textContent = '- LKR ' + fmtN(totalDisc);
    document.getElementById('inv-grand').textContent = 'LKR ' + fmtN(d.grand_total);
    document.getElementById('inv-method').textContent = d.payment_method;
    document.getElementById('inv-paid').textContent = 'LKR ' + fmtN(d.total_paid || 0);
    document.getElementById('inv-status').textContent = d.payment_status.toUpperCase();
    document.getElementById('invoice-print').classList.remove('hidden');
    setTimeout(() => { window.print(); document.getElementById('invoice-print').classList.add('hidden'); if(afterPrint) afterPrint(); }, 300);
  });
}

//  New Sale / Reset 
function newSale() {
  saleItems = []; billDiscVal = 0; billDiscType = 'fixed';
  currentSaleId = null; currentBillId = null; selectedCustomerId = null;
  document.getElementById('cust_search').value = '';
  document.getElementById('selected_customer_id').value = '';
  document.getElementById('selected_customer_label').classList.add('hidden');
  document.getElementById('item_search').value = '';
  document.getElementById('bill_disc_val').value = '';
  document.getElementById('editing-badge').classList.add('hidden');
  document.getElementById('cancel-edit-btn').classList.add('hidden');
  document.getElementById('sale_date').value = new Date().toISOString().substr(0,10);
  renderItemsTable();
  calcTotals();
}

function cancelEdit() { newSale(); }

//  Load Sale for Edit 
function loadSaleForEdit(saleId) {
  fetch(`?action=get_pos_sale&id=${saleId}`).then(r=>r.json()).then(res => {
    if (!res.success) { alert(res.message); return; }
    const d = res.data;
    newSale();
    currentSaleId = saleId;
    currentBillId = d.bill_id;
    document.getElementById('sale_date').value = d.sale_date;
    if (d.customer_id) selectCustomer(d.customer_id, d.customer_name, d.contact_number);
    document.getElementById('editing-badge').textContent = 'Editing: ' + d.bill_id;
    document.getElementById('editing-badge').classList.remove('hidden');
    document.getElementById('cancel-edit-btn').classList.remove('hidden');
    saleItems = d.items.map(it => ({
      rowId: Date.now()+Math.random(), item_id:it.container_item_id,
      brand_name:it.brand_name, available_qty:parseInt(it.available_qty)||0,
      pallets:it.pallets, country:it.country, cost_price:parseFloat(it.cost_price)||0,
      selling_price:parseFloat(it.selling_price)||0, qty:parseInt(it.qty)||0,
      damaged_qty:parseInt(it.damaged_qty)||0, item_discount:parseFloat(it.item_discount)||0,
      line_total:parseFloat(it.line_total)||0
    }));
    billDiscVal = parseFloat(d.bill_discount) || 0;
    billDiscType = d.bill_discount_type || 'fixed';
    document.getElementById('bill_disc_val').value = billDiscVal || '';
    document.getElementById('bill_disc_type').value = billDiscType;
    renderItemsTable();
    window.scrollTo({top:0, behavior:'smooth'});
  });
}

//  Bank Search 
function searchBankPOS(term) {
  if (term.length < 1) { document.getElementById('pm_bank_results').classList.add('hidden'); return; }
  fetch(`?action=search_bank&term=${encodeURIComponent(term)}`).then(r=>r.json()).then(data => {
    const res = document.getElementById('pm_bank_results');
    let html = '';
    if (data.length > 0) {
      data.forEach(b => { html += `<div onmousedown="selectBankPOS(${b.id},'${escJs(b.name)}')">${b.name} <span class="text-[10px] text-slate-400">${b.account_number||''}</span></div>`; });
    } else {
      html = `<div class="text-center p-3"><p class="text-[10px] text-slate-400 mb-2">Bank not found</p><button type="button" onmousedown="openNewBankModal()" class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-[10px] font-black">+ Add Bank</button></div>`;
    }
    res.innerHTML = html; res.classList.remove('hidden');
  });
}
function selectBankPOS(id, name) {
  document.getElementById('pm_bank_id').value = id;
  document.getElementById('pm_bank_search').value = name;
  document.getElementById('pm_bank_results').classList.add('hidden');
}
function openNewBankModal() { openModal('modal-new-bank'); }
function saveNewBank(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action', 'create_bank');
  fetch('', {method:'POST',body:fd}).then(r=>r.json()).then(res => {
    if (res.success) { selectBankPOS(res.id, res.name); closeModal('modal-new-bank'); e.target.reset(); }
  });
}

//  Helpers 
function fmt(n) { return parseFloat(n||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtN(n) { return parseFloat(n||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function escJs(s) { return (s||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"'); }
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
function showToast(msg) {
  const t = document.createElement('div');
  t.className = 'fixed top-6 right-6 z-[999] bg-slate-900 text-white px-5 py-3 rounded-2xl text-xs font-black shadow-2xl transition-all';
  t.textContent = msg; document.body.appendChild(t);
  setTimeout(() => t.remove(), 3500);
}

// Close dropdowns on outside click
document.addEventListener('click', e => {
  if (!e.target.closest('#cust_search') && !e.target.closest('#cust_results')) document.getElementById('cust_results').classList.add('hidden');
  if (!e.target.closest('#item_search') && !e.target.closest('#item_results')) document.getElementById('item_results').classList.add('hidden');
  if (!e.target.closest('#pm_bank_search') && !e.target.closest('#pm_bank_results')) document.getElementById('pm_bank_results')?.classList.add('hidden');
});

// Init
newSale();

// Handle ?edit= param on load (from pos_sales_history.php)
(function() {
    const params = new URLSearchParams(window.location.search);
    const editId = params.get('edit');
    if (editId) {
        loadSaleForEdit(parseInt(editId));
    }
})();
</script>
</body>
</html>
