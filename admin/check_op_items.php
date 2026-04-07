<?php
require_once '../config.php';
try {
    $stmt = $pdo->query('DESC other_purchase_items');
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
} catch (Exception $e) {
    echo $e->getMessage();
}
?>
