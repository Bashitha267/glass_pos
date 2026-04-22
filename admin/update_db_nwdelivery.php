<?php
require_once '../config.php';
try {
    $pdo->exec("ALTER TABLE delivery_payments ADD COLUMN cheque_bank VARCHAR(255) DEFAULT NULL");
    echo "Column cheque_bank added successfully.";
} catch (Exception $e) {
    echo "Error or column already exists: " . $e->getMessage();
}
?>
