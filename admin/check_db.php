<?php
require_once '../config.php';
function desc($pdo, $tbl) {
    try {
        $stmt = $pdo->query("DESCRIBE $tbl");
        file_put_contents('db_out.txt', "$tbl: " . implode(', ', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field')) . "\n", FILE_APPEND);
    } catch(Exception $e) { file_put_contents('db_out.txt', "$tbl Error: " . $e->getMessage() . "\n", FILE_APPEND); }
}
file_put_contents('db_out.txt', '');
desc($pdo, 'delivery_items');
desc($pdo, 'delivery_customers');
desc($pdo, 'delivery_proof_photos');
