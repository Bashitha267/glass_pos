<?php
require_once '../config.php';
require_once '../auth.php';
checkAuth();

// Restrict to admins
if (!isAdmin()) {
    header('Location: ../sale/dashboard.php');
    exit;
}

// Handle Add Expense
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_expense') {
    $name = trim($_POST['expense_name'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
    
    if (!empty($name) && $amount > 0) {
        $stmt = $pdo->prepare("INSERT INTO monthly_expenses (expense_name, amount, expense_date) VALUES (?, ?, ?)");
        $stmt->execute([$name, $amount, $expense_date]);
    }
    
    // Preserve filters in redirect
    $q = $_SERVER['QUERY_STRING'];
    header("Location: other_expenses.php" . ($q ? "?$q" : ""));
    exit;
}

// Handle Delete Expense
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM monthly_expenses WHERE id = ?")->execute([$id]);
    header("Location: other_expenses.php");
    exit;
}

// Filters
$search = $_GET['search'] ?? '';
$month = $_GET['month'] ?? date('m');
$year = $_GET['year'] ?? date('Y');

$where = ["1=1"];
$params = [];

if (!empty($search)) {
    $where[] = "expense_name LIKE ?";
    $params[] = "%$search%";
}

if (!empty($month)) {
    $where[] = "MONTH(expense_date) = ?";
    $params[] = $month;
}

if (!empty($year)) {
    $where[] = "YEAR(expense_date) = ?";
    $params[] = $year;
}

$whereClause = implode(' AND ', $where);
$stmt = $pdo->prepare("SELECT * FROM monthly_expenses WHERE $whereClause ORDER BY expense_date ASC");
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total for filtered view
$total_expense = 0;
foreach ($expenses as $ex) {
    $total_expense += $ex['amount'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Other General Expenses | Crystal POS</title>
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
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 20px;
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05);
        }
        
        input, select {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid #e2e8f0;
            color: #0f172a;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
        }
        
        /* Inline Form Inputs */
        .inline-input {
            width: 100%;
            background: transparent;
            border: none;
            border-bottom: 2px dashed #cbd5e1;
            border-radius: 0;
            padding: 4px 8px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .inline-input:focus {
            border-bottom: 2px solid #6366f1;
            box-shadow: none;
            background: rgba(99, 102, 241, 0.05);
        }
    </style>
</head>
<body class="bg-gray-50 flex flex-col h-screen">

    <header class="glass-header sticky top-0 z-40 w-full px-6 py-4 flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex items-center space-x-4">
            <a href="dashboard.php" class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-slate-600 hover:bg-slate-200 transition-colors">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-black font-['Outfit'] tracking-tight text-slate-900">Overhead Expenses</h1>
                <p class="text-xs font-bold text-slate-400 tracking-widest uppercase mt-0.5">Monthly General Costs Tracker</p>
            </div>
        </div>

        <!-- Filters Form -->
        <form id="filterForm" method="GET" class="flex flex-wrap items-center gap-3">
            <div class="relative">
                <i class="fa-solid fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-400 text-sm"></i>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                       oninput="this.form.submit()" 
                       placeholder="Search expense..." 
                       class="pl-9 pr-4 py-2 rounded-xl text-sm font-semibold w-48 transition-all focus:w-56 placeholder-slate-400"
                       <?php if(!empty($search)) echo 'autofocus onfocus="this.value = this.value;"'; ?>>
            </div>
            
            <select name="month" onchange="this.form.submit()" class="py-2 pl-4 pr-8 rounded-xl text-sm font-semibold text-slate-700 cursor-pointer">
                <option value="">All Months</option>
                <?php
                for ($m=1; $m<=12; $m++) {
                    $mStr = str_pad($m, 2, '0', STR_PAD_LEFT);
                    $monthName = date('F', mktime(0, 0, 0, $m, 1));
                    $selected = ($mStr == $month) ? 'selected' : '';
                    echo "<option value='$mStr' $selected>$monthName</option>";
                }
                ?>
            </select>
            
            <select name="year" onchange="this.form.submit()" class="py-2 pl-4 pr-8 rounded-xl text-sm font-semibold text-slate-700 cursor-pointer">
                <option value="">All Years</option>
                <?php
                $currY = date('Y');
                for ($y=$currY-2; $y<=$currY; $y++) {
                    $selected = ($y == $year) ? 'selected' : '';
                    echo "<option value='$y' $selected>$y</option>";
                }
                ?>
            </select>
            
            <button type="submit" class="bg-slate-900 text-white w-10 h-10 rounded-xl flex items-center justify-center hover:bg-slate-800 transition-colors shadow-lg shadow-slate-900/20">
                <i class="fa-solid fa-filter text-sm"></i>
            </button>
            <?php if(!empty($search) || !empty($month) || !empty($year)): ?>
            <a href="other_expenses.php" class="bg-rose-100 text-rose-600 w-10 h-10 rounded-xl flex items-center justify-center hover:bg-rose-200 transition-colors">
                <i class="fa-solid fa-xmark text-sm"></i>
            </a>
            <?php endif; ?>
        </form>
    </header>

    <div class="flex-1 overflow-auto p-6">
        <div class="max-w-6xl mx-auto space-y-6 animate-fade-in-up">
            
            <!-- Context Action Bar (Top Left Quick Buttons) -->
            <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-6">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-xs font-black text-slate-400 uppercase tracking-widest pl-2 w-full lg:w-auto">Quick Add:</span>
                    <button type="button" onclick="quickFill('Electricity Bill')" class="bg-amber-100 text-amber-700 hover:bg-amber-200 px-4 py-2 rounded-xl text-sm font-bold shadow-sm transition-all flex items-center gap-2">
                        <i class="fa-solid fa-bolt"></i> <span class="hidden sm:inline">Electricity</span><span class="sm:hidden">Elect</span>
                    </button>
                    <button type="button" onclick="quickFill('Water Bill')" class="bg-blue-100 text-blue-700 hover:bg-blue-200 px-4 py-2 rounded-xl text-sm font-bold shadow-sm transition-all flex items-center gap-2">
                        <i class="fa-solid fa-droplet"></i> <span class="hidden sm:inline">Water Bill</span><span class="sm:hidden">Water</span>
                    </button>
                    <button type="button" onclick="quickFill('Tax Payment')" class="bg-rose-100 text-rose-700 hover:bg-rose-200 px-4 py-2 rounded-xl text-sm font-bold shadow-sm transition-all flex items-center gap-2">
                        <i class="fa-solid fa-file-invoice-dollar"></i> Tax
                    </button>
                    <button type="button" onclick="quickFill('Rent')" class="bg-emerald-100 text-emerald-700 hover:bg-emerald-200 px-4 py-2 rounded-xl text-sm font-bold shadow-sm transition-all flex items-center gap-2">
                        <i class="fa-solid fa-house"></i> Rent
                    </button>
                </div>
                
                <!-- Stat Summary -->
                <div class="bg-slate-900 text-white px-6 py-2 rounded-xl shadow-xl shadow-slate-900/20 flex items-center gap-4 w-full lg:w-auto">
                    <i class="fa-solid fa-calculator opacity-50"></i>
                    <div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Total Filtered</p>
                        <p class="text-lg font-black leading-tight">LKR <?php echo number_format($total_expense, 2); ?></p>
                    </div>
                </div>
            </div>

            <div class="glass-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse min-w-[700px]">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200">
                            <th class="py-4 px-6 text-xs font-black text-slate-500 uppercase tracking-widest w-[120px]">Date</th>
                            <th class="py-4 px-6 text-xs font-black text-slate-500 uppercase tracking-widest">Expense Category / Name</th>
                            <th class="py-4 px-6 text-xs font-black text-slate-500 uppercase tracking-widest text-right w-[200px]">Amount (LKR)</th>
                            <th class="py-4 px-6 text-xs font-black text-slate-500 uppercase tracking-widest text-center w-[100px]">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($expenses as $ex): ?>
                        <tr class="border-b border-slate-100 hover:bg-slate-50/50 transition-colors group">
                            <td class="py-3 px-6">
                                <span class="bg-slate-100 text-slate-600 px-3 py-1 rounded-md text-xs font-bold font-mono">
                                    <?php echo date('Y-m-d', strtotime($ex['expense_date'])); ?>
                                </span>
                            </td>
                            <td class="py-3 px-6 text-sm font-black text-slate-800">
                                <?php echo htmlspecialchars($ex['expense_name']); ?>
                            </td>
                            <td class="py-3 px-6 text-sm font-black text-rose-600 text-right">
                                <?php echo number_format($ex['amount'], 2); ?>
                            </td>
                            <td class="py-3 px-6 text-center">
                                <a href="?delete=<?php echo $ex['id']; ?>" onclick="return confirm('Are you sure you want to delete this expense?')" class="w-8 h-8 rounded-lg bg-rose-50 text-rose-500 flex items-center justify-center opacity-0 group-hover:opacity-100 hover:bg-rose-500 hover:text-white transition-all mx-auto">
                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <!-- Inline Add Expense Form Row (Always at the bottom) -->
                        <tr class="bg-indigo-50/30 border-t-2 border-indigo-100 hover:bg-indigo-50 transition-colors">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="add_expense">
                                <td class="py-4 px-6">
                                    <input type="date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" required class="inline-input font-mono text-sm text-indigo-900 border-indigo-200">
                                </td>
                                <td class="py-4 px-6">
                                    <input type="text" id="new_expense_name" name="expense_name" placeholder="Type new expense name..." required class="inline-input text-sm text-indigo-900 border-indigo-200 placeholder-indigo-300">
                                </td>
                                <td class="py-4 px-6">
                                    <div class="relative flex items-center">
                                        <span class="absolute left-0 text-xs font-black text-indigo-400 pl-2">LKR</span>
                                        <input type="number" id="new_expense_amount" name="amount" step="0.01" min="0.01" placeholder="0.00" required class="inline-input pl-10 text-right text-sm text-indigo-900 border-indigo-200 placeholder-indigo-300 font-bold">
                                    </div>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <button type="submit" class="w-full bg-indigo-600 text-white rounded-xl py-2 text-xs font-black uppercase tracking-widest hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20 flex items-center justify-center gap-2">
                                        <i class="fa-solid fa-plus"></i> Add
                                    </button>
                                </td>
                            </form>
                        </tr>
                    </tbody>
                </table>
            </div>
            
        </div>
    </div>

    <!-- Script down below for Quick Buttons -->
    <script>
        function quickFill(name) {
            const inputName = document.getElementById('new_expense_name');
            const inputAmount = document.getElementById('new_expense_amount');
            
            inputName.value = name;
            
            // Add a brief highlight flash animation
            inputName.style.backgroundColor = '#fef08a';
            setTimeout(() => {
                inputName.style.backgroundColor = 'transparent';
            }, 300);
            
            inputAmount.focus();
        }
    </script>
</body>
</html>
