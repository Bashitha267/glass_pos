<?php
$_GET['action'] = 'search_brand_stock';
$_GET['term'] = 'a';
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
require_once '../config.php';
// bypass auth check in pos.php
function checkAuth() {}
function isAdmin() { return true; }
$user_id = 1;
$action = 'search_brand_stock';

// Include only the AJAX block
    $term = $_GET['term'] ?? '';
    $termParam = '%' . $term . '%';
    
    $query = "
        SELECT si.*, 
               COALESCE(ci.pallets, opi.pallets, 0) as pallets,
               COALESCE(ci.country, 'Direct') as country,
               COALESCE(c.container_number, op.purchase_number) as container_number
        FROM shop_inventory si
        LEFT JOIN container_items ci ON si.item_id = ci.id AND si.item_source = 'container'
        LEFT JOIN containers c ON ci.container_id = c.id
        LEFT JOIN other_purchase_items opi ON si.item_id = opi.id AND si.item_source = 'other'
        LEFT JOIN other_purchases op ON opi.purchase_id = op.id
        WHERE (si.full_sheets_qty > 0 OR si.partial_sqft_qty > 0)
    ";
    
    if (!empty($term)) {
        $query .= " AND (si.brand_name LIKE ? OR si.category LIKE ?)";
    }
    
    $query .= " ORDER BY si.id DESC LIMIT 10";
    $stmt = $pdo->prepare($query);
    
    $params = [];
    if (!empty($term)) {
        $params[] = $termParam;
        $params[] = $termParam;
    }
    
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
