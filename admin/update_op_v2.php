<?php
require_once '../config.php';
try {
    // 1. Items update
    // Check if total_sqft exists
    $cols = $pdo->query("DESC other_purchase_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('square_feet', $cols)) {
        $pdo->exec("ALTER TABLE other_purchase_items ADD COLUMN square_feet DOUBLE DEFAULT 0 AFTER qty");
    }
    
    // 2. Payments update
    $cols = $pdo->query("DESC other_purchase_payments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('bank_name', $cols)) {
        $pdo->exec("ALTER TABLE other_purchase_payments ADD COLUMN bank_name VARCHAR(255) AFTER amount");
    }
    if (!in_array('cheque_number', $cols)) {
        $pdo->exec("ALTER TABLE other_purchase_payments ADD COLUMN cheque_number VARCHAR(100) AFTER bank_name");
    }
    if (!in_array('payer_name', $cols)) {
        $pdo->exec("ALTER TABLE other_purchase_payments ADD COLUMN payer_name VARCHAR(255) AFTER cheque_number");
    }
    
    echo "Database updated successfully";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
