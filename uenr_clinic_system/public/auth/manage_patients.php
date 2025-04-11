<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'records_keeper') {
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

// Get recent patients (last 30 days)
$recentPatients = [];
$stmt = $pdo->prepare("SELECT * FROM patients WHERE registered_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY registered_at DESC");
$stmt->execute();
$recentPatients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Search patients (for form submission)
$searchResults = [];
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['search'])) {
    $searchTerm = "%{$_GET['search']}%";
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR phone LIKE ?");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Patients - UENR Clinic</title>
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
        
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: var(--dark);
            white-space: nowrap;
            position: sticky;
            top: 0;
            background-color: white;
        }
        
        .table-responsive {
            border-radius: 0.5rem;
            max-height: 500px;
            overflow-y: auto;
        }
        
        /* Search box styles */
        .search-container {
            position: relative;
            margin-bottom: 2rem; /* Added margin to create space below search */
        }
        
        #searchResults {
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
        
        #searchResults .dropdown-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #f8f9fa;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        #searchResults .dropdown-item:hover {
            background-color: #f8f9fa;
        }
        
        #searchResults .patient-id {
            font-weight: bold;
            margin-right: 10px;
        }
        
        #searchResults .patient-phone {
            font-size: 0.85rem;
            color: #6c757d;
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
        
        .badge {
            font-weight: 500;
            padding: 0.35em 0.65em;
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
        
        .status-badge {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Modal styles */
        .patient-modal .modal-header {
            background-color: var(--primary);
            color: white;
        }
        
        .patient-info-row {
            margin-bottom: 1rem;
        }
        
        .patient-info-label {
            font-weight: 600;
            color: var(--dark);
        }
        
        .patient-info-value {
            color: #495057;
        }
        
        .quick-view-btn {
            cursor: pointer;
        }
        
        /* Added space between search results and recent patients */
        .search-results-section {
            margin-bottom: 2.5rem;
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
                        <a href="records_keeper_dashboard.php" class="nav-link">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                        <a href="manage_patients.php" class="nav-link active">
                            <i class="bi bi-people"></i> Manage Patients
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
                        <h2 class="mb-1">Patient Management</h2>
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

                <!-- Patient Search -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-search me-2"></i>Patient Search</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="mb-4">
                            <div class="search-container">
                                <input type="text" class="form-control" id="patientSearch" 
                                       name="search" placeholder="Search by Patient ID, Name or Phone" 
                                       autocomplete="off" value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                                <button class="btn btn-primary" type="submit" style="position: absolute; right: 0; top: 0; height: 100%; border-radius: 0 0.25rem 0.25rem 0;">
                                    <i class="bi bi-search"></i>
                                </button>
                                <div id="searchResults" class="dropdown-menu"></div>
                            </div>
                            <?php if (isset($_GET['search'])): ?>
                                <a href="manage_patients.php" class="btn btn-outline-danger mt-2">
                                    <i class="bi bi-x"></i> Clear Search
                                </a>
                            <?php endif; ?>
                        </form>

                        <?php if (!empty($searchResults)): ?>
                            <div class="search-results-section"> <!-- Added wrapper with margin class -->
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Patient ID</th>
                                                <th>Name</th>
                                                <th>Gender</th>
                                                <th>Age</th>
                                                <th>Phone</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($searchResults as $patient): 
                                                $dob = new DateTime($patient['dob']);
                                                $now = new DateTime();
                                                $age = $dob->diff($now)->y;
                                            ?>
                                                <tr class="quick-view-btn" data-patient-id="<?php echo $patient['id']; ?>">
                                                    <td class="fw-bold"><?php echo htmlspecialchars($patient['patient_id']); ?></td>
                                                    <td>
                                                        <div class="fw-bold"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></div>
                                                        <small class="text-muted"><?php echo date('M j, Y', strtotime($patient['dob'])); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($patient['gender']); ?></td>
                                                    <td><?php echo $age; ?> years</td>
                                                    <td><?php echo htmlspecialchars($patient['phone']); ?></td>
                                                    <td>
                                                        <div class="d-flex gap-2">
                                                            <button class="btn btn-sm btn-outline-primary view-patient-btn" 
                                                                    data-patient-id="<?php echo $patient['id']; ?>">
                                                                <i class="bi bi-eye"></i> Quick View
                                                            </button>
                                                            <a href="patient_details_records.php?id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                                                <i class="bi bi-pencil"></i> Edit
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php elseif (isset($_GET['search'])): ?>
                            <div class="empty-state">
                                <i class="bi bi-search"></i>
                                <h5>No patients found</h5>
                                <p class="text-muted">No records match your search criteria</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Patients 
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Recent Registrations</h5>
                        <span class="badge bg-primary">Last 30 Days</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentPatients)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Patient ID</th>
                                            <th>Name</th>
                                            <th>Gender</th>
                                            <th>Age</th>
                                            <th>Phone</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentPatients as $patient): 
                                            $dob = new DateTime($patient['dob']);
                                            $now = new DateTime();
                                            $age = $dob->diff($now)->y;
                                        ?>
                                            <tr class="quick-view-btn" data-patient-id="<?php echo $patient['id']; ?>">
                                                <td class="fw-bold"><?php echo htmlspecialchars($patient['patient_id']); ?></td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></div>
                                                    <small class="text-muted"><?php echo date('M j, Y', strtotime($patient['dob'])); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($patient['gender']); ?></td>
                                                <td><?php echo $age; ?> years</td>
                                                <td><?php echo htmlspecialchars($patient['phone']); ?></td>
                                                <td>
                                                    <div class="d-flex gap-2">
                                                        <button class="btn btn-sm btn-outline-primary view-patient-btn" 
                                                                data-patient-id="<?php echo $patient['id']; ?>">
                                                            <i class="bi bi-eye"></i> Quick View
                                                        </button>
                                                        <a href="patient_details_records.php?id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                                            <i class="bi bi-pencil"></i> Edit
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-people"></i>
                                <h5>No recent registrations</h5>
                                <p class="text-muted">No patients registered in the last 30 days</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>-->
            </div>
        </div>
    </div>

    <!-- Patient Quick View Modal -->
    <div class="modal fade patient-modal" id="patientQuickViewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-circle me-2"></i> Patient Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="patientModalContent">
                    <!-- Content will be loaded via AJAX -->
                    <div class="text-center my-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="#" id="fullDetailsLink" class="btn btn-primary">
                        <i class="bi bi-file-text"></i> Full Details
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize the modal
        const patientModal = new bootstrap.Modal(document.getElementById('patientQuickViewModal'));
        
        // Handle quick view button clicks and row clicks
        document.querySelectorAll('.view-patient-btn, .quick-view-btn').forEach(element => {
            element.addEventListener('click', function() {
                const patientId = this.getAttribute('data-patient-id') || 
                                 this.closest('tr').getAttribute('data-patient-id');
                loadPatientDetails(patientId);
            });
        });
        
        // Function to load patient details via AJAX
        function loadPatientDetails(patientId) {
            const modalContent = document.getElementById('patientModalContent');
            const fullDetailsLink = document.getElementById('fullDetailsLink');
            
            // Show loading spinner
            modalContent.innerHTML = `
                <div class="text-center my-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `;
            
            // Show the modal
            patientModal.show();
            
            // Set the full details link
            fullDetailsLink.href = `patient_details_records.php?id=${patientId}`;
            
            // Fetch patient details
            fetch(`get_patient_details.php?id=${patientId}`)
                .then(response => response.text())
                .then(data => {
                    modalContent.innerHTML = data;
                })
                .catch(error => {
                    modalContent.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill"></i> Error loading patient details
                        </div>
                    `;
                    console.error('Error:', error);
                });
        }
        
        // Live patient search functionality
        const patientSearch = document.getElementById('patientSearch');
        const searchResults = document.getElementById('searchResults');
        
        patientSearch.addEventListener('input', function() {
            const searchTerm = this.value.trim();
            
            if (searchTerm.length < 2) {
                searchResults.style.display = 'none';
                return;
            }
            
            fetch(`search_patients.php?term=${encodeURIComponent(searchTerm)}`)
                .then(response => response.json())
                .then(patients => {
                    if (patients.length > 0) {
                        let html = '';
                        patients.forEach(patient => {
                            html += `
                                <a class="dropdown-item" href="patient_details_records.php?id=${patient.id}">
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
                        searchResults.innerHTML = html;
                        searchResults.style.display = 'block';
                    } else {
                        searchResults.innerHTML = '<div class="dropdown-item text-muted">No patients found</div>';
                        searchResults.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    searchResults.innerHTML = '<div class="dropdown-item text-muted">Error loading results</div>';
                    searchResults.style.display = 'block';
                });
        });
        
        // Hide dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-container')) {
                searchResults.style.display = 'none';
            }
        });
        
        // Make table headers sticky when scrolling
        const tables = document.querySelectorAll('.table-responsive');
        tables.forEach(table => {
            table.addEventListener('scroll', () => {
                const thead = table.querySelector('thead');
                thead.style.transform = `translateY(${table.scrollTop}px)`;
            });
        });
    </script>
</body>
</html>