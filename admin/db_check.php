<?php
require_once 'c:/xampp/htdocs/glass/config.php';
$tables = ['delivery_items', 'container_items', 'products', 'brands'];
foreach($tables as $t) {
    try {
        $stmt = $pdo->query("DESCRIBE $t");
        echo "\nTable: $t\n";
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "  " . $row['Field'] . "\n";
        }
    } catch(Exception $e) {
        echo "\nTable $t not found or error: " . $e->getMessage() . "\n";
    }
}
