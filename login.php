<?php
require_once 'auth.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (login($username, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Glass Pallet POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        glass: {
                            DEFAULT: 'rgba(255, 255, 255, 0.1)',
                            border: 'rgba(255, 255, 255, 0.2)',
                        }
                    },
                    backdropBlur: {
                        xs: '2px',
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.8s ease-out forwards',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0', transform: 'translateY(20px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
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
        .glass-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 relative overflow-hidden">
    <!-- Background Overlay -->
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm -z-10"></div>
    
    <!-- Design Elements -->
    <div class="absolute top-[-10%] right-[-10%] w-96 h-96 bg-cyan-500/20 rounded-full blur-[100px] -z-10"></div>
    <div class="absolute bottom-[-10%] left-[-10%] w-96 h-96 bg-blue-600/20 rounded-full blur-[100px] -z-10"></div>

    <div class="w-full max-w-md animate-fade-in">
        <div class="glass-card rounded-[2rem] p-8 md:p-10 text-white">
            <div class="text-center mb-10">
                <div class="inline-flex items-center justify-center w-20 h-20 mb-6 rounded-2xl bg-gradient-to-tr from-cyan-400 to-blue-600 shadow-lg shadow-cyan-500/30">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                </div>
                <h1 class="text-4xl font-bold tracking-tight bg-gradient-to-r from-white to-cyan-200 bg-clip-text text-transparent">
                    Crystal POS
                </h1>
                <p class="text-slate-300/80 mt-2 font-light">Glass Pallet Shop Management</p>
            </div>

            <?php if ($error): ?>
                <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-300 text-sm text-center">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="space-y-6">
                <div>
                    <label for="username" class="block text-sm font-medium text-slate-200 mb-2 ml-1">Username</label>
                    <input type="text" name="username" id="username" 
                           class="w-full px-5 py-4 bg-white/5 border border-white/10 rounded-xl focus:ring-2 focus:ring-cyan-500/50 focus:border-cyan-500/50 focus:bg-white/10 outline-none transition-all text-white placeholder-slate-400" 
                           placeholder="Enter your username" required>
                </div>
                
                <div>
                    <label for="password" class="block text-sm font-medium text-slate-200 mb-2 ml-1">Password</label>
                    <input type="password" name="password" id="password" 
                           class="w-full px-5 py-4 bg-white/5 border border-white/10 rounded-xl focus:ring-2 focus:ring-cyan-500/50 focus:border-cyan-500/50 focus:bg-white/10 outline-none transition-all text-white placeholder-slate-400" 
                           placeholder="Enter your password" required>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <div class="flex items-center">
                        <input id="remember-me" type="checkbox" class="w-4 h-4 rounded border-white/10 bg-white/5 text-cyan-500 focus:ring-cyan-500/50">
                        <label for="remember-me" class="ml-2 text-xs text-slate-300 cursor-pointer">Remember me</label>
                    </div>
                    <a href="#" class="text-xs text-cyan-400 hover:text-cyan-300 transition-colors">Forgot password?</a>
                </div>

                <button type="submit" 
                        class="w-full py-4 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white font-semibold rounded-xl shadow-lg shadow-cyan-500/25 transition-all transform active:scale-[0.98] mt-4">
                    Sign In
                </button>
            </form>

            <div class="mt-8 text-center">
                <p class="text-xs text-slate-400/60">
                    &copy; <?php echo date('Y'); ?> Crystal POS System. Secure Interface.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
