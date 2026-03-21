<?php
require_once '../config.php';
$stmt = $pdo->query('SHOW COLUMNS FROM delivery_payments');
foreach($stmt->fetchAll() as $row) echo $row['Field'] . " (" . $row['Type'] . ")\n";
