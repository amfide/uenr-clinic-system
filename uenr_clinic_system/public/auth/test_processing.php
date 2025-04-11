<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'lab_scientist') {
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

// -------------------- FUNCTION DEFINITIONS --------------------

function getLabRequestDetails($requestId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT 
                tr.*, 
                p.first_name, 
                p.last_name, 
                p.gender,
                p.date_of_birth,
                p.patient_id,
                lt.test_name,
                lt.id AS test_type_id,
                u.full_name AS doctor_name
            FROM test_requests tr
            JOIN patients p ON tr.patient_id = p.id
            JOIN lab_tests lt ON tr.test_id = lt.id
            JOIN users u ON tr.requested_by = u.id
            JOIN test_components tc ON tr.test_type_id = lt.id
            WHERE tr.id = ?
        ");
        $stmt->execute([$requestId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            error_log("No lab request found for ID: $requestId");
        }
        
        return $result;
    } catch (PDOException $e) {
        error_log("Database error in getLabRequestDetails: " . $e->getMessage());
        return false;
    }
}

function getTestComponents($testTypeId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM test_components WHERE test_type_id = ?");
    $stmt->execute([$testTypeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTestsReadyForProcessing() {
    global $pdo;
    $stmt = $pdo->query("SELECT tr.*, p.first_name, p.last_name, lt.test_name AS test_name
        FROM test_requests tr
        JOIN patients p ON tr.patient_id = p.id
        LEFT JOIN test_request_items tri ON tr.id = tri.request_id
        LEFT JOIN lab_tests lt ON tri.test_id = lt.id
        WHERE tr.status = 'Sample Collected'
        ORDER BY tr.request_date DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRecentProcessedTests($limit = 5) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT 
            tr.*, 
            p.first_name, 
            p.last_name, 
            GROUP_CONCAT(lt.test_name SEPARATOR ', ') AS tests
        FROM test_requests tr
        JOIN patients p ON tr.patient_id = p.id
        LEFT JOIN test_request_items ti ON tr.id = ti.request_id
        LEFT JOIN lab_tests lt ON ti.test_id = lt.id
        WHERE tr.status IN ('Processing', 'Completed')
        GROUP BY tr.id
        ORDER BY tr.processed_date DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function saveTestResults($requestId, $results, $status, $notes, $processedBy) {
    global $pdo;
    try {
        $pdo->beginTransaction();

        foreach ($results as $componentId => $resultData) {
            $stmt = $pdo->prepare("INSERT INTO test_results 
                (request_id, component_id, result_value, flag, notes)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                result_value = VALUES(result_value),
                flag = VALUES(flag),
                notes = VALUES(notes)
            ");
            $stmt->execute([
                $requestId,
                $componentId,
                $resultData['value'],
                $resultData['flag'],
                $notes
            ]);
        }

        $stmt = $pdo->prepare("UPDATE lab_requests 
            SET status = ?, 
                processed_by = ?, 
                processed_date = NOW(),
                notes = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, $processedBy, $notes, $requestId]);

        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error saving test results: " . $e->getMessage());
        return false;
    }
}

function notifyDoctor($requestId) {
    error_log("Notification sent to doctor for request ID: $requestId");
}

// -------------------- GET DATA FOR DISPLAY --------------------

try {
    $stmt = $pdo->prepare("SELECT 
        tr.*, 
        p.first_name, 
        p.last_name, 
        p.patient_id, 
        sc.collection_date,
        GROUP_CONCAT(lt.test_name SEPARATOR ', ') AS tests
    FROM test_requests tr
    JOIN patients p ON tr.patient_id = p.id
    JOIN sample_collections sc ON tr.id = sc.request_id
    LEFT JOIN test_request_items ti ON tr.id = ti.request_id
    LEFT JOIN lab_tests lt ON ti.test_id = lt.id
    WHERE tr.status = 'Sample Collected' 
    AND sc.collection_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)
    GROUP BY tr.id
    ORDER BY sc.collection_date DESC;
    ");
    $stmt->execute();
    $collectedSamples = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

$recentTests = getRecentProcessedTests(5);

// -------------------- HANDLE FORM SUBMISSION --------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_results'])) {
    $requestId = $_POST['request_id'];
    $results = $_POST['results'];
    $status = $_POST['status'];
    $notes = $_POST['notes'];

    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid form submission. Please try again.";
    } else {
        if (saveTestResults($requestId, $results, $status, $notes, $user['id'])) {
            $_SESSION['success'] = "Test results saved successfully!";

            if ($status === 'completed') {
                notifyDoctor($requestId);
            }

            header("Location: test_processing.php");
            exit();
        } else {
            $error = "Failed to save test results. Please try again.";
        }
    }
}

// If specific test request is being viewed
$requestId = $_GET['request_id'] ?? null;
$requestDetails = null;
$testComponents = [];

if ($requestId) {
    $requestDetails = getLabRequestDetails($requestId);
    
    if ($requestDetails) {
        $testComponents = getTestComponents($requestDetails['test_id']);
    } else {
        $error = "No lab request found for the provided request ID.";
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Processing - UENR Clinic</title>
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
        
        .test-info-card {
            border-left: 4px solid var(--info);
        }
        
        .patient-info-card {
            border-left: 4px solid var(--success);
        }
        
        .result-input {
            max-width: 200px;
        }
        
        .normal-range {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        /* Search box styling */
        #searchInput {
            transition: all 0.3s;
            border-left: none;
        }
        
        #searchInput:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
        }
        
        .input-group-text {
            background-color: white;
            border-right: none;
        }
        
        #clearSearch {
            transition: all 0.3s;
        }
        
        /* Highlight matching text */
        .highlight {
            background-color: yellow;
            font-weight: bold;
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
                        <a href="lab_scientist_dashboard.php" class="nav-link">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                        <a href="sample_collection.php" class="nav-link">
                            <i class="bi bi-droplet"></i> Sample Collection
                        </a>
                        <a href="test_processing.php" class="nav-link active">
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
                        <h2 class="mb-1">Test Processing</h2>
                        <p class="text-muted mb-0">Process lab tests and record results</p>
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

                <!-- Search Bar -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" id="searchInput" class="form-control" placeholder="Search tests...">
                            <button class="btn btn-outline-secondary" type="button" id="clearSearch">Clear</button>
                        </div>
                    </div>
                </div>

                <?php if ($requestId && $requestDetails): ?>
                    <!-- Test Processing Form -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="bi bi-flask me-2"></i>Process Test</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
                                
                                <div class="row mb-4">
                                    <!-- Patient Information -->
                                    <div class="col-md-6">
                                        <div class="card patient-info-card h-100">
                                            <div class="card-header bg-white">
                                                <h6 class="mb-0"><i class="bi bi-person me-2"></i>Patient Information</h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <label class="form-label text-muted">Patient Name</label>
                                                    <p class="fw-bold"><?php echo htmlspecialchars($requestDetails['first_name'] . ' ' . $requestDetails['last_name']); ?></p>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label text-muted">Patient ID</label>
                                                        <p class="fw-bold"><?php echo htmlspecialchars($requestDetails['patient_id']); ?></p>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label text-muted">Date of Birth</label>
                                                        <p class="fw-bold"><?php echo date('M j, Y', strtotime($requestDetails['date_of_birth'])); ?></p>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label text-muted">Gender</label>
                                                    <p class="fw-bold"><?php echo htmlspecialchars($requestDetails['gender']); ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Test Information -->
                                    <div class="col-md-6">
                                        <div class="card test-info-card h-100">
                                            <div class="card-header bg-white">
                                                <h6 class="mb-0"><i class="bi bi-clipboard-data me-2"></i>Test Information</h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <label class="form-label text-muted">Request ID</label>
                                                    <p class="fw-bold">LAB-<?php echo $requestDetails['id']; ?></p>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label text-muted">Test Type</label>
                                                    <p class="fw-bold"><?php echo htmlspecialchars($requestDetails['test_name']); ?></p>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label text-muted">Requested By</label>
                                                    <p class="fw-bold">Dr. <?php echo htmlspecialchars($requestDetails['doctor_name']); ?></p>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label text-muted">Request Date</label>
                                                    <p class="fw-bold"><?php echo date('M j, Y H:i', strtotime($requestDetails['request_date'])); ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Test Results -->
                                <div class="card mb-4">
                                    <div class="card-header bg-white">
                                        <h6 class="mb-0"><i class="bi bi-clipboard2-pulse me-2"></i>Test Results</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Component</th>
                                                        <th>Result</th>
                                                        <th>Unit</th>
                                                        <th>Normal Range</th>
                                                        <th>Flag</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($testComponents as $component): ?>
                                                        <tr>
                                                            <td>
                                                                <?php echo htmlspecialchars($component['component_name']); ?>
                                                                <?php if ($component['description']): ?>
                                                                    <small class="text-muted d-block"><?php echo htmlspecialchars($component['description']); ?></small>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <input type="text" class="form-control result-input" 
                                                                       name="results[<?php echo $component['id']; ?>][value]" 
                                                                       placeholder="Enter result">
                                                            </td>
                                                            <td><?php echo htmlspecialchars($component['unit']); ?></td>
                                                            <td>
                                                                <span class="normal-range">
                                                                    <?php echo htmlspecialchars($component['normal_range']); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <select class="form-select form-select-sm" 
                                                                        name="results[<?php echo $component['id']; ?>][flag]">
                                                                    <option value="normal">Normal</option>
                                                                    <option value="high">High</option>
                                                                    <option value="low">Low</option>
                                                                    <option value="critical">Critical</option>
                                                                </select>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Additional Notes and Status -->
                                <div class="row">
                                    <div class="col-md-8 mb-3">
                                        <label for="notes" class="form-label">Notes/Comments</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="3" 
                                                  placeholder="Any additional notes about the test..."></textarea>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="status" class="form-label">Test Status</label>
                                        <select class="form-select" id="status" name="status" required>
                                            <option value="processing">Processing</option>
                                            <option value="completed">Completed</option>
                                            <option value="pending">Pending</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-end mt-4">
                                    <a href="test_processing.php" class="btn btn-outline-secondary me-2">Cancel</a>
                                    <button type="submit" name="submit_results" class="btn btn-primary">
                                        <i class="bi bi-save me-1"></i> Save Results
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Tests Ready for Processing -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0"><i class="bi bi-hourglass me-2"></i>Tests Ready for Processing</h5>
                            <span class="badge bg-primary"><?php echo count(getTestsReadyForProcessing()); ?> pending</span>
                        </div>
                        <div class="card-body p-0">
                            <?php $testsReady = getTestsReadyForProcessing(); ?>
                            <?php if (!empty($testsReady)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Request ID</th>
                                                <th>Patient</th>
                                                <th>Test Type</th>
                                                <th>Collection Date</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($testsReady as $test): ?>
                                                <tr>
                                                    <td>LAB-<?php echo $test['id']; ?></td>
                                                    <td>
                                                        <div class="fw-bold"><?php echo htmlspecialchars($test['first_name'] . ' ' . $test['last_name']); ?></div>
                                                        <small class="text-muted">ID: <?php echo htmlspecialchars($test['patient_id']); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($test['test_name']); ?></td>
                                                    <td><?php echo date('M j, Y H:i', strtotime($test['completed_at'])); ?></td>
                                                    <td><span class="badge bg-info status-badge">Collected</span></td>
                                                    <td>
                                                        <a href="test_processing.php?request_id=<?php echo $test['id']; ?>" class="btn btn-sm btn-primary">
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
                                    <i class="bi bi-flask"></i>
                                    <h5>No tests ready for processing</h5>
                                    <p class="text-muted">All collected samples have been processed</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Recently Processed Tests -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Recently Processed Tests</h5>
                        <span class="badge bg-secondary"><?php echo count($recentTests); ?> recent</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($recentTests)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Request ID</th>
                                            <th>Patient</th>
                                            <th>Test Type</th>
                                            <th>Processed Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentTests as $test): ?>
                                            <tr>
                                                <td>LAB-<?php echo $test['id']; ?></td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($test['first_name'] . ' ' . $test['last_name']); ?></div>
                                                    <small class="text-muted">ID: <?php echo htmlspecialchars($test['patient_id']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($test['test_name']); ?></td>
                                                <td><?php echo date('M j, Y H:i', strtotime($test['processed_date'])); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $test['status'] === 'completed' ? 'bg-success' : 'bg-warning'; ?> status-badge">
                                                        <?php echo ucfirst($test['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="view_results.php?request_id=<?php echo $test['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-eye"></i> View
                                                    </a>
                                                    <a href="print_results.php?request_id=<?php echo $test['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                                        <i class="bi bi-printer"></i> Print
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-clock-history"></i>
                                <h5>No recently processed tests</h5>
                                <p class="text-muted">No tests have been processed yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Highlight abnormal results
        document.querySelectorAll('select[name^="results"]').forEach(select => {
            select.addEventListener('change', function() {
                const row = this.closest('tr');
                if (this.value !== 'normal') {
                    row.classList.add('table-warning');
                } else {
                    row.classList.remove('table-warning');
                }
            });
        });
        
        // Auto-flag based on result value (simplified example)
        document.querySelectorAll('.result-input').forEach(input => {
            input.addEventListener('blur', function() {
                const row = this.closest('tr');
                const flagSelect = row.querySelector('select[name^="results"]');
                
                if (this.value && !isNaN(this.value)) {
                    const value = parseFloat(this.value);
                    if (value > 10) {
                        flagSelect.value = 'high';
                        flagSelect.dispatchEvent(new Event('change'));
                    } else if (value < 2) {
                        flagSelect.value = 'low';
                        flagSelect.dispatchEvent(new Event('change'));
                    }
                }
            });
        });

        // Live search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const clearSearch = document.getElementById('clearSearch');
            const tables = document.querySelectorAll('.table-hover');
            
            function highlightText(element, searchTerm) {
                const text = element.textContent;
                const regex = new RegExp(searchTerm, 'gi');
                const highlightedText = text.replace(regex, match => `<span class="highlight">${match}</span>`);
                element.innerHTML = highlightedText;
            }

            function removeHighlights(element) {
                const highlights = element.querySelectorAll('.highlight');
                highlights.forEach(highlight => {
                    const parent = highlight.parentNode;
                    parent.textContent = parent.textContent;
                });
            }

            if (searchInput && tables.length > 0) {
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    
                    tables.forEach(table => {
                        const rows = table.querySelectorAll('tbody tr');
                        let hasMatches = false;
                        
                        rows.forEach(row => {
                            const cells = row.querySelectorAll('td');
                            let rowMatches = false;
                            
                            // Remove previous highlights
                            cells.forEach(cell => removeHighlights(cell));
                            
                            // Check each cell for matches
                            cells.forEach(cell => {
                                const cellText = cell.textContent.toLowerCase();
                                if (cellText.includes(searchTerm)) {
                                    if (searchTerm.length > 0) {
                                        highlightText(cell, searchTerm);
                                    }
                                    rowMatches = true;
                                }
                            });
                            
                            if (rowMatches) {
                                row.style.display = '';
                                hasMatches = true;
                            } else {
                                row.style.display = 'none';
                            }
                        });
                        
                        // Show/hide empty state if no matches
                        const emptyState = table.closest('.card-body').querySelector('.empty-state');
                        if (emptyState) {
                            emptyState.style.display = hasMatches ? 'none' : 'block';
                        }
                    });
                });
                
                clearSearch.addEventListener('click', function() {
                    searchInput.value = '';
                    searchInput.dispatchEvent(new Event('input'));
                });
            }
        });
    </script>
</body>
</html>