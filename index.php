<?php
require_once 'auth.php';
checkAuth();

$username = $_SESSION['username'];
$role = $_SESSION['role'];

// Simple logout logic
if (isset($_GET['logout'])) {
    logout();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Glass Pallet POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        glass: {
                            DEFAULT: 'rgba(255, 255, 255, 0.05)',
                            border: 'rgba(255, 255, 255, 0.1)',
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
            background: url('assets/img/bg.png') no-repeat center center fixed;
            background-size: cover;
        }
        .sidebar-glass {
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(20px);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
        }
        .content-glass {
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            background: rgba(255, 255, 255, 0.06);
            transform: translateY(-5px);
            border-color: rgba(0, 210, 255, 0.3);
        }
    </style>
</head>
<body class="min-h-screen text-slate-200">
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <aside class="sidebar-glass w-72 hidden md:flex flex-col p-6 space-y-8">
            <div class="flex items-center space-x-3 px-2">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-cyan-400 to-blue-600 flex items-center justify-center shadow-lg shadow-cyan-500/20">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                </div>
                <span class="text-xl font-bold tracking-tight text-white italic">Crystal POS</span>
            </div>

            <nav class="flex-1 space-y-2">
                <a href="index.php" class="flex items-center space-x-3 p-4 rounded-2xl bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    <span class="font-medium">Dashboard</span>
                </a>
                
                <?php if ($role === 'admin'): ?>
                <a href="#" class="flex items-center space-x-3 p-4 rounded-2xl hover:bg-white/5 text-slate-400 hover:text-white transition-all group">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 group-hover:text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <span class="font-medium">Analytics</span>
                </a>
                <a href="#" class="flex items-center space-x-3 p-4 rounded-2xl hover:bg-white/5 text-slate-400 hover:text-white transition-all group">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 group-hover:text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    <span class="font-medium">Employees</span>
                </a>
                <?php endif; ?>

                <a href="#" class="flex items-center space-x-3 p-4 rounded-2xl hover:bg-white/5 text-slate-400 hover:text-white transition-all group">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 group-hover:text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                    <span class="font-medium">Stock</span>
                </a>

                <a href="#" class="flex items-center space-x-3 p-4 rounded-2xl hover:bg-white/5 text-slate-400 hover:text-white transition-all group">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 group-hover:text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 00-2 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                    </svg>
                    <span class="font-medium">Sales History</span>
                </a>
            </nav>

            <div class="pt-6 border-t border-white/5 mx-2">
                <a href="?logout=1" class="flex items-center space-x-3 p-4 rounded-2xl hover:bg-red-500/10 text-slate-400 hover:text-red-400 transition-all group">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    <span class="font-medium">Logout</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col p-4 md:p-8 space-y-8 overflow-y-auto">
            <!-- Header -->
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h2 class="text-3xl font-bold text-white">Welcome back, <?php echo ucfirst(htmlspecialchars($username)); ?>! 👋</h2>
                    <p class="text-slate-400 mt-1">Here's what's happening at your glass pallet shop today.</p>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="bg-white/5 border border-white/10 p-2 rounded-2xl flex items-center space-x-3 pr-4">
                        <div class="w-10 h-10 rounded-xl bg-cyan-500/20 flex items-center justify-center text-cyan-400">
                            <span class="font-bold"><?php echo strtoupper(substr($username, 0, 1)); ?></span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-semibold text-white"><?php echo htmlspecialchars($username); ?></span>
                            <span class="text-[10px] uppercase tracking-wider text-slate-500"><?php echo $role; ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="stat-card p-6 rounded-3xl">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-2xl bg-cyan-500/10 flex items-center justify-center text-cyan-500 shadow-inner">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <span class="text-xs font-semibold text-emerald-400 px-2 py-1 bg-emerald-400/10 rounded-lg">+12.5%</span>
                    </div>
                    <p class="text-slate-400 text-sm font-medium">Daily Sales</p>
                    <h3 class="text-2xl font-bold text-white mt-1">LKR 45,250</h3>
                </div>

                <div class="stat-card p-6 rounded-3xl">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-2xl bg-blue-500/10 flex items-center justify-center text-blue-500 shadow-inner">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                            </svg>
                        </div>
                        <span class="text-xs font-semibold text-emerald-400 px-2 py-1 bg-emerald-400/10 rounded-lg">24 New</span>
                    </div>
                    <p class="text-slate-400 text-sm font-medium">Orders</p>
                    <h3 class="text-2xl font-bold text-white mt-1">156</h3>
                </div>

                <div class="stat-card p-6 rounded-3xl">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-2xl bg-purple-500/10 flex items-center justify-center text-purple-500 shadow-inner">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                        </div>
                        <span class="text-xs font-semibold text-amber-400 px-2 py-1 bg-amber-400/10 rounded-lg">Low Stock</span>
                    </div>
                    <p class="text-slate-400 text-sm font-medium">Current Inventory</p>
                    <h3 class="text-2xl font-bold text-white mt-1">1,240 <span class="text-xs font-light text-slate-500">Items</span></h3>
                </div>

                <div class="stat-card p-6 rounded-3xl">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-2xl bg-rose-500/10 flex items-center justify-center text-rose-500 shadow-inner">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                        <span class="text-xs font-semibold text-emerald-400 px-2 py-1 bg-emerald-400/10 rounded-lg">Active</span>
                    </div>
                    <p class="text-slate-400 text-sm font-medium">Working Staff</p>
                    <h3 class="text-2xl font-bold text-white mt-1">08</h3>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 pb-10">
                <!-- Recent Transactions -->
                <div class="lg:col-span-2 content-glass rounded-[2rem] p-8">
                    <div class="flex items-center justify-between mb-8">
                        <h4 class="text-xl font-bold text-white">Recent Transactions</h4>
                        <button class="text-sm text-cyan-400 hover:text-cyan-300 transition-colors">View All</button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="text-slate-500 text-xs uppercase tracking-wider border-b border-white/5">
                                    <th class="pb-4 font-semibold">Customer / Order ID</th>
                                    <th class="pb-4 font-semibold">Item</th>
                                    <th class="pb-4 font-semibold">Amount</th>
                                    <th class="pb-4 font-semibold">Status</th>
                                    <th class="pb-4 font-semibold">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <!-- Sample Row 1 -->
                                <tr class="text-slate-300">
                                    <td class="py-5">
                                        <div class="flex flex-col">
                                            <span class="font-semibold text-white">Nimesh Fernando</span>
                                            <span class="text-[10px] text-slate-500">ID: #GP-8429</span>
                                        </div>
                                    </td>
                                    <td class="py-5">Tempered Glass Pallet</td>
                                    <td class="py-5 font-bold text-white">LKR 125,000</td>
                                    <td class="py-5">
                                        <span class="px-3 py-1 bg-emerald-500/10 text-emerald-400 rounded-full text-[10px] font-bold uppercase tracking-tighter">Completed</span>
                                    </td>
                                    <td class="py-5">
                                        <button class="p-2 hover:bg-white/5 rounded-xl transition-all">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-slate-500 hover:text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                                <!-- Sample Row 2 -->
                                <tr class="text-slate-300">
                                    <td class="py-5">
                                        <div class="flex flex-col">
                                            <span class="font-semibold text-white">Retail Store X</span>
                                            <span class="text-[10px] text-slate-500">ID: #GP-8430</span>
                                        </div>
                                    </td>
                                    <td class="py-5">Standard Glass Box</td>
                                    <td class="py-5 font-bold text-white">LKR 45,000</td>
                                    <td class="py-5">
                                        <span class="px-3 py-1 bg-amber-500/10 text-amber-400 rounded-full text-[10px] font-bold uppercase tracking-tighter">Pending</span>
                                    </td>
                                    <td class="py-5">
                                        <button class="p-2 hover:bg-white/5 rounded-xl transition-all">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-slate-500 hover:text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Quick Actions / Sidebar Info -->
                <div class="space-y-8">
                    <div class="content-glass rounded-[2rem] p-8 bg-gradient-to-br from-cyan-600/20 to-blue-700/20 border-cyan-500/20">
                        <h4 class="text-xl font-bold text-white mb-6">Quick Actions</h4>
                        <div class="grid grid-cols-2 gap-4">
                            <button class="flex flex-col items-center justify-center p-4 rounded-2xl bg-white/5 border border-white/5 hover:bg-white/10 hover:border-cyan-500/30 transition-all group">
                                <div class="w-10 h-10 mb-3 rounded-xl bg-cyan-500 flex items-center justify-center text-white shadow-lg shadow-cyan-500/40">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                    </svg>
                                </div>
                                <span class="text-xs font-semibold text-slate-300 group-hover:text-white">New Sale</span>
                            </button>
                            <button class="flex flex-col items-center justify-center p-4 rounded-2xl bg-white/5 border border-white/5 hover:bg-white/10 hover:border-cyan-500/30 transition-all group">
                                <div class="w-10 h-10 mb-3 rounded-xl bg-blue-500 flex items-center justify-center text-white shadow-lg shadow-blue-500/40">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 00-2 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                    </svg>
                                </div>
                                <span class="text-xs font-semibold text-slate-300 group-hover:text-white">Invoice</span>
                            </button>
                        </div>
                    </div>

                    <div class="content-glass rounded-[2rem] p-8">
                        <h4 class="text-xl font-bold text-white mb-6">Inventory Status</h4>
                        <div class="space-y-6">
                            <div>
                                <div class="flex justify-between text-xs mb-2">
                                    <span class="text-slate-400">Tempered Glass</span>
                                    <span class="text-white font-bold">85%</span>
                                </div>
                                <div class="w-full h-1.5 bg-white/5 rounded-full overflow-hidden">
                                    <div class="h-full bg-cyan-500 rounded-full" style="width: 85%"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between text-xs mb-2">
                                    <span class="text-slate-400">Standard Plywood</span>
                                    <span class="text-white font-bold">42%</span>
                                </div>
                                <div class="w-full h-1.5 bg-white/5 rounded-full overflow-hidden">
                                    <div class="h-full bg-amber-500 rounded-full" style="width: 42%"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between text-xs mb-2">
                                    <span class="text-slate-400">Mirror Pallets</span>
                                    <span class="text-white font-bold">12%</span>
                                </div>
                                <div class="w-full h-1.5 bg-white/5 rounded-full overflow-hidden">
                                    <div class="h-full bg-red-500 rounded-full" style="width: 12%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
