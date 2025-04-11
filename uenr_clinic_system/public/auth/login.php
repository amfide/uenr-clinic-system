<?php
require_once __DIR__ . '/../../config/config.php';

// At the top of login.php, after require_once
if (!function_exists('checkLoginAttempts')) {
    die("Security functions not loaded. Check config.php");
}

// Your existing login code...

// Redirect if already logged in


// Check for brute force attempts
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['username'])) {
    $username = trim($_POST['username']);
    
    if (!checkLoginAttempts($username)) {
        $error = "Too many failed login attempts. Please try again later.";
        recordLoginAttempt($username, false);
    } else {
        $password = trim($_POST['password']);
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Check if password needs rehashing
            if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => 12])) {
                $newHash = hashPassword($password);
                $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $updateStmt->execute([$newHash, $user['id']]);
            }
            
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);
            
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['last_activity'] = time();
            
            // Update last login
            $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW(), last_ip = ? WHERE id = ?");
            $updateStmt->execute([$_SERVER['REMOTE_ADDR'], $user['id']]);
            
            // Log successful login
            logActivity($user['id'], 'User logged in');
            recordLoginAttempt($username, true);
            
            // Redirect to intended page or dashboard
            $redirect = isset($_SESSION['login_redirect']) ? $_SESSION['login_redirect'] : "{$user['role']}_dashboard.php";
            unset($_SESSION['login_redirect']);
            header("Location: $redirect");
            exit();
        } else {
            $error = "Invalid username or password";
            recordLoginAttempt($username, false);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | UENR Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #28a745;
            --info: #17a2b8;
            --warning: #ffc107;
            --danger: #dc3545;
            --light: #f8f9fa;
            --dark: #343a40;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            height: 100vh;
            display: flex;
            align-items: center;
            background: linear-gradient(135deg, rgba(44,62,80,0.9) 0%, rgba(52,152,219,0.8) 100%);
        }
        
        .login-container {
            max-width: 420px;
            width: 100%;
            margin: 0 auto;
            padding: 2.5rem;
            background: white;
            border-radius: 0.75rem;
            box-shadow: 0 1rem 3rem rgba(0,0,0,0.2);
            transform: translateY(-5%);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .login-header img {
            height: 80px;
            margin-bottom: 1rem;
        }
        
        .login-header h2 {
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .login-header p {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .form-control {
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            border: 1px solid #e0e0e0;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 0.25rem rgba(52,152,219,0.25);
        }
        
        .btn-login {
            background-color: var(--primary);
            border: none;
            padding: 0.75rem;
            font-weight: 500;
            border-radius: 0.5rem;
            transition: all 0.3s;
        }
        
        .btn-login:hover {
            background-color: #1a252f;
            transform: translateY(-2px);
        }
        
        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .alert {
            border-radius: 0.5rem;
        }
        
        .forgot-password {
            color: var(--secondary);
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .forgot-password:hover {
            color: #1a5276;
            text-decoration: underline;
        }
        
        .input-group-text {
            background-color: white;
            border-right: none;
        }
        
        .password-group .form-control {
            border-left: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="login-header">
                <img src="../assets/images/uenr_logo.png" alt="UENR Clinic Logo">
                <h2>UENR HEALTH DIRECTORATE</h2>
                <p>HEALTH MANAGEMENT SYSTEM</p>
            </div>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                        <input type="text" class="form-control" id="username" name="username" 
                               placeholder="Enter your username" required autocomplete="username">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group password-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Enter your password" required autocomplete="current-password">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                            <i class="bi bi-eye-fill"></i>
                        </button>
                    </div>
                </div>
                
                <div class="mb-3 d-flex justify-content-between align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="remember" name="remember">
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>
                    <a href="forgot_password.php" class="forgot-password">Forgot password?</a>
                </div>
                
                <button type="submit" class="btn btn-primary btn-login w-100 mb-3">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Login
                </button>
                
                <div class="text-center mt-3 text-muted">
                    <small>Secure access for authorized personnel only</small>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('bi-eye-fill');
                icon.classList.add('bi-eye-slash-fill');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('bi-eye-slash-fill');
                icon.classList.add('bi-eye-fill');
            }
        });
        
        // Form validation
        (function() {
            'use strict';
            const forms = document.querySelectorAll('.needs-validation');
            
            Array.from(forms).forEach(function(form) {
                form.addEventListener('submit', function(event) {
                    if (!form.checkValidity()) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                    form.classList.add('was-validated');
                }, false);
            });
        })();
    </script>
</body>
</html>