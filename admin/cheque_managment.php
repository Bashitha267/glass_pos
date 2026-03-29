<?php
require_once '../auth.php';

// Restrict to admins
if (!isAdmin()) {
    header('Location: ../sale/dashboard.php');
    exit();
}

$current_tab = $_GET['tab'] ?? 'received';
$search = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$bank_id = $_GET['bank_id'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 8;
$offset = ($page - 1) * $limit;

// Fetch Banks for Dropdown
$banks = $pdo->query("SELECT id, name FROM banks ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Queries
$where = "WHERE 1=1";
$params = [];

if ($search !== '') {
    if ($current_tab === 'received') {
        $where .= " AND (cheque_id LIKE ? OR customer_name LIKE ? OR order_id LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
    } else {
        $where .= " AND (cheque_id LIKE ? OR buyer_name LIKE ? OR order_id LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
    }
}

if ($start_date !== '') {
    $where .= " AND date >= ?";
    $params[] = $start_date;
}

if ($end_date !== '') {
    $where .= " AND date <= ?";
    $params[] = $end_date;
}

if ($bank_id !== '') {
    $where .= " AND bank_id = ?";
    $params[] = $bank_id;
}

if ($current_tab === 'received') {
    $baseQuery = "
        SELECT
            'POS' as source, psp.id as internal_id, psp.payment_date as date, psp.cheque_number as cheque_id,
            COALESCE(psp.cheque_payer_name, c.name, 'Walk-in Customer') as customer_name,
            psp.amount as value, b.id as bank_id, b.name as bank_name, ps.bill_id as order_id
        FROM pos_sale_payments psp
        JOIN pos_sales ps ON psp.sale_id = ps.id
        LEFT JOIN customers c ON ps.customer_id = c.id
        LEFT JOIN banks b ON psp.bank_id = b.id
        WHERE psp.payment_type = 'Cheque'
        UNION ALL
        SELECT
            'Delivery' as source, dp.id as internal_id, dp.payment_date as date, dp.cheque_number as cheque_id,
            COALESCE(dp.cheque_payer_name, c.name, 'Unknown') as customer_name,
            dp.amount as value, b.id as bank_id, b.name as bank_name, dc.bill_number as order_id
        FROM delivery_payments dp
        JOIN delivery_customers dc ON dp.delivery_customer_id = dc.id
        LEFT JOIN customers c ON dc.customer_id = c.id
        LEFT JOIN banks b ON dp.bank_id = b.id
        WHERE dp.payment_type = 'Cheque'
    ";
} else {
    $baseQuery = "
        SELECT
            'Container' as source, cp.id as internal_id, cp.payment_date as date, cp.payment_id as cheque_id,
            'Supplier' as buyer_name,
            cp.amount as value, NULL as bank_id, NULL as bank_name, c.container_number as order_id
        FROM container_payments cp
        JOIN containers c ON cp.container_id = c.id
        WHERE cp.method = 'Cheque'
        UNION ALL
        SELECT
            'Other Purchase' as source, opp.id as internal_id, opp.payment_date as date, opp.cheque_number as cheque_id,
            op.buyer_name as buyer_name,
            opp.amount as value, b.id as bank_id, b.name as bank_name, op.purchase_number as order_id
        FROM other_purchase_payments opp
        JOIN other_purchases op ON opp.purchase_id = op.id
        LEFT JOIN banks b ON opp.bank_id = b.id
        WHERE opp.payment_type = 'Cheque'
    ";
}

$countQuery = "SELECT COUNT(*) FROM ($baseQuery) as combined $where";
$stmt = $pdo->prepare($countQuery);
$stmt->execute($params);
$total_records = $stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

$query = "SELECT * FROM ($baseQuery) as combined $where ORDER BY date DESC, internal_id DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cheque Management | Crystal POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@300;400;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background: url('../assests/glass_bg.png') no-repeat center center fixed;
            background-size: cover;
            color: #0f172a;
            min-height: 100vh;
        }
        .glass-header {
            background: rgba(241, 245, 249, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            box-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.08);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 1);
            border-radius: 20px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .input-glass {
            background: rgba(255, 255, 255, 0.6);
            border: 1px solid rgba(203, 213, 225, 0.6);
            color: #1e293b;
            padding: 8px 12px;
            border-radius: 12px;
            outline: none;
            transition: all 0.3s;
        }
        .input-glass:focus {
            border-color: #0891b2;
            background: white;
            box-shadow: 0 0 20px rgba(8, 145, 178, 0.1);
        }
        .tab-btn {
            padding: 12px 24px;
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            color: #64748b;
        }
        .tab-btn.active {
            border-bottom-color: #0891b2;
            color: #0891b2;
            background: rgba(8, 145, 178, 0.05);
        }
        .auto-search {
            transition: border-color 0.2s;
        }
    </style>
</head>
<body class="flex flex-col">

    <header class="glass-header sticky top-0 z-40 py-3">
        <div class="px-10 flex items-center justify-between">
            <div class="flex items-center space-x-3 sm:space-x-4">
                <a href="dashboard.php" class="text-slate-800 hover:text-cyan-600 transition-colors">
                    <i class="fa-solid fa-arrow-left text-lg sm:text-xl"></i>
                </a>
                <h1 class="text-xl sm:text-2xl font-bold tracking-tight uppercase text-slate-800 font-['Outfit']">
                    Cheque Management
                </h1>
            </div>
            <div>
                <!-- Add options like Export if needed in the future -->
            </div>
        </div>
        <!-- Tabs -->
        <div class="px-10 mt-4 flex border-b border-slate-200">
            <a href="?tab=received" class="tab-btn <?php echo $current_tab === 'received' ? 'active' : ''; ?>">Cheques Received</a>
            <a href="?tab=given" class="tab-btn <?php echo $current_tab === 'given' ? 'active' : ''; ?>">Cheques Given</a>
        </div>
    </header>

    <main class="w-full px-6 py-8 sm:py-10 flex-grow">
        <!-- Filters Bar -->
        <div class="glass-card bg-slate-800/80 p-4 sm:p-6 mb-8 border-slate-700">
            <form id="filter-form" method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4 items-end">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($current_tab); ?>">
                
                <div class="sm:col-span-2 lg:col-span-2 relative">
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">Search</label>
                    <div class="relative">
                        <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                            placeholder="<?php echo $current_tab === 'received' ? 'Cheque No, Customer, Order ID...' : 'Cheque No, Buyer, Purchase ID...'; ?>" 
                            class="px-3 py-2 rounded-xl outline-none transition-all border focus:border-cyan-500 w-full pl-10 bg-slate-900/40 border-slate-700 text-white placeholder:text-slate-500 focus:ring-2 focus:ring-cyan-500/50 auto-search">
                    </div>
                </div>

                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">Start Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" 
                        class="px-3 py-2 rounded-xl outline-none transition-all border focus:border-cyan-500 w-full bg-slate-900/40 border-slate-700 text-white focus:ring-2 focus:ring-cyan-500/50 auto-search">
                </div>

                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">End Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" 
                        class="px-3 py-2 rounded-xl outline-none transition-all border focus:border-cyan-500 w-full bg-slate-900/40 border-slate-700 text-white focus:ring-2 focus:ring-cyan-500/50 auto-search">
                </div>

                <div>
                    <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">Bank</label>
                    <select name="bank_id" class="px-3 py-2 rounded-xl outline-none transition-all border focus:border-cyan-500 w-full bg-slate-900/40 border-slate-700 text-white focus:ring-2 focus:ring-cyan-500/50 auto-search">
                        <option value="" class="bg-slate-800">All Banks</option>
                        <?php foreach($banks as $b): ?>
                            <option value="<?php echo $b['id']; ?>" class="bg-slate-800" <?php echo $bank_id == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex space-x-2">
                    <a href="?tab=<?php echo $current_tab; ?>" 
                        class="bg-rose-500/20 text-rose-400 p-2.5 px-4 rounded-xl hover:bg-rose-500/30 transition-all flex items-center h-[42px] w-full justify-center" 
                        title="Reset Filters">
                        <i class="fa-solid fa-rotate-left mr-2"></i>
                        <span class="text-xs font-bold uppercase tracking-wider">Reset</span>
                    </a>
                </div>
            </form>
        </div>

        <!-- Cheque List -->
        <div class="glass-card bg-white/80 overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left min-w-[900px]">
                    <thead>
                        <?php if ($current_tab === 'received'): ?>
                            <tr class="bg-slate-800 text-[11px] uppercase font-black tracking-widest text-slate-200 border-b border-slate-700">
                                <th class="px-5 py-4 w-16 text-center">#</th>
                                <th class="px-5 py-4">Cheque Number</th>
                                <th class="px-5 py-4">Date</th>
                                <th class="px-5 py-4">Customer Name</th>
                                <th class="px-5 py-4 text-emerald-400">Value (LKR)</th>
                                <th class="px-5 py-4">Bank Details</th>
                                <th class="px-5 py-4">Order / Bill ID</th>
                                <th class="px-5 py-4 text-center">Source</th>
                            </tr>
                        <?php else: ?>
                            <tr class="bg-indigo-900 text-[11px] uppercase font-black tracking-widest text-indigo-100 border-b border-indigo-800">
                                <th class="px-5 py-4 w-16 text-center">#</th>
                                <th class="px-5 py-4">Cheque Number</th>
                                <th class="px-5 py-4">Date</th>
                                <th class="px-5 py-4">Buyer Name</th>
                                <th class="px-5 py-4 text-rose-400">Value (LKR)</th>
                                <th class="px-5 py-4">Bank Details</th>
                                <th class="px-5 py-4">Purchase ID</th>
                                <th class="px-5 py-4 text-center">Source</th>
                            </tr>
                        <?php endif; ?>
                    </thead>
                    <tbody class="divide-y divide-slate-100/60">
                        <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center text-slate-500 font-medium">
                                    <div class="flex flex-col items-center justify-center space-y-3">
                                        <i class="fa-solid fa-money-check text-4xl text-slate-300"></i>
                                        <p>No cheques found matching your criteria.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($records as $index => $r): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors group">
                                <td class="px-5 py-4 text-xs font-bold text-slate-400 text-center"><?php echo $offset + $index + 1; ?></td>
                                <td class="px-5 py-4 text-sm font-black text-slate-800">
                                    <?php echo htmlspecialchars($r['cheque_id'] ?: 'N/A'); ?>
                                </td>
                                <td class="px-5 py-4 text-sm font-semibold text-slate-500">
                                    <?php echo date('Y-m-d', strtotime($r['date'])); ?>
                                </td>
                                <td class="px-5 py-4 text-sm font-bold text-slate-700">
                                    <?php 
                                        if ($current_tab === 'received') {
                                            echo htmlspecialchars($r['customer_name']); 
                                        } else {
                                            echo htmlspecialchars($r['buyer_name']);
                                        }
                                    ?>
                                </td>
                                <td class="px-5 py-4 text-sm font-black <?php echo $current_tab === 'received' ? 'text-emerald-600' : 'text-rose-600'; ?>">
                                    <?php echo number_format($r['value'], 2); ?>
                                </td>
                                <td class="px-5 py-4 text-sm font-semibold text-indigo-600">
                                    <?php echo htmlspecialchars($r['bank_name'] ?: 'Not Specified'); ?>
                                </td>
                                <td class="px-5 py-4 text-sm font-medium text-slate-500 italic">
                                    <?php echo htmlspecialchars($r['order_id'] ?: '-'); ?>
                                </td>
                                <td class="px-5 py-4 text-center">
                                    <span class="inline-flex items-center justify-center px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-wider
                                        <?php
                                            if ($r['source'] == 'POS') echo 'bg-cyan-100 text-cyan-700';
                                            else if ($r['source'] == 'Delivery') echo 'bg-amber-100 text-amber-700';
                                            else if ($r['source'] == 'Container') echo 'bg-blue-100 text-blue-700';
                                            else echo 'bg-purple-100 text-purple-700'; // Other Purchase
                                        ?>
                                    ">
                                        <?php echo htmlspecialchars($r['source']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 border-t border-slate-100 bg-slate-50/50 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="text-[10px] uppercase font-bold text-slate-500 tracking-widest">
                        Showing <span class="text-slate-800"><?php echo $offset + 1; ?></span> to 
                        <span class="text-slate-800"><?php echo min($offset + $limit, $total_records); ?></span> of 
                        <span class="text-slate-800"><?php echo $total_records; ?></span> entries
                    </div>
                    
                    <div class="flex items-center space-x-1">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                                class="w-8 h-8 flex items-center justify-center bg-white border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-cyan-600 rounded-lg transition-colors">
                                <i class="fa-solid fa-chevron-left text-xs"></i>
                            </a>
                        <?php endif; ?>

                        <?php 
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        for ($i = $start; $i <= $end; $i++): 
                        ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                class="w-8 h-8 flex items-center justify-center border font-bold text-xs rounded-lg transition-colors
                                <?php echo $page == $i ? 'bg-cyan-600 border-cyan-600 text-white shadow-md' : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-cyan-600'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                                class="w-8 h-8 flex items-center justify-center bg-white border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-cyan-600 rounded-lg transition-colors">
                                <i class="fa-solid fa-chevron-right text-xs"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Auto-Submit Filter Script -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('filter-form');
            const inputs = form.querySelectorAll('.auto-search');
            let timeout = null;

            inputs.forEach(input => {
                input.addEventListener('input', () => {
                    clearTimeout(timeout);
                    timeout = setTimeout(() => {
                        form.submit();
                    }, 600); // 600ms debounce
                });

                // Also trigger immediately on change for selects and dates
                if (input.tagName === 'SELECT' || input.type === 'date') {
                    input.addEventListener('change', () => {
                        form.submit();
                    });
                }
            });
        });
    </script>
</body>
</html>
