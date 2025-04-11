<?php
require_once __DIR__.'/../../config/config.php';

$user = [
    'username' => 'dr_amoah',
    'password' => 'Doctor@123', // Will be hashed
    'full_name' => 'Dr. Kwame Amoah',
    'role' => 'doctor',
    'email' => 'k.amoah@uenrclinic.edu.gh',
    'phone' => '+233244556677'
];

try {
    $hashedPassword = password_hash($user['password'], PASSWORD_BCRYPT);
    
    $stmt = $pdo->prepare("INSERT INTO users 
                          (username, password, full_name, role, email, phone) 
                          VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $user['username'],
        $hashedPassword,
        $user['full_name'],
        $user['role'],
        $user['email'],
        $user['phone']
    ]);
    
    echo "✅ User created! ID: " . $pdo->lastInsertId();
} catch(PDOException $e) {
    die("❌ Error: " . $e->getMessage());
}
?>