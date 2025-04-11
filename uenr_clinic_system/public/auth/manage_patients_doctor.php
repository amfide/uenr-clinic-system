<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'doctor') {
    header("Location: /public/dashboards/{$user['role']}_dashboard.php");
    exit();
}

// Search functionality
$search = isset($_GET['search']) ? $_GET['search'] : '';

try {
    $sql = "SELECT *, TIMESTAMPDIFF(YEAR, dob, CURDATE()) as age FROM patients WHERE 1=1";
    $params = [];
    
    if (!empty($search)) {
        $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR patient_id LIKE ? OR phone LIKE ?)";
        $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
    }
    
    $sql .= " ORDER BY last_name, first_name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $_SESSION['error'] = "Failed to load patients. Please try again later.";
    $patients = [];
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
        
        .patient-photo {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid var(--light);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
        
        .search-card .form-control {
            border-radius: 0.5rem;
            border-right: none;
        }
        
        .search-card .btn {
            border-radius: 0.5rem;
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
                        <a href="manage_patients_doctor.php" class="nav-link active">
                            <i class="bi bi-people-fill"></i> Patient Management
                        </a>
                        <a href="appointments.php" class="nav-link">
                            <i class="bi bi-calendar-check"></i> Appointments
                        </a>
                        <a href="lab_tests.php" class="nav-link">
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
                        <h2 class="mb-1">Patient Management</h2>
                        <p class="text-muted mb-0">Manage patient records and information</p>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-light text-dark">
                            <i class="bi bi-calendar"></i> <?php echo date('l, F j, Y'); ?>
                        </span>
                    </div>
                </div>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Search Card -->
                <div class="card search-card">
                    <div class="card-body">
                        <form method="get" class="row g-2">
                            <div class="col-md-8">
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control" 
                                           placeholder="Search patients by name, ID or phone..." 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search"></i> Search
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <a href="manage_patients_doctor.php" class="btn btn-outline-secondary w-100">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Patients Table Card -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-people-fill me-2"></i>Patient Records</h5>
                        <span class="badge bg-primary"><?php echo count($patients); ?> found</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($patients)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th></th>
                                            <th>Patient</th>
                                            <th>ID</th>
                                            <th>Age</th>
                                            <th>Gender</th>
                                            <th>Phone</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($patients as $patient): ?>
                                            <tr>
                                                <td>
                                                    <img src="<?php echo !empty($patient['photo']) ? htmlspecialchars($patient['photo']) : 'assets/images/default_profile.jpg'; ?>" 
                                                         alt="Profile" class="patient-photo">
                                                </td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></div>
                                                </td>
                                                <td><?php echo htmlspecialchars($patient['patient_id']); ?></td>
                                                <td><?php echo htmlspecialchars($patient['age']); ?> yrs</td>
                                                <td><?php echo htmlspecialchars($patient['gender']); ?></td>
                                                <td><?php echo htmlspecialchars($patient['phone']); ?></td>
                                                <td>
                                                    <a href="patient_management.php?id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-person-lines-fill"></i> View
                                                    </a>
                                                    <a href="medical_record.php?patient_id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-info">
                                                        <i class="bi bi-file-earmark-medical"></i> Record
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-people"></i>
                                <h5>No patients found</h5>
                                <p class="text-muted"><?php echo empty($search) ? 'No patients in the system' : 'No patients match your search criteria'; ?></p>
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