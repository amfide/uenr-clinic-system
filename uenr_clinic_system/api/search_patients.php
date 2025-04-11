<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/uenr_clinic_system/includes/config.php';

header("Content-Type: application/json");

try {
    $query = $_GET['q'] ?? '';
    
    if (empty($query)) {
        echo json_encode([]);
        exit();
    }

    // Using named parameters correctly
    $stmt = $pdo->prepare("SELECT id, patient_id, first_name, last_name 
                          FROM patients 
                          WHERE patient_id LIKE :query 
                          OR first_name LIKE :query 
                          OR last_name LIKE :query
                          LIMIT 10");
    
    $searchTerm = "%$query%";
    
    // Bind the same parameter multiple times
    $stmt->bindValue(':query', $searchTerm);
    
    // Alternative: Execute with named parameters
    // $stmt->execute([':query' => $searchTerm]);
    
    if (!$stmt->execute()) {
        throw new Exception("Query execution failed");
    }

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}