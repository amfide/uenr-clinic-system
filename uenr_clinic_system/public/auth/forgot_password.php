<?php
require_once 'config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    $user = getCurrentUser();
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token. Please try again.";
    } else {
        // Check if email exists
        $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE email = ? AND active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Generate password reset token
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry
            
            // Store token in database
            $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?");
            $stmt->execute([$token, $expiry, $user['id']]);
            
            // Send password reset email (in a real system)
            $resetLink = "https://{$_SERVER['HTTP_HOST']}/reset_password.php?token=$token";
            
            // In a production system, you would send an email here
            // mail($email, "Password Reset Request", "Click here to reset your password: $resetLink");
            
            // For demo purposes, we'll just show the link
            $message = "Password reset link has been sent to your email. For demo purposes: <a href='$resetLink'>$resetLink</a>";
            
            // Log the request
            logActivity($user['id'], 'Requested password reset');
        } else {
            $error = "No account found with that email address.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - UENR Clinic</title>
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
                <h3 class="mt-3">Password Recovery</h3>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo $message; ?></div>
                <div class="text-center mt-3">
                    <a href="login.php" class="btn btn-primary">Back to Login</a>
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email" required autocomplete="email">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Reset Password</button>
                    <div class="text-center mt-3">
                        <a href="login.php">Back to Login</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>