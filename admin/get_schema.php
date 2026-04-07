<?php
require_once __DIR__ . '/../config.php';
$stmt = $pdo->query("DESC employee_salary_settings");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
?>
