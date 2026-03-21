<?php
require_once '../config.php';
$stmt = $pdo->query('SHOW COLUMNS FROM delivery_payments');
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
