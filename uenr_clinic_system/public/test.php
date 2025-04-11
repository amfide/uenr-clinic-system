<?php
require_once __DIR__ . '/../../config/config.php';

echo "<h1>System Check</h1>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Database Connection: ";

try {
    $pdo->query("SELECT 1");
    echo "✅ Working";
} catch (PDOException $e) {
    echo "❌ Failed: " . $e->getMessage();
}

echo "<h2>Loaded Extensions</h2>";
print_r(get_loaded_extensions());

echo "<h2>Session Status</h2>";
print_r(session_status());
?>