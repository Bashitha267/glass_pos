<?php
require_once '../auth.php';
checkAuth();

// Restrict to admins
if (!isAdmin()) {
    header('Location: ../sale/dashboard.php');
    exit;
}

$username = $_SESSION['username'];
$role = $_SESSION['role'];

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

require_once '../config.php';

$currentMonth = date('m');
$currentYear = date('Y');

// Total revenue (Deliveries + POS)
$revStmt = $pdo->prepare("SELECT SUM(dc.subtotal - dc.discount) as total_earnings
                          FROM delivery_customers dc
                          JOIN deliveries d ON dc.delivery_id = d.id 
                          WHERE MONTH(d.delivery_date) = ? AND YEAR(d.delivery_date) = ?");
$revStmt->execute([$currentMonth, $currentYear]);
$del_revenue = (float) $revStmt->fetchColumn();

// POS Sales revenue
$posRev = (float) $pdo->query("SELECT COALESCE(SUM(grand_total),0) FROM pos_sales WHERE MONTH(sale_date) = $currentMonth AND YEAR(sale_date) = $currentYear")->fetchColumn();

// COMBINED REVENUE for Header display
$dash_total_revenue = $del_revenue + $posRev;

// Total cost (COGS) - Deliveries
$costStmt = $pdo->prepare("SELECT SUM(di.qty * di.cost_price)
                            FROM delivery_items di
                            JOIN delivery_customers dc ON di.delivery_customer_id = dc.id 
                            JOIN deliveries d ON dc.delivery_id = d.id 
                            WHERE MONTH(d.delivery_date) = ? AND YEAR(d.delivery_date) = ?");
$costStmt->execute([$currentMonth, $currentYear]);
$dash_total_cost = (float) $costStmt->fetchColumn();

// Total operational expenses (Deliveries)
$expStmt = $pdo->prepare("SELECT SUM(amount) FROM delivery_expenses de 
                          JOIN deliveries d ON de.delivery_id = d.id 
                          WHERE MONTH(d.delivery_date) = ? AND YEAR(d.delivery_date) = ?");
$expStmt->execute([$currentMonth, $currentYear]);
$dash_total_expenses = (float) $expStmt->fetchColumn();

// Employee payments
$empStmt = $pdo->prepare("SELECT SUM(salary_amount) FROM employee_salary_payments WHERE status = 'paid' AND salary_month = ? AND salary_year = ?");
$empStmt->execute([$currentMonth, $currentYear]);
$dash_total_emp_payments = (float) $empStmt->fetchColumn();

// POS Sales profit
$posCost = (float) $pdo->query("SELECT COALESCE(SUM(psi.qty * psi.cost_price),0) FROM pos_sale_items psi JOIN pos_sales ps ON psi.sale_id=ps.id WHERE MONTH(ps.sale_date) = $currentMonth AND YEAR(ps.sale_date) = $currentYear")->fetchColumn();
$posProfit = $posRev - $posCost;

// Other Purchases (expenses)
$opExp = (float) $pdo->query("SELECT COALESCE(SUM(grand_total),0) FROM other_purchases WHERE MONTH(purchase_date) = $currentMonth AND YEAR(purchase_date) = $currentYear")->fetchColumn();

// Overhead Expenses (bills)
$oeExp = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM monthly_expenses WHERE MONTH(expense_date) = $currentMonth AND YEAR(expense_date) = $currentYear")->fetchColumn();

$dash_total_profit = ($del_revenue - $dash_total_cost - $dash_total_expenses) + $posProfit - $dash_total_emp_payments - $opExp - $oeExp;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control Center | Crystal POS — Sahan Picture &amp; Mirror</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;600;700&display=swap');

        body {
            font-family: 'Inter', sans-serif;
            background: url('../assests/glass_bg.png') no-repeat center center fixed;
            background-size: cover;
            color: #0f172a;
            min-height: 100vh;
        }

        .glass-header {
            background: rgba(241, 245, 249, 0.98);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            box-shadow: 0 2px 15px -3px rgba(0, 0, 0, 0.07);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            border: 1.5px solid #334155;
            border-radius: 28px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05);
        }

        .glass-card:hover {
            background: white;
            transform: translateY(-6px);
            border-color: #0891b2;
            box-shadow: 0 20px 40px -10px rgba(8, 145, 178, 0.15);
        }

        .action-icon {
            transition: all 0.3s ease;
        }

        .glass-card:hover .action-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .text-premium-label {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: #475569;
            /* Darkened from slate-500 to slate-600 */
            text-shadow: 0 1px 2px rgba(255, 255, 255, 0.5);
        }

        .header-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: #1e293b;
        }

        .btn-logout {
            background: #ef4444;
            color: white;
            padding: 8px 20px;
            border-radius: 12px;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.05em;
            box-shadow: 0 4px 12px -2px rgba(239, 68, 68, 0.3);
            transition: all 0.3s;
        }

        .btn-logout:hover {
            background: #dc2626;
            transform: translateY(-1px);
            box-shadow: 0 6px 15px -2px rgba(239, 68, 68, 0.4);
        }
    </style>
</head>

<body class="overflow-x-hidden min-h-screen flex flex-col">

    <!-- Header Block -->
    <header class="glass-header sticky top-0 z-50 py-3 leading-none">
        <div class="max-w-[1400px] mx-auto px-6 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <div class="w-11 h-11 rounded-2xl bg-white overflow-hidden flex items-center justify-center shadow-lg">
                    <img src="../assests/logo.jpeg" alt="Crystal POS logo" class="w-10 h-10 object-cover">
                </div>
                <div>
                    <h1 class="text-xl font-black uppercase tracking-tighter text-slate-900 font-['Outfit']">Crystal POS
                    </h1>
                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Sahan Picture &amp;
                        Mirror — Inventory Management System</p>
                </div>
            </div>

            <div class="flex items-center space-x-8">
                <div class="hidden md:flex items-center space-x-6">
                    <div class="text-right hidden lg:block">
                        <span class="text-premium-label">Monthly Revenue</span>
                        <p class="text-md font-black text-emerald-600 tracking-tighter mt-0.5">LKR
                            <?php echo number_format($dash_total_revenue, 2); ?>
                        </p>
                    </div>
                    <div class="w-px h-8 bg-slate-200 hidden lg:block"></div>
                    <div class="text-right hidden lg:block">
                        <span class="text-premium-label">Monthly Profit</span>
                        <p class="text-md font-black text-indigo-600 tracking-tighter mt-0.5">LKR
                            <?php echo number_format($dash_total_profit, 2); ?>
                        </p>
                    </div>
                    <div class="w-px h-8 bg-slate-200 hidden lg:block"></div>
                    <div class="text-right flex-col justify-center">
                        <span class="text-premium-label block">System Time</span>
                        <p id="current-time" class="text-sm font-black text-slate-800 tracking-widest mt-0.5"></p>
                        <p id="current-date"
                            class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-0.5"></p>
                    </div>
                    <div class="w-px h-8 bg-slate-200"></div>
                    <div class="text-right flex-col justify-center">
                        <span class="text-premium-label block">Operator</span>
                        <p class="text-sm font-black text-slate-800 mt-0.5"><?php echo htmlspecialchars($username); ?>
                        </p>
                    </div>
                </div>

                <a href="?logout=1" class="btn-logout">
                    <i class="fa-solid fa-power-off mr-2"></i>Exit
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-[1400px] mx-auto w-full px-6 py-12 flex-grow">
        <!-- Hero Section -->


        <!-- Dashboard Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-6">
            <!-- Point of Sale -->
            <a href="pos.php" class="glass-card group p-8 flex flex-col items-center justify-center text-center">
                <div
                    class="action-icon w-16 h-16 bg-cyan-50 border-2 border-cyan-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-cash-register text-3xl text-cyan-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-cyan-600 transition-colors">Point of Sale
                </h3>
            </a>
            <a href="nwdelivery.php" class="glass-card group p-8 flex flex-col items-center justify-center text-center">
                <div
                    class="action-icon w-16 h-16 bg-amber-50 border-2 border-amber-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-truck-fast text-3xl text-amber-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-amber-600 transition-colors">Delivery
                    Management</h3>
            </a>
            <a href="addcontainer.php"
                class="glass-card group p-8 flex flex-col items-center justify-center text-center">
                <div
                    class="action-icon w-16 h-16 bg-blue-50 border-2 border-blue-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-boxes-stacked text-3xl text-blue-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-blue-600 transition-colors">Inventory</h3>
            </a>

            <a href="managePayments.php"
                class="glass-card group p-8 flex flex-col items-center justify-center text-center">
                <div
                    class="action-icon w-16 h-16 bg-indigo-50 border-2 border-indigo-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-clock-rotate-left text-3xl text-indigo-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-indigo-600 transition-colors">Payment
                    Management</h3>
            </a>
            <!-- POS Sales History -->

            <!-- New Delivery -->


            <!-- Manage Stocks -->

            <a href="salary.php" class="glass-card group p-8 flex flex-col items-center justify-center text-center">
                <div
                    class="action-icon w-16 h-16 bg-emerald-50 border-2 border-emerald-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-money-check-dollar text-3xl text-emerald-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-emerald-600 transition-colors">Salary &
                    Payroll</h3>
            </a>

            <!-- Manage Employees -->
            <a href="manageEmploy.php"
                class="glass-card group p-8 flex flex-col items-center justify-center text-center">
                <div
                    class="action-icon w-16 h-16 bg-purple-50 border-2 border-purple-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-user-tie text-3xl text-purple-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-purple-600 transition-colors">Staff
                    Management</h3>
            </a>

            <!-- Salary -->

            <!-- Payment Management -->

            <a href="pos_sales_history.php"
                class="glass-card group p-8 flex flex-col items-center justify-center text-center">
                <div
                    class="action-icon w-16 h-16 bg-violet-50 border-2 border-violet-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-store text-3xl text-violet-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-violet-600 transition-colors">POS Sales
                    History</h3>
            </a>

            <!-- POS Audits -->


            <!-- Payment History -->
            <a href="finance.php" class="glass-card group p-8 flex flex-col items-center justify-center text-center">
                <div
                    class="action-icon w-16 h-16 bg-emerald-50 border-2 border-emerald-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-receipt text-3xl text-emerald-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-emerald-600 transition-colors">Delivery
                    Payment History</h3>
            </a>


            <!-- Cheque Management -->
            <a href="cheque_managment.php"
                class="glass-card group p-8 flex flex-col items-center justify-center text-center">
                <div
                    class="action-icon w-16 h-16 bg-teal-50 border-2 border-teal-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-money-check-dollar text-3xl text-teal-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-teal-600 transition-colors">Cheque
                    Management</h3>
            </a>
            <a href="other_expenses.php"
                class="glass-card group p-8 flex flex-col items-center justify-center text-center">
                <div
                    class="action-icon w-16 h-16 bg-purple-50 border-2 border-purple-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-file-invoice-dollar text-3xl text-purple-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-purple-600 transition-colors">Overhead
                    Expenses</h3>
            </a>
            <!-- Reports Hub -->
            <a href="reports.php" class="glass-card group p-8 flex flex-col items-center justify-center text-center">
                <div
                    class="action-icon w-16 h-16 bg-rose-50 border-2 border-rose-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-chart-line text-3xl text-rose-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-rose-600 transition-colors">Business
                    Reports</h3>
            </a>

            <!-- Overhead Expenses -->

        </div>
    </main>

    <footer class="py-12 border-t border-slate-100 mt-12">
        <div class="max-w-7xl mx-auto px-6 text-center">
            <p class="text-[10px] text-white uppercase tracking-[0.4em] font-black">
                Crystal POS &bull; Sahan Picture &amp; Mirror Inventory Management System &bull;
                <?php echo date('Y'); ?>
            </p>
        </div>
    </footer>

    <script>
        function updateDateTime() {
            const now = new Date();
            const timeOptions = {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true,
                timeZone: 'Asia/Colombo'
            };
            const dateOptions = {
                weekday: 'long',
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                timeZone: 'Asia/Colombo'
            };

            const timeElement = document.getElementById('current-time');
            const dateElement = document.getElementById('current-date');

            if (timeElement) {
                timeElement.textContent = now.toLocaleTimeString('en-US', timeOptions);
            }
            if (dateElement) {
                dateElement.textContent = now.toLocaleDateString('en-US', dateOptions);
            }
        }
        setInterval(updateDateTime, 1000);
        updateDateTime();
    </script>
</body>

</html>