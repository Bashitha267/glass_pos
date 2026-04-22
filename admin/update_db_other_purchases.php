<?php
require_once '../config.php';
try {
    // Add columns to other_purchase_items if they don't exist
    $pdo->exec("ALTER TABLE other_purchase_items ADD COLUMN IF NOT EXISTS pallets INT DEFAULT 0");
    $pdo->exec("ALTER TABLE other_purchase_items ADD COLUMN IF NOT EXISTS qty_per_pallet INT DEFAULT 0");
    $pdo->exec("ALTER TABLE other_purchase_items ADD COLUMN IF NOT EXISTS sold_qty INT DEFAULT 0");
    echo "Columns added successfully";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
