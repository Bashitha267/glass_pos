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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control Center | Crystal POS</title>
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
            border: 1px solid rgba(255, 255, 255, 1);
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
            color: #475569; /* Darkened from slate-500 to slate-600 */
            text-shadow: 0 1px 2px rgba(255,255,255,0.5);
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
                <div class="w-11 h-11 rounded-2xl bg-slate-900 flex items-center justify-center shadow-lg">
                    <i class="fa-solid fa-gem text-cyan-400 text-lg"></i>
                </div>
                <div>
                    <h1 class="text-xl font-black uppercase tracking-tighter text-slate-900 font-['Outfit']">Crystal POS</h1>
                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Admin Control Center</p>
                </div>
            </div>

            <div class="flex items-center space-x-8">
                <div class="hidden md:flex items-center space-x-6">
                    <div class="text-right">
                        <span class="text-premium-label">System Time</span>
                        <p id="current-time" class="text-sm font-black text-slate-800 tracking-widest mt-0.5"></p>
                    </div>
                    <div class="w-px h-8 bg-slate-200"></div>
                    <div class="text-right">
                        <span class="text-premium-label">Operator</span>
                        <p class="text-sm font-black text-slate-800 mt-0.5"><?php echo htmlspecialchars($username); ?></p>
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
        <div class="mb-14 relative">
            <div class="absolute -left-4 top-0 w-1 h-20 bg-cyan-600 rounded-full"></div>
            <p class="text-premium-label mb-2 ml-2">Main Dashboard</p>
            <h2 class="text-5xl md:text-6xl font-black text-slate-900 font-['Outfit'] tracking-tight">
                Welcome, <span class="bg-gradient-to-r from-cyan-600 to-blue-700 bg-clip-text text-transparent"><?php echo htmlspecialchars($username); ?></span>
            </h2>
            <div id="current-date" class="mt-3 ml-2 text-sm font-black text-slate-600 uppercase tracking-[0.2em]"></div>
        </div>

        <!-- Dashboard Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-6">
            <!-- Point of Sale -->
            <div class="glass-card group p-8 flex flex-col items-center justify-center text-center cursor-pointer">
                <div class="action-icon w-16 h-16 bg-cyan-50 border-2 border-cyan-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-cash-register text-3xl text-cyan-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-cyan-600 transition-colors">Point of Sale</h3>
            </div>

            <!-- New Delivery -->
            <a href="nwdelivery.php" class="glass-card group p-8 flex flex-col items-center justify-center text-center">
                <div class="action-icon w-16 h-16 bg-amber-50 border-2 border-amber-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-truck-fast text-3xl text-amber-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-amber-600 transition-colors">Delivery Management</h3>
            </a>
 
            <!-- Manage Stocks -->
            <a href="addcontainer.php" class="glass-card group p-8 flex flex-col items-center justify-center text-center">
                <div class="action-icon w-16 h-16 bg-blue-50 border-2 border-blue-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-boxes-stacked text-3xl text-blue-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-blue-600 transition-colors">Container Management</h3>
            </a>

            <!-- Manage Employees -->
            <a href="manageEmploy.php" class="glass-card group p-8 flex flex-col items-center justify-center text-center">
                <div class="action-icon w-16 h-16 bg-purple-50 border-2 border-purple-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-user-tie text-3xl text-purple-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-purple-600 transition-colors">Staff Management</h3>
            </a>

            <!-- Container History -->
            <div class="glass-card group p-8 flex flex-col items-center justify-center text-center cursor-pointer">
                <div class="action-icon w-16 h-16 bg-indigo-50 border-2 border-indigo-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-clock-rotate-left text-3xl text-indigo-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-indigo-600 transition-colors">Payment Managment</h3>
            </div>

            <!-- Container Ledger -->
            <a href="ledger.php" class="glass-card group p-8 flex flex-col items-center justify-center text-center">
                <div class="action-icon w-16 h-16 bg-slate-100 border-2 border-slate-400/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-list-check text-3xl text-slate-700"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-slate-900 transition-colors">Audit Ledger</h3>
            </a>

            <!-- Sales History -->
            <div class="glass-card group p-8 flex flex-col items-center justify-center text-center cursor-pointer">
                <div class="action-icon w-16 h-16 bg-emerald-50 border-2 border-emerald-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-receipt text-3xl text-emerald-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-emerald-600 transition-colors">Finance Logs</h3>
            </div>

           

            <!-- Reports Hub -->
            <div class="glass-card group p-8 flex flex-col items-center justify-center text-center cursor-pointer">
                <div class="action-icon w-16 h-16 bg-rose-50 border-2 border-rose-500/30 rounded-3xl flex items-center justify-center mb-6 shadow-sm">
                    <i class="fa-solid fa-chart-line text-3xl text-rose-600"></i>
                </div>
                <h3 class="text-premium-label text-slate-800 group-hover:text-rose-600 transition-colors">Reports</h3>
            </div>
        </div>
    </main>

    <footer class="py-12 border-t border-slate-100 mt-12">
        <div class="max-w-7xl mx-auto px-6 text-center">
            <p class="text-[10px] text-slate-400 uppercase tracking-[0.4em] font-black">
                CRYSTAL POS &bull; PREMIUM ENTERPRISE EDITION &bull; <?php echo date('Y'); ?>
            </p>
        </div>
    </footer>

    <script>
        function updateDateTime() {
            const now = new Date();
            const dateOptions = { day: '2-digit', month: 'short', year: 'numeric', weekday: 'long' };
            document.getElementById('current-date').textContent = now.toLocaleDateString('en-GB', dateOptions);
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
            document.getElementById('current-time').textContent = now.toLocaleTimeString('en-GB', timeOptions);
        }
        setInterval(updateDateTime, 1000);
        updateDateTime();
    </script>
</body>
</html>
