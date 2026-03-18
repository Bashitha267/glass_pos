<?php
require_once '../auth.php';
require_once '../config.php';
checkAuth();

if (!isAdmin()) {
	header('Location: ../sale/dashboard.php');
	exit;
}

$salary_success = '';
$salary_error = '';

$staff_search = trim($_GET['staff_search'] ?? '');
$salary_month = (int)($_GET['salary_month'] ?? date('n'));
$salary_year = (int)($_GET['salary_year'] ?? date('Y'));
$salary_status = $_GET['salary_status'] ?? 'all';

if ($salary_month < 1 || $salary_month > 12) {
	$salary_month = (int)date('n');
}
if ($salary_year < 2020 || $salary_year > ((int)date('Y') + 5)) {
	$salary_year = (int)date('Y');
}
if (!in_array($salary_status, ['all', 'paid', 'nonpaid'], true)) {
	$salary_status = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$salary_action = $_POST['salary_action'] ?? '';

	if ($salary_action === 'pay_salary') {
		$employee_id = (int)($_POST['employee_id'] ?? 0);
		$pay_month = (int)($_POST['salary_month'] ?? date('n'));
		$pay_year = (int)($_POST['salary_year'] ?? date('Y'));
		$salary_amount = (float)($_POST['salary_amount'] ?? 0);
		$payment_date = $_POST['payment_date'] ?? date('Y-m-d');

		if ($employee_id <= 0 || $pay_month < 1 || $pay_month > 12 || $pay_year < 2020 || $salary_amount <= 0 || !$payment_date) {
			$salary_error = 'Invalid salary payment details.';
		} else {
			try {
				$pdo->beginTransaction();

				$employeeStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'employee'");
				$employeeStmt->execute([$employee_id]);
				$employee = $employeeStmt->fetch();

				if (!$employee) {
					throw new Exception('Selected employee does not exist.');
				}

				$deliveriesStmt = $pdo->prepare("SELECT COUNT(DISTINCT de.delivery_id)
					FROM delivery_employees de
					JOIN deliveries d ON d.id = de.delivery_id
					WHERE de.user_id = ? AND MONTH(d.delivery_date) = ? AND YEAR(d.delivery_date) = ?");
				$deliveriesStmt->execute([$employee_id, $pay_month, $pay_year]);
				$delivery_count = (int)$deliveriesStmt->fetchColumn();

				$existingStmt = $pdo->prepare("SELECT id, status FROM employee_salary_payments WHERE user_id = ? AND salary_month = ? AND salary_year = ? FOR UPDATE");
				$existingStmt->execute([$employee_id, $pay_month, $pay_year]);
				$existingPayment = $existingStmt->fetch();

				if ($existingPayment && $existingPayment['status'] === 'paid') {
					throw new Exception('Salary already paid for this employee/month.');
				}

				$settingStmt = $pdo->prepare("INSERT INTO employee_salary_settings (user_id, monthly_salary) VALUES (?, ?)
					ON DUPLICATE KEY UPDATE monthly_salary = VALUES(monthly_salary)");
				$settingStmt->execute([$employee_id, $salary_amount]);

				if ($existingPayment) {
					$updatePaymentStmt = $pdo->prepare("UPDATE employee_salary_payments
						SET deliveries_count = ?, salary_amount = ?, payment_date = ?, status = 'paid', paid_at = NOW(), recorded_by = ?
						WHERE id = ?");
					$updatePaymentStmt->execute([$delivery_count, $salary_amount, $payment_date, $_SESSION['user_id'], $existingPayment['id']]);
				} else {
					$insertPaymentStmt = $pdo->prepare("INSERT INTO employee_salary_payments
						(user_id, salary_month, salary_year, deliveries_count, salary_amount, payment_date, status, paid_at, recorded_by)
						VALUES (?, ?, ?, ?, ?, ?, 'paid', NOW(), ?)");
					$insertPaymentStmt->execute([$employee_id, $pay_month, $pay_year, $delivery_count, $salary_amount, $payment_date, $_SESSION['user_id']]);
				}

				$pdo->commit();
				$salary_success = 'Salary payment saved successfully.';
			} catch (Exception $e) {
				if ($pdo->inTransaction()) {
					$pdo->rollBack();
				}
				$salary_error = $e->getMessage();
			}
		}
	}

	if ($salary_action === 'update_payment_status') {
		$payment_id = (int)($_POST['payment_id'] ?? 0);
		$new_status = $_POST['new_status'] ?? '';
		$payment_date = $_POST['payment_date'] ?? date('Y-m-d');

		if ($payment_id <= 0 || !in_array($new_status, ['paid', 'nonpaid'], true)) {
			$salary_error = 'Invalid payment status update request.';
		} else {
			$updateStatusStmt = $pdo->prepare("UPDATE employee_salary_payments
				SET status = ?,
					payment_date = CASE WHEN ? = 'paid' THEN ? ELSE NULL END,
					paid_at = CASE WHEN ? = 'paid' THEN NOW() ELSE NULL END,
					recorded_by = ?
				WHERE id = ?");
			$updateStatusStmt->execute([$new_status, $new_status, $payment_date, $new_status, $_SESSION['user_id'], $payment_id]);
			$salary_success = 'Payment status updated.';
		}
	}

	if ($salary_action === 'delete_payment') {
		$payment_id = (int)($_POST['payment_id'] ?? 0);
		if ($payment_id <= 0) {
			$salary_error = 'Invalid delete request.';
		} else {
			$deleteStmt = $pdo->prepare("DELETE FROM employee_salary_payments WHERE id = ?");
			$deleteStmt->execute([$payment_id]);
			$salary_success = 'Payment record deleted.';
		}
	}

	if ($salary_action === 'add_staff') {
		$full_name = trim($_POST['full_name'] ?? '');
		$contact_number = trim($_POST['contact_number'] ?? '');
		$monthly_salary = (float)($_POST['monthly_salary'] ?? 0);

		if (empty($full_name)) {
			$salary_error = 'Employee name is required.';
		} else {
			$username = strtolower(str_replace(' ', '', $full_name)) . rand(100, 999);
			$password = password_hash('password123', PASSWORD_DEFAULT);
			try {
				$pdo->beginTransaction();
				$stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, contact_number) VALUES (?, ?, ?, 'employee', ?)");
				$stmt->execute([$username, $password, $full_name, $contact_number]);
				$new_user_id = $pdo->lastInsertId();

				if ($monthly_salary > 0) {
					$stmtSal = $pdo->prepare("INSERT INTO employee_salary_settings (user_id, monthly_salary) VALUES (?, ?)");
					$stmtSal->execute([$new_user_id, $monthly_salary]);
				}
				$pdo->commit();
				$salary_success = "Staff member '$full_name' added successfully. (Username: $username, Default Password: password123)";
			} catch (Exception $e) {
				if ($pdo->inTransaction()) { $pdo->rollBack(); }
				$salary_error = 'Error adding staff: ' . $e->getMessage();
			}
		}
	}

	if ($salary_action === 'update_salary') {
		header('Content-Type: application/json');
		$employee_id = (int)($_POST['employee_id'] ?? 0);
		$new_salary = (float)($_POST['monthly_salary'] ?? 0);
		if ($employee_id > 0) {
			$settingStmt = $pdo->prepare("INSERT INTO employee_salary_settings (user_id, monthly_salary) VALUES (?, ?) ON DUPLICATE KEY UPDATE monthly_salary = VALUES(monthly_salary)");
			$settingStmt->execute([$employee_id, $new_salary]);
			echo json_encode(['success' => true]);
		} else {
			echo json_encode(['success' => false, 'error' => 'Invalid ID']);
		}
		exit;
	}
}

$staffWhere = ["u.role = 'employee'"];
$staffParams = [];

if ($staff_search !== '') {
	$staffWhere[] = "(u.full_name LIKE ? OR u.contact_number LIKE ?)";
	$staffParams[] = "%$staff_search%";
	$staffParams[] = "%$staff_search%";
}

$staffWhereClause = implode(' AND ', $staffWhere);

$staffStmt = $pdo->prepare("SELECT
		u.id,
		u.full_name,
		u.contact_number,
		COALESCE(ss.monthly_salary, 0) AS monthly_salary,
		(
			SELECT COUNT(DISTINCT de.delivery_id)
			FROM delivery_employees de
			JOIN deliveries d ON d.id = de.delivery_id
			WHERE de.user_id = u.id AND MONTH(d.delivery_date) = ? AND YEAR(d.delivery_date) = ?
		) AS delivery_count
	FROM users u
	LEFT JOIN employee_salary_settings ss ON ss.user_id = u.id
	WHERE $staffWhereClause
	ORDER BY u.full_name ASC");
$staffStmt->execute(array_merge([$salary_month, $salary_year], $staffParams));
$staff_raw = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

$paymentStmt = $pdo->prepare("SELECT * FROM employee_salary_payments WHERE salary_month = ? AND salary_year = ?");
$paymentStmt->execute([$salary_month, $salary_year]);
$paymentRows = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

$paymentMap = [];
foreach ($paymentRows as $payRow) {
	$paymentMap[(int)$payRow['user_id']] = $payRow;
}

$staff_salary_rows = [];
$total_paid_salary = 0.0;
$paid_count = 0;
$pending_count = 0;

foreach ($staff_raw as $staff) {
	$staffId = (int)$staff['id'];
	$payment = $paymentMap[$staffId] ?? null;
	$status = $payment['status'] ?? 'nonpaid';
	$salary_amount = isset($payment['salary_amount']) ? (float)$payment['salary_amount'] : (float)$staff['monthly_salary'];

	if ($status === 'paid') {
		$total_paid_salary += $salary_amount;
		$paid_count++;
	} else {
		$pending_count++;
	}

	if ($salary_status !== 'all' && $status !== $salary_status) {
		continue;
	}

	$staff_salary_rows[] = [
		'id' => $staffId,
		'full_name' => $staff['full_name'],
		'contact_number' => $staff['contact_number'],
		'delivery_count' => (int)$staff['delivery_count'],
		'monthly_salary' => (float)$staff['monthly_salary'],
		'payment_id' => $payment['id'] ?? null,
		'payment_date' => $payment['payment_date'] ?? null,
		'status' => $status,
		'salary_amount' => $salary_amount
	];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Staff Salary | Crystal POS</title>
	<script src="https://cdn.tailwindcss.com"></script>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
	<style>
		@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@300;400;600;700&display=swap');

		body {
			font-family: 'Inter', sans-serif;
			background: #f8fafc url('../assests/glass_bg.png') no-repeat center center fixed;
			background-size: cover;
			min-height: 100vh;
		}

		.glass-header {
			background: rgba(255, 255, 255, 0.8);
			backdrop-filter: blur(20px);
			border-bottom: 1px solid rgba(255, 255, 255, 1);
		}

		.glass-card {
			background: rgba(255, 255, 255, 0.88);
			backdrop-filter: blur(20px);
			border: 1px solid white;
			border-radius: 28px;
			box-shadow: 0 10px 30px -5px rgba(0,0,0,0.04);
		}

		.input-glass {
			background: rgba(255, 255, 255, 0.8);
			border: 1px solid #e2e8f0;
			padding: 10px 16px;
			border-radius: 14px;
			outline: none;
			transition: all 0.3s;
			font-size: 14px;
			font-weight: 600;
			color: #1e293b;
		}

		.input-glass:focus {
			border-color: #0f172a;
			background: white;
		}
	</style>
</head>
<body class="pb-10">
	<header class="glass-header sticky top-0 z-40 py-4 mb-8 leading-none shadow-sm">
		<div class="max-w-[1600px] mx-auto px-6 flex items-center justify-between">
			<div class="flex items-center space-x-5">
				<a href="reports.php" class="w-10 h-10 flex items-center justify-center rounded-xl bg-slate-100 text-slate-800 hover:bg-slate-900 hover:text-white transition-all shadow-sm">
					<i class="fa-solid fa-arrow-left"></i>
				</a>
				<div>
					<h1 class="text-2xl font-black text-slate-900 font-['Outfit'] tracking-tight">Staff Salary Payments</h1>
					<p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mt-1">Monthly Salary Management</p>
				</div>
			</div>
			<div class="bg-slate-900 text-white px-5 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest flex items-center space-x-3 shadow-lg shadow-slate-900/20">
				<i class="fa-solid fa-calendar-check text-base"></i>
				<span><?php echo date('Y-M-d'); ?></span>
			</div>
		</div>
	</header>

	<main class="max-w-[1200px] mx-auto px-6">
		<?php if ($salary_success): ?>
			<div class="mb-6 rounded-2xl bg-emerald-100 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm font-semibold">
				<?php echo htmlspecialchars($salary_success); ?>
			</div>
		<?php endif; ?>

		<?php if ($salary_error): ?>
			<div class="mb-6 rounded-2xl bg-rose-100 border border-rose-200 text-rose-800 px-4 py-3 text-sm font-semibold">
				<?php echo htmlspecialchars($salary_error); ?>
			</div>
		<?php endif; ?>

		<div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-6">
			<div class="glass-card p-5">
				<p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mb-2">Paid Staff</p>
				<p class="text-3xl font-black text-emerald-600"><?php echo $paid_count; ?></p>
			</div>
			<div class="glass-card p-5">
				<p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mb-2">Pending Staff</p>
				<p class="text-3xl font-black text-rose-600"><?php echo $pending_count; ?></p>
			</div>
			<div class="glass-card p-5">
				<p class="text-[10px] uppercase font-black text-slate-400 tracking-widest mb-2">Total Paid Salary</p>
				<p class="text-3xl font-black text-slate-900">LKR <?php echo number_format($total_paid_salary, 2); ?></p>
			</div>
		</div>

		<div class="glass-card p-6 mb-6">
			<form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end" id="filterForm">
				<div class="md:col-span-3">
					<label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Search Name / Contact</label>
					<input type="text" name="staff_search" id="searchInput" value="<?php echo htmlspecialchars($staff_search); ?>" placeholder="Type to search..." class="w-full input-glass" onkeyup="filterSalaryTable(this.value)">
				</div>
				<div class="md:col-span-2">
					<label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Month</label>
					<select name="salary_month" class="w-full input-glass" onchange="document.getElementById('filterForm').submit()">
						<?php for($sm=1; $sm<=12; $sm++): ?>
							<option value="<?php echo $sm; ?>" <?php echo $salary_month === $sm ? 'selected' : ''; ?>>
								<?php echo date('F', mktime(0, 0, 0, $sm, 1)); ?>
							</option>
						<?php endfor; ?>
					</select>
				</div>
				<div class="md:col-span-2">
					<label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Year</label>
					<select name="salary_year" class="w-full input-glass" onchange="document.getElementById('filterForm').submit()">
						<?php for($sy=date('Y'); $sy>=2024; $sy--): ?>
							<option value="<?php echo $sy; ?>" <?php echo $salary_year === $sy ? 'selected' : ''; ?>><?php echo $sy; ?></option>
						<?php endfor; ?>
					</select>
				</div>
				<div class="md:col-span-2">
					<label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Payment Status</label>
					<select name="salary_status" class="w-full input-glass" onchange="document.getElementById('filterForm').submit()">
						<option value="all" <?php echo $salary_status === 'all' ? 'selected' : ''; ?>>All</option>
						<option value="paid" <?php echo $salary_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
						<option value="nonpaid" <?php echo $salary_status === 'nonpaid' ? 'selected' : ''; ?>>Non Paid</option>
					</select>
				</div>
				<div class="md:col-span-3 flex gap-2">
					<a href="salary.php" class="flex-1 h-[46px] rounded-xl bg-slate-100 text-slate-700 text-[10px] font-black uppercase tracking-widest hover:bg-slate-200 flex items-center justify-center">Reset</a>
					<button type="button" onclick="openModal(document.getElementById('addStaffModal'))" class="flex-1 h-[46px] rounded-xl bg-indigo-600 text-white text-[10px] font-black uppercase tracking-widest hover:bg-indigo-700 flex items-center justify-center shadow-md">
                        <i class="fa-solid fa-plus mr-1"></i> Staff
                    </button>
				</div>
			</form>
		</div>

		<div class="glass-card p-0 overflow-hidden">
			<div class="overflow-x-auto">
				<table class="min-w-full text-sm">
					<thead class="bg-slate-900 text-white uppercase text-[10px] tracking-widest">
						<tr>
							<th class="text-left px-4 py-3">Name</th>
							<th class="text-left px-4 py-3">Contact Number</th>
							<th class="text-left px-4 py-3">Deliveries</th>
							<th class="text-left px-4 py-3">Salary (Monthly)</th>
							<th class="text-left px-4 py-3">Paid Date</th>
							<th class="text-left px-4 py-3">Payment Status</th>
							<th class="text-left px-4 py-3">Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($staff_salary_rows as $row): ?>
							<tr class="border-b border-slate-100 hover:bg-slate-50/60">
								<td class="px-4 py-3 font-bold text-slate-900"><?php echo htmlspecialchars($row['full_name']); ?></td>
								<td class="px-4 py-3 font-semibold text-slate-700"><?php echo htmlspecialchars($row['contact_number'] ?: '-'); ?></td>
								<td class="px-4 py-3 font-semibold text-slate-700"><?php echo (int)$row['delivery_count']; ?></td>
								<td class="px-4 py-3 font-black text-slate-900 group">
									<div class="flex items-center space-x-2 bg-slate-50/50 hover:bg-white border-b-2 border-transparent hover:border-slate-300 focus-within:border-indigo-500 rounded-t-lg px-2 transition-colors">
										<span class="text-xs text-slate-400">LKR</span>
										<input type="number" 
											class="w-24 bg-transparent outline-none py-1 text-slate-900 font-black" 
											value="<?php echo htmlspecialchars($row['salary_amount']); ?>"
											onchange="updateSalary(<?php echo (int)$row['id']; ?>, this.value, this)"
										>
										<i class="fa-solid fa-pen text-[10px] text-slate-300 group-focus-within:text-indigo-500 transition-colors"></i>
									</div>
								</td>
								<td class="px-4 py-3 font-semibold text-slate-700"><?php echo $row['payment_date'] ? htmlspecialchars($row['payment_date']) : 'Pending'; ?></td>
								<td class="px-4 py-3">
									<?php if ($row['status'] === 'paid'): ?>
										<span class="inline-flex items-center px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest bg-emerald-100 text-emerald-700 border border-emerald-200">Paid</span>
									<?php else: ?>
										<span class="inline-flex items-center px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest bg-rose-100 text-rose-700 border border-rose-200">Non Paid</span>
									<?php endif; ?>
								</td>
								<td class="px-4 py-3">
									<div class="flex flex-wrap gap-2">
										<?php if ($row['status'] !== 'paid'): ?>
											<button type="button"
												class="open-pay-modal px-3 py-1.5 rounded-lg bg-rose-600 text-white text-[10px] font-black uppercase tracking-widest hover:bg-rose-700"
												data-employee-id="<?php echo (int)$row['id']; ?>"
												data-employee-name="<?php echo htmlspecialchars($row['full_name']); ?>"
												data-salary-amount="<?php echo (float)$row['salary_amount']; ?>">
												Pay
											</button>
										<?php else: ?>
											<span class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-[10px] font-black uppercase tracking-widest">Paid</span>
										<?php endif; ?>

										<?php if (!empty($row['payment_id'])): ?>
											<button type="button"
												class="open-status-modal px-3 py-1.5 rounded-lg bg-amber-500 text-white text-[10px] font-black uppercase tracking-widest hover:bg-amber-600"
												data-payment-id="<?php echo (int)$row['payment_id']; ?>"
												data-current-status="<?php echo htmlspecialchars($row['status']); ?>"
												data-payment-date="<?php echo htmlspecialchars($row['payment_date'] ?: date('Y-m-d')); ?>">
												Edit
											</button>

											<form method="POST" onsubmit="return confirm('Delete this payment record?');" class="inline">
												<input type="hidden" name="salary_action" value="delete_payment">
												<input type="hidden" name="payment_id" value="<?php echo (int)$row['payment_id']; ?>">
												<button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-700 text-white text-[10px] font-black uppercase tracking-widest hover:bg-slate-900">Delete</button>
											</form>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
						<?php if (empty($staff_salary_rows)): ?>
							<tr>
								<td colspan="7" class="text-center py-10 text-slate-400 font-semibold">No staff records found for the selected filters.</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div id="paySalaryModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 px-4">
			<div class="w-full max-w-md glass-card p-6">
				<div class="flex items-center justify-between mb-5">
					<h4 class="text-lg font-black text-slate-900 font-['Outfit']">Pay Staff Salary</h4>
					<button type="button" class="close-modal text-slate-500 hover:text-slate-800"><i class="fa-solid fa-xmark text-lg"></i></button>
				</div>
				<form method="POST" class="space-y-4">
					<input type="hidden" name="salary_action" value="pay_salary">
					<input type="hidden" name="employee_id" id="payEmployeeId" value="">

					<div>
						<label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Employee</label>
						<input type="text" id="payEmployeeName" class="w-full input-glass" readonly>
					</div>
					<div>
						<label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Salary Amount</label>
						<input type="number" min="0" step="0.01" name="salary_amount" id="paySalaryAmount" class="w-full input-glass" required>
					</div>
					<div class="grid grid-cols-2 gap-3">
						<div>
							<label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Month</label>
							<select name="salary_month" id="paySalaryMonth" class="w-full input-glass">
								<?php for($pm=1; $pm<=12; $pm++): ?>
									<option value="<?php echo $pm; ?>" <?php echo $salary_month === $pm ? 'selected' : ''; ?>>
										<?php echo date('F', mktime(0, 0, 0, $pm, 1)); ?>
									</option>
								<?php endfor; ?>
							</select>
						</div>
						<div>
							<label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Year</label>
							<select name="salary_year" id="paySalaryYear" class="w-full input-glass">
								<?php for($py=date('Y'); $py>=2024; $py--): ?>
									<option value="<?php echo $py; ?>" <?php echo $salary_year === $py ? 'selected' : ''; ?>><?php echo $py; ?></option>
								<?php endfor; ?>
							</select>
						</div>
					</div>
					<div>
						<label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Payment Date</label>
						<input type="date" name="payment_date" class="w-full input-glass" value="<?php echo date('Y-m-d'); ?>" required>
					</div>
					<button type="submit" class="w-full h-11 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-[11px] font-black uppercase tracking-widest">Pay Now</button>
				</form>
			</div>
		</div>

		<div id="editStatusModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 px-4">
			<div class="w-full max-w-md glass-card p-6">
				<div class="flex items-center justify-between mb-5">
					<h4 class="text-lg font-black text-slate-900 font-['Outfit']">Edit Payment Status</h4>
					<button type="button" class="close-modal text-slate-500 hover:text-slate-800"><i class="fa-solid fa-xmark text-lg"></i></button>
				</div>
				<form method="POST" class="space-y-4">
					<input type="hidden" name="salary_action" value="update_payment_status">
					<input type="hidden" name="payment_id" id="editPaymentId" value="">

					<div>
						<label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Status</label>
						<select name="new_status" id="editNewStatus" class="w-full input-glass" required>
							<option value="paid">Paid</option>
							<option value="nonpaid">Non Paid</option>
						</select>
					</div>
					<div>
						<label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Payment Date</label>
						<input type="date" name="payment_date" id="editPaymentDate" class="w-full input-glass" value="<?php echo date('Y-m-d'); ?>">
					</div>
					<button type="submit" class="w-full h-11 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-[11px] font-black uppercase tracking-widest">Update Status</button>
				</form>
			</div>
		</div>
		<div id="addStaffModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 px-4">
			<div class="w-full max-w-md glass-card p-6 border border-white/40 shadow-2xl">
				<div class="flex items-center justify-between mb-5">
					<h4 class="text-lg font-black text-slate-900 font-['Outfit']">Add New Staff Member</h4>
					<button type="button" class="close-modal text-slate-500 hover:text-slate-800"><i class="fa-solid fa-xmark text-lg"></i></button>
				</div>
				<form method="POST" class="space-y-4">
					<input type="hidden" name="salary_action" value="add_staff">
					<div>
						<label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Full Name</label>
						<input type="text" name="full_name" class="w-full input-glass" required placeholder="John Doe">
					</div>
					<div>
						<label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Contact Number</label>
						<input type="text" name="contact_number" class="w-full input-glass" placeholder="07XXXXXXXX">
					</div>
					<div>
						<label class="text-[10px] uppercase font-black text-slate-400 mb-2 ml-1 block tracking-widest">Initial Monthly Salary (LKR)</label>
						<input type="number" min="0" step="0.01" name="monthly_salary" class="w-full input-glass" value="0">
					</div>
					<div class="pt-2">
                        <button type="submit" class="w-full h-11 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-[11px] font-black uppercase tracking-widest transition-colors shadow-md text-center">Save Staff</button>
                    </div>
				</form>
			</div>
		</div>

	</main>

	<script>
        function updateSalary(employeeId, newSalary, inputElement) {
            newSalary = parseFloat(newSalary) || 0;
            const originalColor = inputElement.style.color;
            inputElement.style.color = '#94a3b8'; // loading state

            fetch('salary.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    'salary_action': 'update_salary',
                    'employee_id': employeeId,
                    'monthly_salary': newSalary
                })
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    inputElement.style.color = '#10b981'; // success green
                    setTimeout(() => inputElement.style.color = originalColor || '', 1000);
                } else {
                    alert('Error saving salary');
                    inputElement.style.color = '#ef4444'; // error red
                }
            })
            .catch(err => {
                console.error(err);
                inputElement.style.color = '#ef4444'; 
            });
        }

		function filterSalaryTable(query) {
			query = query.toLowerCase();
			const rows = document.querySelectorAll('tbody tr');
			rows.forEach(row => {
				if (row.cells.length < 2) return; // Skip "No records found" row easily
				const name = row.cells[0].innerText.toLowerCase();
				const contact = row.cells[1].innerText.toLowerCase();
				if (name.includes(query) || contact.includes(query)) {
					row.style.display = '';
				} else {
					row.style.display = 'none';
				}
			});
		}

		const paySalaryModal = document.getElementById('paySalaryModal');
		const editStatusModal = document.getElementById('editStatusModal');
		const payEmployeeId = document.getElementById('payEmployeeId');
		const payEmployeeName = document.getElementById('payEmployeeName');
		const paySalaryAmount = document.getElementById('paySalaryAmount');
		const paySalaryMonth = document.getElementById('paySalaryMonth');
		const paySalaryYear = document.getElementById('paySalaryYear');
		const editPaymentId = document.getElementById('editPaymentId');
		const editNewStatus = document.getElementById('editNewStatus');
		const editPaymentDate = document.getElementById('editPaymentDate');

		const openPayButtons = document.querySelectorAll('.open-pay-modal');
		const openStatusButtons = document.querySelectorAll('.open-status-modal');
		const closeButtons = document.querySelectorAll('.close-modal');

		function openModal(modal) {
			if (!modal) return;
			modal.classList.remove('hidden');
			modal.classList.add('flex');
		}

		function closeModal(modal) {
			if (!modal) return;
			modal.classList.add('hidden');
			modal.classList.remove('flex');
		}

		openPayButtons.forEach((btn) => {
			btn.addEventListener('click', () => {
				payEmployeeId.value = btn.dataset.employeeId;
				payEmployeeName.value = btn.dataset.employeeName;
				paySalaryAmount.value = Number(btn.dataset.salaryAmount || 0).toFixed(2);
				paySalaryMonth.value = '<?php echo (int)$salary_month; ?>';
				paySalaryYear.value = '<?php echo (int)$salary_year; ?>';
				openModal(paySalaryModal);
			});
		});

		openStatusButtons.forEach((btn) => {
			btn.addEventListener('click', () => {
				editPaymentId.value = btn.dataset.paymentId;
				editNewStatus.value = btn.dataset.currentStatus;
				editPaymentDate.value = btn.dataset.paymentDate || '<?php echo date('Y-m-d'); ?>';
				openModal(editStatusModal);
			});
		});

		closeButtons.forEach((btn) => {
			btn.addEventListener('click', () => {
				closeModal(paySalaryModal);
				closeModal(editStatusModal);
                closeModal(document.getElementById('addStaffModal'));
			});
		});

		[paySalaryModal, editStatusModal, document.getElementById('addStaffModal')].forEach((modal) => {
			if (!modal) return;
			modal.addEventListener('click', (e) => {
				if (e.target === modal) {
					closeModal(modal);
				}
			});
		});
	</script>
</body>
</html>
