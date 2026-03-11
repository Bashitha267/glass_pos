<?php
require_once '../auth.php';
checkAuth();

$username = $_SESSION['username'];
$role = $_SESSION['role'];

if (isset($_GET['logout'])) {
    logout();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Dashboard | Crystal POS</title>
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
            background: url('../assets/img/bg.png') no-repeat center center fixed;
            background-size: cover;
        }
        .sidebar-glass {
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(20px);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
        }
        .content-glass {
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.06);
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-5px);
            border-color: rgba(0, 210, 255, 0.3);
        }
        #mobile-sidebar { transition: transform 0.3s ease-in-out; }
        #mobile-sidebar.hidden-sidebar { transform: translateX(-100%); }
    </style>
</head>
<body class="min-h-screen text-slate-200 overflow-x-hidden">
    <div class="flex min-h-screen relative">
        <div id="sidebar-overlay" class="fixed inset-0 bg-slate-950/60 backdrop-blur-sm z-40 hidden lg:hidden"></div>

        <!-- Sidebar -->
        <aside id="mobile-sidebar" class="fixed inset-y-0 left-0 w-72 sidebar-glass z-50 transform lg:relative lg:translate-x-0 hidden-sidebar lg:flex flex-col p-6 space-y-8">
            <div class="flex items-center justify-between px-2">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-cyan-400 to-blue-600 flex items-center justify-center shadow-lg shadow-cyan-500/20">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                    </div>
                    <span class="text-xl font-bold tracking-tight text-white italic">Sales Terminal</span>
                </div>
                <button id="close-sidebar" class="lg:hidden text-slate-400 hover:text-white p-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <nav class="flex-1 space-y-2">
                <a href="dashboard.php" class="flex items-center space-x-3 p-4 rounded-2xl bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
                    <span class="font-medium">POS Terminal</span>
                </a>
                <a href="#" class="flex items-center space-x-3 p-4 rounded-2xl hover:bg-white/5 text-slate-400 hover:text-white transition-all group">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 group-hover:text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
                    <span class="font-medium">Stock Status</span>
                </a>
            </nav>

            <div class="pt-6 border-t border-white/5 mx-2">
                <a href="?logout=1" class="flex items-center space-x-3 p-4 rounded-2xl hover:bg-red-500/10 text-slate-400 hover:text-red-400 transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
                    <span class="font-medium">Logout Employee</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col p-4 md:p-8 space-y-8 overflow-y-auto">
            <header class="flex justify-between items-center">
                <div>
                    <h2 class="text-3xl font-bold text-white">Crystal Sales Terminal</h2>
                    <p class="text-slate-400">Welcome, <?php echo htmlspecialchars($username); ?>. Let's make some sales!</p>
                </div>
                <div class="lg:hidden">
                    <button id="open-sidebar" class="p-2 bg-white/5 rounded-xl border border-white/10 text-white">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7" /></svg>
                    </button>
                </div>
            </header>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Action Cards -->
                <button class="content-glass p-10 rounded-[2.5rem] flex flex-col items-center justify-center group hover:border-cyan-500/50 transition-all transform hover:-translate-y-2">
                    <div class="w-16 h-16 bg-cyan-500 rounded-2xl flex items-center justify-center text-white mb-4 shadow-lg shadow-cyan-500/20">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    </div>
                    <span class="text-xl font-bold text-white">Create New Sale</span>
                </button>
                <button class="content-glass p-10 rounded-[2.5rem] flex flex-col items-center justify-center group hover:border-blue-500/50 transition-all transform hover:-translate-y-2">
                    <div class="w-16 h-16 bg-blue-500 rounded-2xl flex items-center justify-center text-white mb-4 shadow-lg shadow-blue-500/20">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 00-2 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                    </div>
                    <span class="text-xl font-bold text-white">Issue Receipt</span>
                </button>
                <button class="content-glass p-10 rounded-[2.5rem] flex flex-col items-center justify-center group hover:border-purple-500/50 transition-all transform hover:-translate-y-2">
                    <div class="w-16 h-16 bg-purple-500 rounded-2xl flex items-center justify-center text-white mb-4 shadow-lg shadow-purple-500/20">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <span class="text-xl font-bold text-white">Check Prices</span>
                </button>
            </div>
        </main>
    </div>

    <script>
        const sidebar = document.getElementById('mobile-sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const openBtn = document.getElementById('open-sidebar');
        const closeBtn = document.getElementById('close-sidebar');

        function toggleSidebar() {
            sidebar.classList.toggle('hidden-sidebar');
            overlay.classList.toggle('hidden');
        }

        openBtn?.addEventListener('click', toggleSidebar);
        closeBtn?.addEventListener('click', toggleSidebar);
        overlay?.addEventListener('click', toggleSidebar);
    </script>
</body>
</html>
