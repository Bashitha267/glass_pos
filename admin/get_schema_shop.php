<?php
require_once '../config.php';
$s = $pdo->query('SHOW COLUMNS FROM shop_inventory')->fetchAll(PDO::FETCH_ASSOC);
print_r($s);
