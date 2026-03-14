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
$stmtCost = $pdo->prepare("SELECT SUM(di.qty * di.cost_price) FROM delivery_items di JOIN delivery_customers dc ON di.delivery_customer_id = dc.id JOIN deliveries d ON dc.delivery_id = d.id WHERE $whereClause");
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
    SELECT b.name as brand_name, SUM(di.qty) as total_qty 
    FROM delivery_items di 
    JOIN container_items ci ON di.container_item_id = ci.id
    JOIN brands b ON ci.brand_id = b.id
    JOIN delivery_customers dc ON di.delivery_customer_id = dc.id 
    JOIN deliveries d ON dc.delivery_id = d.id 
    WHERE $whereClause 
    GROUP BY b.name 
    ORDER BY total_qty DESC 
    LIMIT 10
");
$stmtItems->execute($params);
$items_data = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$profit = $total_earnings - $total_expenses - $total_cost;

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
            transform: translateY(-5px);
            background: white;
            box-shadow: 0 20px 40px -10px rgba(0,0,0,0.08);
        }

        .input-glass {
            background: rgba(255, 255, 255, 0.6);
            border: 1px solid #e2e8f0;
            padding: 10px 16px;
            border-radius: 14px;
            outline: none;
            transition: all 0.3s;
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
        }

        .input-glass:focus {
            border-color: #6366f1;
            background: white;
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

    <header class="glass-header sticky top-0 z-40 py-4 mb-8 leading-none shadow-sm">
        <div class="max-w-[1600px] mx-auto px-6 flex items-center justify-between">
            <div class="flex items-center space-x-5">
                <a href="dashboard.php" class="w-10 h-10 flex items-center justify-center rounded-xl bg-slate-100 text-slate-800 hover:bg-slate-900 hover:text-white transition-all shadow-sm">
                    <i class="fa-solid fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-black text-slate-900 font-['Outfit'] tracking-tight">Business Intelligence</h1>
                    <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mt-1">Performance & Growth Insights</p>
                </div>
            </div>
            <div class="bg-indigo-600 text-white px-5 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest flex items-center space-x-3 shadow-lg shadow-indigo-600/20">
                <i class="fa-solid fa-calendar-check text-base"></i>
                <span>REPORT GENERATED: <?php echo date('Y-M-d'); ?></span>
            </div>
        </div>
    </header>

    <main class="max-w-[1600px] mx-auto px-6">

        <!-- Filters (Synced with managePayments style) -->
        <div class="glass-card bg-slate-900/90 p-6 mb-8 border-slate-700 shadow-2xl">
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

        <!-- Key Metrics Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
            <!-- Deliveries -->
            <div class="glass-card p-8">
                <div class="stat-icon bg-cyan-100/50 text-cyan-600">
                    <i class="fa-solid fa-truck-ramp-box text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Deliveries</p>
                <h2 class="text-4xl font-black text-slate-900 tracking-tighter"><?php echo number_format($total_deliveries); ?></h2>
                <div class="mt-4 flex items-center text-xs text-cyan-600 font-bold uppercase tracking-tight">
                    <i class="fa-solid fa-arrow-trend-up mr-2"></i> Scheduled Trips
                </div>
            </div>

            <!-- Customers -->
            <div class="glass-card p-8">
                <div class="stat-icon bg-indigo-100/50 text-indigo-600">
                    <i class="fa-solid fa-users text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Unique Clients</p>
                <h2 class="text-4xl font-black text-slate-900 tracking-tighter"><?php echo number_format($total_customers); ?></h2>
                <div class="mt-4 flex items-center text-xs text-indigo-600 font-bold uppercase tracking-tight">
                    <i class="fa-solid fa-circle-check mr-2"></i> Active Accounts
                </div>
            </div>

            <!-- Profit -->
            <div class="glass-card p-8 bg-emerald-500/5 border-emerald-500/20">
                <div class="stat-icon bg-emerald-100/50 text-emerald-600">
                    <i class="fa-solid fa-sack-dollar text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Net Earnings</p>
                <h2 class="text-4xl font-black text-emerald-600 tracking-tighter">LKR <?php echo number_format($profit, 2); ?></h2>
                <div class="mt-4 flex items-center text-xs text-emerald-600 font-bold uppercase tracking-tight">
                    <i class="fa-solid fa-chart-line mr-2"></i> Operational Profit
                </div>
            </div>

            <!-- Pending -->
            <div class="glass-card p-8 bg-rose-500/5 border-rose-500/20">
                <div class="stat-icon bg-rose-100/50 text-rose-600">
                    <i class="fa-solid fa-wallet text-2xl"></i>
                </div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Outstanding Balance</p>
                <h2 class="text-4xl font-black text-rose-600 tracking-tighter">LKR <?php echo number_format($pending_payments, 2); ?></h2>
                <div class="mt-4 flex items-center text-xs text-rose-600 font-bold uppercase tracking-tight">
                    <i class="fa-solid fa-triangle-exclamation mr-2"></i> Total Due AR
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <!-- Charts Section -->
            <div class="lg:col-span-5">
                <div class="glass-card p-8 h-full">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h3 class="text-xl font-black text-slate-900 font-['Outfit']">Inventory Distribution</h3>
                            <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mt-1">Most Sold Brand Items (By Volume)</p>
                        </div>
                        <i class="fa-solid fa-chart-pie text-slate-300 text-2xl"></i>
                    </div>
                    
                    <div class="relative flex justify-center">
                        <canvas id="itemsChart" style="max-height: 400px; max-width: 400px;"></canvas>
                    </div>

                    <div class="mt-8 space-y-4">
                        <?php foreach($items_data as $i): ?>
                            <div class="flex items-center justify-between p-3 rounded-2xl bg-slate-50 border border-slate-100">
                                <span class="text-xs font-black text-slate-700 uppercase tracking-tight"><?php echo htmlspecialchars($i['brand_name']); ?></span>
                                <span class="text-xs font-black text-slate-900"><?php echo number_format($i['total_qty']); ?> PKTS</span>
                            </div>
                        <?php endforeach; ?>
                        <?php if(empty($items_data)): ?>
                            <p class="text-center text-slate-400 italic text-sm py-10">No sales data available for this period.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Financial Breakdown -->
            <div class="lg:col-span-7">
                <div class="glass-card p-8 h-full">
                    <div class="flex items-center justify-between mb-10 border-b border-slate-100 pb-6">
                        <div>
                            <h3 class="text-xl font-black text-slate-900 font-['Outfit']">Financial Breakdown</h3>
                            <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mt-1">Revenue vs Expenditure Analysis</p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mb-1">Financial Health</p>
                            <span class="px-4 py-1.5 bg-emerald-100 text-emerald-600 rounded-full text-[10px] font-black uppercase tracking-widest">Operational</span>
                        </div>
                    </div>

                    <div class="space-y-8">
                        <!-- Revenue -->
                        <div class="relative">
                            <div class="flex justify-between items-center mb-3">
                                <span class="text-xs font-bold text-slate-600 uppercase tracking-widest">Total Sales Revenue</span>
                                <span class="text-sm font-black text-slate-900">LKR <?php echo number_format($total_earnings, 2); ?></span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-3">
                                <div class="bg-indigo-500 h-3 rounded-full" style="width: 100%"></div>
                            </div>
                        </div>

                        <!-- COGS -->
                        <div class="relative">
                            <div class="flex justify-between items-center mb-3">
                                <span class="text-xs font-bold text-slate-600 uppercase tracking-widest">Cost of Good Sold (COGS)</span>
                                <span class="text-sm font-black text-rose-600">LKR <?php echo number_format($total_cost, 2); ?></span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden">
                                <?php $cogsPerc = $total_earnings > 0 ? ($total_cost / $total_earnings) * 100 : 0; ?>
                                <div class="bg-rose-500 h-3" style="width: <?php echo min(100, $cogsPerc); ?>%"></div>
                            </div>
                            <p class="text-[9px] text-slate-400 mt-2 font-black uppercase tracking-widest">Margin Impact: <?php echo number_format($cogsPerc, 1); ?>% of Revenue</p>
                        </div>

                        <!-- OpEx -->
                        <div class="relative">
                            <div class="flex justify-between items-center mb-3">
                                <span class="text-xs font-bold text-slate-600 uppercase tracking-widest">Operating Expenses (Fuel/Staff)</span>
                                <span class="text-sm font-black text-amber-600">LKR <?php echo number_format($total_expenses, 2); ?></span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden">
                                <?php $opexPerc = $total_earnings > 0 ? ($total_expenses / $total_earnings) * 100 : 0; ?>
                                <div class="bg-amber-500 h-3" style="width: <?php echo min(100, $opexPerc); ?>%"></div>
                            </div>
                            <p class="text-[9px] text-slate-400 mt-2 font-black uppercase tracking-widest">OpEx Ratio: <?php echo number_format($opexPerc, 1); ?>% of Revenue</p>
                        </div>

                        <!-- Profitability Card -->
                        <div class="mt-12 p-8 rounded-[2rem] bg-gradient-to-br from-indigo-900 to-slate-900 text-white shadow-2xl relative overflow-hidden group">
                            <div class="absolute top-0 right-0 p-10 opacity-10 transform scale-150 rotate-12 group-hover:rotate-45 transition-transform duration-700">
                                <i class="fa-solid fa-gem text-9xl"></i>
                            </div>
                            <div class="relative z-10">
                                <p class="text-[10px] font-black uppercase tracking-[0.3em] text-indigo-300 mb-2">Net Business Profitability</p>
                                <h3 class="text-5xl font-black tracking-tighter mb-4">LKR <?php echo number_format($profit, 2); ?></h3>
                                <p class="text-xs text-indigo-400 font-bold leading-relaxed max-w-sm">
                                    Your net profit after deducting all operational costs, inventory procurement expenses, and delivery overheads for the selected period.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Pie Chart Initialization
        const ctx = document.getElementById('itemsChart');
        const itemsData = <?php echo json_encode($items_data); ?>;
        
        const labels = itemsData.map(i => i.brand_name);
        const values = itemsData.map(i => i.total_qty);

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
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
                    legend: {
                        display: false
                    },
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
    </script>
</body>
</html>
