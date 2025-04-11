<?php
require_once '../includes/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'administrator') {
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_user'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $fullName = trim($_POST['full_name']);
        $role = $_POST['role'];
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, email, phone) 
                                  VALUES (?, ?, ?, ?, ?, ?)");
            $hashedPassword = hashPassword($password);
            $stmt->execute([$username, $hashedPassword, $fullName, $role, $email, $phone]);
            
            $_SESSION['success'] = "User created successfully";
            logActivity($user['id'], "Created new user: $username");
        } catch (PDOException $e) {
            $error = "Error creating user: " . $e->getMessage();
        }
    }
}

// Handle user status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_user_status'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $userId = $_POST['user_id'];
        $action = $_POST['action'];
        
        try {
            $stmt = $pdo->prepare("UPDATE users SET active = ? WHERE id = ?");
            $stmt->execute([$action == 'activate' ? 1 : 0, $userId]);
            
            $_SESSION['success'] = "User status updated successfully";
            logActivity($user['id'], ($action == 'activate' ? "Activated" : "Deactivated") . " user ID: $userId");
        } catch (PDOException $e) {
            $error = "Error updating user status: " . $e->getMessage();
        }
    }
}

// Get all users
$stmt = $pdo->prepare("SELECT * FROM users ORDER BY role, full_name");
$stmt->execute();
$allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get activity logs
$stmt = $pdo->prepare("SELECT al.*, u.full_name 
                       FROM activity_logs al
                       JOIN users u ON al.user_id = u.id
                       ORDER BY al.action_time DESC
                       LIMIT 50");
$stmt->execute();
$activityLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Dashboard - UENR Clinic</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar p-3">
                <div class="text-center mb-4">
                    <h4>UENR Clinic</h4>
                    <hr>
                    <p class="mb-0">Welcome, <?php echo $user['full_name']; ?></p>
                    <small>(Administrator)</small>
                </div>
                <a href="administrator_dashboard.php" class="active"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="#userManagement" data-bs-toggle="collapse"><i class="bi bi-people"></i> User Management</a>
                <a href="#activityLogs" data-bs-toggle="collapse"><i class="bi bi-list-check"></i> Activity Logs</a>
                <a href="#systemSettings" data-bs-toggle="collapse"><i class="bi bi-gear"></i> System Settings</a>
                <a href="../auth/logout.php"><i class="bi bi-box-arrow-left"></i> Logout</a>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 main-content">
                <h2 class="mb-4">Administrator Dashboard</h2>
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- User Management -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">User Management</h5>
                    </div>
                    <div class="card-body">
                        <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#createUserModal">
                            <i class="bi bi-plus-circle"></i> Create New User
                        </button>
                        
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Full Name</th>
                                        <th>Role</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allUsers as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['phone']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $user['active'] ? 'success' : 'danger'; ?>">
                                                    <?php echo $user['active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($user['active']): ?>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <input type="hidden" name="action" value="deactivate">
                                                        <button type="submit" name="update_user_status" class="btn btn-sm btn-warning" 
                                                                onclick="return confirm('Deactivate this user?')">Deactivate</button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <input type="hidden" name="action" value="activate">
                                                        <button type="submit" name="update_user_status" class="btn btn-sm btn-success" 
                                                                onclick="return confirm('Activate this user?')">Activate</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Activity Logs -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0">Recent Activity Logs</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($activityLogs)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Timestamp</th>
                                            <th>User</th>
                                            <th>Action</th>
                                            <th>IP Address</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($activityLogs as $log): ?>
                                            <tr>
                                                <td><?php echo date('d/m/Y H:i:s', strtotime($log['action_time'])); ?></td>
                                                <td><?php echo htmlspecialchars($log['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($log['action']); ?></td>
                                                <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">No activity logs found.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create User Modal -->
    <div class="modal fade" id="createUserModal" tabindex="-1" aria-labelledby="createUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createUserModalLabel">Create New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">Role</label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="">Select Role</option>
                                <option value="records_keeper">Records Keeper</option>
                                <option value="nurse">Nurse</option>
                                <option value="doctor">Doctor</option>
                                <option value="lab_scientist">Lab Scientist</option>
                                <option value="pharmacist">Pharmacist</option>
                                <option value="store_keeper">Store Keeper</option>
                                <option value="administrator">Administrator</option>
                                <option value="clergy">Clergy</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control" id="phone" name="phone">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_user" class="btn btn-primary" 
                                onclick="return confirm('Create this new user?')">Create User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
</body>
</html>