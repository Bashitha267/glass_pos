<?php
require_once '../auth.php';
require_once '../config.php';
checkAuth();
if (!isAdmin()) { header('Location: ../sale/dashboard.php'); exit; }

$search_id  = trim($_GET['search_id'] ?? '');
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date   = $_GET['end_date']   ?? date('Y-m-d');
$filter_month = $_GET['filter_month'] ?? '';
$filter_year  = $_GET['filter_year']  ?? '';
$action_f   = $_GET['action_type'] ?? '';
$page       = max(1,(int)($_GET['page'] ?? 1));
$limit      = 8;
$offset     = ($page-1)*$limit;

$where  = [];
$params = [];

if ($search_id) { $where[] = "psa.sale_id = ?"; $params[] = $search_id; }
if ($action_f) { $where[] = "psa.action_type = ?"; $params[] = $action_f; }
if ($filter_month && $filter_year && !$_GET['start_date']) {
    $where[] = "MONTH(psa.changed_at)=? AND YEAR(psa.changed_at)=?";
    $params[] = $filter_month; $params[] = $filter_year;
} else {
    if ($start_date) { $where[] = "DATE(psa.changed_at) >= ?"; $params[] = $start_date; }
    if ($end_date)   { $where[] = "DATE(psa.changed_at) <= ?"; $params[] = $end_date; }
}

$wc = $where ? "WHERE ".implode(" AND ",$where) : "";

$base = "SELECT psa.*, u.full_name as changed_by_name, ps.bill_id FROM pos_sale_audits psa
    JOIN users u ON psa.changed_by=u.id
    LEFT JOIN pos_sales ps ON psa.sale_id=ps.id
    $wc";

$cnt = $pdo->prepare("SELECT COUNT(*) FROM ($base) t");
$cnt->execute($params);
$total_records = (int)$cnt->fetchColumn();
$total_pages = max(1, ceil($total_records/$limit));

$audits = $pdo->prepare("$base ORDER BY psa.changed_at DESC LIMIT $limit OFFSET $offset");
$audits->execute($params);
$audits = $audits->fetchAll(PDO::FETCH_ASSOC);

$action_types = ['CREATED','EDITED','DELETED','PAYMENT_ADDED','PAYMENT_DELETED'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Sale Audits | Sahan Picture & Mirror</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background: url('../assests/glass_bg.png') no-repeat center center fixed; background-size: cover; color: #1e293b; min-height: 100vh; }
        .glass-header { background: rgba(248,250,252,0.96); backdrop-filter: blur(20px); border-bottom: 1px solid rgba(226,232,240,0.8); box-shadow: 0 4px 20px -5px rgba(0,0,0,0.05); }
        .glass-card { background: rgba(255,255,255,0.88); backdrop-filter: blur(20px); border: 1px solid white; border-radius: 24px; box-shadow: 0 10px 30px -5px rgba(0,0,0,0.04); }
        .input-glass { background: rgba(255,255,255,0.6); border: 1px solid #e2e8f0; padding: 10px 16px; border-radius: 14px; outline: none; transition: all 0.3s; font-size: 14px; font-weight: 700; color: #0f172a; }
        .input-glass:focus { border-color: #0891b2; background: white; }
        .table-header { background: #1e293b; color: white; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; }
        .custom-scroll::-webkit-scrollbar { width: 6px; } .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="flex flex-col pb-12">
<header class="glass-header sticky top-0 z-40 py-4">
    <div class="px-5 flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex items-center space-x-3 md:space-x-5 self-start sm:self-auto">
            <a href="dashboard.php" class="text-slate-800 hover:text-cyan-600 p-2 rounded-2xl hover:bg-slate-100 transition-colors"><i class="fa-solid fa-arrow-left text-lg"></i></a>
            <div>
                <h1 class="text-lg md:text-2xl font-black text-slate-900 font-['Outfit']">POS Sale Audits</h1>
                <p class="hidden md:block text-[10px] uppercase font-black text-slate-400 tracking-widest mt-0.5">Change Log & Audit Trail</p>
            </div>
        </div>
    </div>
</header>
<main class="px-5 py-8 w-full">

    <!-- Filters -->
    <div class="glass-card p-5 mb-6 border-slate-200/50">
        <form method="GET" id="filterForm" class="grid grid-cols-2 md:grid-cols-12 gap-4 items-end">
            <div class="col-span-2 md:col-span-2">
                <label class="text-[10px] uppercase font-black text-slate-600 mb-1.5 ml-1 block tracking-widest">Sale ID</label>
                <input type="number" name="search_id" value="<?php echo htmlspecialchars($search_id); ?>" placeholder="Sale ID..." class="input-glass w-full h-[46px]" oninput="autoSubmitAudit()">
            </div>
            <div class="md:col-span-2">
                <label class="text-[10px] uppercase font-black text-slate-600 mb-1.5 ml-1 block tracking-widest">From</label>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="input-glass w-full h-[46px]" onchange="this.form.submit()">
            </div>
            <div class="md:col-span-2">
                <label class="text-[10px] uppercase font-black text-slate-600 mb-1.5 ml-1 block tracking-widest">To</label>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="input-glass w-full h-[46px]" onchange="this.form.submit()">
            </div>
            <div class="md:col-span-2">
                <label class="text-[10px] uppercase font-black text-slate-600 mb-1.5 ml-1 block tracking-widest">Month</label>
                <select name="filter_month" class="input-glass w-full h-[46px] appearance-none cursor-pointer" onchange="this.form.submit()">
                    <option value="">All</option>
                    <?php for($m=1;$m<=12;$m++): ?>
                    <option value="<?php echo str_pad($m,2,'0',STR_PAD_LEFT); ?>" <?php echo $filter_month==str_pad($m,2,'0',STR_PAD_LEFT)?'selected':''; ?>><?php echo date('M',mktime(0,0,0,$m,1)); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="md:col-span-1">
                <label class="text-[10px] uppercase font-black text-slate-600 mb-1.5 ml-1 block tracking-widest">Year</label>
                <select name="filter_year" class="input-glass w-full h-[46px] appearance-none cursor-pointer" onchange="this.form.submit()">
                    <?php for($y=date('Y');$y>=2024;$y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $filter_year==$y?'selected':''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="text-[10px] uppercase font-black text-slate-600 mb-1.5 ml-1 block tracking-widest">Action Type</label>
                <select name="action_type" class="input-glass w-full h-[46px] appearance-none cursor-pointer" onchange="this.form.submit()">
                    <option value="">All Actions</option>
                    <?php foreach($action_types as $at): ?>
                    <option value="<?php echo $at; ?>" <?php echo $action_f===$at?'selected':''; ?>><?php echo $at; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-1">
                <a href="pos_sale_audits.php" class="w-full h-[46px] bg-rose-50 text-rose-500 rounded-2xl hover:bg-rose-100 transition-all flex items-center justify-center border border-rose-200"><i class="fa-solid fa-rotate-right"></i></a>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="glass-card overflow-hidden">
        <div class="overflow-x-auto no-scrollbar">
            <table class="w-full text-left min-w-[1000px]">
                <thead>
                    <tr class="table-header">
                        <th class="px-4 py-3.5">ID</th>
                        <th class="px-4 py-3.5">Sale / Bill ID</th>
                        <th class="px-4 py-3.5">Action</th>
                        <th class="px-4 py-3.5">Field Changed</th>
                        <th class="px-4 py-3.5">From → To</th>
                        <th class="px-4 py-3.5">Notes</th>
                        <th class="px-4 py-3.5">By</th>
                        <th class="px-4 py-3.5">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach($audits as $a):
                        $ac = match($a['action_type']) {
                            'CREATED' => 'bg-emerald-100 text-emerald-700',
                            'EDITED'  => 'bg-amber-100 text-amber-700',
                            'DELETED' => 'bg-rose-100 text-rose-700',
                            'PAYMENT_ADDED' => 'bg-blue-100 text-blue-700',
                            'PAYMENT_DELETED' => 'bg-orange-100 text-orange-700',
                            default => 'bg-slate-100 text-slate-700'
                        };
                    ?>
                    <tr class="hover:bg-slate-50/50 transition-colors">
                        <td class="px-4 py-3 font-black text-slate-500 text-[11px]">#<?php echo $a['id']; ?></td>
                        <td class="px-4 py-3 font-black text-indigo-600 text-[11px]">
                            <?php if($a['bill_id']): ?>
                            <a href="pos_sales_history.php?search=<?php echo htmlspecialchars($a['bill_id']); ?>" class="hover:underline"><?php echo htmlspecialchars($a['bill_id']); ?></a>
                            <?php elseif($a['sale_id']): ?>
                            <span class="text-slate-400">#<?php echo $a['sale_id']; ?> (deleted)</span>
                            <?php else: ?><span class="text-slate-400">—</span><?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2.5 py-1 rounded-full text-[9px] font-black uppercase tracking-wider <?php echo $ac; ?>"><?php echo $a['action_type']; ?></span>
                        </td>
                        <td class="px-4 py-3 text-xs font-bold text-slate-600"><?php echo htmlspecialchars($a['field_name'] ?: '—'); ?></td>
                        <td class="px-4 py-3 text-xs text-slate-500">
                            <?php if($a['old_value'] !== null || $a['new_value'] !== null): ?>
                            <span class="text-rose-500"><?php echo htmlspecialchars($a['old_value'] ?? '∅'); ?></span>
                            <span class="text-slate-400 mx-1">→</span>
                            <span class="text-emerald-600 font-bold"><?php echo htmlspecialchars($a['new_value'] ?? '∅'); ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-600 max-w-xs"><?php echo htmlspecialchars($a['notes'] ?: '—'); ?></td>
                        <td class="px-4 py-3 text-xs font-bold text-slate-700"><?php echo htmlspecialchars($a['changed_by_name']); ?></td>
                        <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap"><?php echo date('M d, Y H:i',strtotime($a['changed_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($audits)): ?>
                    <tr><td colspan="8" class="px-4 py-12 text-center text-slate-400 font-bold text-xs uppercase tracking-widest italic">No audit logs found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="px-5 py-4 bg-slate-50/50 border-t border-slate-100 flex items-center justify-between">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Page <?php echo $page; ?> of <?php echo $total_pages; ?> &bull; <?php echo $total_records; ?> records</p>
            <div class="flex space-x-2">
                <?php if($page>1): ?><a href="?<?php echo http_build_query(array_merge($_GET,['page'=>$page-1])); ?>" class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold hover:bg-slate-50">Prev</a><?php endif; ?>
                <?php if($page<$total_pages): ?><a href="?<?php echo http_build_query(array_merge($_GET,['page'=>$page+1])); ?>" class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold hover:bg-slate-50">Next</a><?php endif; ?>
            </div>
        </div>
    </div>
</main>
<script>
let auditTimeout;
function autoSubmitAudit() { clearTimeout(auditTimeout); auditTimeout = setTimeout(()=>document.getElementById('filterForm').submit(), 400); }
</script>
</body>
</html>
