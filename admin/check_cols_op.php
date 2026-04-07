<?php
require_once '../config.php';
$stmt = $pdo->query('DESC other_purchase_items');
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'] . " (" . $row['Type'] . ") - " . $row['Null'] . " - " . $row['Key'] . " - " . $row['Default'] . "\n";
}
?>
