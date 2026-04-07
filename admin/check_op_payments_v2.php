<?php
require_once '../config.php';
$cols = $pdo->query('DESC other_purchase_payments')->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";
?>
