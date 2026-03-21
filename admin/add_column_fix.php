<?php
require_once '../config.php';
try {
    $pdo->exec("ALTER TABLE delivery_payments ADD COLUMN cheque_payer_name VARCHAR(255) DEFAULT NULL AFTER cheque_number");
    echo "COLUMN ADDED SUCCESSFULLY";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage();
}
