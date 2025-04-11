<?php
require_once __DIR__.'/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required fields
    $required = ['username', 'password', 'full_name', 'role', 'email'];
    $errors = [];
    
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            $errors[] = "Field $field is required.";
        }
    }
    
    if (empty($errors)) {
        $hashedPassword = password_hash($_POST['password'], PASSWORD_BCRYPT);
        
        try {
            $stmt = $pdo->prepare("INSERT INTO users 
                                  (username, password, full_name, role, email, phone) 
                                  VALUES (?, ?, ?, ?, ?, ?)");
            $success = $stmt->execute([
                $_POST['username'],
                $hashedPassword,
                $_POST['full_name'],
                $_POST['role'],
                $_POST['email'],
                $_POST['phone'] ?? null
            ]);
            
            if ($success) {
                $_SESSION['success'] = "User registered successfully!";
                header("Location: login.php");
                exit();
            }
        } catch (PDOException $e) {
            $error = "Registration error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Registration - UENR Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .registration-container {
            max-width: 600px;
            margin: 50px auto;
            padding: 30px;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .registration-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .registration-header h2 {
            color: #0d6efd;
        }
        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        .btn-primary {
            background-color: #0d6efd;
            border-color: #0d6efd;
            padding: 10px 20px;
            font-weight: 500;
        }
        .btn-primary:hover {
            background-color: #0b5ed7;
            border-color: #0b5ed7;
        }
        .login-link {
            text-align: center;
            margin-top: 20px;
        }
        .role-icon {
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="registration-container">
            <div class="registration-header">
                <h2><i class="bi bi-person-plus"></i> User Registration</h2>
                <p class="text-muted">Create a new account for UENR Clinic System</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($_SESSION['success']); 
                    unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="username" class="form-label">Username</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person-circle"></i></span>
                            <input type="text" class="form-control" id="username" name="username" placeholder="Enter username" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password" placeholder="Enter password" required>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="full_name" class="form-label">Full Name</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-card-text"></i></span>
                        <input type="text" class="form-control" id="full_name" name="full_name" placeholder="Enter full name" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="role" class="form-label">Role</label>
                    <select class="form-select" id="role" name="role" required>
                        <option value="" disabled selected>Select user role</option>
                        <option value="admin"><i class="bi bi-person-gear role-icon"></i> Administrator</option>
                        <option value="doctor"><i class="bi bi-heart-pulse role-icon"></i> Doctor</option>
                        <option value="nurse"><i class="bi bi-heart role-icon"></i> Nurse</option>
                        <option value="lab_scientist"><i class="bi bi-droplet role-icon"></i> Lab Scientist</option>
                        <option value="records_keeper"><i class="bi bi-files role-icon"></i> Records Keeper</option>
                        <option value="pharmacist"><i class="bi bi-capsule role-icon"></i> Pharmacist</option>
                        <option value="storekeeper"><i class="bi bi-box-seam role-icon"></i> Store Keeper</option>
                        <option value="clergy"><i class="bi bi-people role-icon"></i> Clergy</option>
                    </select>
                </div>

                <div class="row mb-3">
                    <div class="col-md-8">
                        <label for="email" class="form-label">Email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control" id="email" name="email" placeholder="Enter email" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label for="phone" class="form-label">Phone</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                            <input type="tel" class="form-control" id="phone" name="phone" placeholder="Enter phone">
                        </div>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-person-plus"></i> Register
                    </button>
                </div>

                <div class="login-link">
                    <p class="text-muted">Already have an account? <a href="login.php">Login here</a></p>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Enhance form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const password = document.getElementById('password');
            if (password.value.length < 8) {
                alert('Password must be at least 8 characters long');
                e.preventDefault();
            }
            
            const email = document.getElementById('email');
            if (!email.value.includes('@')) {
                alert('Please enter a valid email address');
                e.preventDefault();
            }
        });

        // Add icons to selected role (for better UX)
        document.getElementById('role').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                this.style.backgroundImage = `url('data:image/svg+xml;utf8,${encodeURIComponent(selectedOption.innerHTML.match(/<i[^>]*>/)[0])}')`;
                this.style.backgroundRepeat = 'no-repeat';
                this.style.backgroundPosition = '10px center';
                this.style.paddingLeft = '35px';
            }
        });
    </script>
</body>
</html>