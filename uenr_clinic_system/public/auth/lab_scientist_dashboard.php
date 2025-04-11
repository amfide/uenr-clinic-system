<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'lab_scientist') {
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

// Initialize variables with empty arrays to prevent undefined variable errors
$pendingRequests = [];
$collectedSamples = [];
$bloodRequests = [];
$bloodStock = [];
$error = null;

// Handle sample collection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['collect_sample'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $requestId = $_POST['request_id'];
        
        try {
            $stmt = $pdo->prepare("UPDATE test_requests SET status = 'Sample Collected', completed_by = ?, completed_at = NOW() WHERE id = ?");
            $stmt->execute([$user['id'], $requestId]);
            
            $_SESSION['success'] = "Sample collected successfully";
            logActivity($user['id'], "Collected sample for request ID: $requestId");
        } catch (PDOException $e) {
            $error = "Error recording sample collection: " . $e->getMessage();
        }
    }
}

// Handle test completion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['complete_test'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $itemId = $_POST['item_id'];
        $result = $_POST['result'];
        
        try {
            $stmt = $pdo->prepare("UPDATE test_request_items SET result = ?, result_date = NOW() WHERE id = ?");
            $stmt->execute([$result, $itemId]);
            
            // Check if all tests for this request are completed
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM test_request_items WHERE request_id = (SELECT request_id FROM test_request_items WHERE id = ?) AND result IS NULL");
            $stmt->execute([$itemId]);
            $pendingTests = $stmt->fetchColumn();
            
            if ($pendingTests == 0) {
                $stmt = $pdo->prepare("UPDATE test_requests SET status = 'Completed' WHERE id = (SELECT request_id FROM test_request_items WHERE id = ?)");
                $stmt->execute([$itemId]);
            }
            
            $_SESSION['success'] = "Test result recorded successfully";
            logActivity($user['id'], "Recorded test result for item ID: $itemId");
        } catch (PDOException $e) {
            $error = "Error recording test result: " . $e->getMessage();
        }
    }
}

// Handle blood request processing
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['process_blood_request'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $requestId = $_POST['request_id'];
        $action = $_POST['action'];
        
        try {
            if ($action == 'approve') {
                // Check blood stock
                $stmt = $pdo->prepare("SELECT blood_group, units FROM blood_requests WHERE id = ?");
                $stmt->execute([$requestId]);
                $request = $stmt->fetch();
                
                $stmt = $pdo->prepare("SELECT units_available FROM blood_bank WHERE blood_group = ?");
                $stmt->execute([$request['blood_group']]);
                $stock = $stmt->fetchColumn();
                
                if ($stock >= $request['units']) {
                    // Update blood bank stock
                    $stmt = $pdo->prepare("UPDATE blood_bank SET units_available = units_available - ? WHERE blood_group = ?");
                    $stmt->execute([$request['units'], $request['blood_group']]);
                    
                    // Update request status
                    $stmt = $pdo->prepare("UPDATE blood_requests SET status = 'Fulfilled', processed_by = ?, processed_date = NOW() WHERE id = ?");
                    $stmt->execute([$user['id'], $requestId]);
                    
                    $_SESSION['success'] = "Blood request fulfilled successfully";
                    logActivity($user['id'], "Fulfilled blood request ID: $requestId");
                } else {
                    $error = "Insufficient blood stock to fulfill this request";
                }
            } else {
                // Reject request
                $stmt = $pdo->prepare("UPDATE blood_requests SET status = 'Rejected', processed_by = ?, processed_date = NOW() WHERE id = ?");
                $stmt->execute([$user['id'], $requestId]);
                
                $_SESSION['success'] = "Blood request rejected";
                logActivity($user['id'], "Rejected blood request ID: $requestId");
            }
        } catch (PDOException $e) {
            $error = "Error processing blood request: " . $e->getMessage();
        }
    }
}

try {
    // Get pending lab requests with error handling
    $stmt = $pdo->prepare("SELECT tr.*, p.first_name, p.last_name, p.patient_id 
                         FROM test_requests tr
                         JOIN patients p ON tr.patient_id = p.id
                         WHERE tr.status = 'Pending'
                         ORDER BY tr.request_date ASC");
    $stmt->execute();
    $pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get collected samples with error handling
    $stmt = $pdo->prepare("SELECT tr.*, p.first_name, p.last_name, p.patient_id 
                         FROM test_requests tr
                         JOIN patients p ON tr.patient_id = p.id
                         WHERE tr.status = 'Sample Collected'
                         ORDER BY tr.request_date ASC");
    $stmt->execute();
    $collectedSamples = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get blood requests with error handling
    $stmt = $pdo->prepare("SELECT br.*, p.first_name, p.last_name, p.patient_id 
                         FROM blood_requests br
                         JOIN patients p ON br.patient_id = p.id
                         WHERE br.status = 'Pending'
                         ORDER BY br.request_date ASC");
    $stmt->execute();
    $bloodRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get blood bank stock with error handling
    $stmt = $pdo->prepare("SELECT * FROM blood_bank ORDER BY blood_group");
    $stmt->execute();
    $bloodStock = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    error_log($error);
}

// [Rest of your HTML template remains the same...]
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Dashboard - UENR Clinic</title>
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
        }
        
        .sidebar {
            min-height: 100vh;
            background: var(--primary);
            color: white;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar-brand {
            padding: 1.5rem 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-nav {
            padding: 1rem 0;
        }
        
        .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 0.75rem 1rem;
            margin: 0.25rem 0;
            border-radius: 0.25rem;
            transition: all 0.3s;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
            transform: translateX(5px);
        }
        
        .nav-link i {
            width: 24px;
            text-align: center;
            margin-right: 10px;
        }
        
        .main-content {
            padding: 2rem;
            background-color: #f5f7fa;
        }
        
        .dashboard-header {
            margin-bottom: 2rem;
        }
        
        .card {
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
            margin-bottom: 1.5rem;
            transition: transform 0.2s;
        }
        
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.1);
        }
        
        .card-header {
            background-color: white;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding: 1rem 1.5rem;
            font-weight: 600;
            border-radius: 0.5rem 0.5rem 0 0 !important;
        }
        
        .card-title {
            margin-bottom: 0;
            color: var(--primary);
        }
        
        .badge {
            font-weight: 500;
            padding: 0.35em 0.65em;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: var(--dark);
            white-space: nowrap;
        }
        
        .table-responsive {
            border-radius: 0.5rem;
        }
        
        .status-badge {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
        
        .alert {
            border-radius: 0.5rem;
        }
        
        .empty-state {
            padding: 2rem;
            text-align: center;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #dee2e6;
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--primary);
            position: relative;
            padding-left: 1rem;
        }
        
        .section-title:before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: var(--secondary);
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar p-0">
                <div class="sidebar-brand text-center">
                    <h4>UENR Clinic</h4>
                </div>
                <div class="sidebar-nav">
                    <div class="nav flex-column">
                        <a href="lab_scientist_dashboard.php" class="nav-link active">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                        <a href="sample_collection.php" class="nav-link">
                            <i class="bi bi-droplet"></i> Sample Collection
                        </a>
                        <a href="test_processing.php" class="nav-link">
                            <i class="bi bi-flask"></i> Test Processing
                        </a>
                        <a href="blood_bank.php" class="nav-link">
                            <i class="bi bi-droplet-fill"></i> Blood Bank
                        </a>
                        <div class="mt-4 pt-3 border-top border-secondary">
                            <a href="../auth/logout.php" class="nav-link">
                                <i class="bi bi-box-arrow-left"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 main-content">
                <div class="dashboard-header d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">Lab Dashboard</h2>
                        <p class="text-muted mb-0">Welcome back, <?php echo $user['full_name']; ?></p>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-light text-dark">
                            <i class="bi bi-calendar"></i> <?php echo date('l, F j, Y'); ?>
                        </span>
                    </div>
                </div>
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Quick Stats -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card border-start border-primary border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-2">Pending Requests</h6>
                                        <h3 class="mb-0"><?php echo count($pendingRequests); ?></h3>
                                    </div>
                                    <div class="bg-primary bg-opacity-10 p-3 rounded">
                                        <i class="bi bi-hourglass text-primary" style="font-size: 1.5rem;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-start border-info border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-2">Collected Samples</h6>
                                        <h3 class="mb-0"><?php echo count($collectedSamples); ?></h3>
                                    </div>
                                    <div class="bg-info bg-opacity-10 p-3 rounded">
                                        <i class="bi bi-droplet text-info" style="font-size: 1.5rem;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-start border-danger border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-2">Blood Requests</h6>
                                        <h3 class="mb-0"><?php echo count($bloodRequests); ?></h3>
                                    </div>
                                    <div class="bg-danger bg-opacity-10 p-3 rounded">
                                        <i class="bi bi-droplet-fill text-danger" style="font-size: 1.5rem;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pending Lab Requests -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-hourglass me-2"></i>Pending Lab Requests</h5>
                        <span class="badge bg-primary"><?php echo count($pendingRequests); ?> pending</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($pendingRequests)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Request ID</th>
                                            <th>Patient</th>
                                            <th>Request Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pendingRequests as $request): ?>
                                            <tr>
                                                <td>LAB-<?php echo $request['id']; ?></td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></div>
                                                    <small class="text-muted">ID: <?php echo htmlspecialchars($request['patient_id']); ?></small>
                                                </td>
                                                <td><?php echo date('M j, Y H:i', strtotime($request['request_date'])); ?></td>
                                                <td><span class="badge bg-warning status-badge">Pending</span></td>
                                                <td>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                        <button type="submit" name="collect_sample" class="btn btn-sm btn-success" 
                                                                onclick="return confirm('Mark this sample as collected?')">
                                                            <i class="bi bi-check-circle"></i> Collect
                                                        </button>
                                                    </form>
                                                    <a href="view_request.php?id=<?php echo $request['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-eye"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-hourglass"></i>
                                <h5>No pending requests</h5>
                                <p class="text-muted">All lab requests have been processed</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Collected Samples -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-droplet me-2"></i>Collected Samples</h5>
                        <span class="badge bg-info"><?php echo count($collectedSamples); ?> collected</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($collectedSamples)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Request ID</th>
                                            <th>Patient</th>
                                            <th>Collection Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($collectedSamples as $sample): ?>
                                            <tr>
                                                <td>LAB-<?php echo $sample['id']; ?></td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($sample['first_name'] . ' ' . $sample['last_name']); ?></div>
                                                    <small class="text-muted">ID: <?php echo htmlspecialchars($sample['patient_id']); ?></small>
                                                </td>
                                                <td><?php echo date('M j, Y H:i', strtotime($sample['completed_at'])); ?></td>
                                                <td><span class="badge bg-info status-badge">Collected</span></td>
                                                <td>
                                                    <a href="process_test.php?request_id=<?php echo $sample['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-flask"></i> Process
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-droplet"></i>
                                <h5>No collected samples</h5>
                                <p class="text-muted">No samples have been collected yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Blood Bank Requests -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-droplet-fill me-2"></i>Blood Requests</h5>
                        <span class="badge bg-danger"><?php echo count($bloodRequests); ?> pending</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($bloodRequests)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Request ID</th>
                                            <th>Patient</th>
                                            <th>Blood Group</th>
                                            <th>Units</th>
                                            <th>Request Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($bloodRequests as $request): ?>
                                            <tr>
                                                <td>BLOOD-<?php echo $request['id']; ?></td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></div>
                                                    <small class="text-muted">ID: <?php echo htmlspecialchars($request['patient_id']); ?></small>
                                                </td>
                                                <td><span class="badge bg-danger"><?php echo htmlspecialchars($request['blood_group']); ?></span></td>
                                                <td><?php echo $request['units']; ?></td>
                                                <td><?php echo date('M j, Y H:i', strtotime($request['request_date'])); ?></td>
                                                <td>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="submit" name="process_blood_request" class="btn btn-sm btn-success" 
                                                                onclick="return confirm('Approve this blood request?')">
                                                            <i class="bi bi-check-circle"></i> Approve
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <button type="submit" name="process_blood_request" class="btn btn-sm btn-outline-danger" 
                                                                onclick="return confirm('Reject this blood request?')">
                                                            <i class="bi bi-x-circle"></i> Reject
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-droplet-fill"></i>
                                <h5>No blood requests</h5>
                                <p class="text-muted">No pending blood requests found</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Simple confirmation for actions
        document.querySelectorAll('[data-confirm]').forEach(element => {
            element.addEventListener('click', (e) => {
                if (!confirm(element.dataset.confirm)) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>