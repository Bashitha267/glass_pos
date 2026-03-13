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
    $stmt = $pdo->prepare("SELECT DISTINCT container_number as suggest FROM containers WHERE container_number LIKE ? 
                           UNION 
                           SELECT DISTINCT name FROM brands WHERE name LIKE ? 
                           LIMIT 10");
    $stmt->execute([$term, $term]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    exit;
}

// 1. Prepare Filter variables
$search = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Pagination settings (8 by 8 as requested)
$limit = 8;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

if ($search) {
    $where[] = "(c.container_number LIKE ? OR b.name LIKE ? OR u.username LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($start_date) { $where[] = "l.changed_at >= ?"; $params[] = $start_date . ' 00:00:00'; }
if ($end_date) { $where[] = "l.changed_at <= ?"; $params[] = $end_date . ' 23:59:59'; }

$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

// Count total for pagination
$countQuery = "SELECT COUNT(DISTINCT l.id) FROM container_ledger l
               JOIN users u ON l.changed_by = u.id
               JOIN containers c ON l.container_id = c.id
               LEFT JOIN container_items ci ON c.id = ci.container_id
               LEFT JOIN brands b ON ci.brand_id = b.id
               $whereClause";
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$total_records = $countStmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Fetch Ledger Data with User, Container, and Brand info
$query = "
    SELECT l.*, u.username as actor, c.container_number, b.name as brand_name
    FROM container_ledger l
    JOIN users u ON l.changed_by = u.id
    JOIN containers c ON l.container_id = c.id
    LEFT JOIN (SELECT container_id, MIN(brand_id) as brand_id FROM container_items GROUP BY container_id) ci ON c.id = ci.container_id
    LEFT JOIN brands b ON ci.brand_id = b.id
    $whereClause
    ORDER BY l.changed_at DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$ledger = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Container Ledger | Crystal POS</title>
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
            padding: 10px 16px;
            border-radius: 12px;
            outline: none;
            transition: all 0.3s;
        }

        .input-glass:focus {
            border-color: #0891b2;
            background: white;
            box-shadow: 0 0 20px rgba(8, 145, 178, 0.1);
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
            transition: all 0.2s;
            color: #475569;
        }

        .suggestion-item:hover {
            background: #f1f5f9;
            color: #0891b2;
        }
        
        .table-header {
            background: #1e293b;
            color: white;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
    </style>
</head>
<body class="flex flex-col">

    <!-- Header -->
    <header class="glass-header sticky top-0 z-40 py-4">
        <div class="max-w-7xl mx-auto px-6 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="dashboard.php" class="text-slate-800 hover:text-cyan-600 transition-colors p-2 rounded-xl hover:bg-slate-100">
                    <i class="fa-solid fa-arrow-left text-xl"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-800 font-['Outfit']">Container Ledger</h1>
                    <p class="text-[10px] uppercase font-bold text-slate-500 tracking-wider">Audit Trail of System Changes</p>
                </div>
            </div>
            <div class="hidden md:block">
                <div class="text-right">
                    <p class="text-xs font-bold text-slate-800"><?php echo date('l, F jS'); ?></p>
                    <p class="text-[10px] text-cyan-600 uppercase font-black tracking-widest">Live Monitoring</p>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto w-full px-6 py-8">
        <!-- Filters -->
        <div class="glass-card p-6 mb-8 border-slate-200">
            <form method="GET" id="filter-form" class="grid grid-cols-1 md:grid-cols-12 gap-6 items-end">
                <div class="md:col-span-6 relative">
                    <label class="text-[10px] uppercase font-black text-slate-600 mb-2 ml-1 block tracking-widest">Search</label>
                    <div class="relative group">
                        <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="text" name="search" id="search-input" autocomplete="off" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search ID, Brand or Actor..." class="input-glass w-full pl-12 h-[48px] text-sm">
                        <div id="suggestions" class="suggestion-box"></div>
                    </div>
                </div>
                <div class="md:col-span-2">
                    <label class="text-[10px] uppercase font-black text-slate-600 mb-2 ml-1 block tracking-widest">Start Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="input-glass w-full h-[48px] text-sm" onchange="this.form.submit()">
                </div>
                <div class="md:col-span-2">
                    <label class="text-[10px] uppercase font-black text-slate-600 mb-2 ml-1 block tracking-widest">End Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="input-glass w-full h-[48px] text-sm" onchange="this.form.submit()">
                </div>
                <div class="md:col-span-2">
                    <a href="ledger.php" class="w-full h-[48px] bg-slate-100 text-slate-600 rounded-xl hover:bg-slate-200 transition-all flex items-center justify-center font-bold uppercase text-xs tracking-widest">
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
                            <th class="px-6 py-5 font-bold">Timestamp</th>
                            <th class="px-6 py-5 font-bold">Container</th>
                            <th class="px-6 py-5 font-bold text-center">Action</th>
                            <th class="px-6 py-5 font-bold">Actor</th>
                            <th class="px-6 py-5 font-bold">Description</th>
                            <th class="px-6 py-5 font-bold text-center">Data Changes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($ledger)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-16 text-center text-slate-500 italic">No activity logs found.</td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($ledger as $entry): ?>
                        <tr class="odd:bg-gray-50/40 even:bg-white/40 hover:bg-cyan-500/5 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-[12px] font-bold text-slate-800"><?php echo date('Y-m-d', strtotime($entry['changed_at'])); ?></div>
                                <div class="text-[10px] text-slate-500 font-medium"><?php echo date('h:i A', strtotime($entry['changed_at'])); ?></div>
                            </td>
                            <td class="px-6 py-4 font-bold text-cyan-600 text-sm">
                                <?php echo htmlspecialchars($entry['container_number']); ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php 
                                    $class = "bg-slate-100 text-slate-600";
                                    switch($entry['action_type']) {
                                        case 'CREATED': $class = "bg-emerald-100 text-emerald-700"; break;
                                        case 'UPDATED': $class = "bg-cyan-100 text-cyan-700"; break;
                                        case 'PAYMENT': $class = "bg-amber-100 text-amber-700"; break;
                                        case 'EXPENSE': $class = "bg-rose-100 text-rose-700"; break;
                                        case 'UPDATE': $class = "bg-blue-100 text-blue-700"; break;
                                    }
                                ?>
                                <span class="px-3 py-1 rounded-lg text-[10px] font-extrabold uppercase tracking-tight <?php echo $class; ?>">
                                    <?php echo htmlspecialchars($entry['action_type']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm font-semibold text-slate-700"><?php echo htmlspecialchars($entry['actor']); ?></td>
                            <td class="px-6 py-4 text-xs text-slate-500 leading-tight italic"><?php echo htmlspecialchars($entry['field_name'] ?? '-'); ?></td>
                            <td class="px-6 py-4 text-[11px] text-center whitespace-nowrap">
                                <?php if ($entry['old_value'] !== null && $entry['action_type'] == 'UPDATE'): ?>
                                    <span class="text-rose-500 line-through opacity-60"><?php echo htmlspecialchars($entry['old_value']); ?></span>
                                    <i class="fa-solid fa-arrow-right mx-2 text-slate-300"></i>
                                    <span class="text-emerald-600 font-bold"><?php echo htmlspecialchars($entry['new_value']); ?></span>
                                <?php else: ?>
                                    <span class="text-slate-800 font-bold"><?php echo htmlspecialchars($entry['new_value'] ?? '-'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="px-6 py-5 bg-slate-50 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="text-[10px] uppercase font-bold tracking-widest text-slate-500">
                    Showing <span class="text-slate-800"><?php echo $offset + 1; ?></span> to <span class="text-slate-800"><?php echo min($offset + $limit, $total_records); ?></span> of <span class="text-slate-800"><?php echo $total_records; ?></span> entries
                </div>
                <div class="flex space-x-1">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="w-10 h-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl hover:bg-slate-50 transition-all text-slate-400">
                            <i class="fa-solid fa-chevron-left text-xs"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php 
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    for ($i = $start; $i <= $end; $i++): 
                    ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="w-10 h-10 flex items-center justify-center rounded-xl text-xs font-bold transition-all <?php echo $page == $i ? 'bg-cyan-600 text-white shadow-lg shadow-cyan-900/20' : 'bg-white border border-slate-200 text-slate-400 hover:bg-slate-50'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="w-10 h-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl hover:bg-slate-50 transition-all text-slate-400">
                            <i class="fa-solid fa-chevron-right text-xs"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="mt-auto py-8 text-center text-[10px] text-slate-400 uppercase tracking-widest font-bold">
        Crystal POS Audit System &copy; <?php echo date('Y'); ?>
    </footer>

    <script>
        const searchInput = document.getElementById('search-input');
        const suggestionBox = document.getElementById('suggestions');
        let suggestTimeout;

        searchInput.addEventListener('input', function() {
            const query = this.value.trim();
            clearTimeout(suggestTimeout);

            if (query.length < 2) {
                suggestionBox.style.display = 'none';
                return;
            }

            suggestTimeout = setTimeout(() => {
                fetch(`ledger.php?action=suggest&term=${encodeURIComponent(query)}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.length > 0) {
                            suggestionBox.innerHTML = data.map(item => `<div class="suggestion-item">${item}</div>`).join('');
                            suggestionBox.style.display = 'block';

                            document.querySelectorAll('.suggestion-item').forEach(div => {
                                div.addEventListener('click', () => {
                                    searchInput.value = div.innerText;
                                    suggestionBox.style.display = 'none';
                                    document.getElementById('filter-form').submit();
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
    </script>
</body>
</html>
