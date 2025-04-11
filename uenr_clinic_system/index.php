
<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if system is properly installed
if (!file_exists('config/config.php')) {
    die("System not properly installed. Missing config file.");
}

try {
    // Test database connection
    require_once 'config/config.php';
    $testConnection = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $testConnection = null; // Close connection
    
    // Redirect to login if all checks pass
    header("Location: public/auth/login.php");
    exit();
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UENR Clinic Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-image: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }
        .welcome-container {
            max-width: 600px;
            text-align: center;
            padding: 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .logo {
            max-width: 150px;
            margin-bottom: 20px;
        }
        .btn-enter {
            padding: 10px 30px;
            font-size: 1.1rem;
            margin-top: 20px;
        }
        .spinner {
            display: none;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <div class="welcome-container">
        <img src="public/assets/images/uenr_logo.png" alt="UENR Clinic Logo" class="logo">
        <h2>UENR Clinic Management System</h2>
        <p class="lead">Welcome to the University of Energy and Natural Resources Clinic Management Portal</p>
        
        <button id="enterBtn" class="btn btn-primary btn-enter">
            Enter System
            <span id="spinner" class="spinner-border spinner-border-sm spinner" role="status"></span>
        </button>
        
        <div class="mt-4 text-muted small">
            <p>Secure access for authorized personnel only</p>
        </div>
    </div>

    <script>
        document.getElementById('enterBtn').addEventListener('click', function() {
            // Show spinner
            document.getElementById('spinner').style.display = 'inline-block';
            // Redirect after a brief delay for UX
            setTimeout(function() {
                window.location.href = 'public/auth/login.php';
            }, 500);
        });
        
        // Auto-redirect after 5 seconds if user doesn't click
        setTimeout(function() {
            window.location.href = 'public/auth/login.php';
        }, 5000);
    </script>
</body>
</html>