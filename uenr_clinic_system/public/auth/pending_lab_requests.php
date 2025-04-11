<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'doctor') {
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

// Initialize variables
$pendingRequests = [];
$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_status'])) {
        // Update test request status
        $requestId = $_POST['request_id'];
        $newStatus = $_POST['new_status'];
        
        try {
            $stmt = $pdo->prepare("UPDATE test_requests SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $requestId]);
            $success = "Test request status updated successfully!";
        } catch (PDOException $e) {
            $error = "Error updating status: " . $e->getMessage();
        }
    } elseif (isset($_POST['cancel_request'])) {
        // Cancel test request
        $requestId = $_POST['request_id'];
        
        try {
            $stmt = $pdo->prepare("UPDATE test_requests SET status = 'Cancelled' WHERE id = ?");
            $stmt->execute([$requestId]);
            $success = "Test request cancelled successfully!";
        } catch (PDOException $e) {
            $error = "Error cancelling request: " . $e->getMessage();
        }
    }
}

// Get all pending lab test requests
$stmt = $pdo->prepare("SELECT tr.*, 
                      p.first_name, p.last_name, p.patient_id,
                      u.full_name as doctor_name,
                      COUNT(tri.id) as test_count,
                      SUM(CASE WHEN tri.status = 'Completed' THEN 1 ELSE 0 END) as completed_count
                      FROM test_requests tr
                      JOIN patients p ON tr.patient_id = p.id
                      JOIN users u ON tr.requested_by = u.id
                      JOIN test_request_items tri ON tr.id = tri.request_id
                      WHERE tr.status = 'Pending'
                      GROUP BY tr.id
                      ORDER BY tr.request_date DESC");
$stmt->execute();
$pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Lab Requests - UENR Clinic</title>
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
        
        .request-card {
            border-left: 4px solid var(--secondary);
            margin-bottom: 1rem;
            border-radius: 0.5rem;
            transition: all 0.3s;
        }
        
        .request-card:hover {
            box-shadow: 0 0.25rem 0.5rem rgba(0,0,0,0.1);
        }
        
        .progress {
            height: 0.5rem;
            border-radius: 0.25rem;
        }
        
        .progress-bar {
            background-color: var(--secondary);
        }
        
        .test-item {
            padding: 1rem;
            background-color: var(--light);
            margin-bottom: 0.75rem;
            border-radius: 0.25rem;
            border-left: 3px solid var(--info);
        }
        
        .badge {
            font-weight: 500;
            padding: 0.35em 0.65em;
        }
        
        .status-badge {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
        
        .action-btns .btn {
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .price-badge {
            background-color: var(--dark);
            color: white;
        }
        
        .insured-badge {
            background-color: var(--success);
            color: white;
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
                        <a href="doctor_dashboard.php" class="nav-link">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                        <a href="manage_patients_doctor.php" class="nav-link">
                            <i class="bi bi-people-fill"></i> Patient Management
                        </a>
                        <a href="appointments.php" class="nav-link">
                            <i class="bi bi-calendar-check"></i> Appointments
                        </a>
                        <a href="lab_tests.php" class="nav-link">
                            <i class="bi bi-droplet"></i> Lab Tests
                        </a>
                        <a href="pending_lab_requests.php" class="nav-link active">
                            <i class="bi bi-hourglass-split"></i> Pending Requests
                        </a>
                        <a href="prescriptions.php" class="nav-link">
                            <i class="bi bi-capsule"></i> Prescriptions
                        </a>
                        <a href="medical_records.php" class="nav-link">
                            <i class="bi bi-file-earmark-medical"></i> Medical Records
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
                        <h2 class="mb-1">Pending Lab Requests</h2>
                        <p class="text-muted mb-0">Track and manage pending laboratory test requests</p>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-light text-dark">
                            <i class="bi bi-calendar"></i> <?php echo date('l, F j, Y'); ?>
                        </span>
                    </div>
                </div>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Pending Requests Card -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-hourglass-split me-2"></i>Pending Test Requests</h5>
                        <span class="badge bg-warning"><?php echo count($pendingRequests); ?> pending</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pendingRequests)): ?>
                            <div class="empty-state">
                                <i class="bi bi-hourglass-top"></i>
                                <h5>No pending requests</h5>
                                <p class="text-muted">All lab test requests have been processed</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($pendingRequests as $request): ?>
                                    <div class="list-group-item request-card">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1">
                                                    <a href="lab_tests.php?patient_id=<?php echo $request['patient_id']; ?>" class="text-decoration-none">
                                                        <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?>
                                                    </a>
                                                    <small class="text-muted">(ID: <?php echo htmlspecialchars($request['patient_id']); ?>)</small>
                                                </h6>
                                                <small class="text-muted">
                                                    <i class="bi bi-person-badge me-1"></i>Requested by <?php echo htmlspecialchars($request['doctor_name']); ?>
                                                    <i class="bi bi-clock ms-2 me-1"></i><?php echo date('M d, Y H:i', strtotime($request['request_date'])); ?>
                                                </small>
                                            </div>
                                            <span class="badge status-badge bg-warning">Pending</span>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <div class="progress">
                                                <div class="progress-bar" role="progressbar" 
                                                     style="width: <?php echo ($request['completed_count'] / $request['test_count']) * 100; ?>%" 
                                                     aria-valuenow="<?php echo ($request['completed_count'] / $request['test_count']) * 100; ?>" 
                                                     aria-valuemin="0" aria-valuemax="100">
                                                </div>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo $request['completed_count']; ?> of <?php echo $request['test_count']; ?> tests completed
                                            </small>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <button class="btn btn-sm btn-outline-primary view-details" 
                                                    data-request-id="<?php echo $request['id']; ?>">
                                                <i class="bi bi-chevron-down me-1"></i>View Details
                                            </button>
                                        </div>
                                        
                                        <div class="test-details mt-3" id="details-<?php echo $request['id']; ?>" style="display: none;">
                                            <?php
                                            // Get test items for this request
                                            $stmt = $pdo->prepare("SELECT tri.*, lt.test_name, lt.price 
                                                                  FROM test_request_items tri
                                                                  JOIN lab_tests lt ON tri.test_id = lt.id
                                                                  WHERE tri.request_id = ?");
                                            $stmt->execute([$request['id']]);
                                            $testItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                            
                                            foreach ($testItems as $item): ?>
                                                <div class="test-item">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <strong><?php echo htmlspecialchars($item['test_name']); ?></strong>
                                                            <div class="text-muted small mt-1">
                                                                <span class="badge price-badge">GH₵<?php echo number_format($item['price'], 2); ?></span>
                                                                <?php if ($request['is_insured']): ?>
                                                                    <span class="badge insured-badge ms-1">Insured</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <span class="badge status-badge bg-<?php 
                                                            echo $item['status'] == 'Completed' ? 'success' : 'warning'; 
                                                        ?>">
                                                            <?php echo $item['status']; ?>
                                                        </span>
                                                    </div>
                                                    <?php if ($item['result']): ?>
                                                        <div class="mt-2">
                                                            <strong>Result:</strong> <?php echo htmlspecialchars($item['result']); ?>
                                                        </div>
                                                        <div class="text-muted small">
                                                            <i class="bi bi-calendar me-1"></i>Completed on <?php echo date('M d, Y H:i', strtotime($item['result_date'])); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                            
                                            <div class="mt-3 p-3 bg-light rounded">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="d-flex justify-content-between mb-2">
                                                            <strong>Total Amount:</strong>
                                                            <span>
                                                                <?php if ($request['is_insured']): ?>
                                                                    <span class="text-success">GH₵0.00 (Insured)</span>
                                                                <?php else: ?>
                                                                    GH₵<?php echo number_format($request['total_amount'], 2); ?>
                                                                <?php endif; ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="d-flex justify-content-between mb-2">
                                                            <strong>Payment Status:</strong>
                                                            <span class="badge status-badge bg-<?php 
                                                                echo $request['payment_status'] == 'Paid' ? 'success' : 
                                                                     ($request['payment_status'] == 'Partial' ? 'warning' : 'danger'); 
                                                            ?>">
                                                                <?php echo $request['payment_status']; ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mt-3 d-flex justify-content-end action-btns">
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                    <button type="submit" name="cancel_request" class="btn btn-sm btn-danger">
                                                        <i class="bi bi-x-circle me-1"></i>Cancel Request
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle test details
        document.querySelectorAll('.view-details').forEach(button => {
            button.addEventListener('click', function() {
                const detailsId = 'details-' + this.dataset.requestId;
                const detailsDiv = document.getElementById(detailsId);
                
                if (detailsDiv.style.display === 'none') {
                    detailsDiv.style.display = 'block';
                    this.innerHTML = '<i class="bi bi-chevron-up me-1"></i>Hide Details';
                } else {
                    detailsDiv.style.display = 'none';
                    this.innerHTML = '<i class="bi bi-chevron-down me-1"></i>View Details';
                }
            });
        });
        
        // Auto-refresh every 60 seconds
        setTimeout(() => {
            window.location.reload();
        }, 60000);
    </script>
</body>
</html>