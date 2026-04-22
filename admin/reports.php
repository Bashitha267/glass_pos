<?php
require_once '../auth.php';
require_once '../config.php';
checkAuth();

if (!isAdmin()) {
    header('Location: ../sale/dashboard.php');
    exit;
}

// ---------------------------------------------------------------------------------------------------------------------
// FILTERS & AGGREGATION
// ---------------------------------------------------------------------------------------------------------------------

$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$month = $_GET['month'] ?? date('m');
$year = $_GET['year'] ?? date('Y');

$where = ["1=1"];
$params = [];

if ($start_date) {
    $where[] = "d.delivery_date >= ?";
    $params[] = $start_date;
}
if ($end_date) {
    $where[] = "d.delivery_date <= ?";
    $params[] = $end_date;
}
if ($month && $year && !$start_date && !$end_date) {
    $where[] = "MONTH(d.delivery_date) = ? AND YEAR(d.delivery_date) = ?";
    $params[] = $month;
    $params[] = $year;
}

$whereClause = implode(" AND ", $where);

// 1. Deliveries Count
$stmtDeliveries = $pdo->prepare("SELECT COUNT(*) FROM deliveries d WHERE $whereClause");
$stmtDeliveries->execute($params);
$total_deliveries = $stmtDeliveries->fetchColumn();

// 2. Sales (Unique Customers) Count
$stmtCustomers = $pdo->prepare("SELECT COUNT(DISTINCT dc.customer_id) FROM delivery_customers dc JOIN deliveries d ON dc.delivery_id = d.id WHERE $whereClause");
$stmtCustomers->execute($params);
$total_customers = $stmtCustomers->fetchColumn();

// 3. Earnings (Revenue)
$stmtEarnings = $pdo->prepare("SELECT SUM(dc.subtotal - dc.discount) FROM delivery_customers dc JOIN deliveries d ON dc.delivery_id = d.id WHERE $whereClause");
$stmtEarnings->execute($params);
$total_earnings = (float)$stmtEarnings->fetchColumn();

// 4. Total Expenses
$stmtExpenses = $pdo->prepare("SELECT SUM(de.amount) FROM delivery_expenses de JOIN deliveries d ON de.delivery_id = d.id WHERE $whereClause");
$stmtExpenses->execute($params);
$total_expenses = (float)$stmtExpenses->fetchColumn();

// 5. Cost of Goods (Profit calculation helper)
$stmtCost = $pdo->prepare("
    SELECT SUM(
        CASE 
            WHEN di.square_feet > 0 THEN (di.qty - COALESCE(di.damaged_qty,0)) * di.square_feet * di.cost_price 
            ELSE (di.qty - COALESCE(di.damaged_qty,0)) * di.cost_price 
        END
    ) 
    FROM delivery_items di 
    JOIN delivery_customers dc ON di.delivery_customer_id = dc.id 
    JOIN deliveries d ON dc.delivery_id = d.id 
    WHERE $whereClause
");
$stmtCost->execute($params);
$total_cost = (float)$stmtCost->fetchColumn();

// 6. Pending Payments
$stmtPending = $pdo->prepare("
    SELECT SUM(dc.subtotal - dc.discount - (SELECT COALESCE(SUM(amount), 0) FROM delivery_payments WHERE delivery_customer_id = dc.id)) 
    FROM delivery_customers dc 
    JOIN deliveries d ON dc.delivery_id = d.id 
    WHERE $whereClause
");
$stmtPending->execute($params);
$pending_payments = (float)$stmtPending->fetchColumn();

// 7. Most Sold Items (Pie Chart Data)
$stmtItems = $pdo->prepare("
    SELECT 
        CASE 
            WHEN di.item_source = 'container' THEN COALESCE(b.name, 'Unknown Brand')
            WHEN di.item_source = 'other' THEN COALESCE(opi.item_name, 'Other Item')
        END as brand_name, 
        SUM(di.qty) as total_qty 
    FROM delivery_items di 
    LEFT JOIN container_items ci ON di.item_source = 'container' AND di.item_id = ci.id
    LEFT JOIN brands b ON ci.brand_id = b.id
    LEFT JOIN other_purchase_items opi ON di.item_source = 'other' AND di.item_id = opi.id
    JOIN delivery_customers dc ON di.delivery_customer_id = dc.id 
    JOIN deliveries d ON dc.delivery_id = d.id 
    WHERE $whereClause 
    GROUP BY brand_name 
    ORDER BY total_qty DESC 
    LIMIT 10
");
$stmtItems->execute($params);
$items_data = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// NEW 8. Employee Salary Payments
$empWhere = ["status = 'paid'"];
$empParams = [];
if ($start_date) {
    $empWhere[] = "payment_date >= ?";
    $empParams[] = $start_date;
}
if ($end_date) {
    $empWhere[] = "payment_date <= ?";
    $empParams[] = $end_date;
}
if ($month && $year && !$start_date && !$end_date) {
    $empWhere[] = "salary_month = ? AND salary_year = ?";
    $empParams[] = (int)$month;
    $empParams[] = (int)$year;
}
$empWhereClause = implode(" AND ", $empWhere);
$stmtEmpPay = $pdo->prepare("SELECT SUM(salary_amount) FROM employee_salary_payments WHERE $empWhereClause");
$stmtEmpPay->execute($empParams);
$total_emp_payments = (float)$stmtEmpPay->fetchColumn();

// Include container costs in Total Expenses
$total_expenses += $total_cost;

// Calculate Delivery Profit (excluding employee salaries)
$profit = $total_earnings - $total_expenses;

// NEW 9. Total Payments Received
$stmtTotalPayments = $pdo->prepare("
    SELECT SUM(dp.amount) 
    FROM delivery_payments dp 
    JOIN delivery_customers dc ON dp.delivery_customer_id = dc.id 
    JOIN deliveries d ON dc.delivery_id = d.id 
    WHERE $whereClause
");
$stmtTotalPayments->execute($params);
$total_payments_got = (float)$stmtTotalPayments->fetchColumn();

// ── Define POS WHERE conditions before loop ──
$posWhere = ["1=1"];
$posParams = [];
if ($start_date) { $posWhere[] = "ps.sale_date >= ?"; $posParams[] = $start_date; }
if ($end_date)   { $posWhere[] = "ps.sale_date <= ?"; $posParams[] = $end_date; }
if ($month && $year && !$start_date && !$end_date) {
    $posWhere[] = "MONTH(ps.sale_date) = ? AND YEAR(ps.sale_date) = ?";
    $posParams[] = $month; $posParams[] = $year;
}
$posWhereClause = implode(" AND ", $posWhere);

// NEW 10. Bank Account Aggregates (In & Out Flow)
$stmtAllBanks = $pdo->query("SELECT id, name, account_number FROM banks");
$allBanks = $stmtAllBanks->fetchAll(PDO::FETCH_ASSOC);

$banks_data = [];
foreach ($allBanks as $b) {
    $bank_id = $b['id'];
    
    // RECEIVED: Deliveries
    $stmt1 = $pdo->prepare("SELECT COALESCE(SUM(dp.amount),0) FROM delivery_payments dp JOIN delivery_customers dc ON dp.delivery_customer_id = dc.id JOIN deliveries d ON dc.delivery_id = d.id WHERE dp.bank_id = ? AND $whereClause");
    $stmt1->execute(array_merge([$bank_id], $params));
    $rec1 = (float)$stmt1->fetchColumn();
    
    // RECEIVED: POS Sales
    $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(psp.amount),0) FROM pos_sale_payments psp JOIN pos_sales ps ON psp.sale_id = ps.id WHERE psp.bank_id = ? AND $posWhereClause");
    $stmt2->execute(array_merge([$bank_id], $posParams));
    $rec2 = (float)$stmt2->fetchColumn();
    
    $total_received = $rec1 + $rec2;
    
    // PAID: Containers
    $paid1 = 0; // Skipping container payments matching, schema omitted
    
    // PAID: Other Purchases
    $opWhere2 = ["opp.bank_id = ?"];
    $opParams2 = [$bank_id];
    if ($start_date) { $opWhere2[] = "op.purchase_date >= ?"; $opParams2[] = $start_date; }
    if ($end_date)   { $opWhere2[] = "op.purchase_date <= ?"; $opParams2[] = $end_date; }
    if ($month && $year && !$start_date && !$end_date) {
        $opWhere2[] = "MONTH(op.purchase_date) = ? AND YEAR(op.purchase_date) = ?";
        $opParams2[] = $month; $opParams2[] = $year;
    }
    $opWhere2Clause = implode(" AND ", $opWhere2);

    $stmt4 = $pdo->prepare("SELECT COALESCE(SUM(opp.amount),0) FROM other_purchase_payments opp JOIN other_purchases op ON opp.purchase_id = op.id WHERE $opWhere2Clause");
    $stmt4->execute($opParams2);
    $paid2 = (float)$stmt4->fetchColumn();
    
    $total_paid = $paid1 + $paid2;
    $leftover = $total_received - $total_paid;
    
    if ($total_received > 0 || $total_paid > 0) {
        $banks_data[] = [
            'bank_name' => $b['name'],
            'account_number' => $b['account_number'],
            'total_received' => $total_received,
            'total_paid' => $total_paid,
            'leftover' => $leftover
        ];
    }
}

// NEW 11. Payment Types Pie Chart Data
$stmtPayTypes = $pdo->prepare("
    SELECT dp.payment_type, SUM(dp.amount) as total_amount
    FROM delivery_payments dp
    JOIN delivery_customers dc ON dp.delivery_customer_id = dc.id
    JOIN deliveries d ON dc.delivery_id = d.id
    WHERE $whereClause
    GROUP BY dp.payment_type
");
$stmtPayTypes->execute($params);
$pay_types_data = $stmtPayTypes->fetchAll(PDO::FETCH_ASSOC);

// ── POS Sales Stats ───────────────────────────────────────────────────────────
$stmtPOSRev = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) FROM pos_sales ps WHERE $posWhereClause");
$stmtPOSRev->execute($posParams);
$pos_revenue = (float)$stmtPOSRev->fetchColumn();

$stmtPOSCost = $pdo->prepare("
    SELECT COALESCE(SUM(
        CASE 
            WHEN psi.category = 'Glass' THEN psi.total_sqft * psi.cost_price 
            ELSE psi.qty * psi.cost_price 
        END
    ),0) 
    FROM pos_sale_items psi 
    JOIN pos_sales ps ON psi.sale_id=ps.id 
    WHERE $posWhereClause
");
$stmtPOSCost->execute($posParams);
$pos_cost = (float)$stmtPOSCost->fetchColumn();
$pos_profit = $pos_revenue - $pos_cost;

// Calculate Overall Final Business Profit
    
// NEW: Other Purchases
$opWhere = ["1=1"];
$opParams = [];
if ($start_date) { $opWhere[] = "purchase_date >= ?"; $opParams[] = $start_date; }
if ($end_date)   { $opWhere[] = "purchase_date <= ?"; $opParams[] = $end_date; }
if ($month && $year && !$start_date && !$end_date) {
    $opWhere[] = "MONTH(purchase_date) = ? AND YEAR(purchase_date) = ?";
    $opParams[] = $month; $opParams[] = $year;
}
$opWhereClause = implode(" AND ", $opWhere);

$stmtOPExpenses = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) FROM other_purchases WHERE $opWhereClause");
$stmtOPExpenses->execute($opParams);
$total_other_purchases = (float)$stmtOPExpenses->fetchColumn();

// NEW: Monthly General Expenses (Overheads)
$oeWhere = ["1=1"];
$oeParams = [];
if ($start_date) { $oeWhere[] = "expense_date >= ?"; $oeParams[] = $start_date; }
if ($end_date)   { $oeWhere[] = "expense_date <= ?"; $oeParams[] = $end_date; }
if ($month && $year && !$start_date && !$end_date) {
    $oeWhere[] = "MONTH(expense_date) = ? AND YEAR(expense_date) = ?";
    $oeParams[] = $month; $oeParams[] = $year;
}
$oeWhereClause = implode(" AND ", $oeWhere);

$stmtOEExpenses = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM monthly_expenses WHERE $oeWhereClause");
$stmtOEExpenses->execute($oeParams);
$total_overhead_expenses = (float)$stmtOEExpenses->fetchColumn();

// 8. Other Incomes
$oiWhereClause = str_replace('expense_date', 'income_date', $oeWhereClause);
$stmtOIIncomes = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM monthly_incomes WHERE $oiWhereClause");
$stmtOIIncomes->execute($oeParams);
$total_other_incomes = (float)$stmtOIIncomes->fetchColumn();

// Business Profit = (Delivery Revenue - Delivery COGS - Delivery Expenses) + (POS Revenue - POS COGS) - Salaries - Overheads + Other Incomes
$total_business_profit = $profit + $pos_profit - $total_emp_payments - $total_overhead_expenses + $total_other_incomes;

// ====== EXCLUSIVE CURRENT MONTH PROFIT ======
$currM = date('m');
$currY = date('Y');

$currDelRev = (float)$pdo->query("SELECT COALESCE(SUM(dc.subtotal - dc.discount),0) FROM delivery_customers dc JOIN deliveries d ON dc.delivery_id = d.id WHERE MONTH(d.delivery_date) = $currM AND YEAR(d.delivery_date) = $currY")->fetchColumn();
$currDelExp = (float)$pdo->query("SELECT COALESCE(SUM(de.amount),0) FROM delivery_expenses de JOIN deliveries d ON de.delivery_id = d.id WHERE MONTH(d.delivery_date) = $currM AND YEAR(d.delivery_date) = $currY")->fetchColumn();
$currDelCost = (float)$pdo->query("
    SELECT COALESCE(SUM(
        CASE 
            WHEN di.square_feet > 0 THEN (di.qty - COALESCE(di.damaged_qty,0)) * di.square_feet * di.cost_price 
            ELSE (di.qty - COALESCE(di.damaged_qty,0)) * di.cost_price 
        END
    ),0) 
    FROM delivery_items di 
    JOIN delivery_customers dc ON di.delivery_customer_id = dc.id 
    JOIN deliveries d ON dc.delivery_id = d.id 
    WHERE MONTH(d.delivery_date) = $currM AND YEAR(d.delivery_date) = $currY
")->fetchColumn();
$currDelProfit = $currDelRev - ($currDelExp + $currDelCost);

$currPOSRev = (float)$pdo->query("SELECT COALESCE(SUM(grand_total),0) FROM pos_sales WHERE MONTH(sale_date) = $currM AND YEAR(sale_date) = $currY")->fetchColumn();
$currPOSCost = (float)$pdo->query("
    SELECT COALESCE(SUM(
        CASE 
            WHEN psi.category = 'Glass' THEN psi.total_sqft * psi.cost_price 
            ELSE psi.qty * psi.cost_price 
        END
    ),0) 
    FROM pos_sale_items psi 
    JOIN pos_sales ps ON psi.sale_id=ps.id 
    WHERE MONTH(ps.sale_date) = $currM AND YEAR(ps.sale_date) = $currY
")->fetchColumn();
$currPOSProfit = $currPOSRev - $currPOSCost;

$currEmp = (float)$pdo->query("SELECT COALESCE(SUM(salary_amount),0) FROM employee_salary_payments WHERE salary_month = $currM AND salary_year = $currY AND status='paid'")->fetchColumn();
$currOP = (float)$pdo->query("SELECT COALESCE(SUM(grand_total),0) FROM other_purchases WHERE MONTH(purchase_date) = $currM AND YEAR(purchase_date) = $currY")->fetchColumn();
$currOE = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM monthly_expenses WHERE MONTH(expense_date) = $currM AND YEAR(expense_date) = $currY")->fetchColumn();
$currOI = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM monthly_incomes WHERE MONTH(income_date) = $currM AND YEAR(income_date) = $currY")->fetchColumn();

$currMonthProfit = $currDelProfit + $currPOSProfit - $currEmp - $currOE + $currOI;

$stmtPOSItems = $pdo->prepare("
    SELECT 
        CASE 
            WHEN psi.item_source = 'container' THEN COALESCE(b.name, 'Unknown Brand')
            WHEN psi.item_source = 'other' THEN COALESCE(opi.item_name, 'Other Item')
        END as brand_name, 
        SUM(psi.qty) as total_qty,
        SUM(psi.line_total - (CASE WHEN psi.category = 'Glass' THEN psi.total_sqft * psi.cost_price ELSE psi.qty * psi.cost_price END)) as brand_profit
    FROM pos_sale_items psi
    LEFT JOIN container_items ci ON psi.item_source = 'container' AND psi.item_id = ci.id
    LEFT JOIN brands b ON ci.brand_id = b.id
    LEFT JOIN other_purchase_items opi ON psi.item_source = 'other' AND psi.item_id = opi.id
    JOIN pos_sales ps ON psi.sale_id = ps.id
    WHERE $posWhereClause
    GROUP BY brand_name 
    ORDER BY brand_profit DESC 
    LIMIT 10");
$stmtPOSItems->execute($posParams);
$pos_items_data = $stmtPOSItems->fetchAll(PDO::FETCH_ASSOC);

$stmtPOSPayTypes = $pdo->prepare("
    SELECT payment_type, SUM(amount) as total_amount
    FROM pos_sale_payments psp
    JOIN pos_sales ps ON psp.sale_id=ps.id
    WHERE $posWhereClause
    GROUP BY payment_type");
$stmtPOSPayTypes->execute($posParams);
$pos_pay_types = $stmtPOSPayTypes->fetchAll(PDO::FETCH_ASSOC);
// ─────────────────────────────────────────────────────────────────────────────

if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=business_report_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Delivery Metrics
    fputcsv($output, ['DELIVERY KEY METRICS', 'VALUES']);
    fputcsv($output, ['Total Deliveries', $total_deliveries]);
    fputcsv($output, ['Unique Clients', $total_customers]);
    fputcsv($output, ['Total Sales Revenue (LKR)', number_format($total_earnings, 2, '.', '')]);
    fputcsv($output, ['Total Payments Got (LKR)', number_format($total_payments_got, 2, '.', '')]);
    fputcsv($output, ['Outstanding Balance (LKR)', number_format($pending_payments, 2, '.', '')]);
    fputcsv($output, ['Total Expenses (LKR)', number_format($total_expenses, 2, '.', '')]);
    fputcsv($output, ['Delivery Profit (LKR)', number_format($profit, 2, '.', '')]);
    fputcsv($output, ['Total Business Profit (LKR)', number_format($total_business_profit, 2, '.', '')]);
    fputcsv($output, ['Employee Salary Payments (LKR)', number_format($total_emp_payments, 2, '.', '')]);
    fputcsv($output, []);

    // POS Metrics
    fputcsv($output, ['POS SALES METRICS', 'VALUES']);
    fputcsv($output, ['POS Revenue (LKR)', number_format($pos_revenue, 2, '.', '')]);
    fputcsv($output, ['POS Profit (LKR)', number_format($pos_profit, 2, '.', '')]);
    fputcsv($output, []);

    // POS Most Sold
    fputcsv($output, ['POS MOST SOLD ITEMS']);
    fputcsv($output, ['Brand Name', 'Total Quantity']);
    foreach ($pos_items_data as $i) { fputcsv($output, [$i['brand_name'], $i['total_qty']]); }
    fputcsv($output, []);

    // POS Payment Types
    fputcsv($output, ['POS PAYMENT TYPES']);
    fputcsv($output, ['Payment Type', 'Total Amount (LKR)']);
    foreach ($pos_pay_types as $pt) { fputcsv($output, [$pt['payment_type'], number_format($pt['total_amount'], 2, '.', '')]); }
    fputcsv($output, []);
    
    // Bank Details
    fputcsv($output, ['BANK DETAILS']);
    fputcsv($output, ['Bank Name', 'Account Number', 'Total Received (LKR)', 'Total Paid (LKR)', 'Leftover (LKR)']);
    foreach ($banks_data as $b) { fputcsv($output, [$b['bank_name'], $b['account_number'], number_format($b['total_received'], 2, '.', ''), number_format($b['total_paid'], 2, '.', ''), number_format($b['leftover'], 2, '.', '')]); }
    fputcsv($output, []);
    
    // Delivery Most Sold Items
    fputcsv($output, ['DELIVERY MOST SOLD ITEMS']);
    fputcsv($output, ['Brand Name', 'Total Quantity (PKTS)']);
    foreach ($items_data as $i) { fputcsv($output, [$i['brand_name'], $i['total_qty']]); }
    fputcsv($output, []);
    
    // Delivery Payment Types
    fputcsv($output, ['DELIVERY PAYMENT TYPES']);
    fputcsv($output, ['Payment Type', 'Total Amount (LKR)']);
    foreach ($pay_types_data as $pt) { fputcsv($output, [$pt['payment_type'], number_format($pt['total_amount'], 2, '.', '')]); }
    fputcsv($output, []);
    
    // Final Summary
    fputcsv($output, ['OVERALL BUSINESS PERFORMANCE', 'VALUES']);
    fputcsv($output, ['DELIVERY PROFIT', number_format($profit, 2, '.', '')]);
    fputcsv($output, ['POS SALES PROFIT', number_format($pos_profit, 2, '.', '')]);
    fputcsv($output, ['EMPLOYEE SALARY PAYMENTS', number_format($total_emp_payments, 2, '.', '')]);
    fputcsv($output, ['OTHER PURCHASES/EXPENSES', number_format($total_other_purchases, 2, '.', '')]);
    fputcsv($output, ['OVERHEAD EXPENSES (BILLS/TAX)', number_format($total_overhead_expenses, 2, '.', '')]);
    fputcsv($output, ['--------------------------', '----------']);
    fputcsv($output, ['TOTAL CONSOLIDATED BUSINESS PROFIT', number_format($total_business_profit, 2, '.', '')]);
    
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Reports | Crystal POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@300;400;600;700&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc url('../assests/glass_bg.png') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
        }

        .glass-header {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 1);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid white;
            border-radius: 28px;
            box-shadow: 0 10px 30px -5px rgba(0,0,0,0.04);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .glass-card:hover {
            box-shadow: 0 20px 40px -10px rgba(0,0,0,0.08);
        }

        .input-glass {
            background: rgba(255, 255, 255, 0.6);
            border: 1px solid #e2e8f0;
            padding: 10px 16px;
            border-radius: 14px;
            outline: none;
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
        }

        select.input-glass option {
            background-color: #0f172a;
            color: white;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
            margin-bottom: 20px;
        }

        .custom-scroll::-webkit-scrollbar { width: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
    </style>
</head>
<body class="pb-10">

    <header class="glass-header sticky top-0 z-40 py-4 mb-4 sm:mb-8 leading-none shadow-sm">
        <div class="max-w-[1600px] mx-auto px-4 sm:px-6 flex flex-col md:flex-row items-center justify-between gap-6">
            <div class="flex items-center space-x-5">
                <a href="dashboard.php" class="w-10 h-10 flex items-center justify-center rounded-xl bg-slate-100 text-slate-800 hover:bg-slate-900 hover:text-white transition-all shadow-sm">
                    <i class="fa-solid fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-black text-slate-900 font-['Outfit'] tracking-tight">Business Intelligence</h1>
                    <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mt-1">Performance & Growth Insights</p>
                </div>
            </div>
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['action' => 'export_excel'])); ?>" class="bg-emerald-600 text-white px-5 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest flex items-center justify-center space-x-2 shadow-sm shadow-emerald-600/20 transition-all hover:bg-emerald-700">
                    <i class="fa-solid fa-file-excel text-base"></i>
                    <span>Export Excel</span>
                </a>
                <div class="bg-indigo-600 text-white px-5 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest flex items-center justify-center space-x-3 shadow-lg shadow-indigo-600/20">
                    <i class="fa-solid fa-calendar-check text-base"></i>
                    <span class="whitespace-nowrap">REPORT GENERATED: <?php echo date('Y-M-d'); ?></span>
                </div>
                <div class="bg-emerald-500 text-white px-5 py-2.5 rounded-2xl text-[12px] font-black uppercase tracking-widest flex items-center justify-center space-x-3 shadow-lg shadow-emerald-500/30">
                    <i class="fa-solid fa-sack-dollar text-xl"></i>
                    <div class="flex flex-col text-left leading-none">
                        <span class="text-[8px] opacity-80 whitespace-nowrap">THIS MONTH'S PROFIT</span>
                        <span class="whitespace-nowrap">LKR <?php echo number_format($currMonthProfit, 2); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-[1600px] mx-auto px-4 sm:px-6">

        <!-- Filters (Synced with managePayments style) -->
        <div class="bg-slate-900/90 backdrop-blur-xl border border-slate-700 rounded-[28px] p-6 mb-8 shadow-2xl">
            <form id="filterForm" method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-5 items-end">
                <div class="md:col-span-3">
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Date Range Start</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="input-glass w-full bg-slate-800/50 border-slate-700 text-white" onchange="this.form.submit()">
                </div>
                <div class="md:col-span-3">
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Date Range End</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="input-glass w-full bg-slate-800/50 border-slate-700 text-white" onchange="this.form.submit()">
                </div>
                <div class="md:col-span-2">
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">View by Month</label>
                    <select name="month" class="input-glass w-full bg-slate-800/50 border-slate-700 text-white appearance-none cursor-pointer" onchange="this.form.submit()">
                        <?php for($m=1; $m<=12; $m++): ?>
                            <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>" <?php echo $month == str_pad($m, 2, '0', STR_PAD_LEFT) ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Year</label>
                    <select name="year" class="input-glass w-full bg-slate-800/50 border-slate-700 text-white appearance-none cursor-pointer" onchange="this.form.submit()">
                        <?php for($y=date('Y'); $y>=2024; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <a href="reports.php" class="w-full h-[46px] bg-white/10 text-white rounded-xl hover:bg-white/20 transition-all flex items-center justify-center border border-white/10 uppercase font-black text-[10px] tracking-widest">
                        <i class="fa-solid fa-rotate-right mr-2"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Delivery Section Heading -->
        <h2 class="text-xl font-black text-slate-900 font-['Outfit'] tracking-tight mb-6 flex items-center gap-3">
            <i class="fa-solid fa-truck-fast text-slate-400"></i>
            Delivery Details
        </h2>

        <!-- Key Metrics Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
            <!-- Deliveries -->
            <div class="glass-card p-8 bg-gradient-to-br from-cyan-500/10 to-transparent border-cyan-200">
                <div class="stat-icon bg-cyan-100/50 text-cyan-600 mb-4">
                    <i class="fa-solid fa-truck-ramp-box text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Total Deliveries</p>
                <h2 class="text-3xl font-black text-slate-900 tracking-tighter"><?php echo number_format($total_deliveries); ?></h2>
             
            </div>

            <!-- Customers -->
            <div class="glass-card p-8 bg-gradient-to-br from-indigo-500/10 to-transparent border-indigo-200">
                <div class="stat-icon bg-indigo-100/50 text-indigo-600 mb-4">
                    <i class="fa-solid fa-users text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">total customers</p>
                <h2 class="text-3xl font-black text-slate-900 tracking-tighter"><?php echo number_format($total_customers); ?></h2>
          
            </div>

            <!-- Revenue -->
            <div class="glass-card p-8 bg-gradient-to-br from-emerald-500/10 to-transparent border-emerald-200">
                <div class="stat-icon bg-emerald-100/50 text-emerald-600 mb-4">
                    <i class="fa-solid fa-money-bill-trend-up text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Total Sales Revenue</p>
                <h2 class="text-3xl font-black text-emerald-600 tracking-tighter">LKR <?php echo number_format($total_earnings, 2); ?></h2>
         
            </div>

            <!-- Payments Got -->
            <div class="glass-card p-8 bg-gradient-to-br from-blue-500/10 to-transparent border-blue-200">
                <div class="stat-icon bg-blue-100/50 text-blue-600 mb-4">
                    <i class="fa-solid fa-hand-holding-dollar text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Total Payments Got from customers</p>
                <h2 class="text-3xl font-black text-blue-600 tracking-tighter">LKR <?php echo number_format($total_payments_got, 2); ?></h2>
              
            </div>

            <!-- Pending -->
            <div class="glass-card p-8 bg-gradient-to-br from-rose-500/10 to-transparent border-rose-200">
                <div class="stat-icon bg-rose-100/50 text-rose-600 mb-4">
                    <i class="fa-solid fa-wallet text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Pending Payments from deliveries</p>
                <h2 class="text-3xl font-black text-rose-600 tracking-tighter">LKR <?php echo number_format($pending_payments, 2); ?></h2>
              
            </div>

            <!-- Total Expenses -->
            <div class="glass-card p-8 bg-gradient-to-br from-orange-500/10 to-transparent border-orange-200">
                <div class="stat-icon bg-orange-100/50 text-orange-600 mb-4">
                    <i class="fa-solid fa-file-invoice-dollar text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Total Expenses</p>
                <h2 class="text-3xl font-black text-orange-600 tracking-tighter">LKR <?php echo number_format($total_expenses, 2); ?></h2>
          
            </div>

            <!-- Delivery Profit -->
            <div class="glass-card p-8 bg-gradient-to-br from-teal-500/10 to-transparent border-teal-200">
                <div class="stat-icon bg-teal-100/50 text-teal-600 mb-4">
                    <i class="fa-solid fa-truck-ramp-box text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Delivery Profit</p>
                <h2 class="text-3xl font-black text-teal-600 tracking-tighter">LKR <?php echo number_format($profit, 2); ?></h2>
            </div>
            <div class="glass-card p-8 bg-gradient-to-br from-amber-500/10 to-transparent border-amber-200">
                <div class="stat-icon bg-amber-100/50 text-amber-600 mb-4">
                    <i class="fa-solid fa-money-check-dollar text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Total Employee Salary Payments</p>
                <h2 class="text-3xl font-black text-amber-600 tracking-tighter">LKR <?php echo number_format($total_emp_payments, 2); ?></h2>
          
            </div>                 

            <div class="glass-card p-8 bg-gradient-to-br from-rose-500/10 to-transparent border-rose-200">
                <div class="stat-icon bg-rose-100/50 text-rose-600 mb-4">
                    <i class="fa-solid fa-cart-shopping text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Other Purchases / Expenses</p>
                <h2 class="text-3xl font-black text-rose-600 tracking-tighter">LKR <?php echo number_format($total_other_purchases, 2); ?></h2>
            </div>
            
            <div class="glass-card p-8 bg-gradient-to-br from-fuchsia-300/10 to-transparent border-fuchsia-200">
                <div class="stat-icon bg-fuchsia-100/50 text-fuchsia-600 mb-4">
                    <i class="fa-solid fa-bolt text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Overhead Expenses (Bills)</p>
                <h2 class="text-3xl font-black text-fuchsia-600 tracking-tighter">LKR <?php echo number_format($total_overhead_expenses, 2); ?></h2>
            </div>
            
            <!-- POS Sales Reports Heading -->
            <div class="col-span-full mt-4 mb-2">
                <h2 class="text-xl font-black text-slate-900 font-['Outfit'] tracking-tight flex items-center gap-3">
                    <i class="fa-solid fa-cash-register text-slate-400"></i>
                    POS Sales Reports
                </h2>
            </div>

            <!-- POS Revenue -->
            <div class="glass-card p-8 bg-gradient-to-br from-violet-500/10 to-transparent border-violet-200">
                <div class="stat-icon bg-violet-100/50 text-violet-600 mb-4">
                    <i class="fa-solid fa-cash-register text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">POS Sales Revenue</p>
                <h2 class="text-3xl font-black text-violet-600 tracking-tighter">LKR <?php echo number_format($pos_revenue, 2); ?></h2>
            </div>

            <!-- POS Profit -->
            <div class="glass-card p-8 bg-gradient-to-br from-fuchsia-500/10 to-transparent border-fuchsia-200">
                <div class="stat-icon bg-fuchsia-100/50 text-fuchsia-600 mb-4">
                    <i class="fa-solid fa-store text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">POS Sales Profit</p>
                <h2 class="text-3xl font-black text-fuchsia-600 tracking-tighter">LKR <?php echo number_format($pos_profit, 2); ?></h2>
            </div>

            <!-- TOTAL BUSINESS PROFIT -->
            <div class="glass-card p-8 bg-slate-900 border-slate-700 text-white shadow-2xl lg:col-span-2">
                <div class="stat-icon bg-white/10 text-white mb-4">
                    <i class="fa-solid fa-vault text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Business Profit</p>
                <h2 class="text-3xl font-black text-white tracking-tighter">LKR <?php echo number_format($total_business_profit, 2); ?></h2>
                <p class="text-[9px] text-slate-400 mt-2 font-medium italic">(Delivery + POS - Salaries - Other Purchases - Overheads)</p>
            </div>
        </div>

        <div class="mb-10">
            <div class="glass-card p-8 border-slate-200 bg-white/60 text-slate-900">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h3 class="text-xl font-black font-['Outfit']">Bank Details by Payments</h3>
                        <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mt-1">Aggregated Collection Overview</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-separate border-spacing-y-1">
                        <thead>
                            <tr class="text-[10px] uppercase font-black tracking-widest text-slate-600 bg-slate-100/80 rounded-xl overflow-hidden">
                                <th class="py-3 px-5 rounded-l-xl">Bank Name</th>
                                <th class="py-3 px-5">Account Details</th>
                                <th class="py-3 px-5 text-right">Total Received</th>
                                <th class="py-3 px-5 text-right">Total Paid</th>
                                <th class="py-3 px-5 text-right rounded-r-xl">Leftover</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($banks_data as $b): 
                                $isPositive = $b['leftover'] >= 0;
                            ?>
                                <tr class="bg-white/40 hover:bg-slate-50 transition-colors group">
                                    <td class="py-4 px-5 rounded-l-xl border-y border-l border-transparent group-hover:border-slate-200">
                                        <div class="flex items-center space-x-4">
                                            <div class="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center text-indigo-600 border border-indigo-100 shadow-sm transition-all">
                                                <i class="fa-solid fa-building-columns text-sm"></i>
                                            </div>
                                            <span class="text-xs font-black text-slate-700 uppercase tracking-tight"><?php echo htmlspecialchars($b['bank_name']); ?></span>
                                        </div>
                                    </td>
                                    <td class="py-4 px-5 border-y border-transparent group-hover:border-slate-200">
                                        <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest bg-slate-100 px-2 py-1 rounded-md">ACC: <?php echo htmlspecialchars($b['account_number']); ?></span>
                                    </td>
                                    <td class="py-4 px-5 text-right border-y border-transparent group-hover:border-slate-200">
                                        <span class="text-xs font-black text-emerald-600">LKR <?php echo number_format($b['total_received'], 2); ?></span>
                                    </td>
                                    <td class="py-4 px-5 text-right border-y border-transparent group-hover:border-slate-200">
                                        <span class="text-xs font-black text-amber-600">LKR <?php echo number_format($b['total_paid'], 2); ?></span>
                                    </td>
                                    <td class="py-4 px-5 text-right rounded-r-xl border-y border-r border-transparent group-hover:border-slate-200">
                                        <span class="text-sm font-black <?php echo $isPositive ? 'text-slate-900' : 'text-rose-600'; ?>">LKR <?php echo number_format($b['leftover'], 2); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($banks_data)): ?>
                                <tr>
                                    <td colspan="5" class="py-12 text-center text-slate-400 font-bold text-[10px] uppercase tracking-widest italic bg-white/20 rounded-2xl">
                                        <div class="flex flex-col items-center gap-2">
                                            <i class="fa-solid fa-folder-open text-2xl opacity-20"></i>
                                            No bank payment activity found.
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
            </div>
                </div>
            </div>
        </div>

        <!-- Delivery Analysis Section -->
        <h2 class="text-xl font-black text-slate-900 font-['Outfit'] tracking-tight mb-6 flex items-center gap-3">
            <i class="fa-solid fa-chart-pie text-slate-400"></i>
            Delivery Details
        </h2>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Items Pie Chart -->
            <div class="glass-card p-8 h-full bg-slate-50 border-slate-200">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h3 class="text-xl font-black text-slate-900 font-['Outfit']">Inventory Distribution</h3>
                        <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mt-1">Most Sold Brand Items (By Volume)</p>
                    </div>
                </div>
                <div class="relative flex justify-center mb-8">
                    <canvas id="itemsChart" style="max-height: 250px; max-width: 250px;"></canvas>
                </div>
                <div class="space-y-3">
                    <?php foreach($items_data as $i): ?>
                        <div class="flex items-center justify-between p-3 rounded-2xl bg-white border border-slate-100 shadow-sm">
                            <span class="text-[11px] font-black text-slate-500 uppercase tracking-widest"><?php echo htmlspecialchars($i['brand_name']); ?></span>
                            <span class="text-xs font-black text-slate-900"><?php echo number_format($i['total_qty']); ?> PKTS</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Payment Types Pie Chart -->
            <div class="glass-card p-8 h-full bg-slate-50 border-slate-200">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h3 class="text-xl font-black text-slate-900 font-['Outfit']">Payment Types Source</h3>
                        <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mt-1">Transaction Methods Breakdown</p>
                    </div>
                </div>
                <div class="relative flex justify-center mb-8">
                    <canvas id="paymentsChart" style="max-height: 250px; max-width: 250px;"></canvas>
                </div>
                <div class="space-y-3 mt-4">
                    <?php foreach($pay_types_data as $pt): ?>
                        <div class="flex items-center justify-between p-3 rounded-2xl bg-white border border-slate-100 shadow-sm">
                            <span class="text-[11px] font-black text-slate-500 uppercase tracking-widest"><?php echo htmlspecialchars($pt['payment_type']); ?></span>
                            <span class="text-xs font-black text-slate-900">LKR <?php echo number_format($pt['total_amount'], 2); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- POS Analysis Section -->
        <h2 class="text-xl font-black text-slate-900 font-['Outfit'] tracking-tight mb-6 mt-10 flex items-center gap-3">
            <i class="fa-solid fa-chart-line text-slate-400"></i>
            POS Sales Reports
        </h2>

        <!-- POS Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mt-4">
            <!-- POS Most Sold Items -->
            <div class="glass-card p-8 h-full bg-slate-50 border-violet-200">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h3 class="text-xl font-black text-slate-900 font-['Outfit']">POS Brand Profitability</h3>
                        <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mt-1">Most Profitable Brands (Line Item Profit)</p>
                    </div>
                </div>
                <div class="relative flex justify-center mb-8">
                    <canvas id="posItemsChart" style="max-height: 250px; max-width: 250px;"></canvas>
                </div>
                <div class="space-y-3">
                    <?php foreach($pos_items_data as $i): ?>
                        <div class="flex items-center justify-between p-3 rounded-2xl bg-white border border-slate-100 shadow-sm">
                            <span class="text-[11px] font-black text-slate-500 uppercase tracking-widest"><?php echo htmlspecialchars($i['brand_name']); ?></span>
                            <span class="text-xs font-black text-emerald-600">LKR <?php echo number_format($i['brand_profit'], 2); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if(empty($pos_items_data)): ?><p class="text-center text-xs text-slate-400 italic py-6">No POS sales data yet.</p><?php endif; ?>
                </div>
            </div>

            <!-- POS Payment Methods -->
            <div class="glass-card p-8 h-full bg-slate-50 border-fuchsia-200">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h3 class="text-xl font-black text-slate-900 font-['Outfit']">POS Payment Methods</h3>
                        <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mt-1">Payment Breakdown for POS Sales</p>
                    </div>
                </div>
                <div class="relative flex justify-center mb-8">
                    <canvas id="posPayChart" style="max-height: 250px; max-width: 250px;"></canvas>
                </div>
                <div class="space-y-3 mt-4">
                    <?php foreach($pos_pay_types as $pt): ?>
                        <div class="flex items-center justify-between p-3 rounded-2xl bg-white border border-slate-100 shadow-sm">
                            <span class="text-[11px] font-black text-slate-500 uppercase tracking-widest"><?php echo htmlspecialchars($pt['payment_type']); ?></span>
                            <span class="text-xs font-black text-slate-900">LKR <?php echo number_format($pt['total_amount'], 2); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if(empty($pos_pay_types)): ?><p class="text-center text-xs text-slate-400 italic py-6">No POS payment data yet.</p><?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Pie Chart Initialization (Items)
        const ctxItems = document.getElementById('itemsChart');
        const itemsData = <?php echo json_encode($items_data); ?>;
        const labelsItems = itemsData.map(i => i.brand_name);
        const valuesItems = itemsData.map(i => i.total_qty);

        new Chart(ctxItems, {
            type: 'doughnut',
            data: {
                labels: labelsItems,
                datasets: [{
                    data: valuesItems,
                    backgroundColor: [
                        '#6366f1', '#10b981', '#f59e0b', '#ef4444', 
                        '#06b6d4', '#8b5cf6', '#ec4899', '#14b8a6', 
                        '#f43f5e', '#2dd4bf'
                    ],
                    borderWidth: 0,
                    hoverOffset: 20
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        padding: 15,
                        titleFont: { size: 13, weight: 'bold' },
                        bodyFont: { size: 12 },
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        cornerRadius: 12,
                        displayColors: true
                    }
                },
                cutout: '75%'
            }
        });

        // Pie Chart Initialization (Payments)
        const ctxPayments = document.getElementById('paymentsChart');
        const paymentsData = <?php echo json_encode($pay_types_data); ?>;
        const labelsPay = paymentsData.map(i => i.payment_type);
        const valuesPay = paymentsData.map(i => i.total_amount);

        new Chart(ctxPayments, {
            type: 'doughnut',
            data: {
                labels: labelsPay,
                datasets: [{
                    data: valuesPay,
                    backgroundColor: [
                        '#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ef4444'
                    ],
                    borderWidth: 0,
                    hoverOffset: 20
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        padding: 15,
                        titleFont: { size: 13, weight: 'bold' },
                        bodyFont: { size: 12 },
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        cornerRadius: 12,
                        displayColors: true,
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) { label += ': '; }
                                if (context.parsed !== null) {
                                    label += new Intl.NumberFormat('en-LK', { style: 'currency', currency: 'LKR' }).format(context.parsed);
                                }
                                return label;
                            }
                        }
                    }
                },
                cutout: '75%'
            }
        });
    </script>
    <script>
        // POS Items Chart
        const posItemsData = <?php echo json_encode($pos_items_data); ?>;
        if (posItemsData.length > 0) {
            new Chart(document.getElementById('posItemsChart'), {
                type: 'doughnut',
                data: {
                    labels: posItemsData.map(i => i.brand_name),
                    datasets: [{ data: posItemsData.map(i => i.brand_profit), backgroundColor: ['#7c3aed','#a78bfa','#c4b5fd','#8b5cf6','#6d28d9','#ddd6fe','#4c1d95','#ede9fe','#5b21b6','#764ba2'], borderWidth: 0, hoverOffset: 20 }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    plugins: { 
                        legend: { display: false }, 
                        tooltip: { 
                            padding: 15, 
                            backgroundColor: 'rgba(15,23,42,0.95)', 
                            cornerRadius: 12,
                            callbacks: {
                                label: function(ctx) {
                                    return ctx.label + ': LKR ' + parseFloat(ctx.parsed).toLocaleString('en-LK', {minimumFractionDigits:2});
                                }
                            }
                        } 
                    }, 
                    cutout: '75%' 
                }
            });
        }
        // POS Payment Types Chart
        const posPayData = <?php echo json_encode($pos_pay_types); ?>;
        if (posPayData.length > 0) {
            new Chart(document.getElementById('posPayChart'), {
                type: 'doughnut',
                data: {
                    labels: posPayData.map(i => i.payment_type),
                    datasets: [{ data: posPayData.map(i => i.total_amount), backgroundColor: ['#c026d3','#a21caf','#86198f','#6b21a8','#ec4899'], borderWidth: 0, hoverOffset: 20 }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { padding: 15, backgroundColor: 'rgba(15,23,42,0.95)', cornerRadius: 12, callbacks: { label: function(ctx) { return ctx.label + ': LKR ' + parseFloat(ctx.parsed).toLocaleString('en-LK', {minimumFractionDigits:2}); } } } }, cutout: '75%' }
            });
        }
    </script>
</body>
</html>
