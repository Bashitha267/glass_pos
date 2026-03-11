<?php
require_once '../auth.php';
checkAuth();

$username = $_SESSION['username'];
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

if (isset($_GET['logout'])) {
    logout();
}

// Fetch pending deliveries assigned to this employee
$stmt = $pdo->prepare("
    SELECT d.id, d.delivery_date, 
           (SELECT COUNT(*) FROM delivery_customers WHERE delivery_id = d.id) as customer_count,
           (SELECT SUM(subtotal) FROM delivery_customers WHERE delivery_id = d.id) as total_sales
    FROM deliveries d
    JOIN delivery_employees de ON d.id = de.delivery_id
    WHERE de.user_id = ? AND d.status = 'pending'
    ORDER BY d.delivery_date ASC
");
$stmt->execute([$user_id]);
$pending_deliveries = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard | Crystal POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        glass: {
                            DEFAULT: 'rgba(255, 255, 255, 0.1)',
                            border: 'rgba(255, 255, 255, 0.2)',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap');
        
        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(rgba(15, 23, 42, 0.8), rgba(15, 23, 42, 0.8)), url('../assests/bg.webp') no-repeat center center fixed;
            background-size: cover;
            color: white;
            min-height: 100vh;
        }

        .glass-header {
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
        }

        .glass-card:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-8px);
            border-color: #00d2ff;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
        }

        .status-badge {
            background: rgba(255, 255, 255, 0.05);
            padding: 6px 14px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
    </style>
</head>
<body class="overflow-x-hidden min-h-screen flex flex-col">

    <!-- Top Navigation Bar -->
    <header class="glass-header sticky top-0 z-50 py-3 mb-6">
        <div class="px-7 flex items-center justify-between gap-4">
            <div class="flex items-center space-x-4">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-cyan-400 to-blue-600 flex items-center justify-center font-bold text-lg shadow-lg">
                    <i class="fa-solid fa-gem text-white text-sm"></i>
                </div>
                <h1 class="text-xl font-bold tracking-wider uppercase hidden sm:block">Crystal POS</h1>
            </div>

            <div class="flex items-center space-x-6">
                <div class="hidden sm:flex flex-col text-right">
                    <span class="text-xs text-slate-400 font-medium uppercase tracking-widest">Employee Mode</span>
                    <span class="text-sm font-bold text-white"><?php echo htmlspecialchars($username); ?></span>
                </div>
                <a href="?logout=1" class="w-10 h-10 rounded-xl bg-rose-500/10 text-rose-500 flex items-center justify-center hover:bg-rose-500 hover:text-white transition-all">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </div>
    </header>

    <main class="px-7 py-4 flex-1 w-full">
        <div class="mb-10 text-center sm:text-left">
            <h2 class="text-3xl sm:text-4xl font-black text-white mb-2">My Deliveries</h2>
            <p class="text-slate-400">Manage your assigned pending deliveries below.</p>
        </div>

        <?php if (empty($pending_deliveries)): ?>
            <div class="glass-card p-12 text-center border-dashed border-2">
                <div class="w-16 h-16 bg-white/5 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-truck-fast text-2xl text-slate-500"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-1">No Pending Deliveries</h3>
                <p class="text-slate-400 text-sm">You are all caught up! Check back later for new assignments.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8">
                <?php foreach ($pending_deliveries as $del): ?>
                    <a href="del_details.php?id=<?php echo $del['id']; ?>" class="glass-card p-6 flex flex-col group">
                        <div class="flex justify-between items-start mb-6">
                            <div class="w-12 h-12 rounded-2xl bg-cyan-500/20 flex items-center justify-center text-cyan-400 group-hover:bg-cyan-500 group-hover:text-white transition-all duration-300">
                                <i class="fa-solid fa-truck-ramp-box text-xl"></i>
                            </div>
                            <span class="status-badge text-[10px] font-bold uppercase tracking-wider text-amber-400 border-amber-400/20">Pending</span>
                        </div>
                        
                        <div class="mb-auto">
                            <p class="text-[10px] uppercase font-bold text-slate-500 tracking-widest mb-1">Delivery Reference</p>
                            <h3 class="text-xl font-bold text-white mb-4">#DEL-<?php echo str_pad($del['id'], 4, '0', STR_PAD_LEFT); ?></h3>
                        </div>

                        <div class="space-y-4 pt-4 border-t border-white/5 mt-4">
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-slate-400 flex items-center gap-2">
                                    <i class="fa-regular fa-calendar-check text-cyan-400/60"></i> Date
                                </span>
                                <span class="text-xs font-bold text-white"><?php echo date('M d, Y', strtotime($del['delivery_date'])); ?></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-slate-400 flex items-center gap-2">
                                    <i class="fa-solid fa-users text-cyan-400/60"></i> Customers
                                </span>
                                <span class="text-xs font-black text-white"><?php echo $del['customer_count']; ?></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-slate-400 flex items-center gap-2">
                                    <i class="fa-solid fa-coins text-cyan-400/60"></i> Est. Sales
                                </span>
                                <span class="text-xs font-black text-emerald-400">Rs. <?php echo number_format($del['total_sales'], 2); ?></span>
                            </div>
                        </div>

                        <div class="mt-6 flex items-center text-[10px] font-bold uppercase tracking-widest text-cyan-400 group-hover:translate-x-2 transition-transform">
                            View Details <i class="fa-solid fa-arrow-right ml-2"></i>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <footer class="py-10 text-center opacity-40">
        <p class="text-[10px] uppercase tracking-widest font-bold">&copy; 2024 Crystal POS System &bull; Employee Portal</p>
    </footer>

</body>
</html>
