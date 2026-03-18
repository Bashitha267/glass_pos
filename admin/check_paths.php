<?php
require_once '../config.php';
$s = $pdo->query('SELECT photo_path FROM delivery_proof_photos LIMIT 5');
print_r($s->fetchAll(PDO::FETCH_ASSOC));
$s = $pdo->query('SELECT bill_image FROM delivery_items WHERE bill_image IS NOT NULL LIMIT 5');
print_r($s->fetchAll(PDO::FETCH_ASSOC));
