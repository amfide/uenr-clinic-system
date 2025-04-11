<?php
// Enable error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Load .env file
if (file_exists(__DIR__ . 'uenr_clinic_system/.env')) {
    $env = parse_ini_file(__DIR__ . '/../.env');
    foreach ($env as $key => $value) {
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
} else {
    die('.env file not found. Please create one based on .env.example');
}

// Database configuration using .env
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'uenr_clinic');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASSWORD') ?: '');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');


// Database Configuration
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');  // Change to your MySQL username
define('DB_PASS', ''); // Change to your MySQL password
define('DB_NAME', 'uenr_clinic');      // Database name

// Security Configuration
define('MAX_LOGIN_ATTEMPTS', 25);
define('LOGIN_LOCKOUT_TIME', 15 * 60); // 15 minutes in seconds
define('SESSION_TIMEOUT', 30 * 60);    // 30 minutes in seconds
define('CSRF_TOKEN_EXPIRY', 60 * 60);  // 1 hour in seconds

// Application Paths
define('BASE_URL', 'http://localhost/uenr_clinic_system'); // Change to your base URL
define('APP_ROOT', dirname(__DIR__));

// Establish database connection
try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", 
        DB_USER, 
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch(PDOException $e) {
    die("ERROR: Could not connect to database. " . $e->getMessage());
}

// Add these functions to your existing config.php

/**
 * Check if user has exceeded max login attempts
 */
function checkLoginAttempts($username) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts 
                          WHERE username = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute([$username, LOGIN_LOCKOUT_TIME]);
    $count = $stmt->fetchColumn();
    
    return ($count < MAX_LOGIN_ATTEMPTS);
}



/**
 * Record a login attempt
 */
function recordLoginAttempt($username, $success) {
    global $pdo;
    
    $stmt = $pdo->prepare("INSERT INTO login_attempts 
                          (username, ip_address, success) 
                          VALUES (?, ?, ?)");
    $stmt->execute([
        $username,
        $_SERVER['REMOTE_ADDR'],
        $success ? 1 : 0
    ]);
}

/**
 * Create the login_attempts table if missing
 */
function createLoginAttemptsTable() {
    global $pdo;
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        success TINYINT(1) NOT NULL,
        attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

// Call this when config loads
createLoginAttemptsTable();

// Secure session configuration
session_set_cookie_params([
    'lifetime' => SESSION_TIMEOUT,
    'path' => '/',
    'domain' => '', // Set to your domain in production
    'secure' => isset($_SERVER['HTTPS']), // Enable in production with HTTPS
    'httponly' => true,
    'samesite' => 'Strict'
]);

session_start();

// Regenerate session ID to prevent fixation
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
}



if (isset($_SESSION['user_agent'])) {
    if ($_SESSION['user_agent'] != $_SERVER['HTTP_USER_AGENT']) {
        session_unset();
        session_destroy();
        die("Security violation detected. Please login again.");
    }
}

// Utility Functions
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['last_activity']);
}

function checkAuth() {
    if (!isLoggedIn()) {
        $_SESSION['login_redirect'] = $_SERVER['REQUEST_URI'];
        header("Location: ".BASE_URL."/public/auth/login.php");
        exit();
    }
    $_SESSION['last_activity'] = time(); // Update last activity time
}

function getCurrentUser() {
    global $pdo;
    if (isLoggedIn()) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    }
    return null;
}

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token']) || time() > $_SESSION['csrf_token_expiry']) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_expiry'] = time() + CSRF_TOKEN_EXPIRY;
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return !empty($_SESSION['csrf_token']) && 
           !empty($token) && 
           hash_equals($_SESSION['csrf_token'], $token) &&
           time() < $_SESSION['csrf_token_expiry'];
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function logActivity($userId, $action) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, ip_address, user_agent) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $action, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
}

function showAlert($type, $message) {
    echo '<div class="alert alert-'.$type.' alert-dismissible fade show" role="alert">
            '.htmlspecialchars($message).'
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>';
}
?>
