<?php
require_once 'config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    $user = getCurrentUser();
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

$error = '';
$success = '';

// Check for token
if (!isset($_GET['token'])) {
    $error = "Invalid password reset link.";
} else {
    $token = $_GET['token'];
    
    // Check token validity
    $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_token_expiry > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $error = "Invalid or expired password reset link.";
    } elseif ($_SERVER['REQUEST_METHOD'] == 'POST') {
        // Validate CSRF token
        if (!validateCSRFToken($_POST['csrf_token'])) {
            $error = "Invalid security token. Please try again.";
        } else {
            $password = $_POST['password'];
            $confirmPassword = $_POST['confirm_password'];
            
            // Validate passwords
            if ($password !== $confirmPassword) {
                $error = "Passwords do not match.";
            } elseif (strlen($password) < 8) {
                $error = "Password must be at least 8 characters long.";
            } else {
                // Update password
                $hashedPassword = hashPassword($password);
                $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
                $stmt->execute([$hashedPassword, $user['id']]);
                
                // Log the password change
                logActivity($user['id'], 'Password reset successfully');
                
                $success = "Password has been reset successfully. You can now <a href='login.php'>login</a> with your new password.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - UENR Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-container {
            max-width: 400px;
            margin: 0 auto;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .logo {
            text-align: center;
            margin-bottom: 20px;
        }
        .logo img {
            max-width: 150px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="logo">
                <img src="images/uenr_logo.png" alt="UENR Clinic Logo">
                <h3 class="mt-3">Reset Password</h3>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <div class="text-center mt-3">
                    <a href="forgot_password.php" class="btn btn-primary">Request New Reset Link</a>
                </div>
            <?php elseif ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="mb-3">
                        <label for="password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="password" name="password" required autocomplete="new-password">
                        <small class="text-muted">Minimum 8 characters</small>
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Reset Password</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>