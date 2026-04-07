<?php
require_once '../config.php';
try {
    $pdo->exec("ALTER TABLE other_purchase_items MODIFY COLUMN qty DOUBLE DEFAULT 0");
    $pdo->exec("ALTER TABLE other_purchase_items ADD COLUMN category ENUM('Glass', 'Other') DEFAULT 'Other' AFTER item_name");
    echo "Database updated successfully";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column already exists";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
?>
