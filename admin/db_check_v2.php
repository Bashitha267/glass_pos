<?php
require_once 'c:/xampp/htdocs/glass/config.php';
$stmt = $pdo->query("DESCRIBE delivery_items");
echo "delivery_items Columns:\n";
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "- " . $row['Field'] . "\n";
}
