<?php
require_once '../auth.php';
require_once '../config.php';
checkAuth();

if (!isAdmin()) {
    header('Location: ../sale/dashboard.php');
    exit;
}

$username = $_SESSION['username'];

// Handle AJAX actions for auto-suggestion
if (isset($_GET['action']) && $_GET['action'] == 'suggest') {
    $term = '%' . $_GET['term'] . '%';
    $idTerm = '%' . ltrim(str_ireplace('DEL-', '', $_GET['term']), '0') . '%';
    
    $stmt = $pdo->prepare("
        SELECT DISTINCT container_number as suggest FROM containers WHERE container_number LIKE ?
        UNION
        SELECT DISTINCT name FROM customers WHERE name LIKE ?
        UNION
        SELECT DISTINCT cheque_number FROM delivery_payments WHERE cheque_number LIKE ? AND cheque_number IS NOT NULL AND cheque_number != ''
        UNION
        SELECT DISTINCT CONCAT('DEL-', LPAD(id, 4, '0')) FROM deliveries WHERE id LIKE ? OR CONCAT('DEL-', LPAD(id, 4, '0')) LIKE ?
        LIMIT 8
    ");
    $stmt->execute([$term, $term, $term, $idTerm, $term]);
    echo json_encode(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN)));
    exit;
}

// Filters
$search = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$type_filter = $_GET['type'] ?? '';

// Pagination
$limit = 8;
$page = isset($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

if ($search) {
    $where[] = "(reference_id LIKE ? OR entity_name LIKE ? OR cheque_or_notes LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($start_date) {
    $where[] = "pay_date >= ?";
    $params[] = $start_date;
}

if ($end_date) {
    $where[] = "pay_date <= ?";
    $params[] = $end_date;
}

if ($type_filter) {
    $where[] = "source_type = ?";
    $params[] = $type_filter;
}

$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

$unifiedQuery = "
    SELECT 
        'container' as source_type,
        cp.id as pay_id,
        DATE(cp.payment_date) as pay_date,
        cp.amount,
        cp.method as pay_method,
        c.container_number as reference_id,
        'Container Expense' as entity_name,
        cp.description as cheque_or_notes,
        NULL as proof
    FROM container_payments cp
    JOIN containers c ON cp.container_id = c.id

    UNION ALL

    SELECT 
        'delivery' as source_type,
        dp.id as pay_id,
        DATE(dp.payment_date) as pay_date,
        dp.amount,
        dp.payment_type as pay_method,
        CONCAT('DEL-', LPAD(IFNULL(dc.delivery_id, 0), 4, '0')) as reference_id,
        IFNULL(cust.name, '(Unknown Customer)') as entity_name,
        TRIM(CONCAT_WS(' ', IFNULL(dp.cheque_number, ''), IFNULL(bk.name, ''))) as cheque_or_notes,
        dp.proof_image as proof
    FROM delivery_payments dp
    LEFT JOIN delivery_customers dc ON dp.delivery_customer_id = dc.id
    LEFT JOIN customers cust ON dc.customer_id = cust.id
    LEFT JOIN banks bk ON dp.bank_id = bk.id
";

// Total Records for Pagination
$countQuery = "SELECT COUNT(*) FROM ($unifiedQuery) as U $whereClause";
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$total_records = $countStmt->fetchColumn();
$total_pages = max(1, ceil($total_records / $limit));

// Fetch Records
$query = "SELECT * FROM ($unifiedQuery) as U $whereClause ORDER BY pay_date DESC, pay_id DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

function formatCurrency($amount) {
    return 'LKR ' . number_format((float)$amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History | Crystal POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;600;700;900&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background: url('../assests/glass_bg.png') no-repeat center center fixed;
            background-size: cover;
            color: #1e293b;
            min-height: 100vh;
        }

        .glass-header {
            background: rgba(248, 250, 252, 0.92);
            backdrop-filter: blur(24px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            box-shadow: 0 4px 20px -5px rgba(0, 0, 0, 0.05);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 1);
            border-radius: 24px;
            box-shadow: 0 10px 40px -10px rgba(0,0,0,0.08);
        }

        .input-glass {
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid #e2e8f0;
            padding: 10px 16px;
            border-radius: 14px;
            outline: none;
            transition: all 0.3s;
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
        }

        .input-glass:focus {
            border-color: #0891b2;
            background: white;
            box-shadow: 0 0 15px rgba(8, 145, 178, 0.1);
        }

        .table-header {
            background: #0f172a;
            color: white;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-weight: 800;
        }

        .suggestion-box {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-top: 5px;
            z-index: 50;
            display: none;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .suggestion-item {
            padding: 10px 15px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
            color: #475569;
        }

        .suggestion-item:hover {
            background: #f1f5f9;
            color: #0891b2;
        }
    </style>
</head>
<body class="flex flex-col">

    <header class="glass-header sticky top-0 z-40 py-4">
        <div class="max-w-7xl mx-auto px-6 flex items-center justify-between">
            <div class="flex items-center space-x-5">
                <a href="dashboard.php" class="text-slate-800 hover:text-cyan-600 transition-colors p-2.5 rounded-2xl hover:bg-slate-100 flex items-center justify-center">
                    <i class="fa-solid fa-arrow-left text-xl"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-black text-slate-900 font-['Outfit'] tracking-tight">Payment History</h1>
                    <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mt-0.5">Comprehensive Financial Logs</p>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto w-full px-6 py-8">
        <!-- Filters -->
        <div class="glass-card p-6 mb-8 border-slate-200">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-5 items-end">
                <div class="md:col-span-4 relative">
                    <label class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Search</label>
                    <div class="relative group">
                        <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="text" name="search" id="search-input" autocomplete="off" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search ID, Client or Cheque..." class="input-glass w-full pl-12 h-[45px]">
                        <div id="suggestions" class="suggestion-box"></div>
                    </div>
                </div>
                <div class="md:col-span-2 relative">
                    <label class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Type</label>
                    <select name="type" class="input-glass w-full h-[45px] appearance-none" onchange="this.form.submit()">
                        <option value="">All Payments</option>
                        <option value="container" <?php echo $type_filter === 'container' ? 'selected' : ''; ?>>Container Expenses</option>
                        <option value="delivery" <?php echo $type_filter === 'delivery' ? 'selected' : ''; ?>>Customer Payments</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">Start Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="input-glass w-full h-[45px]" onchange="this.form.submit()">
                </div>
                <div class="md:col-span-2">
                    <label class="text-[10px] uppercase font-black text-slate-500 mb-2 ml-1 block tracking-widest">End Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="input-glass w-full h-[45px]" onchange="this.form.submit()">
                </div>
                <div class="md:col-span-2">
                    <a href="finance.php" class="w-full h-[45px] bg-slate-100 text-slate-600 rounded-xl hover:bg-slate-200 transition-all flex items-center justify-center font-bold uppercase text-[10px] tracking-widest">
                        <i class="fa-solid fa-rotate-left mr-2"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="table-header">
                            <th class="px-6 py-5 font-bold">Date</th>
                            <th class="px-6 py-5 font-bold">Source & ID</th>
                            <th class="px-6 py-5 font-bold">Entity</th>
                            <th class="px-6 py-5 font-bold text-center">Method</th>
                            <th class="px-6 py-5 font-bold">Reference/Cheque Details</th>
                            <th class="px-6 py-5 font-bold text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100/50">
                        <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-16 text-center text-slate-400 font-bold uppercase tracking-widest text-[11px]">No payment records found.</td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php foreach ($payments as $pay): ?>
                        <tr class="odd:bg-gray-50/40 even:bg-white/40 hover:bg-cyan-500/5 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-[12px] font-bold text-slate-800"><?php echo date('Y-m-d', strtotime($pay['pay_date'])); ?></div>
                                <div class="text-[10px] text-slate-500 font-medium">Logged Payment</div>
                            </td>
                            <td class="px-6 py-4 font-bold text-cyan-600 text-sm">
                                <?php echo htmlspecialchars($pay['reference_id']); ?>
                                <span class="text-[9px] px-2 py-0.5 rounded-full bg-slate-200 text-slate-600 block w-fit mt-1 uppercase">
                                    <?php echo $pay['source_type'] === 'container' ? 'Container Exp' : 'Customer Pay'; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm font-semibold text-slate-700 max-w-xs truncate">
                                <?php echo htmlspecialchars($pay['entity_name']); ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php 
                                    $class = "bg-slate-100 text-slate-600";
                                    switch($pay['pay_method']) {
                                        case 'Cash': $class = "bg-emerald-100 text-emerald-700"; break;
                                        case 'Card': $class = "bg-cyan-100 text-cyan-700"; break;
                                        case 'Cheque': $class = "bg-amber-100 text-amber-700"; break;
                                        case 'Account Transfer': $class = "bg-blue-100 text-blue-700"; break;
                                    }
                                ?>
                                <span class="px-3 py-1 rounded-lg text-[10px] font-extrabold uppercase tracking-tight <?php echo $class; ?>">
                                    <?php echo htmlspecialchars($pay['pay_method']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-500 leading-tight italic max-w-xs truncate">
                                <?php echo htmlspecialchars($pay['cheque_or_notes'] ?: '-'); ?>
                            </td>
                            <td class="px-6 py-4 text-[11px] font-bold text-right whitespace-nowrap">
                                <span class="<?php echo $pay['source_type'] === 'container' ? 'text-rose-600' : 'text-emerald-600'; ?>">
                                    <?php echo formatCurrency($pay['amount']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="px-6 py-5 bg-slate-50/40 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="text-[10px] uppercase font-black tracking-widest text-slate-400">
                    Showing <span class="text-slate-700"><?php echo $offset + 1; ?></span> to <span class="text-slate-700"><?php echo min($offset + $limit, $total_records); ?></span> of <span class="text-slate-700"><?php echo $total_records; ?></span> entries
                </div>
                <div class="flex space-x-1">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="w-9 h-9 flex items-center justify-center bg-white border border-slate-200 rounded-xl hover:bg-slate-50 text-slate-400 shadow-sm transition-all"><i class="fa-solid fa-chevron-left text-[10px]"></i></a>
                    <?php endif; ?>
                    
                    <?php 
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    for ($i = $start; $i <= $end; $i++): 
                    ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="w-9 h-9 flex items-center justify-center rounded-xl text-xs font-black transition-all shadow-sm <?php echo $page == $i ? 'bg-indigo-600 text-white shadow-indigo-600/30' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50'; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="w-9 h-9 flex items-center justify-center bg-white border border-slate-200 rounded-xl hover:bg-slate-50 text-slate-400 shadow-sm transition-all"><i class="fa-solid fa-chevron-right text-[10px]"></i></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        const searchInput = document.getElementById('search-input');
        const suggestionBox = document.getElementById('suggestions');
        let suggestTimeout;

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                clearTimeout(suggestTimeout);

                if (query.length < 2) {
                    suggestionBox.style.display = 'none';
                    return;
                }

                suggestTimeout = setTimeout(() => {
                    fetch(`finance.php?action=suggest&term=${encodeURIComponent(query)}`)
                        .then(r => r.json())
                        .then(data => {
                            if (data.length > 0) {
                                suggestionBox.innerHTML = data.map(item => `<div class="suggestion-item">${item}</div>`).join('');
                                suggestionBox.style.display = 'block';

                                document.querySelectorAll('.suggestion-item').forEach(div => {
                                    div.addEventListener('click', () => {
                                        searchInput.value = div.innerText;
                                        suggestionBox.style.display = 'none';
                                        searchInput.closest('form').submit();
                                    });
                                });
                            } else {
                                suggestionBox.style.display = 'none';
                            }
                        });
                }, 300);
            });

            document.addEventListener('click', (e) => {
                if (!searchInput.contains(e.target) && !suggestionBox.contains(e.target)) {
                    suggestionBox.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
