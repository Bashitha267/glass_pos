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
        $role = $_POST['role'];
        $full_name = $_POST['full_name'];
        $contact_number = $_POST['contact_number'];
        $nic_number = $_POST['nic_number'] ?? null;
        $address = $_POST['address'] ?? null;
        $password = $_POST['password'] ?? null;
        
        // Credentials only for admin
        $username = ($role === 'admin') ? ($_POST['username'] ?? null) : null;
        if ($role === 'employee') {
            $password = null;
        }

        // Check if username exists (only for admins)
        if ($role === 'admin' && $username) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$username, (int)$id]);
            if ($stmt->fetchColumn()) {
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
            $file_name = ($username ?: 'staff') . "_" . time() . "." . $file_ext;
            $target_file = $target_dir . $file_name;
            if (move_uploaded_file($_FILES["profile_pic"]["tmp_name"], $target_file)) {
                $profile_pic = "assets/uploads/profiles/" . $file_name;
            }
        }

        if ($id) {
            // Update
            $sql = "UPDATE users SET username = ?, full_name = ?, contact_number = ?, role = ?, nic_number = ?, address = ?, profile_pic = ? WHERE id = ?";
            $params = [$username, $full_name, $contact_number, $role, $nic_number, $address, $profile_pic, $id];
            
            if ($password && $role === 'admin') {
                $sql = "UPDATE users SET username = ?, full_name = ?, contact_number = ?, role = ?, nic_number = ?, address = ?, profile_pic = ?, password = ? WHERE id = ?";
                $params = [$username, $full_name, $contact_number, $role, $nic_number, $address, $profile_pic, password_hash($password, PASSWORD_DEFAULT), $id];
            }
            $pdo->prepare($sql)->execute($params);
        } else {
            // Insert
            if ($role === 'admin' && !$password) {
                echo json_encode(['success' => false, 'message' => 'Password is required for new administrators']);
                exit;
            }
            $pass_hash = ($password && $role === 'admin') ? password_hash($password, PASSWORD_DEFAULT) : null;
            $sql = "INSERT INTO users (username, password, full_name, contact_number, role, nic_number, address, profile_pic) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$username, $pass_hash, $full_name, $contact_number, $role, $nic_number, $address, $profile_pic]);
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
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
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;600;700&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background: url('../assests/glass_bg.png') no-repeat center center fixed;
            background-size: cover;
            color: #1e293b;
            min-height: 100vh;
        }

        .glass-header {
            background: rgba(248, 250, 252, 0.96);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            box-shadow: 0 4px 20px -5px rgba(0, 0, 0, 0.05);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid white;
            border-radius: 24px;
            box-shadow: 0 10px 30px -5px rgba(0,0,0,0.04);
        }

        .input-glass {
            background: rgba(255, 255, 255, 0.6);
            border: 1px solid #e2e8f0;
            padding: 10px 16px;
            border-radius: 14px;
            outline: none;
            transition: all 0.3s;
            font-size: 13px;
        }

        .input-glass:focus {
            border-color: #0891b2;
            background: white;
            box-shadow: 0 0 15px rgba(8, 145, 178, 0.08);
        }

        .role-badge-admin { background: #0891b2; color: white; border-radius: 8px; font-weight: 800; }
        .role-badge-employee { background: #f1f5f9; color: #64748b; border-radius: 8px; font-weight: 800; }
    </style>
</head>
<body class="flex flex-col">

    <header class="glass-header sticky top-0 z-40 py-4">
        <div class="max-w-7xl mx-auto px-6 flex items-center justify-between">
            <div class="flex items-center space-x-5">
                <a href="dashboard.php" class="text-slate-800 hover:text-cyan-600 transition-colors p-2.5 rounded-2xl hover:bg-slate-100">
                    <i class="fa-solid fa-arrow-left text-xl"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-black text-slate-900 font-['Outfit'] tracking-tight">Staff Directory</h1>
                    <p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mt-0.5">Manage Team Records</p>
                </div>
            </div>
            <div class="flex space-x-3">
                <button onclick="openModal('employee')" class="bg-indigo-600 hover:bg-black text-white px-6 py-3 rounded-2xl font-bold text-xs uppercase tracking-widest transition-all shadow-xl shadow-indigo-900/20">
                    Add Staff Member
                </button>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto w-full px-6 py-10">
        <!-- Stats Section -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
            <div class="glass-card p-6 flex items-center space-x-5 border-l-4 border-l-indigo-600">
                <div class="w-14 h-14 bg-indigo-50 rounded-2xl flex items-center justify-center text-indigo-600">
                    <i class="fa-solid fa-users text-2xl"></i>
                </div>
                <div>
                    <p class="text-[10px] uppercase font-black text-slate-600 tracking-widest mb-1">Active Staff</p>
                    <p class="text-3xl font-black text-slate-800"><?php echo $total_employees; ?></p>
                </div>
            </div>
            <div class="glass-card p-6 flex items-center space-x-5 border-l-4 border-l-cyan-600">
                <div class="w-14 h-14 bg-cyan-50 rounded-2xl flex items-center justify-center text-cyan-600">
                    <i class="fa-solid fa-user-shield text-2xl"></i>
                </div>
                <div>
                    <p class="text-[10px] uppercase font-black text-slate-600 tracking-widest mb-1">System Admins</p>
                    <p class="text-3xl font-black text-slate-800"><?php echo $total_admins; ?></p>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="glass-card p-6 mb-8 border-slate-200">
            <form method="GET" class="flex items-end gap-4">
                <div class="flex-1 relative">
                    <label class="text-[10px] uppercase font-black text-slate-600 mb-2 ml-1 block tracking-widest">Search Records</label>
                    <div class="relative">
                        <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name, username or phone..." class="input-glass w-full pl-12 h-[48px] text-sm">
                    </div>
                </div>
                <button type="submit" class="bg-slate-900 hover:bg-black text-white px-8 py-3 rounded-2xl font-bold uppercase text-xs tracking-widest h-[48px] transition-all">Search</button>
            </form>
        </div>

        <!-- Employee Table -->
        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-slate-900 text-[11px] uppercase tracking-wider text-white">
                            <th class="px-6 py-5 font-black">ID</th>
                            <th class="px-6 py-5 font-black">Staff Member</th>
                            <th class="px-6 py-5 font-black">Contact</th>
                            <th class="px-6 py-5 font-black">Designation</th>
                            <th class="px-6 py-5 text-right font-black">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($user_list as $u): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-4 text-xs font-black text-slate-400">#<?php echo str_pad($u['id'], 3, '0', STR_PAD_LEFT); ?></td>
                            <td class="px-6 py-4">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 rounded-2xl overflow-hidden bg-slate-100 border border-slate-200">
                                        <?php if ($u['profile_pic']): ?>
                                            <img src="../<?php echo $u['profile_pic']; ?>" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <div class="w-full h-full flex items-center justify-center text-slate-400 font-black text-sm uppercase">
                                                <?php echo substr($u['full_name'], 0, 1); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <p class="text-sm font-black text-slate-800"><?php echo htmlspecialchars($u['full_name']); ?></p>
                                        <p class="text-[10px] font-bold text-slate-400"><?php echo $u['username'] ? '@'.$u['username'] : 'General Staff'; ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-700 font-bold"><?php echo htmlspecialchars($u['contact_number']); ?></td>
                            <td class="px-6 py-4">
                                <span class="px-3 py-1.5 text-[9px] font-black uppercase tracking-widest <?php echo $u['role'] == 'admin' ? 'role-badge-admin' : 'role-badge-employee'; ?>">
                                    <?php echo $u['role']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end space-x-1">
                                    <button onclick="viewUser(<?php echo $u['id']; ?>)" class="p-2 text-slate-400 hover:text-indigo-600 transition-colors"><i class="fa-solid fa-eye text-sm"></i></button>
                                    <button onclick="editUser(<?php echo $u['id']; ?>)" class="p-2 text-slate-400 hover:text-emerald-600 transition-colors"><i class="fa-solid fa-pen-to-square text-sm"></i></button>
                                    <button onclick="deleteUser(<?php echo $u['id']; ?>)" class="p-2 text-slate-400 hover:text-rose-600 transition-colors"><i class="fa-solid fa-trash-can text-sm"></i></button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal -->
    <div id="user-modal" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="glass-card w-full max-w-2xl max-h-[90vh] overflow-y-auto bg-white p-8">
            <div class="flex items-center justify-between mb-8">
                <h2 id="modal-title" class="text-2xl font-black text-slate-900 font-['Outfit']">Staff Enrollment</h2>
                <button onclick="closeModal()" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-times text-xl"></i></button>
            </div>

            <form id="user-form" onsubmit="saveUser(event)" class="space-y-8">
                <input type="hidden" name="id" id="user_id">
                <input type="hidden" name="existing_profile_pic" id="existing_profile_pic">
                
                <div class="flex justify-center">
                    <div class="relative group">
                        <div class="w-32 h-32 rounded-[2rem] border-2 border-dashed border-slate-200 overflow-hidden bg-slate-50 flex items-center justify-center cursor-pointer hover:border-indigo-400 transition-all" onclick="document.getElementById('profile_pic_input').click()">
                            <img id="profile-preview" src="" class="w-full h-full object-cover hidden">
                            <div id="pic-placeholder" class="text-slate-400 group-hover:text-indigo-600 text-center">
                                <i class="fa-solid fa-camera-retro text-2xl mb-2"></i>
                                <p class="text-[9px] uppercase font-black tracking-widest">Set Photo</p>
                            </div>
                        </div>
                        <input type="file" id="profile_pic_input" name="profile_pic" class="hidden" accept="image/*" onchange="previewImage(this)">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="md:col-span-2 flex flex-col space-y-2">
                        <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">Full Name</label>
                        <input type="text" name="full_name" id="full_name" class="input-glass" required>
                    </div>
                    
                    <div class="flex flex-col space-y-2">
                        <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">Phone Number</label>
                        <input type="text" name="contact_number" id="contact_number" class="input-glass" required>
                    </div>

                    <div class="flex flex-col space-y-2">
                        <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">Account Type</label>
                        <select name="role" id="role" class="input-glass font-bold" onchange="toggleCredentialFields()">
                            <option value="employee">Field Staff</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>

                    <!-- Admin Only Fields -->
                    <div id="credential-fields" class="md:col-span-2 grid grid-cols-2 gap-8 hidden">
                        <div class="flex flex-col space-y-2">
                            <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">Login Username</label>
                            <input type="text" name="username" id="username_input" class="input-glass">
                        </div>
                        <div class="flex flex-col space-y-2">
                            <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">Secure Password</label>
                            <input type="password" name="password" id="password" class="input-glass">
                        </div>
                    </div>

                    <div class="flex flex-col space-y-2">
                        <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">NIC Number</label>
                        <input type="text" name="nic_number" id="nic_number" class="input-glass">
                    </div>
                    
                    <div class="md:col-span-2 flex flex-col space-y-2">
                        <label class="text-[10px] uppercase font-black text-slate-400 mb-1 block tracking-widest">Home Address</label>
                        <textarea name="address" id="address" class="input-glass min-h-[100px]"></textarea>
                    </div>
                </div>

                <div class="flex justify-end pt-4 gap-4">
                    <button type="button" onclick="closeModal()" class="px-8 py-3.5 font-black uppercase text-[10px] tracking-widest text-slate-400 hover:text-slate-600 transition-colors">Discard</button>
                    <button type="submit" id="submit-btn" class="bg-indigo-600 hover:bg-black text-white font-black py-4 px-12 rounded-2xl shadow-2xl shadow-indigo-900/10 transition-all active:scale-95 text-[10px] uppercase tracking-widest">Sync Records</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Modal -->
    <div id="view-modal" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="glass-card w-full max-w-md bg-white overflow-hidden">
            <div class="h-24 bg-slate-900"></div>
            <div class="p-8 pt-0 flex flex-col items-center">
                <div class="w-32 h-32 rounded-[2.5rem] border-4 border-white overflow-hidden bg-slate-50 shadow-2xl -mt-16 mb-6">
                    <img id="view-pic" src="" class="w-full h-full object-cover">
                </div>
                <h3 id="view-name" class="text-xl font-black text-slate-900 font-['Outfit']"></h3>
                <p id="view-role" class="text-[10px] uppercase font-black tracking-[0.3em] text-indigo-600 mt-1 mb-8"></p>

                <div class="w-full space-y-5 bg-slate-50 p-8 rounded-[2rem] border border-slate-100">
                    <div class="flex justify-between">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Contact</span>
                        <span id="view-contact" class="text-sm font-bold text-slate-800"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">NIC No</span>
                        <span id="view-nic" class="text-sm font-bold text-slate-800"></span>
                    </div>
                    <div class="pt-4 border-t border-slate-200">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">Address</p>
                        <p id="view-address" class="text-xs font-bold text-slate-600 leading-relaxed"></p>
                    </div>
                </div>
                <button onclick="document.getElementById('view-modal').classList.add('hidden')" class="mt-8 text-[10px] font-black uppercase text-slate-400">Close Profile</button>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('user-modal');
        const viewModal = document.getElementById('view-modal');

        function toggleCredentialFields() {
            const role = document.getElementById('role').value;
            const fields = document.getElementById('credential-fields');
            const usernameInput = document.getElementById('username_input');
            const passwordInput = document.getElementById('password');
            const isEdit = document.getElementById('user_id').value !== '';

            if (role === 'admin') {
                fields.classList.remove('hidden');
                usernameInput.required = true;
                // Password required only for new administrators
                passwordInput.required = !isEdit;
            } else {
                fields.classList.add('hidden');
                usernameInput.required = false;
                passwordInput.required = false;
            }
        }
        
        function openModal(defaultRole = 'employee') {
            document.getElementById('user_id').value = '';
            document.getElementById('user-form').reset();
            document.getElementById('profile-preview').classList.add('hidden');
            document.getElementById('pic-placeholder').classList.remove('hidden');
            document.getElementById('modal-title').innerText = "Staff Enrollment";
            document.getElementById('role').value = defaultRole;
            toggleCredentialFields();
            modal.classList.remove('hidden');
        }

        function closeModal() {
            modal.classList.add('hidden');
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

        function editUser(id) {
            fetch(`?action=get_user&id=${id}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const u = res.data;
                        document.getElementById('user_id').value = u.id;
                        document.getElementById('full_name').value = u.full_name;
                        document.getElementById('contact_number').value = u.contact_number;
                        document.getElementById('role').value = u.role;
                        document.getElementById('username_input').value = u.username || '';
                        document.getElementById('nic_number').value = u.nic_number || '';
                        document.getElementById('address').value = u.address || '';
                        document.getElementById('existing_profile_pic').value = u.profile_pic || '';
                        
                        if (u.profile_pic) {
                            document.getElementById('profile-preview').src = '../' + u.profile_pic;
                            document.getElementById('profile-preview').classList.remove('hidden');
                            document.getElementById('pic-placeholder').classList.add('hidden');
                        }
                        
                        toggleCredentialFields();
                        document.getElementById('modal-title').innerText = "Update Record";
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
                        document.getElementById('view-role').innerText = u.role === 'admin' ? 'Administrator' : 'Field Staff';
                        document.getElementById('view-contact').innerText = u.contact_number;
                        document.getElementById('view-nic').innerText = u.nic_number || '-';
                        document.getElementById('view-address').innerText = u.address || 'Address not listed';
                        document.getElementById('view-pic').src = u.profile_pic ? '../' + u.profile_pic : 'https://ui-avatars.com/api/?name=' + u.full_name + '&background=f1f5f9&color=6366f1&size=128&bold=true';
                        viewModal.classList.remove('hidden');
                    }
                });
        }

        function saveUser(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            fetch('?action=save_user', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) location.reload();
                    else alert(res.message);
                });
        }

        function deleteUser(id) {
            if (confirm('Confirm permanent deletion of this record?')) {
                fetch('?action=delete_user', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `id=${id}`
                }).then(r => r.json())
                  .then(res => {
                      if (res.success) location.reload();
                      else alert(res.message);
                  });
            }
        }
    </script>
</body>
</html>
