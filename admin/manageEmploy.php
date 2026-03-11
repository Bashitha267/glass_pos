<?php
require_once '../auth.php';
require_once '../config.php';
checkAuth();

if (!isAdmin()) {
    header('Location: ../sale/dashboard.php');
    exit;
}

// Handle AJAX Actions
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action == 'get_user') {
    $id = $_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    unset($user['password']); // Don't send password hash
    echo json_encode(['success' => true, 'data' => $user]);
    exit;
}

if ($action == 'save_user') {
    try {
        $id = $_POST['id'] ?? null;
        $username = $_POST['username'];
        $full_name = $_POST['full_name'];
        $contact_number = $_POST['contact_number'];
        $role = $_POST['role'];
        $nic_number = $_POST['nic_number'] ?? null;
        $address = $_POST['address'] ?? null;
        $system_access = isset($_POST['system_access']) ? 1 : 0;
        $password = $_POST['password'] ?? null;

        // Check if username exists (if new user or username changed)
        if (!$id) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($count = $stmt->fetchColumn()) {
                echo json_encode(['success' => false, 'message' => 'Username already exists']);
                exit;
            }
        }

        // Handle Profile Pic Upload
        $profile_pic = $_POST['existing_profile_pic'] ?? null;
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
            $target_dir = "../assets/uploads/profiles/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            $file_ext = pathinfo($_FILES["profile_pic"]["name"], PATHINFO_EXTENSION);
            $file_name = $username . "_" . time() . "." . $file_ext;
            $target_file = $target_dir . $file_name;
            if (move_uploaded_file($_FILES["profile_pic"]["tmp_name"], $target_file)) {
                $profile_pic = "assets/uploads/profiles/" . $file_name;
            }
        }

        if ($id) {
            // Update
            $sql = "UPDATE users SET username = ?, full_name = ?, contact_number = ?, role = ?, nic_number = ?, address = ?, system_access = ?, profile_pic = ? WHERE id = ?";
            $params = [$username, $full_name, $contact_number, $role, $nic_number, $address, $system_access, $profile_pic, $id];
            
            if ($password) {
                $sql = "UPDATE users SET username = ?, full_name = ?, contact_number = ?, role = ?, nic_number = ?, address = ?, system_access = ?, profile_pic = ?, password = ? WHERE id = ?";
                $params = [$username, $full_name, $contact_number, $role, $nic_number, $address, $system_access, $profile_pic, password_hash($password, PASSWORD_DEFAULT), $id];
            }
            $pdo->prepare($sql)->execute($params);
        } else {
            // Insert
            if (!$password) {
                echo json_encode(['success' => false, 'message' => 'Password is required for new users']);
                exit;
            }
            $sql = "INSERT INTO users (username, password, full_name, contact_number, role, nic_number, address, profile_pic, system_access) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$username, password_hash($password, PASSWORD_DEFAULT), $full_name, $contact_number, $role, $nic_number, $address, $profile_pic, $system_access]);
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action == 'toggle_access') {
    $id = $_POST['id'];
    $stmt = $pdo->prepare("UPDATE users SET system_access = 1 - system_access WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action == 'delete_user') {
    $id = $_POST['id'];
    if ($id == $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'You cannot delete yourself!']);
        exit;
    }
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

// Fetch Stats
$total_admins = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$total_employees = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'employee'")->fetchColumn();

// Fetch Users
$search = $_GET['search'] ?? '';
$where = "";
$params = [];
if ($search) {
    $where = "WHERE (full_name LIKE ? OR username LIKE ? OR contact_number LIKE ?)";
    $params = ["%$search%", "%$search%", "%$search%"];
}

$users = $pdo->prepare("SELECT * FROM users $where ORDER BY role ASC, full_name ASC");
$users->execute($params);
$user_list = $users->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff | Crystal POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.85)), url('../assests/bg.webp') no-repeat center center fixed;
            background-size: cover;
            color: white;
            min-height: 100vh;
        }
        .glass-header {
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(126, 34, 206, 0.2);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(126, 34, 206, 0.2);
            border-radius: 20px;
        }
        .container-modal {
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(20px);
            border: 2px solid rgba(126, 34, 206, 0.4);
        }
        .input-glass {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(126, 34, 206, 0.2);
            color: white;
            padding: 8px 12px;
            border-radius: 10px;
            outline: none;
            transition: all 0.3s;
        }
        .input-glass:focus {
            border-color: #a855f7;
            box-shadow: 0 0 15px rgba(168, 85, 247, 0.1);
        }
    </style>
</head>
<body class="flex flex-col">

    <header class="glass-header sticky top-0 z-40 py-3">
        <div class="px-10 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="dashboard.php" class="text-white hover:text-purple-400 transition-colors">
                    <i class="fa-solid fa-arrow-left text-xl"></i>
                </a>
                <h1 class="text-2xl font-bold tracking-tight uppercase">Manage Employees</h1>
            </div>
            <div class="flex space-x-3">
                <button onclick="openModal('employee')" class="bg-purple-600 hover:bg-purple-500 text-white px-5 py-2.5 rounded-xl font-bold text-sm uppercase transition-all shadow-lg flex items-center space-x-2">
                    <i class="fa-solid fa-user-plus"></i>
                    <span>Add Employee</span>
                </button>
                <button onclick="openModal('admin')" class="bg-cyan-600 hover:bg-cyan-500 text-white px-5 py-2.5 rounded-xl font-bold text-sm uppercase transition-all shadow-lg flex items-center space-x-2">
                    <i class="fa-solid fa-user-shield"></i>
                    <span>Add Admin</span>
                </button>
            </div>
        </div>
    </header>

    <main class="w-full px-10 py-8">
        <!-- Stats Section -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mb-8">
            <div class="glass-card p-6 flex items-center space-x-4 border-l-4 border-l-purple-500">
                <div class="w-12 h-12 bg-purple-500/20 rounded-full flex items-center justify-center text-purple-400">
                    <i class="fa-solid fa-users text-2xl"></i>
                </div>
                <div>
                    <p class="text-xs uppercase font-bold text-slate-400">Total Employees</p>
                    <p class="text-2xl font-black text-white"><?php echo $total_employees; ?></p>
                </div>
            </div>
            <div class="glass-card p-6 flex items-center space-x-4 border-l-4 border-l-cyan-500">
                <div class="w-12 h-12 bg-cyan-500/20 rounded-full flex items-center justify-center text-cyan-400">
                    <i class="fa-solid fa-user-shield text-2xl"></i>
                </div>
                <div>
                    <p class="text-xs uppercase font-bold text-slate-400">Total Admins</p>
                    <p class="text-2xl font-black text-white"><?php echo $total_admins; ?></p>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="glass-card p-6 mb-8">
            <form method="GET" class="flex items-end">
                <div class="flex-1 relative max-w-md">
                    <label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Search Staff</label>
                    <div class="relative">
                        <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name, username or contact..." class="input-glass w-full pl-12">
                    </div>
                </div>
                <button type="submit" class="ml-4 bg-purple-600 hover:bg-purple-500 text-white px-6 py-2.5 rounded-xl font-bold uppercase text-xs transition-all">Search</button>
                <?php if($search): ?>
                    <a href="manageEmploy.php" class="ml-2 bg-rose-500/20 text-rose-400 px-6 py-2.5 rounded-xl hover:bg-rose-500/30 transition-all text-xs font-bold uppercase">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Employee Table -->
        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-purple-900 text-[12px] uppercase tracking-wider text-white border-b border-purple-700">
                            <th class="px-6 py-4 font-bold">ID</th>
                            <th class="px-6 py-4 font-bold">Name</th>
                            <th class="px-6 py-4 font-bold">Contact</th>
                            <th class="px-6 py-4 font-bold">Username</th>
                            <th class="px-6 py-4 font-bold text-center">Role</th>
                            <th class="px-6 py-4 font-bold text-center">System Access</th>
                            <th class="px-6 py-4 text-center font-bold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-purple-900/30">
                        <?php foreach ($user_list as $u): ?>
                        <tr class="odd:bg-white/[0.01] even:bg-transparent hover:bg-purple-900/10 transition-colors">
                            <td class="px-6 py-4 text-sm font-bold text-purple-400">#<?php echo str_pad($u['id'], 3, '0', STR_PAD_LEFT); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 rounded-full border border-purple-500/30 overflow-hidden bg-slate-800">
                                        <?php if ($u['profile_pic']): ?>
                                            <img src="../<?php echo $u['profile_pic']; ?>" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <div class="w-full h-full flex items-center justify-center text-purple-400 font-bold">
                                                <?php echo substr($u['full_name'], 0, 1); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-sm font-bold text-white"><?php echo htmlspecialchars($u['full_name']); ?></span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-300"><?php echo htmlspecialchars($u['contact_number']); ?></td>
                            <td class="px-6 py-4 text-sm text-cyan-400 font-medium"><?php echo htmlspecialchars($u['username']); ?></td>
                            <td class="px-6 py-4 text-center">
                                <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase <?php echo $u['role'] == 'admin' ? 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/30' : 'bg-purple-500/20 text-purple-400 border border-purple-500/30'; ?>">
                                    <?php echo $u['role']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <button onclick="toggleAccess(<?php echo $u['id']; ?>)" class="transition-all hover:scale-110">
                                    <?php if ($u['system_access']): ?>
                                        <i class="fa-solid fa-toggle-on text-emerald-400 text-2xl"></i>
                                    <?php else: ?>
                                        <i class="fa-solid fa-toggle-off text-rose-500 text-2xl"></i>
                                    <?php endif; ?>
                                </button>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-center space-x-2">
                                    <button onclick="viewUser(<?php echo $u['id']; ?>)" class="text-slate-400 hover:text-purple-400 p-2 rounded-lg hover:bg-purple-400/10">
                                        <i class="fa-solid fa-eye text-sm"></i>
                                    </button>
                                    <button onclick="editUser(<?php echo $u['id']; ?>)" class="text-slate-400 hover:text-emerald-400 p-2 rounded-lg hover:bg-emerald-400/10">
                                        <i class="fa-solid fa-pen-to-square text-sm"></i>
                                    </button>
                                    <button onclick="deleteUser(<?php echo $u['id']; ?>)" class="text-slate-400 hover:text-rose-500 p-2 rounded-lg hover:bg-rose-500/10">
                                        <i class="fa-solid fa-trash-can text-sm"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($user_list)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-10 text-center text-slate-500 italic">No staff members found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal -->
    <div id="user-modal" class="fixed inset-0 bg-black/80 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="container-modal w-full max-w-2xl max-h-[95vh] overflow-y-auto rounded-[30px] shadow-2xl">
            <div class="p-8">
                <div class="flex items-center justify-between mb-8">
                    <h2 id="modal-title" class="text-2xl font-bold">Add Staff Member</h2>
                    <button onclick="closeModal()" class="text-slate-400 hover:text-white">
                        <i class="fa-solid fa-times text-2xl"></i>
                    </button>
                </div>

                <form id="user-form" onsubmit="saveUser(event)" class="space-y-6">
                    <input type="hidden" name="id" id="user_id">
                    <input type="hidden" name="existing_profile_pic" id="existing_profile_pic">
                    
                    <div class="flex justify-center mb-8">
                        <div class="relative group">
                            <div class="w-32 h-32 rounded-3xl border-2 border-dashed border-purple-500/30 overflow-hidden bg-white/5 flex items-center justify-center cursor-pointer hover:border-purple-400 transition-all" onclick="document.getElementById('profile_pic_input').click()">
                                <img id="profile-preview" src="" class="w-full h-full object-cover hidden">
                                <div id="pic-placeholder" class="text-slate-500 group-hover:text-purple-400 text-center">
                                    <i class="fa-solid fa-camera text-2xl mb-2"></i>
                                    <p class="text-[10px] uppercase font-bold">Profile Pic</p>
                                </div>
                            </div>
                            <input type="file" id="profile_pic_input" name="profile_pic" class="hidden" accept="image/*" onchange="previewImage(this)">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-slate-400 tracking-wider">Full Name *</label>
                            <input type="text" name="full_name" id="full_name" class="input-glass" required placeholder="John Doe">
                        </div>
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-slate-400 tracking-wider">Username *</label>
                            <input type="text" name="username" id="username_input" class="input-glass" required placeholder="johndoe">
                        </div>
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-slate-400 tracking-wider">Contact Number *</label>
                            <input type="text" name="contact_number" id="contact_number" class="input-glass" required placeholder="07XXXXXXXX">
                        </div>
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-slate-400 tracking-wider">Role</label>
                            <select name="role" id="role" class="input-glass bg-slate-900">
                                <option value="employee">Employee</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-slate-400 tracking-wider">NIC Number (Optional)</label>
                            <input type="text" name="nic_number" id="nic_number" class="input-glass" placeholder="9XXXXXXXXV">
                        </div>
                        <div class="flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-slate-400 tracking-wider">Password</label>
                            <input type="password" name="password" id="password" class="input-glass" placeholder="Leave blank to keep current">
                        </div>
                        <div class="md:col-span-2 flex flex-col space-y-2">
                            <label class="text-xs uppercase font-bold text-slate-400 tracking-wider">Address (Optional)</label>
                            <textarea name="address" id="address" class="input-glass min-h-[80px]" placeholder="Street, City"></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <div class="flex items-center justify-between p-4 bg-white/5 rounded-2xl border border-purple-500/20">
                                <div>
                                    <p class="text-xs uppercase font-bold text-white mb-1">System Access</p>
                                    <p class="text-[10px] text-slate-400">Allow this user to sign in to the Crystal POS system.</p>
                                </div>
                                <input type="checkbox" name="system_access" id="system_access" class="w-6 h-6 rounded border-purple-500/20 bg-white/10 text-purple-600 focus:ring-purple-500/50" checked>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row justify-end pt-4 gap-3">
                        <button type="button" onclick="closeModal()" class="bg-white/5 text-slate-400 font-bold py-3 px-8 rounded-2xl border border-white/10">Cancel</button>
                        <button type="submit" id="submit-btn" class="bg-gradient-to-r from-purple-500 to-indigo-600 hover:from-purple-400 hover:to-indigo-500 text-white font-bold py-3 px-12 rounded-2xl shadow-xl transition-all active:scale-95">Save Staff Member</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Modal -->
    <div id="view-modal" class="fixed inset-0 bg-black/80 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="container-modal w-full max-w-xl rounded-[30px] shadow-2xl relative overflow-hidden">
            <div class="absolute top-0 left-0 w-full h-32 bg-gradient-to-r from-purple-600 to-indigo-700"></div>
            <div class="p-8 pt-16 relative">
                <button onclick="closeViewModal()" class="absolute top-4 right-4 text-white/70 hover:text-white">
                    <i class="fa-solid fa-times text-2xl"></i>
                </button>
                
                <div class="flex flex-col items-center mb-8">
                    <div class="w-32 h-32 rounded-[2rem] border-4 border-slate-900 overflow-hidden bg-slate-800 shadow-2xl mb-4 relative z-10">
                        <img id="view-pic" src="" class="w-full h-full object-cover">
                    </div>
                    <h3 id="view-name" class="text-2xl font-bold"></h3>
                    <p id="view-role" class="text-xs uppercase font-black tracking-widest text-purple-400 bg-purple-400/10 px-3 py-1 rounded-full mt-2"></p>
                </div>

                <div class="grid grid-cols-2 gap-6 bg-white/5 p-6 rounded-[2rem] border border-white/10">
                    <div>
                        <p class="text-[10px] uppercase font-bold text-slate-500 mb-1">Username</p>
                        <p id="view-username" class="text-sm font-bold text-white"></p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-slate-500 mb-1">Contact</p>
                        <p id="view-contact" class="text-sm font-bold text-white"></p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-slate-500 mb-1">NIC Number</p>
                        <p id="view-nic" class="text-sm font-bold text-white">-</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-slate-500 mb-1">System Access</p>
                        <p id="view-access" class="text-sm font-bold"></p>
                    </div>
                    <div class="col-span-2">
                        <p class="text-[10px] uppercase font-bold text-slate-500 mb-1">Address</p>
                        <p id="view-address" class="text-sm font-bold text-white">-</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('user-modal');
        const viewModal = document.getElementById('view-modal');
        
        function openModal(defaultRole = 'employee') {
            document.getElementById('user_id').value = '';
            document.getElementById('user-form').reset();
            document.getElementById('profile-preview').classList.add('hidden');
            document.getElementById('pic-placeholder').classList.remove('hidden');
            document.getElementById('modal-title').innerText = "Add Staff Member";
            document.getElementById('role').value = defaultRole;
            document.getElementById('password').required = true;
            document.getElementById('password').placeholder = "Enter password";
            modal.classList.remove('hidden');
        }

        function closeModal() {
            modal.classList.add('hidden');
        }

        function closeViewModal() {
            viewModal.classList.add('hidden');
        }

        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('profile-preview').src = e.target.result;
                    document.getElementById('profile-preview').classList.remove('hidden');
                    document.getElementById('pic-placeholder').classList.add('hidden');
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function toggleAccess(id) {
            fetch('?action=toggle_access', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${id}`
            }).then(() => location.reload());
        }

        function editUser(id) {
            fetch(`?action=get_user&id=${id}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const u = res.data;
                        document.getElementById('user_id').value = u.id;
                        document.getElementById('full_name').value = u.full_name;
                        document.getElementById('username_input').value = u.username;
                        document.getElementById('contact_number').value = u.contact_number;
                        document.getElementById('role').value = u.role;
                        document.getElementById('nic_number').value = u.nic_number || '';
                        document.getElementById('address').value = u.address || '';
                        document.getElementById('system_access').checked = u.system_access == 1;
                        document.getElementById('existing_profile_pic').value = u.profile_pic || '';
                        
                        if (u.profile_pic) {
                            document.getElementById('profile-preview').src = '../' + u.profile_pic;
                            document.getElementById('profile-preview').classList.remove('hidden');
                            document.getElementById('pic-placeholder').classList.add('hidden');
                        } else {
                            document.getElementById('profile-preview').classList.add('hidden');
                            document.getElementById('pic-placeholder').classList.remove('hidden');
                        }

                        document.getElementById('password').required = false;
                        document.getElementById('password').placeholder = "Leave blank to keep current";
                        document.getElementById('modal-title').innerText = "Edit Staff Member";
                        modal.classList.remove('hidden');
                    }
                });
        }

        function viewUser(id) {
            fetch(`?action=get_user&id=${id}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const u = res.data;
                        document.getElementById('view-name').innerText = u.full_name;
                        document.getElementById('view-role').innerText = u.role;
                        document.getElementById('view-username').innerText = '@' + u.username;
                        document.getElementById('view-contact').innerText = u.contact_number;
                        document.getElementById('view-nic').innerText = u.nic_number || '-';
                        document.getElementById('view-address').innerText = u.address || '-';
                        document.getElementById('view-access').innerText = u.system_access == 1 ? 'ALLOWED' : 'DENIED';
                        document.getElementById('view-access').className = u.system_access == 1 ? 'text-sm font-bold text-emerald-400' : 'text-sm font-bold text-rose-500';
                        document.getElementById('view-pic').src = u.profile_pic ? '../' + u.profile_pic : 'https://ui-avatars.com/api/?name=' + u.full_name + '&background=7e22ce&color=fff&size=128';
                        viewModal.classList.remove('hidden');
                    }
                });
        }

        function saveUser(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            fetch('?action=save_user', {
                method: 'POST',
                body: formData
            }).then(r => r.json())
                .then(res => {
                    if (res.success) {
                        location.reload();
                    } else {
                        alert(res.message);
                    }
                });
        }

        function deleteUser(id) {
            if (confirm('Are you sure you want to delete this staff member? This action cannot be undone.')) {
                fetch('?action=delete_user', {
                    method: 'POST',
                     headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `id=${id}`
                }).then(r => r.json())
                  .then(res => {
                      if (res.success) {
                          location.reload();
                      } else {
                          alert(res.message);
                      }
                  });
            }
        }
    </script>
</body>
</html>
