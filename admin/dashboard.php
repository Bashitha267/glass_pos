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
    <title>Admin Dashboard | Crystal POS</title>
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
            border: 2px solid rgba(255, 255, 255, 0.9); /* Solid white border */
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
        <div class="max-w-7xl mx-auto px-4 md:px-6 flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center space-x-4">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-cyan-400 to-blue-600 flex items-center justify-center font-bold text-lg shadow-lg">
                    <i class="fa-solid fa-gem text-white text-sm"></i>
                </div>
                <h1 class="text-xl font-bold tracking-wider uppercase hidden sm:block">Crystal POS</h1>
            </div>

            <div class="flex items-center space-x-3 md:space-x-6">
                <!-- Stats Indicators -->
                <div class="hidden lg:flex items-center space-x-4">
                    <div class="status-badge text-center">
                        <p class="text-[9px] uppercase font-bold text-slate-400 opacity-70 tracking-widest">Inventory</p>
                        <p class="text-xs font-bold text-cyan-400 mt-0.5">Rs. 80,958.00</p>
                    </div>
                    <div class="status-badge text-center">
                        <p class="text-[9px] uppercase font-bold text-slate-400 opacity-70 tracking-widest">Today's Sales</p>
                        <p class="text-xs font-bold text-emerald-400 mt-0.5">Rs. 0.00</p>
                    </div>
                </div>

                <div class="flex items-center space-x-3">
                    <div class="hidden md:flex flex-col items-end">
                        <span class="text-[9px] uppercase font-bold text-slate-400 tracking-wider">Operator</span>
                        <span class="text-xs font-bold"><?php echo htmlspecialchars($username); ?></span>
                    </div>
                    <div class="w-px h-8 bg-white/10 hidden md:block"></div>
                    <div class="flex flex-col items-end min-w-[100px]">
                        <span id="current-date" class="text-[9px] font-bold text-slate-400 uppercase tracking-tight"></span>
                        <span id="current-time" class="text-xs font-bold text-white tracking-widest leading-none mt-0.5"></span>
                    </div>
                    <a href="?logout=1" class="bg-red-600/90 hover:bg-red-500 text-white px-4 py-2 rounded-xl font-bold text-[10px] uppercase transition-all shadow-lg">
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto w-full px-4 md:px-6 py-6 md:py-12 flex-grow">
        <div class="mb-10 lg:mb-16">
            <h2 class="text-3xl md:text-5xl font-bold mb-3 tracking-tight">Good Day, <?php echo htmlspecialchars($username); ?>!</h2>
            <div class="h-1.5 w-20 bg-gradient-to-r from-cyan-500 to-blue-600 rounded-full"></div>
        </div>

        <!-- Main Action Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 md:gap-6">
            <!-- Point of Sale Card -->
            <div class="glass-card p-6 flex flex-col items-center justify-center text-center space-y-4">
                <div class="w-16 h-16 bg-cyan-500/20 border-2 border-cyan-400/40 rounded-2xl flex items-center justify-center shadow-inner">
                    <i class="fa-solid fa-cash-register text-2xl text-cyan-400"></i>
                </div>
                <h3 class="font-bold text-sm uppercase tracking-[0.1em]">Point of Sale</h3>
            </div>

            <!-- New Delivery Card -->
            <div class="glass-card p-6 flex flex-col items-center justify-center text-center space-y-4">
                <div class="w-16 h-16 bg-amber-500/20 border-2 border-amber-400/40 rounded-2xl flex items-center justify-center shadow-inner">
                    <i class="fa-solid fa-truck-fast text-2xl text-amber-400"></i>
                </div>
                <h3 class="font-bold text-sm uppercase tracking-[0.1em]">New Delivery</h3>
            </div>
 
            <!-- Manage Stocks Card -->
            <a href="addcontainer.php" class="glass-card p-6 flex flex-col items-center justify-center text-center space-y-4">
                <div class="w-16 h-16 bg-blue-500/20 border-2 border-blue-400/40 rounded-2xl flex items-center justify-center shadow-inner">
                    <i class="fa-solid fa-boxes-stacked text-2xl text-blue-400"></i>
                </div>
                <h3 class="font-bold text-sm uppercase tracking-[0.1em]">Manage Stocks</h3>
            </a>
 
            <!-- Manage Employees Card -->
            <div class="glass-card p-6 flex flex-col items-center justify-center text-center space-y-4">
                <div class="w-16 h-16 bg-purple-500/20 border-2 border-purple-400/40 rounded-2xl flex items-center justify-center shadow-inner">
                    <i class="fa-solid fa-user-tie text-2xl text-purple-400"></i>
                </div>
                <h3 class="font-bold text-sm uppercase tracking-[0.1em]">Manage Employees</h3>
            </div>

            <!-- Container History Card -->
            <div class="glass-card p-6 flex flex-col items-center justify-center text-center space-y-4">
                <div class="w-16 h-16 bg-blue-500/20 border-2 border-blue-400/40 rounded-2xl flex items-center justify-center shadow-inner">
                    <i class="fa-solid fa-box-open text-2xl text-blue-400"></i>
                </div>
                <h3 class="font-bold text-sm uppercase tracking-[0.1em]">Container History</h3>
            </div>

            <!-- Container Ledger Card -->
            <a href="ledger.php" class="glass-card p-6 flex flex-col items-center justify-center text-center space-y-4">
                <div class="w-16 h-16 bg-slate-500/20 border-2 border-slate-400/40 rounded-2xl flex items-center justify-center shadow-inner">
                    <i class="fa-solid fa-list-check text-2xl text-slate-400"></i>
                </div>
                <h3 class="font-bold text-sm uppercase tracking-[0.1em]">Container Ledger</h3>
            </a>

            <!-- POS Sales History Card -->
            <div class="glass-card p-6 flex flex-col items-center justify-center text-center space-y-4">
                <div class="w-16 h-16 bg-cyan-500/20 border-2 border-cyan-400/40 rounded-2xl flex items-center justify-center shadow-inner">
                    <i class="fa-solid fa-clock-rotate-left text-2xl text-cyan-400"></i>
                </div>
                <h3 class="font-bold text-sm uppercase tracking-[0.1em]">POS Sales History</h3>
            </div>

            <!-- Delivery History Card -->
            <div class="glass-card p-6 flex flex-col items-center justify-center text-center space-y-4">
                <div class="w-16 h-16 bg-amber-500/20 border-2 border-amber-400/40 rounded-2xl flex items-center justify-center shadow-inner">
                    <i class="fa-solid fa-truck-ramp-box text-2xl text-amber-400"></i>
                </div>
                <h3 class="font-bold text-sm uppercase tracking-[0.1em]">Delivery History</h3>
            </div>

            <!-- Payments History Card -->
            <div class="glass-card p-6 flex flex-col items-center justify-center text-center space-y-4">
                <div class="w-16 h-16 bg-emerald-500/20 border-2 border-emerald-400/40 rounded-2xl flex items-center justify-center shadow-inner">
                    <i class="fa-solid fa-money-bill-transfer text-2xl text-emerald-400"></i>
                </div>
                <h3 class="font-bold text-sm uppercase tracking-[0.1em]">Payments History</h3>
            </div>

            <!-- Reports Card -->
            <div class="glass-card p-6 flex flex-col items-center justify-center text-center space-y-4">
                <div class="w-16 h-16 bg-rose-500/20 border-2 border-rose-400/40 rounded-2xl flex items-center justify-center shadow-inner">
                    <i class="fa-solid fa-chart-pie text-2xl text-rose-400"></i>
                </div>
                <h3 class="font-bold text-sm uppercase tracking-[0.1em]">Reports</h3>
            </div>
        </div>
    </main>

    <footer class="py-10">
        <div class="max-w-7xl mx-auto px-6 text-center">
            <p class="text-[10px] text-white/40 uppercase tracking-[0.4em] font-medium italic">
                © <?php echo date('Y'); ?> Premium Sales Management System
            </p>
        </div>
    </footer>

    <script>
        function updateDateTime() {
            const now = new Date();
            const dateOptions = { day: '2-digit', month: 'short', year: 'numeric' };
            document.getElementById('current-date').textContent = now.toLocaleDateString('en-GB', dateOptions);
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false };
            document.getElementById('current-time').textContent = now.toLocaleTimeString('en-GB', timeOptions);
        }
        setInterval(updateDateTime, 1000);
        updateDateTime();
    </script>
</body>
</html>
