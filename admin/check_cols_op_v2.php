<?php
require_once '../config.php';
$stmt = $pdo->query('DESC other_purchase_items');
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
file_put_contents('cols_op.txt', print_r($cols, true));
echo "Saved to cols_op.txt";
?>
