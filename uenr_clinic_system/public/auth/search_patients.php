<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

header('Content-Type: application/json');

if (!isset($_GET['term'])) {
    echo json_encode([]);
    exit();
}

$term = "%{$_GET['term']}%";
$stmt = $pdo->prepare("SELECT id, patient_id, first_name, last_name, phone 
                      FROM patients 
                      WHERE patient_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR phone LIKE ?
                      LIMIT 10");
$stmt->execute([$term, $term, $term, $term]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($results);