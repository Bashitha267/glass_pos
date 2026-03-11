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
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap');
        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(rgba(15, 23, 42, 0.9), rgba(15, 23, 42, 0.9)), url('../assests/bg.webp') no-repeat center center fixed;
            background-size: cover;
            color: white;
            min-height: 100vh;
        }
        .glass-header {
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
        }
        .input-glass {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: white;
            padding: 10px 16px;
            transition: all 0.3s ease;
        }
        .input-glass:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(6, 182, 212, 0.5);
            outline: none;
            box-shadow: 0 0 20px rgba(6, 182, 212, 0.1);
        }
        .suggestion-box {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            margin-top: 5px;
            z-index: 50;
            display: none;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
        }
        .suggestion-item {
            padding: 10px 15px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }
        .suggestion-item:hover {
            background: rgba(6, 182, 212, 0.2);
            color: #22d3ee;
        }
    </style>
</head>
<body class="flex flex-col">

    <!-- Header -->
    <header class="glass-header sticky top-0 z-40 py-3">
        <div class="max-w-7xl mx-auto px-6 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="dashboard.php" class="text-white hover:text-cyan-400 transition-colors p-2 rounded-xl hover:bg-white/5">
                    <i class="fa-solid fa-arrow-left text-lg"></i>
                </a>
                <div>
                    <h1 class="text-xl font-bold tracking-wider uppercase text-cyan-400">Container Ledger</h1>
                    <p class="text-[9px] uppercase font-bold text-slate-500 tracking-[0.2em]">Audit Trail & Change Logs</p>
                </div>
            </div>
            <div class="hidden md:block">
                <div class="text-right">
                    <p class="text-xs font-bold text-slate-400"><?php echo date('l, F jS'); ?></p>
                    <p class="text-[10px] text-cyan-500/70 uppercase tracking-widest">System Monitor Active</p>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto w-full px-6 py-8">
        <!-- Redesigned Filters -->
        <div class="glass-card p-6 mb-8">
            <form method="GET" id="filter-form" class="grid grid-cols-1 md:grid-cols-12 gap-6 items-end">
                <div class="md:col-span-5 relative">
                    <label class="text-[10px] uppercase font-bold text-slate-500 mb-2 ml-1 block tracking-widest">Smart Search</label>
                    <div class="relative group">
                        <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-500 group-focus-within:text-cyan-400 transition-colors"></i>
                        <input type="text" name="search" id="search-input" autocomplete="off" value="<?php echo htmlspecialchars($search); ?>" placeholder="ID, Brand or Actor..." class="input-glass w-full pl-12 h-[48px] text-[13px]">
                        <div id="suggestions" class="suggestion-box"></div>
                    </div>
                </div>
                <div class="md:col-span-3">
                    <label class="text-[10px] uppercase font-bold text-slate-500 mb-2 ml-1 block tracking-widest">Start Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="input-glass w-full h-[48px] text-[13px]" onchange="this.form.submit()">
                </div>
                <div class="md:col-span-3">
                    <label class="text-[10px] uppercase font-bold text-slate-500 mb-2 ml-1 block tracking-widest">End Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="input-glass w-full h-[48px] text-[13px]" onchange="this.form.submit()">
                </div>
                <div class="md:col-span-1 flex">
                    <a href="ledger.php" class="w-full h-[48px] bg-rose-500/10 text-rose-400 rounded-xl hover:bg-rose-500/20 transition-all flex items-center justify-center group" title="Clear Filters">
                        <i class="fa-solid fa-xmark group-hover:rotate-90 transition-transform"></i>
                    </a>
                </div>
            </form>
        </div>

        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-white/5 text-[9px] uppercase tracking-widest text-slate-400 border-b border-white/10">
                            <th class="px-4 py-4 font-bold">Timestamp</th>
                            <th class="px-4 py-4 font-bold">Container</th>
                            <th class="px-4 py-4 font-bold text-center">Action</th>
                            <th class="px-4 py-4 font-bold">Actor</th>
                            <th class="px-4 py-4 font-bold">Field / Description</th>
                            <th class="px-4 py-4 font-bold text-center">Changes (Old <i class="fa-solid fa-arrow-right-long mx-1 opacity-50"></i> New)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($ledger)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center">
                                <i class="fa-solid fa-folder-open text-3xl text-slate-700 mb-3 block"></i>
                                <span class="text-slate-500 text-xs italic">No activity logs found matching your criteria.</span>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($ledger as $entry): ?>
                        <tr class="odd:bg-white/[0.01] even:bg-transparent hover:bg-white/[0.04] transition-colors border-b border-white/[0.03]">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="text-[11px] font-medium text-slate-400"><?php echo date('Y-m-d', strtotime($entry['changed_at'])); ?></div>
                                <div class="text-[9px] text-slate-600 font-bold"><?php echo date('h:i A', strtotime($entry['changed_at'])); ?></div>
                            </td>
                            <td class="px-4 py-3 font-bold text-cyan-400 text-[11px] tracking-wider">
                                <?php echo htmlspecialchars($entry['container_number']); ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php 
                                    $class = "bg-slate-500/10 text-slate-400";
                                    switch($entry['action_type']) {
                                        case 'CREATED': $class = "bg-emerald-500/30 text-emerald-400 shadow-sm shadow-emerald-500/20"; break;
                                        case 'UPDATED': $class = "bg-cyan-500/30 text-cyan-400 shadow-sm shadow-cyan-500/20"; break;
                                        case 'PAYMENT': $class = "bg-amber-500/30 text-amber-400 shadow-sm shadow-amber-500/20"; break;
                                        case 'EXPENSE': $class = "bg-rose-500/30 text-rose-400 shadow-sm shadow-rose-500/20"; break;
                                        case 'UPDATE': $class = "bg-blue-500/30 text-blue-400 shadow-sm shadow-blue-500/20"; break;
                                    }
                                ?>
                                <span class="px-2.5 py-1 rounded-[6px] text-[9px] font-black uppercase tracking-wider <?php echo $class; ?>">
                                    <?php echo htmlspecialchars($entry['action_type']); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-[11px] font-semibold text-slate-200"><?php echo htmlspecialchars($entry['actor']); ?></td>
                            <td class="px-4 py-3 text-[11px] text-slate-400 leading-tight italic"><?php echo htmlspecialchars($entry['field_name'] ?? '-'); ?></td>
                            <td class="px-4 py-3 text-[11px] text-center whitespace-nowrap">
                                <?php if ($entry['old_value'] !== null && $entry['action_type'] == 'UPDATE'): ?>
                                    <span class="text-rose-400/60 line-through decoration-rose-400/30"><?php echo htmlspecialchars($entry['old_value']); ?></span>
                                    <i class="fa-solid fa-arrow-right-long mx-2 text-slate-600"></i>
                                    <span class="text-emerald-400 font-bold"><?php echo htmlspecialchars($entry['new_value']); ?></span>
                                <?php else: ?>
                                    <span class="text-emerald-400 font-bold"><?php echo htmlspecialchars($entry['new_value'] ?? '-'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Enhanced Pagination (8 per page) -->
            <?php if ($total_pages > 1): ?>
            <div class="px-6 py-5 bg-white/[0.02] border-t border-white/5 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="text-[10px] uppercase font-bold tracking-widest text-slate-500">
                    Showing <span class="text-cyan-400"><?php echo $offset + 1; ?></span> to <span class="text-cyan-400"><?php echo min($offset + $limit, $total_records); ?></span> of <span class="text-white"><?php echo $total_records; ?></span> logs
                </div>
                <div class="flex space-x-1">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="w-10 h-10 flex items-center justify-center bg-white/5 hover:bg-cyan-500 hover:text-white border border-white/10 rounded-xl transition-all text-slate-400">
                            <i class="fa-solid fa-chevron-left text-xs"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php 
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    for ($i = $start; $i <= $end; $i++): 
                    ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="w-10 h-10 flex items-center justify-center rounded-xl text-[11px] font-bold transition-all <?php echo $page == $i ? 'bg-cyan-600 text-white shadow-lg shadow-cyan-600/30' : 'bg-white/5 hover:bg-white/10 border border-white/10 text-slate-400'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="w-10 h-10 flex items-center justify-center bg-white/5 hover:bg-cyan-500 hover:text-white border border-white/10 rounded-xl transition-all text-slate-400">
                            <i class="fa-solid fa-chevron-right text-xs"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="mt-auto py-8 text-center opacity-30 text-[9px] uppercase tracking-[0.4em] font-medium">
        Crystal POS Audit System &copy; <?php echo date('Y'); ?>
    </footer>

    <script>
        const searchInput = document.getElementById('search-input');
        const suggestionBox = document.getElementById('suggestions');
        let suggestTimeout;

        // Auto-suggestion Logic
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

                            // Item Click Handler
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

        // Close suggestions on outside click
        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !suggestionBox.contains(e.target)) {
                suggestionBox.style.display = 'none';
            }
        });

        // Focus logic
        if (searchInput === document.activeElement) {
            const val = searchInput.value;
            searchInput.value = ''; searchInput.value = val;
        }
    </script>
</body>
</html>
