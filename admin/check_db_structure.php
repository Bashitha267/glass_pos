<?php
require_once '../config.php';
$stmt = $pdo->query('DESCRIBE delivery_payments');
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
