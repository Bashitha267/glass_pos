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
                            DEFAULT: 'rgba(255, 255, 255, 0.15)',
                            border: 'rgba(255, 255, 255, 0.3)',
                        }
                    },
                    backdropBlur: {
                        xs: '4px',
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
            background: rgba(15, 23, 42, 0.65); /* More contrast with background */
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 relative overflow-hidden">
    <!-- Dark Background Overlay for better text visibility -->
    <div class="absolute inset-0 bg-slate-900/60 -z-10"></div>
    
    <!-- Design Elements -->
    <div class="absolute top-[-10%] right-[-10%] w-96 h-96 bg-cyan-500/30 rounded-full blur-[120px] -z-10"></div>
    <div class="absolute bottom-[-10%] left-[-10%] w-96 h-96 bg-blue-600/30 rounded-full blur-[120px] -z-10"></div>

    <div class="w-full max-w-md animate-fade-in px-4">
        <div class="glass-card rounded-[2.5rem] p-10 md:p-12 text-white">
            <div class="text-center mb-12">
                <div class="inline-flex items-center justify-center w-20 h-20 mb-6 rounded-3xl bg-gradient-to-tr from-cyan-400 to-blue-600 shadow-xl shadow-cyan-500/40 transform hover:scale-105 transition-transform">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                </div>
                <h1 class="text-4xl font-extrabold tracking-tight text-white mb-2">
                    Crystal POS
                </h1>
                <p class="text-slate-200 text-base font-normal">Glass Pallet Shop Management</p>
            </div>

            <?php if ($error): ?>
                <div class="mb-8 p-4 rounded-2xl bg-red-500/20 border border-red-500/30 text-red-100 text-sm font-medium text-center">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="space-y-6">
                <div>
                    <label for="username" class="block text-sm font-semibold text-white mb-2.5 ml-1">Username</label>
                    <input type="text" name="username" id="username" 
                           class="w-full px-5 py-4 bg-white/10 border border-white/20 rounded-2xl focus:ring-2 focus:ring-cyan-500/70 focus:border-cyan-500/70 focus:bg-white/15 outline-none transition-all text-white placeholder-slate-300 font-medium" 
                           placeholder="Enter your username" required>
                </div>
                
                <div>
                    <label for="password" class="block text-sm font-semibold text-white mb-2.5 ml-1">Password</label>
                    <input type="password" name="password" id="password" 
                           class="w-full px-5 py-4 bg-white/10 border border-white/20 rounded-2xl focus:ring-2 focus:ring-cyan-500/70 focus:border-cyan-500/70 focus:bg-white/15 outline-none transition-all text-white placeholder-slate-300 font-medium" 
                           placeholder="Enter your password" required>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <div class="flex items-center">
                        <input id="remember-me" type="checkbox" class="w-5 h-5 rounded-lg border-white/20 bg-white/10 text-cyan-500 focus:ring-cyan-500/50">
                        <label for="remember-me" class="ml-2.5 text-sm text-slate-200 cursor-pointer font-medium">Remember me</label>
                    </div>
                    <a href="#" class="text-sm text-cyan-400 hover:text-cyan-300 transition-colors font-semibold">Forgot?</a>
                </div>

                <button type="submit" 
                        class="w-full py-4.5 py-4 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white font-bold text-lg rounded-2xl shadow-xl shadow-cyan-500/30 transition-all transform active:scale-[0.98] mt-4">
                    Sign In
                </button>
            </form>

            <div class="mt-12 text-center">
                <p class="text-[13px] text-slate-300 font-medium">
                    &copy; <?php echo date('Y'); ?> Crystal POS System.
                </p>
                <p class="text-[11px] text-slate-400/80 mt-1 uppercase tracking-widest font-bold">Secure Interface</p>
            </div>
        </div>
    </div>
</body>
</html>
