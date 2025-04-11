<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'doctor') {
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

// Initialize variables
$patient = null;
$searchResults = [];
$error = '';
$success = '';

// Handle patient search
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['patient_search'])) {
    $searchTerm = "%{$_GET['patient_search']}%";
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR phone LIKE ?");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($searchResults) === 1) {
        $patient = $searchResults[0];
    }
}

// Get patient if ID is provided directly
if (isset($_GET['patient_id']) && empty($searchResults)) {
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->execute([$_GET['patient_id']]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all available lab tests
$tests = $pdo->query("SELECT * FROM lab_tests")->fetchAll(PDO::FETCH_ASSOC);

// Handle test request submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_tests'])) {
    $patientId = $_POST['patient_id'];
    $selectedTests = $_POST['tests'] ?? [];
    $isInsured = isset($_POST['is_insured']) ? 1 : 0;
    
    try {
        $pdo->beginTransaction();
        
        // Calculate total amount
        $totalAmount = 0;
        if (!$isInsured) {
            $testIds = implode(',', array_map('intval', $selectedTests));
            $stmt = $pdo->prepare("SELECT SUM(price) as total FROM lab_tests WHERE id IN ($testIds)");
            $stmt->execute();
            $totalAmount = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        }
        
        // Create test request
        $stmt = $pdo->prepare("INSERT INTO test_requests 
                              (patient_id, requested_by, is_insured, total_amount) 
                              VALUES (?, ?, ?, ?)");
        $stmt->execute([$patientId, $user['id'], $isInsured, $totalAmount]);
        $requestId = $pdo->lastInsertId();
        
        // Add test items
        foreach ($selectedTests as $testId) {
            $stmt = $pdo->prepare("INSERT INTO test_request_items 
                                  (request_id, test_id) 
                                  VALUES (?, ?)");
            $stmt->execute([$requestId, $testId]);
        }
        
        $pdo->commit();
        $success = "Lab tests requested successfully!";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Error requesting tests: " . $e->getMessage();
    }
}

// Get pending test requests for real-time tracking
$pendingRequests = [];
if ($patient) {
    $stmt = $pdo->prepare("SELECT tr.*, 
                          COUNT(tri.id) as test_count,
                          SUM(CASE WHEN tri.status = 'Completed' THEN 1 ELSE 0 END) as completed_count
                          FROM test_requests tr
                          JOIN test_request_items tri ON tr.id = tri.request_id
                          WHERE tr.patient_id = ?
                          GROUP BY tr.id
                          ORDER BY tr.request_date DESC");
    $stmt->execute([$patient['id']]);
    $pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Test Management - UENR Clinic</title>
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
        
        .patient-info {
            background-color: var(--light);
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .test-item {
            border-left: 4px solid var(--secondary);
            margin-bottom: 0.75rem;
            padding: 1rem;
            background-color: var(--light);
            border-radius: 0.25rem;
        }
        
        .progress {
            height: 0.5rem;
            border-radius: 0.25rem;
        }
        
        .progress-bar {
            background-color: var(--secondary);
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
        
        .search-card .form-control {
            border-radius: 0.5rem;
            border-right: none;
        }
        
        .search-card .btn {
            border-radius: 0.5rem;
        }
        
        .test-checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
        }
        
        .test-price-badge {
            margin-left: 0.5rem;
            font-size: 0.75rem;
        }
        
        /* Live search styles */
        .search-container {
            position: relative;
            margin-bottom: 1rem;
        }
        
        #liveSearchResults {
            position: absolute;
            top: 100%;
            left: 0;
            z-index: 1000;
            width: 100%;
            max-height: 300px;
            overflow-y: auto;
            background: white;
            border: 1px solid rgba(0,0,0,.15);
            border-radius: 0 0 0.25rem 0.25rem;
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,.175);
            display: none;
        }
        
        #liveSearchResults .dropdown-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #f8f9fa;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        #liveSearchResults .dropdown-item:hover {
            background-color: #f8f9fa;
        }
        
        #liveSearchResults .patient-id {
            font-weight: bold;
            margin-right: 10px;
        }
        
        #liveSearchResults .patient-phone {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .search-results-section {
            margin-bottom: 2rem;
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
                        <a href="manage_appointments.php" class="nav-link">
                            <i class="bi bi-calendar-check"></i> Appointments
                        </a>
                        <a href="lab_tests.php" class="nav-link active">
                            <i class="bi bi-droplet"></i> Lab Tests
                        </a>
                        <a href="pending_lab_requests.php" class="nav-link">
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
                        <h2 class="mb-1">Lab Test Management</h2>
                        <p class="text-muted mb-0">Request and track laboratory tests</p>
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

                <!-- Patient Search Card -->
                <div class="card search-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-search me-2"></i>Patient Search</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="mb-3">
                            <div class="search-container">
                                <input type="text" class="form-control" id="patientSearch" 
                                       name="patient_search" placeholder="Search by Patient ID, Name or Phone" 
                                       autocomplete="off" value="<?php echo isset($_GET['patient_search']) ? htmlspecialchars($_GET['patient_search']) : ''; ?>">
                                <button class="btn btn-primary" type="submit" style="position: absolute; right: 0; top: 0; height: 100%; border-radius: 0 0.25rem 0.25rem 0;">
                                    <i class="bi bi-search"></i>
                                </button>
                                <div id="liveSearchResults" class="dropdown-menu"></div>
                            </div>
                            <?php if (isset($_GET['patient_search'])): ?>
                                <a href="lab_tests.php" class="btn btn-outline-danger mt-2">
                                    <i class="bi bi-x"></i> Clear Search
                                </a>
                            <?php endif; ?>
                        </form>

                        <?php if (!empty($searchResults)): ?>
                            <div class="search-results-section">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Patient ID</th>
                                                <th>Name</th>
                                                <th>Gender</th>
                                                <th>Phone</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($searchResults as $result): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($result['patient_id']); ?></td>
                                                    <td><?php echo htmlspecialchars($result['first_name'] . ' ' . $result['last_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($result['gender']); ?></td>
                                                    <td><?php echo htmlspecialchars($result['phone']); ?></td>
                                                    <td>
                                                        <a href="lab_tests.php?patient_id=<?php echo $result['id']; ?>" class="btn btn-sm btn-primary">
                                                            <i class="bi bi-check-circle"></i> Select
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php elseif (isset($_GET['patient_search'])): ?>
                            <div class="empty-state">
                                <i class="bi bi-person-x"></i>
                                <h5>No patient found</h5>
                                <p class="text-muted">Please try another search</p>
                            </div>
                        <?php endif; ?>

                        <?php if ($patient): ?>
                            <div class="patient-info mt-3">
                                <h5 class="mb-3"><i class="bi bi-person-circle me-2"></i>Selected Patient</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>Name:</strong> <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></p>
                                        <p><strong>Patient ID:</strong> <?php echo htmlspecialchars($patient['patient_id']); ?></p>
                                        <p><strong>Gender:</strong> <?php echo htmlspecialchars($patient['gender']); ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Age:</strong> <?php echo date_diff(date_create($patient['dob']), date_create('today'))->y; ?> years</p>
                                        <p><strong>Blood Group:</strong> <?php echo htmlspecialchars($patient['blood_group']); ?></p>
                                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($patient['phone']); ?></p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Lab Test Request Form -->
                <?php if ($patient): ?>
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-clipboard-plus me-2"></i>Request Lab Tests</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="patient_id" value="<?php echo $patient['id']; ?>">
                            
                            <div class="mb-3 form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_insured" name="is_insured">
                                <label class="form-check-label" for="is_insured">Patient is insured (UENR staff/student)</label>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label"><i class="bi bi-list-check me-2"></i>Select Tests:</label>
                                <div class="row">
                                    <?php foreach ($tests as $test): ?>
                                        <div class="col-md-4 mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input test-checkbox" type="checkbox" 
                                                       name="tests[]" id="test_<?php echo $test['id']; ?>" 
                                                       value="<?php echo $test['id']; ?>"
                                                       data-price="<?php echo $test['price']; ?>">
                                                <label class="form-check-label test-checkbox-label" for="test_<?php echo $test['id']; ?>">
                                                    <?php echo htmlspecialchars($test['test_name']); ?>
                                                    <span class="badge bg-secondary test-price-badge">GH₵<?php echo number_format($test['price'], 2); ?></span>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="mb-4 p-3 bg-light rounded">
                                <h5><i class="bi bi-cash-coin me-2"></i>Total Amount: <span id="totalAmount">GH₵0.00</span></h5>
                            </div>
                            
                            <button type="submit" name="request_tests" class="btn btn-primary">
                                <i class="bi bi-send-check"></i> Submit Test Request
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Test Request Tracking -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-hourglass-split me-2"></i>Test Request Status</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pendingRequests)): ?>
                            <div class="empty-state">
                                <i class="bi bi-hourglass-top"></i>
                                <h5>No test requests</h5>
                                <p class="text-muted">No pending test requests for this patient</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($pendingRequests as $request): ?>
                                    <div class="list-group-item mb-3 rounded">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h6 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Request #<?php echo $request['id']; ?></h6>
                                            <span class="badge status-badge bg-<?php 
                                                echo $request['status'] == 'Completed' ? 'success' : 
                                                     ($request['status'] == 'Pending' ? 'warning' : 'primary'); 
                                            ?>">
                                                <?php echo $request['status']; ?>
                                            </span>
                                        </div>
                                        <small class="text-muted"><i class="bi bi-calendar me-1"></i>Requested on <?php echo date('M d, Y H:i', strtotime($request['request_date'])); ?></small>
                                        
                                        <div class="progress mt-3 mb-2">
                                            <div class="progress-bar" role="progressbar" 
                                                 style="width: <?php echo ($request['completed_count'] / $request['test_count']) * 100; ?>%" 
                                                 aria-valuenow="<?php echo ($request['completed_count'] / $request['test_count']) * 100; ?>" 
                                                 aria-valuemin="0" aria-valuemax="100">
                                            </div>
                                        </div>
                                        
                                        <small class="text-muted"><?php echo $request['completed_count']; ?> of <?php echo $request['test_count']; ?> tests completed</small>
                                        
                                        <button class="btn btn-sm btn-outline-primary mt-3 view-details" 
                                                data-request-id="<?php echo $request['id']; ?>">
                                            <i class="bi bi-chevron-down me-1"></i>View Details
                                        </button>
                                        
                                        <div class="test-details mt-3" id="details-<?php echo $request['id']; ?>" style="display: none;">
                                            <?php
                                            $stmt = $pdo->prepare("SELECT tri.*, lt.test_name 
                                                                  FROM test_request_items tri
                                                                  JOIN lab_tests lt ON tri.test_id = lt.id
                                                                  WHERE tri.request_id = ?");
                                            $stmt->execute([$request['id']]);
                                            $testItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                            
                                            foreach ($testItems as $item): ?>
                                                <div class="test-item">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <strong><?php echo htmlspecialchars($item['test_name']); ?></strong>
                                                        <span class="badge status-badge bg-<?php 
                                                            echo $item['status'] == 'Completed' ? 'success' : 
                                                                 ($item['status'] == 'Pending' ? 'warning' : 'primary'); 
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
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php elseif (!isset($_GET['patient_search'])): ?>
                    <div class="empty-state">
                        <i class="bi bi-person-plus"></i>
                        <h5>No patient selected</h5>
                        <p class="text-muted">Please search for and select a patient to request lab tests</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Calculate total amount
        document.querySelectorAll('.test-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateTotal);
        });
        
        document.getElementById('is_insured').addEventListener('change', function() {
            updateTotal();
            if (this.checked) {
                document.getElementById('totalAmount').textContent = 'GH₵0.00 (Insured)';
            } else {
                updateTotal();
            }
        });
        
        function updateTotal() {
            if (document.getElementById('is_insured').checked) {
                document.getElementById('totalAmount').textContent = 'GH₵0.00 (Insured)';
                return;
            }
            
            let total = 0;
            document.querySelectorAll('.test-checkbox:checked').forEach(checkbox => {
                total += parseFloat(checkbox.dataset.price);
            });
            
            document.getElementById('totalAmount').textContent = 'GH₵' + total.toFixed(2);
        }
        
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
        
        // Auto-refresh every 30 seconds to track test progress
        setTimeout(() => {
            window.location.reload();
        }, 30000);
        
        // Live patient search functionality
        const patientSearch = document.getElementById('patientSearch');
        const liveSearchResults = document.getElementById('liveSearchResults');
        
        patientSearch.addEventListener('input', function() {
            const searchTerm = this.value.trim();
            
            if (searchTerm.length < 2) {
                liveSearchResults.style.display = 'none';
                return;
            }
            
            fetch(`search_patients.php?term=${encodeURIComponent(searchTerm)}`)
                .then(response => response.json())
                .then(patients => {
                    if (patients.length > 0) {
                        let html = '';
                        patients.forEach(patient => {
                            html += `
                                <a class="dropdown-item" href="lab_tests.php?patient_id=${patient.id}">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="patient-id">${patient.patient_id}</span>
                                            <span>${patient.first_name} ${patient.last_name}</span>
                                        </div>
                                        <span class="patient-phone">${patient.phone}</span>
                                    </div>
                                </a>
                            `;
                        });
                        liveSearchResults.innerHTML = html;
                        liveSearchResults.style.display = 'block';
                    } else {
                        liveSearchResults.innerHTML = '<div class="dropdown-item text-muted">No patients found</div>';
                        liveSearchResults.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    liveSearchResults.innerHTML = '<div class="dropdown-item text-muted">Error loading results</div>';
                    liveSearchResults.style.display = 'block';
                });
        });
        
        // Hide dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-container')) {
                liveSearchResults.style.display = 'none';
            }
        });
    </script>
</body>
</html>