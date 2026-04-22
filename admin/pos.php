<?php
require_once '../auth.php';
require_once '../config.php';
checkAuth();
if (!isAdmin()) { header('Location: ../sale/dashboard.php'); exit; }
$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? null;

// AJAX: search customer
if ($action === 'search_customer') {
    $term = '%' . ($_GET['term'] ?? '') . '%';
    $s = $pdo->prepare("SELECT id, name, contact_number, address FROM customers WHERE name LIKE ? OR contact_number LIKE ? LIMIT 6");
    $s->execute([$term, $term]);
    echo json_encode($s->fetchAll(PDO::FETCH_ASSOC)); exit;
}

// AJAX: create customer
if ($action === 'create_customer') {
    $s = $pdo->prepare("INSERT INTO customers (name, contact_number, address) VALUES (?, ?, ?)");
    $s->execute([$_POST['name'], $_POST['contact'], $_POST['address'] ?? '']);
    echo json_encode(['success'=>true,'id'=>$pdo->lastInsertId(),'name'=>$_POST['name'],'contact'=>$_POST['contact']]); exit;
}

// AJAX: search brand stock from shop_inventory
if ($action === 'search_brand_stock') {
    $term = $_GET['term'] ?? '';
    $termParam = '%' . $term . '%';
    
    $query = "
        SELECT si.*, 
               COALESCE(ci.pallets, opi.pallets, 0) as pallets,
               COALESCE(c.country, 'Direct') as country,
               COALESCE(c.container_number, op.purchase_number) as container_number,
               IF(si.item_source = 'other' AND si.category = 'Other', opi.price_per_item, si.cost_price_per_sqft) as true_cost_price
        FROM shop_inventory si
        LEFT JOIN container_items ci ON si.item_id = ci.id AND si.item_source = 'container'
        LEFT JOIN containers c ON ci.container_id = c.id
        LEFT JOIN other_purchase_items opi ON si.item_id = opi.id AND si.item_source = 'other'
        LEFT JOIN other_purchases op ON opi.purchase_id = op.id
        WHERE (si.full_sheets_qty > 0 OR si.partial_sqft_qty > 0)
    ";
    
    if (!empty($term)) {
        $query .= " AND (si.brand_name LIKE ? OR si.category LIKE ?)";
    }
    
    $query .= " ORDER BY si.id DESC LIMIT 10";
    $stmt = $pdo->prepare($query);
    
    $params = [];
    if (!empty($term)) {
        $params[] = $termParam;
        $params[] = $termParam;
    }
    
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── Edit Initialization ──
$editSaleData = null;
if (!empty($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $s = $pdo->prepare("SELECT ps.*, c.name as customer_name, c.contact_number FROM pos_sales ps LEFT JOIN customers c ON ps.customer_id=c.id WHERE ps.id = ?");
    $s->execute([$id]);
    $editSaleData = $s->fetch(PDO::FETCH_ASSOC);
    if ($editSaleData) {
        $si = $pdo->prepare("
            SELECT psi.*, si.brand_name, si.sqft_per_sheet, si.full_sheets_qty as available_full, si.partial_sqft_qty as available_partial 
            FROM pos_sale_items psi 
            LEFT JOIN shop_inventory si ON psi.item_id = si.item_id AND psi.item_source = si.item_source 
            WHERE psi.sale_id = ?
        ");
        $si->execute([$id]);
        $editSaleData['items'] = $si->fetchAll(PDO::FETCH_ASSOC);
    }
}

// AJAX: search bank
if ($action === 'search_bank') {
    $t = '%'.($_GET['term']??'').'%';
    $s = $pdo->prepare("SELECT * FROM banks WHERE name LIKE ? LIMIT 5");
    $s->execute([$t]);
    echo json_encode($s->fetchAll(PDO::FETCH_ASSOC)); exit;
}

// AJAX: create bank
if ($action === 'create_bank') {
    $s = $pdo->prepare("INSERT INTO banks (name, account_number, account_name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE account_number=?, account_name=?");
    $s->execute([$_POST['name'], $_POST['acc_no'], $_POST['acc_name'], $_POST['acc_no'], $_POST['acc_name']]);
    $id = $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM banks WHERE name='".$_POST['name']."'")->fetchColumn();
    echo json_encode(['success'=>true,'id'=>$id,'name'=>$_POST['name']]); exit;
}

// AJAX: save POS sale
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

        if ($editing_id) {
            // Revert old stock from shop_inventory
            $old = $pdo->prepare("SELECT item_id, item_source, qty, total_sqft, sale_type, category, deduct_from FROM pos_sale_items WHERE sale_id=?");
            $old->execute([$editing_id]);
            foreach ($old->fetchAll() as $oi) {
                if ($oi['category'] === 'Other') {
                    $pdo->prepare("UPDATE shop_inventory SET full_sheets_qty = full_sheets_qty + ? WHERE item_id = ? AND item_source = ?")
                        ->execute([$oi['qty'], $oi['item_id'], $oi['item_source']]);
                } else {
                    if ($oi['sale_type'] === 'Complete Sheets' || $oi['sale_type'] === 'Full Sheet') {
                        $pdo->prepare("UPDATE shop_inventory SET full_sheets_qty = full_sheets_qty + ? WHERE item_id = ? AND item_source = ?")
                            ->execute([$oi['qty'], $oi['item_id'], $oi['item_source']]);
                    } else {
                        // Partial Sheets
                        if ($oi['deduct_from'] === 'Partial Leftovers') {
                            $pdo->prepare("UPDATE shop_inventory SET partial_sqft_qty = partial_sqft_qty + ? WHERE item_id = ? AND item_source = ?")
                                ->execute([$oi['total_sqft'], $oi['item_id'], $oi['item_source']]);
                        } else {
                            $si = $pdo->prepare("SELECT sqft_per_sheet FROM shop_inventory WHERE item_id = ? AND item_source = ?");
                            $si->execute([$oi['item_id'], $oi['item_source']]);
                            $shopInfo = $si->fetch();
                            if ($shopInfo) {
                                $leftover = max(0, $shopInfo['sqft_per_sheet'] - $oi['total_sqft']);
                                $pdo->prepare("UPDATE shop_inventory SET full_sheets_qty = full_sheets_qty + 1, partial_sqft_qty = partial_sqft_qty - ? WHERE item_id = ? AND item_source = ?")
                                    ->execute([$leftover, $oi['item_id'], $oi['item_source']]);
                            }
                        }
                    }
                }
            }
            $pdo->prepare("DELETE FROM pos_sale_items WHERE sale_id=?")->execute([$editing_id]);
            $sale_id = $editing_id;
        } else {
            $bill_id = 'POS-'.date('Ymd').'-'.str_pad(rand(1,9999),4,'0',STR_PAD_LEFT);
            $manual_cust = $_POST['manual_customer_name'] ?? null;
            $ins = $pdo->prepare("INSERT INTO pos_sales (bill_id, sale_date, customer_id, manual_customer_name, created_by, payment_method, payment_status) VALUES (?,?,?,?,?,?,?)");
            $ins->execute([$bill_id, $sale_date, $customer_id, $manual_cust, $user_id, $pay_method, 'pending']);
            $sale_id = $pdo->lastInsertId();
        }

        $subtotal = 0; $item_disc = 0;
        foreach ($items as $it) {
            $qty  = (int)$it['qty'];
            $sp   = (float)$it['selling_price'];
            $isc  = (float)($it['item_discount'] ?? 0);
            $cp   = (float)$it['cost_price'];
            $saleType = $it['sale_type'] ?? 'Full Sheet';
            $width = (float)($it['width'] ?? 0);
            $height = (float)($it['height'] ?? 0);
            $totalSqft = (float)($it['total_sqft'] ?? 0);
            $category = $it['category'] ?? 'Glass';
            
            if ($category === 'Glass') {
                $calculated_discount = $totalSqft * $isc;
                $lt = ($totalSqft * $sp) - $calculated_discount;
            } else {
                $calculated_discount = $qty * $isc;
                $lt = ($qty * $sp) - $calculated_discount;
            }
            
            $subtotal += $lt;
            $item_disc += $calculated_discount;

            $stmt = $pdo->prepare("SELECT * FROM shop_inventory WHERE item_id = ? AND item_source = ?");
            $stmt->execute([$it['item_id'], $it['item_source']]);
            $shop = $stmt->fetch();
            if (!$shop) throw new Exception("Item not found in shop inventory.");

            $category = $it['category'] ?? 'Glass';
            $saleType = $it['sale_type'] ?? 'Complete Sheets';
            $deductFrom = $it['deduct_from'] ?? 'Full Sheets';

            if ($category === 'Other') {
                if ($shop['full_sheets_qty'] < $qty) throw new Exception("Insufficient stock for " . $it['brand_name']);
                $pdo->prepare("UPDATE shop_inventory SET full_sheets_qty = full_sheets_qty - ? WHERE id = ?")->execute([$qty, $shop['id']]);
                $saleType = 'Other Item';
            } else {
                if ($saleType === 'Complete Sheets') {
                    if ($shop['full_sheets_qty'] < $qty) throw new Exception("Insufficient full sheets for " . $it['brand_name']);
                    $pdo->prepare("UPDATE shop_inventory SET full_sheets_qty = full_sheets_qty - ? WHERE id = ?")->execute([$qty, $shop['id']]);
                } else {
                    // Partial Sheets
                    if ($deductFrom === 'Partial Leftovers') {
                        if ($shop['partial_sqft_qty'] < $totalSqft) throw new Exception("Insufficient partial leftovers for " . $it['brand_name']);
                        $pdo->prepare("UPDATE shop_inventory SET partial_sqft_qty = partial_sqft_qty - ? WHERE id = ?")->execute([$totalSqft, $shop['id']]);
                    } else {
                        // Deduct from Full Sheets: Break one fresh sheet per quantity requested
                        $sheets_to_break = $qty; 
                        if ($shop['full_sheets_qty'] < $sheets_to_break) throw new Exception("Insufficient stock to break {$sheets_to_break} full sheets for " . $it['brand_name']);
                        
                        // Leftover = (Fresh Sheets * Sqft/Sheet) - Used Sqft
                        $leftover_sqft = ($sheets_to_break * $shop['sqft_per_sheet']) - $totalSqft;
                        
                        $pdo->prepare("UPDATE shop_inventory SET full_sheets_qty = full_sheets_qty - ?, partial_sqft_qty = partial_sqft_qty + ? WHERE id = ?")
                            ->execute([$sheets_to_break, $leftover_sqft, $shop['id']]);
                    }
                }
            }

            $pdo->prepare("INSERT INTO pos_sale_items (sale_id, item_id, item_source, category, sale_type, deduct_from, width, height, total_sqft, qty, cost_price, selling_price, item_discount, line_total) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$sale_id, $it['item_id'], $it['item_source'], $category, $saleType, $deductFrom, $width, $height, $totalSqft, $qty, $cp, $sp, $isc, $lt]);
        }

        $bill_disc_val = ($bill_disc_t === 'percent') ? ($subtotal * $bill_disc / 100) : $bill_disc;
        $grand_total   = max(0, $subtotal - $bill_disc_val);

        $bank_id = !empty($_POST['bank_id']) ? (int)$_POST['bank_id'] : null;
        $cheque_no = !empty($_POST['cheque_number']) ? $_POST['cheque_number'] : null;
        $payee_nm = !empty($_POST['payee_name']) ? $_POST['payee_name'] : null;

        $manual_cust = $_POST['manual_customer_name'] ?? null;
        $pdo->prepare("UPDATE pos_sales SET subtotal=?, item_discount=?, bill_discount=?, bill_discount_type=?, grand_total=?, sale_date=?, customer_id=?, manual_customer_name=?, payment_method=?, cheque_number=?, payee_name=?, bank_id=? WHERE id=?")
            ->execute([$subtotal, $item_disc, $bill_disc_val, $bill_disc_t, $grand_total, $sale_date, $customer_id, $manual_cust, $pay_method, $cheque_no, $payee_nm, $bank_id, $sale_id]);

        $pdo->commit();
        echo json_encode(['success'=>true,'sale_id'=>$sale_id]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// AJAX: save payment
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

        $row = $pdo->query("SELECT grand_total, (SELECT SUM(amount) FROM pos_sale_payments WHERE sale_id=$sale_id) as paid FROM pos_sales WHERE id=$sale_id")->fetch();
        $status = ($row && $row['paid'] >= $row['grand_total']) ? 'completed' : 'pending';
        $pmts = $pdo->query("SELECT DISTINCT payment_type FROM pos_sale_payments WHERE sale_id=$sale_id")->fetchAll(PDO::FETCH_COLUMN);
        $method = count($pmts)>1 ? 'Multiple' : ($pmts[0] ?? $type);
        $pdo->prepare("UPDATE pos_sales SET payment_status=?, payment_method=? WHERE id=?")->execute([$status, $method, $sale_id]);
        echo json_encode(['success'=>true,'payment_status'=>$status]);
    } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// AJAX: get sale
if ($action === 'get_pos_sale') {
    $id = (int)($_GET['id'] ?? 0);
    $sale = $pdo->query("SELECT ps.*, c.name as customer_name, c.contact_number, u.full_name as created_by_name FROM pos_sales ps LEFT JOIN customers c ON ps.customer_id=c.id JOIN users u ON ps.created_by=u.id WHERE ps.id=$id")->fetch(PDO::FETCH_ASSOC);
    if (!$sale) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
    $sale['items'] = $pdo->query("SELECT psi.*, si.brand_name, si.sqft_per_sheet, si.full_sheets_qty as available_full, si.partial_sqft_qty as available_partial FROM pos_sale_items psi LEFT JOIN shop_inventory si ON psi.item_id = si.item_id AND psi.item_source = si.item_source WHERE psi.sale_id=$id")->fetchAll(PDO::FETCH_ASSOC);
    $sale['payments'] = $pdo->query("SELECT psp.*, b.name as bank_name FROM pos_sale_payments psp LEFT JOIN banks b ON psp.bank_id=b.id WHERE psp.sale_id=$id ORDER BY psp.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $sale['total_paid'] = array_sum(array_column($sale['payments'], 'amount'));
    echo json_encode(['success'=>true,'data'=>$sale]); exit;
}

// AJAX: delete sale
if ($action === 'delete_pos_sale') {
    try {
        $pdo->beginTransaction();
        $id = (int)$_POST['id'];
        $old = $pdo->prepare("SELECT item_id, item_source, qty, total_sqft, sale_type FROM pos_sale_items WHERE sale_id=?");
        $old->execute([$id]);
        foreach ($old->fetchAll() as $oi) {
            if ($oi['sale_type'] === 'Full Sheet') {
                $pdo->prepare("UPDATE shop_inventory SET full_sheets_qty = full_sheets_qty + ? WHERE item_id = ? AND item_source = ?")
                    ->execute([$oi['qty'], $oi['item_id'], $oi['item_source']]);
            } else {
                $pdo->prepare("UPDATE shop_inventory SET partial_sqft_qty = partial_sqft_qty + ? WHERE item_id = ? AND item_source = ?")
                    ->execute([$oi['total_sqft'], $oi['item_id'], $oi['item_source']]);
            }
        }
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
        .table-header { background: #1e293b; color: white; font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; }
        .custom-scroll::-webkit-scrollbar { width: 6px; } .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .dropdown-results { background: white !important; opacity: 1 !important; }
        .dropdown-results div { padding: 10px 14px; transition: all 0.2s; border-bottom: 1px solid #f1f5f9; cursor: pointer; }
        .dropdown-results div:hover { background: #f8fafc; color: #0891b2; }
    </style>
</head>
<body class="flex flex-col">
<header class="glass-header sticky top-0 z-40 py-3 sm:py-4">
    <div class="px-3 sm:px-5 flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex items-center space-x-3 md:space-x-5 self-start sm:self-auto">
            <a href="dashboard.php" class="text-slate-800 hover:text-cyan-600 transition-colors p-2 rounded-2xl hover:bg-slate-100">
                <i class="fa-solid fa-arrow-left text-base sm:text-lg"></i>
            </a>
            <div>
                <h1 class="text-lg font-black text-slate-900 font-['Outfit'] tracking-tight">Point of Sale</h1>
                <p class="hidden md:block text-[9px] uppercase font-black text-slate-500 tracking-widest mt-0.5">Direct Sales Terminal</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="pos_sales_history.php" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-xl font-bold text-[11px] uppercase tracking-widest transition-all shadow-lg shadow-indigo-600/20 flex items-center gap-2">
                <i class="fa-solid fa-clock-rotate-left"></i><span class="hidden sm:inline">History</span>
            </a>
        </div>
    </div>
</header>
<main class="px-3 sm:px-5 py-6 sm:py-8 w-full">
  <div class="glass-card p-4 sm:p-5 mb-4 flex flex-col min-h-[calc(100vh-170px)]" id="sale-form-card">
    <div class="flex flex-col md:flex-row gap-4 mb-6 items-start md:items-center justify-between">
      <div class="flex items-center gap-4">
        <div>
          <label class="text-[10px] uppercase font-black text-slate-700 mb-1.5 ml-1 block tracking-widest">Sale Date</label>
          <input type="date" id="sale_date" class="input-glass h-[40px] text-xs" value="<?php echo date('Y-m-d'); ?>">
        </div>
      </div>
    </div>
    <div class="mb-5">
      <label class="text-[10px] uppercase font-black text-slate-700 mb-1.5 ml-1 block tracking-widest">Customer</label>
      <div class="relative max-w-lg">
        <i class="fa-solid fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-[10px]"></i>
        <input type="text" id="cust_search" placeholder="Search customer..." class="input-glass w-full h-[40px] pl-9 text-xs"
               oninput="searchCustomer(this.value)" autocomplete="off">
        <input type="hidden" id="selected_customer_id">
        <div id="cust_results" class="dropdown-results absolute w-full mt-1 border border-slate-200 rounded-xl shadow-2xl z-[100] hidden overflow-hidden"></div>
      </div>
    </div>
    <div class="mb-4">
      <label class="text-[10px] uppercase font-black text-slate-700 tracking-widest mb-1.5 block">Items Search</label>
      <div class="relative max-w-lg mb-4">
        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-[10px]"></i>
        <input type="text" id="item_search" placeholder="Search brands..." class="input-glass w-full h-[40px] pl-9 text-xs"
               oninput="searchItems(this.value)" autocomplete="off">
        <div id="item_results" class="dropdown-results absolute w-full mt-1 border border-slate-200 rounded-xl shadow-2xl z-[100] hidden overflow-hidden max-h-72 overflow-y-auto"></div>
      </div>
      <div class="overflow-x-auto rounded-2xl border border-slate-100 no-scrollbar">
        <table class="w-full text-left min-w-[900px]" id="items-table">
          <thead>
            <tr class="table-header">
              <th class="px-4 py-3 text-[12px]">Item Details</th>
              <th class="px-4 py-3 text-[12px]">Selling Price</th>
              <th class="px-4 py-3 text-[12px]">Size</th>
              <th class="px-4 py-3 text-[12px]">Deduct From</th>
              <th class="px-4 py-3 text-[12px] text-center">Qty</th>
              <th class="px-4 py-3 text-[12px]">Sqft</th>
              <th class="px-4 py-3 text-[12px]">Disc / SQFT</th>
              <th class="px-4 py-3 text-[12px]">Line Total</th>
              <th class="px-4 py-3 text-center text-[12px]">Del</th>
            </tr>
          </thead>
          <tbody id="items-body" class="divide-y divide-slate-100"></tbody>
        </table>
      </div>
    </div>
    <div class="mt-auto glass-card p-4 border-slate-200 flex flex-col lg:flex-row items-center justify-between gap-4">
      <div class="flex flex-col gap-2 w-full lg:w-auto">
        <label class="text-[11px] uppercase font-black text-slate-600 tracking-widest ml-1">Bill Discount</label>
        <div class="flex items-center gap-1.5">
          <input type="number" min="0" step="0.01" id="bill_disc_val" placeholder="Custom" class="input-glass w-24 h-[34px] text-[10px]" onchange="calcTotals()">
          <select id="bill_disc_type" class="input-glass h-[34px] text-[10px] py-1 px-1" onchange="calcTotals()">
            <option value="fixed">LKR</option>
            <option value="percent">%</option>
          </select>
        </div>
      </div>
      <div class="flex flex-col sm:flex-row items-center gap-6 w-full lg:w-auto justify-between lg:justify-end">
        <div class="flex gap-4 items-center">
          <div class="text-right">
            <p class="text-[11px] font-black text-slate-500 uppercase tracking-widest">Grand Total</p>
            <p id="disp-grand" class="text-2xl font-black text-emerald-600">LKR 0.00</p>
          </div>
        </div>
        <button onclick="openPaymentModal()" class="bg-slate-900 hover:bg-black text-white px-6 py-3 rounded-xl font-black text-[10px] uppercase tracking-widest shadow-lg transition-all active:scale-95">Save Sale</button>
      </div>
    </div>
  </div>
</main>

<!-- Payment Modal -->
<div id="payment-modal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[200] hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden transform transition-all">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <h3 class="font-black text-slate-800 text-lg">Complete Sale</h3>
            <button onclick="closePaymentModal()" class="text-slate-400 hover:text-rose-500 transition-colors"><i class="fa-solid fa-xmark text-xl"></i></button>
        </div>
        <div class="p-6">
            <div class="mb-6">
                <p class="text-[10px] uppercase font-black text-slate-500 tracking-widest mb-1">Grand Total</p>
                <p id="modal-grand-total" class="text-3xl font-black text-emerald-600">LKR 0.00</p>
            </div>
            
            <div class="mb-6">
                <label class="text-[10px] uppercase font-black text-slate-700 mb-2 block tracking-widest">Payment Method</label>
                <div class="grid grid-cols-4 gap-2">
                    <label class="cursor-pointer">
                        <input type="radio" name="payment_method" value="Cash" class="peer hidden" checked onchange="togglePaymentFields()">
                        <div class="border-2 border-slate-200 peer-checked:border-emerald-500 peer-checked:bg-emerald-50 rounded-xl p-2 text-center transition-all h-full flex flex-col justify-center items-center">
                            <i class="fa-solid fa-money-bill-wave text-base mb-1 peer-checked:text-emerald-600 text-slate-400 block"></i>
                            <span class="text-[9px] font-black uppercase tracking-widest peer-checked:text-emerald-700 text-slate-500">Cash</span>
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="payment_method" value="Account Transfer" class="peer hidden" onchange="togglePaymentFields()">
                        <div class="border-2 border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-xl p-2 text-center transition-all h-full flex flex-col justify-center items-center">
                            <i class="fa-solid fa-building-columns text-base mb-1 peer-checked:text-indigo-600 text-slate-400 block"></i>
                            <span class="text-[9px] font-black uppercase tracking-widest peer-checked:text-indigo-700 text-slate-500">Bank</span>
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="payment_method" value="Cheque" class="peer hidden" onchange="togglePaymentFields()">
                        <div class="border-2 border-slate-200 peer-checked:border-cyan-500 peer-checked:bg-cyan-50 rounded-xl p-2 text-center transition-all h-full flex flex-col justify-center items-center">
                            <i class="fa-solid fa-money-check-pen text-base mb-1 peer-checked:text-cyan-600 text-slate-400 block"></i>
                            <span class="text-[9px] font-black uppercase tracking-widest peer-checked:text-cyan-700 text-slate-500">Cheque</span>
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="payment_method" value="Later Payment" class="peer hidden" onchange="togglePaymentFields()">
                        <div class="border-2 border-slate-200 peer-checked:border-amber-500 peer-checked:bg-amber-50 rounded-xl p-2 text-center transition-all h-full flex flex-col justify-center items-center">
                            <i class="fa-solid fa-clock-rotate-left text-base mb-1 peer-checked:text-amber-600 text-slate-400 block"></i>
                            <span class="text-[9px] font-black uppercase tracking-widest peer-checked:text-amber-700 text-slate-500">Pay Later</span>
                        </div>
                    </label>
                </div>
            </div>

            <div id="cheque-fields" class="hidden mb-6">
                <label class="text-[10px] uppercase font-black text-slate-700 mb-1 block">Cheque Number</label>
                <input type="text" id="cheque_number" class="input-glass w-full h-[40px] text-xs mb-3" placeholder="Enter cheque number">
                <label class="text-[10px] uppercase font-black text-slate-700 mb-1 block">Payee Name</label>
                <input type="text" id="payee_name" class="input-glass w-full h-[40px] text-xs" placeholder="Enter payee name">
            </div>

            <div id="bank-fields" class="hidden mb-6 relative">
                <label class="text-[10px] uppercase font-black text-slate-700 mb-1 block">Select Bank Account</label>
                <input type="text" id="bank_search" class="input-glass w-full h-[40px] text-xs" placeholder="Search bank..." oninput="searchBank(this.value)">
                <input type="hidden" id="selected_bank_id">
                <div id="bank_results" class="dropdown-results absolute w-full mt-1 border border-slate-200 rounded-xl shadow-2xl z-[200] hidden overflow-hidden bg-white max-h-48 overflow-y-auto"></div>
                
                <div id="new-bank-fields" class="hidden mt-3 p-3 bg-slate-50 border border-slate-200 rounded-xl">
                    <p class="text-[10px] font-bold text-slate-500 mb-2">Bank not found. Create new:</p>
                    <input type="text" id="new_bank_name" class="input-glass w-full h-[34px] text-xs mb-2" placeholder="Bank Name">
                    <input type="text" id="new_bank_acc_no" class="input-glass w-full h-[34px] text-xs mb-2" placeholder="Account Number">
                    <input type="text" id="new_bank_acc_name" class="input-glass w-full h-[34px] text-xs mb-2" placeholder="Account Name">
                    <button onclick="createBank()" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white text-[10px] py-2 rounded-lg font-bold">Save Bank</button>
                </div>
            </div>
            
            <button onclick="confirmAndSaveSale()" class="w-full bg-slate-900 hover:bg-black text-white py-4 rounded-xl font-black text-xs uppercase tracking-widest shadow-xl transition-all active:scale-95 flex items-center justify-center gap-2">
                <i class="fa-solid fa-check"></i> Confirm Payment & Save
            </button>
        </div>
    </div>
</div>

<script>
let saleItems = [];
const editData = <?php echo json_encode($editSaleData); ?>;

window.onload = () => {
    if (editData) {
        document.getElementById('sale_date').value = editData.sale_date;
        if (editData.customer_id) {
            document.getElementById('selected_customer_id').value = editData.customer_id;
            document.getElementById('cust_search').value = editData.customer_name + ' (' + editData.contact_number + ')';
        }
        document.getElementById('bill_disc_val').value = editData.bill_discount;
        document.getElementById('bill_disc_type').value = editData.bill_discount_type;
        
        saleItems = editData.items.map(it => ({
            rowId: 'edit_' + it.id,
            item_id: it.item_id,
            item_source: it.item_source,
            brand_name: it.brand_name,
            category: it.category,
            cost_price: parseFloat(it.cost_price),
            selling_price: parseFloat(it.selling_price),
            sqft_per_sheet: parseFloat(it.sqft_per_sheet || 0),
            qty: parseInt(it.qty),
            sale_type: it.sale_type,
            deduct_from: it.deduct_from,
            width: parseFloat(it.width || 0),
            height: parseFloat(it.height || 0),
            total_sqft: parseFloat(it.total_sqft || 0),
            item_discount: parseFloat(it.item_discount || 0),
            line_total: parseFloat(it.line_total),
            available_full: parseInt(it.available_full || 0),
            available_partial: parseFloat(it.available_partial || 0)
        }));
        renderItemsTable();
    }
};

let lastItemResults = [];
let itemSearchTimeout = null;

function searchCustomer(term) {
  const res = document.getElementById('cust_results');
  if (term.length < 1) { res.classList.add('hidden'); return; }
  fetch(`?action=search_customer&term=${encodeURIComponent(term)}`)
    .then(r=>r.json()).then(data => {
      let html = '';
      data.forEach(c => {
        html += `<div onmousedown="selectCustomer(${c.id}, '${c.name.replace(/'/g, "\\'")}')">${c.name}</div>`;
      });
      res.innerHTML = html; res.classList.remove('hidden');
    });
}

function selectCustomer(id, name) {
  document.getElementById('selected_customer_id').value = id;
  document.getElementById('cust_search').value = name;
  document.getElementById('cust_results').classList.add('hidden');
}

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
          html += `<div onmousedown="addItem(${it.id})" class="hover:bg-slate-50 cursor-pointer transition-colors border-b border-slate-100 last:border-0">
            <div class="flex items-center justify-between p-3">
              <div>
                <p class="text-sm font-black text-slate-800">${it.brand_name}</p>
                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">${it.category}</p>
              </div>
              <div class="text-right">
                ${it.category === 'Glass' ? `
                    <p class="text-[11px] font-bold text-slate-700">Sheets: <span class="text-indigo-600">${it.full_sheets_qty}</span></p>
                    <p class="text-[10px] font-bold text-slate-500">${parseFloat(it.partial_sqft_qty).toFixed(2)} FT²</p>
                    <div class="mt-1 border-t border-slate-100 pt-1">
                        <p class="text-[9px] font-bold text-slate-400">Buy: Rs. ${parseFloat(it.cost_price_per_sqft).toLocaleString()} /SQFT</p>
                        <p class="text-[10px] font-black text-emerald-600">Sell: Rs. ${parseFloat(it.selling_price_per_sqft).toLocaleString()} /SQFT</p>
                    </div>
                ` : `
                    <p class="text-[11px] font-bold text-slate-700">Qty: <span class="text-indigo-600">${it.full_sheets_qty}</span></p>
                    <div class="mt-1 border-t border-slate-100 pt-1">
                        <p class="text-[9px] font-bold text-slate-400">Buy: Rs. ${parseFloat(it.true_cost_price || it.cost_price_per_sqft).toLocaleString()}</p>
                        <p class="text-[10px] font-black text-emerald-600">Sell: Rs. ${parseFloat(it.selling_price_per_sqft).toLocaleString()}</p>
                    </div>
                `}
              </div>
            </div>
          </div>`;
        });
        res.innerHTML = html; res.classList.remove('hidden');
      });
  }, 200);
}

function addItem(shopId) {
  const it = lastItemResults.find(i => i.id == shopId);
  if (!it) return;
  document.getElementById('item_results').classList.add('hidden');
  document.getElementById('item_search').value = '';
  const rowId = Date.now() + '' + Math.floor(Math.random()*100);
  saleItems.push({
    rowId, item_id:it.item_id, item_source: it.item_source, brand_name:it.brand_name, category: it.category,
    cost_price:parseFloat(it.true_cost_price || it.cost_price_per_sqft), selling_price:parseFloat(it.selling_price_per_sqft),
    sqft_per_sheet: parseFloat(it.sqft_per_sheet), qty:1, sale_type: 'Complete Sheets', deduct_from: 'Full Sheets', width:0, height:0,
    fraction: null,
    total_sqft: (it.category === 'Glass' ? parseFloat(it.sqft_per_sheet) : 0), item_discount:0, line_total: parseFloat(it.selling_price_per_sqft) * (it.category === 'Glass' ? parseFloat(it.sqft_per_sheet) : 1),
    available_full: it.full_sheets_qty, available_partial: it.partial_sqft_qty
  });
  renderItemsTable();
}

function renderItemsTable() {
  const body = document.getElementById('items-body');
  body.innerHTML = '';
  saleItems.forEach(it => {
    const row = document.createElement('tr');
    row.className = 'border-b border-slate-100';
    let itemDetailsUI = '';
    let sizeUI = '';
    let deductFromUI = '';
    let qtyUI = '';
    
    if (it.category === 'Glass') {
      itemDetailsUI = `
        <div class="mt-2">
            <select class="input-glass h-[28px] text-[10px] py-0 px-2 w-max border-indigo-200 bg-indigo-50/30 text-indigo-700 font-bold" onchange="updateItem('${it.rowId}', 'sale_type', this.value)">
                <option value="Complete Sheets" ${it.sale_type==='Complete Sheets'?'selected':''}>Complete Sheets</option>
                <option value="Partial Sheets" ${it.sale_type==='Partial Sheets'?'selected':''}>Partial Sheets</option>
            </select>
        </div>
      `;
      
      if (it.sale_type === 'Complete Sheets') {
          sizeUI = `<span class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-2 block">Standard Full Sheet</span>`;
          deductFromUI = `<span class="text-[10px] text-slate-300 font-bold mt-2 block">-</span>`;
          qtyUI = `
              <div class="flex flex-col items-center gap-1 mt-1">
                  <input type="number" class="input-glass w-20 h-[34px] text-center font-bold" value="${it.qty}" onchange="updateItem('${it.rowId}', 'qty', this.value)">
                  <span class="text-[9px] text-slate-400 font-bold uppercase w-max">${it.available_full} Avl</span>
              </div>
          `;
      } else {
          sizeUI = `
          <div class="flex flex-col gap-2 mt-1">
              <div class="flex items-center gap-1">
                  <button onclick="updateItem('${it.rowId}', 'fraction', 0.5)" class="px-2 py-1.5 text-[9px] font-bold rounded transition-colors ${it.fraction===0.5?'bg-indigo-600 text-white':'bg-indigo-100 text-indigo-700 hover:bg-indigo-200'}">1/2</button>
                  <button onclick="updateItem('${it.rowId}', 'fraction', 0.25)" class="px-2 py-1.5 text-[9px] font-bold rounded transition-colors ${it.fraction===0.25?'bg-indigo-600 text-white':'bg-indigo-100 text-indigo-700 hover:bg-indigo-200'}">1/4</button>
                  <button onclick="updateItem('${it.rowId}', 'fraction', 0.75)" class="px-2 py-1.5 text-[9px] font-bold rounded transition-colors ${it.fraction===0.75?'bg-indigo-600 text-white':'bg-indigo-100 text-indigo-700 hover:bg-indigo-200'}">3/4</button>
              </div>
              <div class="flex gap-1 items-center">
                  <input type="number" placeholder="W" class="input-glass w-12 h-[30px] text-[10px]" value="${it.width || ''}" onchange="updateItem('${it.rowId}', 'width', this.value)">
                  <span class="text-slate-400 text-[10px]">x</span>
                  <input type="number" placeholder="H" class="input-glass w-12 h-[30px] text-[10px]" value="${it.height || ''}" onchange="updateItem('${it.rowId}', 'height', this.value)">
              </div>
          </div>
          `;
          deductFromUI = `
             <div class="mt-2">
                 <select class="input-glass h-[34px] text-[10px] py-0 px-2 w-full font-bold" onchange="updateItem('${it.rowId}', 'deduct_from', this.value)">
                     <option value="Full Sheets" ${it.deduct_from==='Full Sheets'?'selected':''}>Full Sheets (${it.available_full} Avl)</option>
                     <option value="Partial Leftovers" ${it.deduct_from==='Partial Leftovers'?'selected':''}>Leftovers (${parseFloat(it.available_partial).toFixed(2)} FT²)</option>
                 </select>
             </div>
          `;
          qtyUI = `
              <div class="flex justify-center mt-2">
                  <input type="number" class="input-glass w-20 h-[34px] text-center font-bold" value="${it.qty}" onchange="updateItem('${it.rowId}', 'qty', this.value)">
              </div>
          `;
      }
    } else {
      sizeUI = `<span class="text-[10px] text-slate-400 font-bold mt-2 block">N/A</span>`;
      deductFromUI = `<span class="text-[10px] text-slate-300 font-bold mt-2 block">-</span>`;
      qtyUI = `
          <div class="flex flex-col items-center gap-1 mt-1">
              <input type="number" class="input-glass w-20 h-[34px] text-center font-bold" value="${it.qty}" onchange="updateItem('${it.rowId}', 'qty', this.value)">
              <span class="text-[9px] text-slate-400 font-bold uppercase w-max">${it.available_full} Avl</span>
          </div>
      `;
    }
    
    row.innerHTML = `
      <td class="px-4 py-3 align-top">
        <div class="flex flex-col">
          <span class="text-sm font-black text-slate-800">${it.brand_name}</span>
          <div class="flex items-center gap-2 mt-0.5">
             <span class="text-[9px] uppercase font-black text-slate-400 tracking-widest">${it.category}</span>
             <span class="text-[9px] font-bold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded">Buy: LKR ${it.cost_price.toLocaleString()}</span>
          </div>
          ${itemDetailsUI}
        </div>
      </td>
      <td class="px-4 py-3 align-top"><input type="number" class="input-glass w-28 h-[34px] text-right font-bold text-emerald-600 mt-2" value="${it.selling_price}" onchange="updateItem('${it.rowId}', 'selling_price', this.value)"></td>
      <td class="px-4 py-3 align-top">${sizeUI}</td>
      <td class="px-4 py-3 align-top min-w-[140px]">${deductFromUI}</td>
      <td class="px-4 py-3 align-top">${qtyUI}</td>
      <td class="px-4 py-3 align-top"><span class="text-xs font-bold text-indigo-600 mt-3 block">${it.category === 'Glass' ? parseFloat(it.total_sqft).toFixed(2) + ' FT²' : '-'}</span></td>
      <td class="px-4 py-3 align-top"><input type="number" class="input-glass w-20 h-[34px] text-right font-bold text-rose-500 mt-2" value="${it.item_discount}" onchange="updateItem('${it.rowId}', 'item_discount', this.value)"></td>
      <td class="px-4 py-3 align-top">
          <span class="text-sm font-black text-slate-800 mt-2 block">LKR ${it.line_total.toLocaleString()}</span>
          ${it.calculated_discount > 0 ? `<span class="text-[9px] font-bold text-rose-500 block uppercase tracking-widest mt-0.5">- LKR ${it.calculated_discount.toLocaleString()} Disc</span>` : ''}
      </td>
      <td class="px-4 py-3 align-top text-center"><button onclick="removeItem('${it.rowId}')" class="w-8 h-8 rounded-full bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white transition-colors mt-1"><i class="fa-solid fa-trash text-[10px]"></i></button></td>
    `;
    body.appendChild(row);
  });
  calcTotals();
}

function updateItem(rowId, field, val) {
  const it = saleItems.find(i=>i.rowId==rowId);
  if (!it) return;
  
  if (field === 'sale_type') {
    it.sale_type = val;
    it.qty = 1; it.width = 0; it.height = 0; it.fraction = null;
    it.total_sqft = (val==='Complete Sheets') ? it.sqft_per_sheet : 0;
  } else if (field === 'deduct_from') {
    it.deduct_from = val;
  } else if (field === 'fraction') {
    it.fraction = parseFloat(val);
    it.width = 0; it.height = 0;
    it.total_sqft = it.sqft_per_sheet * it.fraction;
  } else if (field === 'width' || field === 'height') {
    it[field] = parseFloat(val) || 0;
    it.fraction = null;
    it.total_sqft = (it.width * it.height) / 144;
  } else {
    it[field] = isNaN(parseFloat(val)) ? val : parseFloat(val);
  }

  // Recalculate line total based on category
  if (it.category === 'Glass') {
      if (it.sale_type === 'Complete Sheets') {
          it.total_sqft = it.sqft_per_sheet * it.qty;
          it.deduct_from = 'Full Sheets';
      } else {
          // Partial sheets
          let sqft_per_piece = 0;
          if (it.fraction) {
              sqft_per_piece = it.sqft_per_sheet * it.fraction;
          } else if (it.width && it.height) {
              sqft_per_piece = (it.width * it.height) / 144;
          }
          it.total_sqft = sqft_per_piece * it.qty;
      }
      
      it.calculated_discount = it.total_sqft * it.item_discount;
      it.line_total = (it.total_sqft * it.selling_price) - it.calculated_discount;
  } else {
      it.total_sqft = 0; // not relevant for Other items visually
      it.calculated_discount = it.qty * it.item_discount;
      it.line_total = (it.qty * it.selling_price) - it.calculated_discount;
  }
  renderItemsTable();
}

function removeItem(rowId) {
  saleItems = saleItems.filter(i=>i.rowId!==rowId);
  renderItemsTable();
}

function calcTotals() {
  let sub = 0;
  saleItems.forEach(it => sub += it.line_total);
  const disc = parseFloat(document.getElementById('bill_disc_val').value) || 0;
  const type = document.getElementById('bill_disc_type').value;
  const bd = type==='percent' ? (sub * disc/100) : disc;
  document.getElementById('disp-grand').textContent = 'LKR ' + Math.max(0, sub - bd).toLocaleString();
}

function openPaymentModal() {
  if (!saleItems.length) { alert('No items added'); return; }
  document.getElementById('modal-grand-total').textContent = document.getElementById('disp-grand').textContent;
  document.getElementById('payment-modal').classList.remove('hidden');
  document.getElementById('payment-modal').classList.add('flex');
}

function closePaymentModal() {
  document.getElementById('payment-modal').classList.add('hidden');
  document.getElementById('payment-modal').classList.remove('flex');
}

function togglePaymentFields() {
  const method = document.querySelector('input[name="payment_method"]:checked').value;
  document.getElementById('cheque-fields').classList.toggle('hidden', method !== 'Cheque');
  document.getElementById('bank-fields').classList.toggle('hidden', method !== 'Account Transfer');
}

let bankSearchTimeout = null;
function searchBank(term) {
  const res = document.getElementById('bank_results');
  const newFields = document.getElementById('new-bank-fields');
  
  if (term.length < 1) { 
      res.classList.add('hidden'); 
      newFields.classList.add('hidden');
      return; 
  }
  
  clearTimeout(bankSearchTimeout);
  bankSearchTimeout = setTimeout(() => {
    fetch(`?action=search_bank&term=${encodeURIComponent(term)}`)
      .then(r=>r.json()).then(data => {
        if (!data.length) { 
            res.classList.add('hidden');
            newFields.classList.remove('hidden');
            document.getElementById('new_bank_name').value = term;
            return; 
        }
        
        let html = '';
        data.forEach(b => {
          html += `<div onmousedown="selectBank(${b.id}, '${b.name}')" class="p-3 hover:bg-slate-50 cursor-pointer border-b border-slate-100 last:border-0 transition-colors">
            <p class="text-xs font-bold text-slate-800">${b.name}</p>
            <p class="text-[10px] text-slate-500">${b.account_number || ''} ${b.account_name ? ' - '+b.account_name : ''}</p>
          </div>`;
        });
        res.innerHTML = html;
        res.classList.remove('hidden');
        newFields.classList.add('hidden');
      });
  }, 300);
}

function selectBank(id, name) {
  document.getElementById('selected_bank_id').value = id;
  document.getElementById('bank_search').value = name;
  document.getElementById('bank_results').classList.add('hidden');
}

function createBank() {
  const name = document.getElementById('new_bank_name').value;
  const acc_no = document.getElementById('new_bank_acc_no').value;
  const acc_name = document.getElementById('new_bank_acc_name').value;
  
  if(!name) { alert('Bank name required'); return; }
  
  const fd = new FormData();
  fd.append('action', 'create_bank');
  fd.append('name', name);
  fd.append('acc_no', acc_no);
  fd.append('acc_name', acc_name);
  
  fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(res => {
    if(res.success) {
      selectBank(res.id, res.name);
      document.getElementById('new-bank-fields').classList.add('hidden');
      alert('Bank created and selected!');
    } else {
      alert('Failed to create bank');
    }
  });
}

function confirmAndSaveSale() {
  const method = document.querySelector('input[name="payment_method"]:checked').value;
  const fd = new FormData();
  
  if (method === 'Cheque') {
      const chequeNo = document.getElementById('cheque_number').value;
      if (!chequeNo) { alert('Cheque number required for cheque payments'); return; }
      fd.append('cheque_number', chequeNo);
      fd.append('payee_name', document.getElementById('payee_name').value);
  } else if (method === 'Account Transfer') {
      const bankId = document.getElementById('selected_bank_id').value;
      if (!bankId) { alert('Please select a bank for transfer payments'); return; }
      fd.append('bank_id', bankId);
  }
  
  fd.append('action', 'save_pos_sale');
  if (editData) fd.append('editing_id', editData.id);
  const custId = document.getElementById('selected_customer_id').value;
  const custManual = document.getElementById('cust_search').value;
  fd.append('customer_id', custId);
  if (!custId && custManual) {
      fd.append('manual_customer_name', custManual);
  }
  fd.append('sale_date', document.getElementById('sale_date').value);
  fd.append('items', JSON.stringify(saleItems));
  fd.append('bill_discount', document.getElementById('bill_disc_val').value);
  fd.append('bill_discount_type', document.getElementById('bill_disc_type').value);
  fd.append('payment_method', method); 
  
  fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(res => {
    if (res.success) { alert('Sale saved successfully'); location.reload(); }
    else alert(res.message);
  }).catch(e => {
    console.error(e);
    alert('An error occurred while saving.');
  });
}

function newSale() { location.reload(); }
</script>
</body>
</html>
