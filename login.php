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
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@300;400;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background: url('assests/glass_bg.png') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 1);
            border-radius: 30px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .input-glass {
            background: rgba(255, 255, 255, 0.6);
            border: 1px solid rgba(203, 213, 225, 0.6);
            color: #1e293b;
            padding: 14px 20px;
            border-radius: 16px;
            outline: none;
            transition: all 0.3s;
        }
        .input-glass:focus {
            border-color: #0891b2;
            background: white;
            box-shadow: 0 0 20px rgba(8, 145, 178, 0.1);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 relative overflow-hidden">
    <!-- Design Elements -->
    <div class="absolute top-[-10%] right-[-10%] w-96 h-96 bg-cyan-200/40 rounded-full blur-[120px] -z-10"></div>
    <div class="absolute bottom-[-10%] left-[-10%] w-96 h-96 bg-blue-200/40 rounded-full blur-[120px] -z-10"></div>

    <div class="w-full max-w-md animate-fade-in px-4">
        <div class="glass-card p-10 md:p-12 text-slate-800">
            <div class="text-center mb-10">
                <div class="inline-flex items-center justify-center w-20 h-20 mb-6 rounded-3xl bg-gradient-to-tr from-cyan-600 to-blue-700 shadow-xl shadow-cyan-900/10 transform hover:scale-105 transition-transform">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                </div>
                <h1 class="text-4xl font-extrabold tracking-tight text-slate-900 mb-2 font-['Outfit']">
                    Crystal POS
                </h1>
                <p class="text-slate-600 text-base font-bold">Glass Pallet Shop Management</p>
            </div>

            <?php if ($error): ?>
                <div class="mb-8 p-4 rounded-2xl bg-red-500/20 border border-red-500/30 text-red-100 text-sm font-medium text-center">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="space-y-6">
                <div>
                    <label for="username" class="block text-xs uppercase font-black text-slate-600 tracking-wider mb-2 ml-1">Username</label>
                    <input type="text" name="username" id="username" 
                           class="input-glass w-full placeholder-slate-400" 
                           placeholder="Enter your username" required>
                </div>
                
                <div>
                    <label for="password" class="block text-xs uppercase font-black text-slate-600 tracking-wider mb-2 ml-1">Password</label>
                    <input type="password" name="password" id="password" 
                           class="input-glass w-full placeholder-slate-400" 
                           placeholder="Enter your password" required>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <div class="flex items-center">
                        <input id="remember-me" type="checkbox" class="w-5 h-5 rounded-lg border-slate-300 text-cyan-600 focus:ring-cyan-500/50">
                        <label for="remember-me" class="ml-2.5 text-sm text-slate-600 cursor-pointer font-medium">Remember me</label>
                    </div>
                    <a href="#" class="text-sm text-cyan-600 hover:text-cyan-700 transition-colors font-bold uppercase tracking-wide">Forgot?</a>
                </div>

                <button type="submit" 
                        class="w-full py-4 bg-gradient-to-r from-cyan-600 to-blue-700 hover:from-cyan-500 hover:to-blue-600 text-white font-bold text-lg rounded-2xl shadow-xl shadow-cyan-900/10 transition-all transform active:scale-[0.98] mt-4">
                    Sign In
                </button>
            </form>

            <div class="mt-12 text-center">
                <p class="text-[12px] text-slate-400 font-bold uppercase tracking-widest">
                    &copy; <?php echo date('Y'); ?> Crystal POS System
                </p>
                <p class="text-[10px] text-slate-400/60 mt-2 font-medium">Secure Access Point</p>
            </div>
        </div>
    </div>
</body>
</html>
