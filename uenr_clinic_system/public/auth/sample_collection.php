<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'lab_scientist') {
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

// Initialize variables
$pendingRequests = [];
$collectedSamples = [];
$error = null;

// Handle sample collection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['collect_sample'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $requestId = $_POST['request_id'];
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("UPDATE test_requests SET status = 'Sample Collected', completed_by = ?, completed_at = NOW() WHERE id = ?");
            $stmt->execute([$user['id'], $requestId]);
            
            $stmt = $pdo->prepare("INSERT INTO sample_collections (request_id, collected_by, collection_date) VALUES (?, ?, NOW())");
            $stmt->execute([$requestId, $user['id']]);
            
            $pdo->commit();
            
            $_SESSION['success'] = "Sample collected successfully";
            logActivity($user['id'], "Collected sample for request ID: $requestId");
            header("Location: sample_collection.php");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Error recording sample collection: " . $e->getMessage();
        }
    }
}

// Get pending requests and collected samples
try {
    $stmt = $pdo->prepare("SELECT tr.*, p.first_name, p.last_name, p.patient_id, 
                          (SELECT GROUP_CONCAT(test_name SEPARATOR ', ') 
                           FROM test_request_items 
                           WHERE request_id = tr.id) as tests
                         FROM test_requests tr
                         JOIN patients p ON tr.patient_id = p.id
                         WHERE tr.status = 'Pending'
                         ORDER BY tr.request_date ASC");
    $stmt->execute();
    $pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Get all unique test names for the filter dropdown
$allTests = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT test_name FROM lab_tests ORDER BY test_name");
    $allTests = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $error = "Error fetching test names: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sample Collection - UENR Clinic</title>
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
        
        .test-badge {
            font-size: 0.75rem;
            margin-right: 0.3rem;
            margin-bottom: 0.3rem;
        }
        
        /* Search System Styles */
        .search-system {
            margin-bottom: 1.5rem;
        }
        
        .simple-search {
            position: relative;
            max-width: 400px;
            margin-bottom: 1rem;
        }
        
        .simple-search input {
            padding-left: 2.5rem;
        }
        
        .simple-search i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        
        .advanced-search-toggle {
            cursor: pointer;
            color: var(--secondary);
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .advanced-search-toggle:hover {
            text-decoration: underline;
        }
        
        .advanced-search-panel {
            background-color: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-top: 1rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
            display: none;
        }
        
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .date-range-group {
            display: flex;
            gap: 1rem;
        }
        
        .date-range-group .form-group {
            flex: 1;
        }
        
        .search-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 1rem;
        }
        
        .active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .filter-tag {
            background-color: var(--light);
            border-radius: 1rem;
            padding: 0.25rem 0.75rem;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
        }
        
        .filter-tag .remove-filter {
            margin-left: 0.5rem;
            cursor: pointer;
            color: var(--danger);
        }
        
        /* Highlight matching text */
        .highlight {
            background-color: #fff3cd;
            font-weight: bold;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .date-range-group {
                flex-direction: column;
                gap: 0.5rem;
            }
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
                        <a href="sample_collection.php" class="nav-link active">
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
                        <h2 class="mb-1"><i class="bi bi-droplet me-2"></i>Sample Collection</h2>
                        
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

                <!-- Search System -->
                <div class="search-system">
                    <div class="simple-search">
                        <i class="bi bi-search"></i>
                        <input type="text" id="globalSearch" class="form-control" placeholder="Search all samples...">
                    </div>
                    
                    <div class="advanced-search-toggle" id="toggleAdvancedSearch">
                        <i class="bi bi-funnel-fill"></i>
                        <span>Advanced Search</span>
                    </div>
                    
                    <div class="advanced-search-panel" id="advancedSearchPanel">
                        <div class="filter-row">
                            <div class="filter-group">
                                <label for="searchRequestId" class="form-label">Request ID</label>
                                <input type="text" class="form-control" id="searchRequestId" placeholder="LAB-123">
                            </div>
                            <div class="filter-group">
                                <label for="searchPatient" class="form-label">Patient Name</label>
                                <input type="text" class="form-control" id="searchPatient" placeholder="John Doe">
                            </div>
                            <div class="filter-group">
                                <label for="searchPatientId" class="form-label">Patient ID</label>
                                <input type="text" class="form-control" id="searchPatientId" placeholder="PT-123">
                            </div>
                        </div>
                        
                        <div class="filter-row">
                            <div class="filter-group">
                                <label for="searchTest" class="form-label">Test Type</label>
                                <select class="form-select" id="searchTest">
                                    <option value="">All Tests</option>
                                    <?php foreach ($allTests as $test): ?>
                                        <option value="<?php echo htmlspecialchars($test); ?>"><?php echo htmlspecialchars($test); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label class="form-label">Date Range</label>
                                <div class="date-range-group">
                                    <div class="form-group">
                                        <input type="date" class="form-control" id="startDate" placeholder="From">
                                    </div>
                                    <div class="form-group">
                                        <input type="date" class="form-control" id="endDate" placeholder="To">
                                    </div>
                                </div>
                            </div>
                            <div class="filter-group">
                                <label for="searchStatus" class="form-label">Status</label>
                                <select class="form-select" id="searchStatus">
                                    <option value="">All Statuses</option>
                                    <option value="pending">Pending</option>
                                    <option value="collected">Collected</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="search-actions">
                            <button class="btn btn-primary" id="applyFilters">
                                <i class="bi bi-search"></i> Apply Filters
                            </button>
                            <button class="btn btn-outline-secondary" id="resetFilters">
                                <i class="bi bi-x-circle"></i> Reset
                            </button>
                        </div>
                        
                        <div class="active-filters" id="activeFilters"></div>
                    </div>
                </div>

                <!-- Pending Samples -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-hourglass me-2"></i>Pending Collection</h5>
                        <span class="badge bg-warning"><?php echo count($pendingRequests); ?> pending</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($pendingRequests)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" id="pendingTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Request ID</th>
                                            <th>Patient</th>
                                            <th>Tests Requested</th>
                                            <th>Request Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pendingRequests as $request): ?>
                                            <tr data-id="LAB-<?php echo $request['id']; ?>" 
                                                data-patient="<?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?>"
                                                data-patient-id="<?php echo htmlspecialchars($request['patient_id']); ?>"
                                                data-tests="<?php echo htmlspecialchars($request['tests'] ?? ''); ?>"
                                                data-date="<?php echo date('Y-m-d', strtotime($request['request_date'])); ?>"
                                                data-status="pending">
                                                <td>LAB-<?php echo $request['id']; ?></td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></div>
                                                    <small class="text-muted">ID: <?php echo htmlspecialchars($request['patient_id']); ?></small>
                                                </td>
                                                <td>
                                                    <?php if (!empty($request['tests'])): ?>
                                                        <?php foreach (explode(', ', $request['tests']) as $test): ?>
                                                            <span class="badge bg-primary test-badge"><?php echo htmlspecialchars($test); ?></span>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">No tests specified</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('M j, Y H:i', strtotime($request['request_date'])); ?></td>
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
                            <div id="pendingNoResults" class="empty-state" style="display: none;">
                                <i class="bi bi-search"></i>
                                <h5>No matching pending samples</h5>
                                <p class="text-muted">Try adjusting your search filters</p>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-check-circle"></i>
                                <h5>No pending samples</h5>
                                <p class="text-muted">All samples have been collected</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Collected Samples -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-droplet me-2"></i>Recently Collected</h5>
                        <span class="badge bg-success"><?php echo count($collectedSamples); ?> collected</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($collectedSamples)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" id="collectedTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Request ID</th>
                                            <th>Patient</th>
                                            <th>Tests</th>
                                            <th>Collection Time</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($collectedSamples as $sample): ?>
                                            <tr data-id="LAB-<?php echo $sample['id']; ?>" 
                                                data-patient="<?php echo htmlspecialchars($sample['first_name'] . ' ' . $sample['last_name']); ?>"
                                                data-patient-id="<?php echo htmlspecialchars($sample['patient_id']); ?>"
                                                data-tests="<?php echo htmlspecialchars($sample['tests'] ?? ''); ?>"
                                                data-date="<?php echo date('Y-m-d', strtotime($sample['collection_date'])); ?>"
                                                data-status="collected">
                                                <td>LAB-<?php echo $sample['id']; ?></td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($sample['first_name'] . ' ' . $sample['last_name']); ?></div>
                                                    <small class="text-muted">ID: <?php echo htmlspecialchars($sample['patient_id']); ?></small>
                                                </td>
                                                <td>
                                                    <?php if (!empty($sample['tests'])): ?>
                                                        <?php foreach (explode(', ', $sample['tests']) as $test): ?>
                                                            <span class="badge bg-info test-badge"><?php echo htmlspecialchars($test); ?></span>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">No tests specified</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('M j, Y H:i', strtotime($sample['collection_date'])); ?></td>
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
                            <div id="collectedNoResults" class="empty-state" style="display: none;">
                                <i class="bi bi-search"></i>
                                <h5>No matching collected samples</h5>
                                <p class="text-muted">Try adjusting your search filters</p>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-droplet"></i>
                                <h5>No recently collected samples</h5>
                                <p class="text-muted">No samples collected in the last 24 hours</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Simple confirmation for actions
            document.querySelectorAll('[data-confirm]').forEach(element => {
                element.addEventListener('click', (e) => {
                    if (!confirm(element.dataset.confirm)) {
                        e.preventDefault();
                    }
                });
            });

            // Search System Variables
            const toggleSearch = document.getElementById('toggleAdvancedSearch');
            const searchPanel = document.getElementById('advancedSearchPanel');
            const applyFilters = document.getElementById('applyFilters');
            const resetFilters = document.getElementById('resetFilters');
            const activeFilters = document.getElementById('activeFilters');
            const globalSearch = document.getElementById('globalSearch');
            const pendingTable = document.getElementById('pendingTable');
            const collectedTable = document.getElementById('collectedTable');
            const pendingNoResults = document.getElementById('pendingNoResults');
            const collectedNoResults = document.getElementById('collectedNoResults');
            const pendingRows = Array.from(pendingTable.querySelectorAll('tbody tr'));
            const collectedRows = Array.from(collectedTable.querySelectorAll('tbody tr'));
            
            // Current filters state
            let currentFilters = {
                requestId: '',
                patientName: '',
                patientId: '',
                testType: '',
                startDate: '',
                endDate: '',
                status: '',
                globalSearch: ''
            };
            
            // Toggle advanced search panel
            toggleSearch.addEventListener('click', function() {
                searchPanel.style.display = searchPanel.style.display === 'none' ? 'block' : 'none';
            });
            
            // Apply filters from advanced search
            applyFilters.addEventListener('click', function() {
                currentFilters.requestId = document.getElementById('searchRequestId').value.trim();
                currentFilters.patientName = document.getElementById('searchPatient').value.trim().toLowerCase();
                currentFilters.patientId = document.getElementById('searchPatientId').value.trim().toLowerCase();
                currentFilters.testType = document.getElementById('searchTest').value;
                currentFilters.startDate = document.getElementById('startDate').value;
                currentFilters.endDate = document.getElementById('endDate').value;
                currentFilters.status = document.getElementById('searchStatus').value;
                
                // Clear global search when using advanced filters
                globalSearch.value = '';
                currentFilters.globalSearch = '';
                
                applyCurrentFilters();
            });
            
            // Reset all filters
            resetFilters.addEventListener('click', function() {
                document.getElementById('searchRequestId').value = '';
                document.getElementById('searchPatient').value = '';
                document.getElementById('searchPatientId').value = '';
                document.getElementById('searchTest').value = '';
                document.getElementById('startDate').value = '';
                document.getElementById('endDate').value = '';
                document.getElementById('searchStatus').value = '';
                globalSearch.value = '';
                
                currentFilters = {
                    requestId: '',
                    patientName: '',
                    patientId: '',
                    testType: '',
                    startDate: '',
                    endDate: '',
                    status: '',
                    globalSearch: ''
                };
                
                activeFilters.innerHTML = '';
                applyCurrentFilters();
            });
            
            // Global search handler
            globalSearch.addEventListener('input', function() {
                currentFilters.globalSearch = this.value.trim().toLowerCase();
                
                // Clear advanced filters when using global search
                if (currentFilters.globalSearch) {
                    document.getElementById('searchRequestId').value = '';
                    document.getElementById('searchPatient').value = '';
                    document.getElementById('searchPatientId').value = '';
                    document.getElementById('searchTest').value = '';
                    document.getElementById('startDate').value = '';
                    document.getElementById('endDate').value = '';
                    document.getElementById('searchStatus').value = '';
                    
                    currentFilters.requestId = '';
                    currentFilters.patientName = '';
                    currentFilters.patientId = '';
                    currentFilters.testType = '';
                    currentFilters.startDate = '';
                    currentFilters.endDate = '';
                    currentFilters.status = '';
                }
                
                applyCurrentFilters();
            });
            
            // Apply current filters to both tables
            function applyCurrentFilters() {
                // Update active filters display
                updateActiveFilters();
                
                // Filter pending samples
                filterTable(pendingRows, pendingNoResults);
                
                // Filter collected samples
                filterTable(collectedRows, collectedNoResults);
            }
            
            // Filter a table based on current filters
            function filterTable(rows, noResultsElement) {
                let hasResults = false;
                
                // Remove previous highlights
                document.querySelectorAll('.highlight').forEach(el => {
                    el.outerHTML = el.innerHTML;
                });
                
                rows.forEach(row => {
                    const rowId = row.getAttribute('data-id').toLowerCase();
                    const rowPatient = row.getAttribute('data-patient').toLowerCase();
                    const rowPatientId = row.getAttribute('data-patient-id').toLowerCase();
                    const rowTests = row.getAttribute('data-tests').toLowerCase();
                    const rowDate = row.getAttribute('data-date');
                    const rowStatus = row.getAttribute('data-status');
                    
                    // Check if row matches all filters
                    const matchesRequestId = !currentFilters.requestId || rowId.includes(currentFilters.requestId.toLowerCase());
                    const matchesPatient = !currentFilters.patientName || rowPatient.includes(currentFilters.patientName);
                    const matchesPatientId = !currentFilters.patientId || rowPatientId.includes(currentFilters.patientId);
                    const matchesTestType = !currentFilters.testType || rowTests.includes(currentFilters.testType.toLowerCase());
                    const matchesDateRange = (!currentFilters.startDate || rowDate >= currentFilters.startDate) && 
                                           (!currentFilters.endDate || rowDate <= currentFilters.endDate);
                    const matchesStatus = !currentFilters.status || rowStatus === currentFilters.status;
                    const matchesGlobalSearch = !currentFilters.globalSearch || 
                        rowId.includes(currentFilters.globalSearch) ||
                        rowPatient.includes(currentFilters.globalSearch) ||
                        rowPatientId.includes(currentFilters.globalSearch) ||
                        rowTests.includes(currentFilters.globalSearch);
                    
                    if ((matchesRequestId && matchesPatient && matchesPatientId && 
                         matchesTestType && matchesDateRange && matchesStatus) ||
                        (currentFilters.globalSearch && matchesGlobalSearch)) {
                        row.style.display = '';
                        hasResults = true;
                        
                        // Highlight matching text if global search is active
                        if (currentFilters.globalSearch) {
                            const cells = row.querySelectorAll('td');
                            cells.forEach(cell => {
                                cell.innerHTML = cell.textContent.replace(
                                    new RegExp(currentFilters.globalSearch, 'gi'), 
                                    match => `<span class="highlight">${match}</span>`
                                );
                            });
                        } else {
                            // Highlight specific matches from advanced search
                            if (currentFilters.requestId) {
                                const idCell = row.querySelector('td:first-child');
                                idCell.innerHTML = idCell.textContent.replace(
                                    new RegExp(currentFilters.requestId, 'gi'), 
                                    match => `<span class="highlight">${match}</span>`
                                );
                            }
                            
                            if (currentFilters.patientName) {
                                const patientCell = row.querySelector('td:nth-child(2)');
                                patientCell.innerHTML = patientCell.textContent.replace(
                                    new RegExp(currentFilters.patientName, 'gi'), 
                                    match => `<span class="highlight">${match}</span>`
                                );
                            }
                        }
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                // Show/hide no results message
                if (noResultsElement) {
                    noResultsElement.style.display = hasResults ? 'none' : 'block';
                }
            }
            
            // Update active filters display
            function updateActiveFilters() {
                activeFilters.innerHTML = '';
                
                // Show global search tag if active
                if (currentFilters.globalSearch) {
                    addFilterTag('Search', currentFilters.globalSearch, 'globalSearch');
                    return;
                }
                
                // Show advanced filter tags
                if (currentFilters.requestId) {
                    addFilterTag('Request ID', currentFilters.requestId, 'requestId');
                }
                if (currentFilters.patientName) {
                    addFilterTag('Patient', currentFilters.patientName, 'patientName');
                }
                if (currentFilters.patientId) {
                    addFilterTag('Patient ID', currentFilters.patientId, 'patientId');
                }
                if (currentFilters.testType) {
                    addFilterTag('Test', currentFilters.testType, 'testType');
                }
                if (currentFilters.startDate || currentFilters.endDate) {
                    const dateText = `${currentFilters.startDate || 'Any'} to ${currentFilters.endDate || 'Any'}`;
                    addFilterTag('Date Range', dateText, 'dateRange');
                }
                if (currentFilters.status) {
                    addFilterTag('Status', currentFilters.status, 'status');
                }
            }
            
            // Add a filter tag to the active filters display
            function addFilterTag(label, value, filterKey) {
                const tag = document.createElement('div');
                tag.className = 'filter-tag';
                tag.innerHTML = `
                    <span>${label}: ${value}</span>
                    <span class="remove-filter"><i class="bi bi-x"></i></span>
                `;
                
                tag.querySelector('.remove-filter').addEventListener('click', function() {
                    // Remove this filter
                    if (filterKey === 'globalSearch') {
                        currentFilters.globalSearch = '';
                        globalSearch.value = '';
                    } 
                    else if (filterKey === 'requestId') {
                        currentFilters.requestId = '';
                        document.getElementById('searchRequestId').value = '';
                    }
                    else if (filterKey === 'patientName') {
                        currentFilters.patientName = '';
                        document.getElementById('searchPatient').value = '';
                    }
                    else if (filterKey === 'patientId') {
                        currentFilters.patientId = '';
                        document.getElementById('searchPatientId').value = '';
                    }
                    else if (filterKey === 'testType') {
                        currentFilters.testType = '';
                        document.getElementById('searchTest').value = '';
                    }
                    else if (filterKey === 'dateRange') {
                        currentFilters.startDate = '';
                        currentFilters.endDate = '';
                        document.getElementById('startDate').value = '';
                        document.getElementById('endDate').value = '';
                    }
                    else if (filterKey === 'status') {
                        currentFilters.status = '';
                        document.getElementById('searchStatus').value = '';
                    }
                    
                    // Re-apply filters
                    applyCurrentFilters();
                });
                
                activeFilters.appendChild(tag);
            }
        });
    </script>
</body>
</html>