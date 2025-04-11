<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'nurse') {
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

// Get recent patients (last 30 days)
$recentPatients = [];
$stmt = $pdo->prepare("SELECT * FROM patients WHERE registered_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY registered_at DESC");
$stmt->execute();
$recentPatients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent vitals records
$recentVitals = [];
$stmt = $pdo->prepare("SELECT v.*, p.first_name, p.last_name, p.patient_id 
                     FROM vitals v 
                     JOIN patients p ON v.patient_id = p.id 
                     ORDER BY v.recorded_at DESC 
                     LIMIT 5");
$stmt->execute();
$recentVitals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming appointments (updated query)
$stmt = $pdo->prepare("SELECT 
    a.*, 
    p.first_name, 
    p.last_name, 
    p.patient_id,
    d.name AS department
FROM appointments a 
JOIN patients p ON a.patient_id = p.id
LEFT JOIN departments d ON a.department_id = d.id
WHERE a.appointment_date >= NOW() 
ORDER BY a.appointment_date ASC 
LIMIT 10");
$stmt->execute();
$upcomingAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Search patients
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
    <title>Nurse Dashboard - UENR Clinic</title>
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
            max-height: 500px;
            overflow-y: auto;
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
        
        /* Nurse-specific additions */
        .quick-stat-card {
            text-align: center;
            height: 100%;
        }
        
        .quick-stat-icon {
            font-size: 2.5rem;
            color: var(--secondary);
            margin-bottom: 1rem;
        }
        
        .badge-scheduled {
            background-color: var(--warning);
            color: var(--dark);
        }
        
        .badge-confirmed {
            background-color: var(--info);
            color: white;
        }
        
        .badge-completed {
            background-color: var(--success);
            color: white;
        }
        
        .search-card {
            border-left: 4px solid var(--primary);
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
                        <a href="nurse_dashboard.php" class="nav-link">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                        <a href="manage_patients_nurse.php" class="nav-link active">
                            <i class="bi bi-people"></i> Manage Patients
                        </a>
                        <a href="record_vitals.php" class="nav-link">
                            <i class="bi bi-heart-pulse"></i> Record Vitals
                        </a>
                        <a href="schedule_appointment.php" class="nav-link">
                            <i class="bi bi-calendar-plus"></i> Schedule Appointment
                        </a>
                        <a href="track_appointments.php" class="nav-link">
                            <i class="bi bi-calendar-check"></i> Track Appointments
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
                        <h2 class="mb-1">Nurse Dashboard</h2>
                        <p class="text-muted mb-0">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?></p>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-light text-dark">
                            <i class="bi bi-calendar"></i> <?php echo date('l, F j, Y'); ?>
                        </span>
                    </div>
                </div>
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Quick Stats -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card border-start border-primary border-4 quick-stat-card">
                            <div class="card-body">
                                <i class="bi bi-people quick-stat-icon"></i>
                                <h5>Total Patients</h5>
                                <p class="display-6"><?php echo count($recentPatients); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-start border-info border-4 quick-stat-card">
                            <div class="card-body">
                                <i class="bi bi-heart-pulse quick-stat-icon"></i>
                                <h5>Vitals Recorded</h5>
                                <p class="display-6"><?php echo count($recentVitals); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-start border-success border-4 quick-stat-card">
                            <div class="card-body">
                                <i class="bi bi-calendar-check quick-stat-icon"></i>
                                <h5>Upcoming Appointments</h5>
                                <p class="display-6"><?php echo count($upcomingAppointments); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Patient Search -->
                <div class="card search-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-search me-2"></i>Search Patients</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="mb-4">
                            <div class="input-group">
                                <input type="text" class="form-control" name="search" placeholder="Search by Patient ID, Name or Phone" value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                                <button class="btn btn-outline-secondary" type="submit">Search</button>
                                <?php if (isset($_GET['search'])): ?>
                                    <a href="nurse_dashboard.php" class="btn btn-outline-danger">Clear</a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <?php if (!empty($searchResults)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Patient ID</th>
                                            <th>Name</th>
                                            <th>Gender</th>
                                            <th>Phone</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($searchResults as $patient): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($patient['patient_id']); ?></td>
                                                <td><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></td>
                                                <td><?php echo htmlspecialchars($patient['gender']); ?></td>
                                                <td><?php echo htmlspecialchars($patient['phone']); ?></td>
                                                <td>
                                                    <a href="nurse_view_patient.php?id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-info" title="View">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="record_vitals.php?patient_id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-success" title="Record Vitals">
                                                        <i class="bi bi-heart-pulse"></i>
                                                    </a>
                                                    <a href="schedule_appointment.php?patient_id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-warning" title="Schedule Appointment">
                                                        <i class="bi bi-calendar-plus"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif (isset($_GET['search'])): ?>
                            <div class="empty-state">
                                <i class="bi bi-search"></i>
                                <h5>No patients found</h5>
                                <p class="text-muted">No patients match your search criteria</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Activity Section -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0"><i class="bi bi-heart-pulse me-2"></i>Recent Vitals Records</h5>
                                <span class="badge bg-info"><?php echo count($recentVitals); ?> records</span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (!empty($recentVitals)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Patient</th>
                                                    <th>Temp</th>
                                                    <th>BP</th>
                                                    <th>Pulse</th>
                                                    <th>Time</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recentVitals as $vital): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($vital['first_name'] . ' ' . $vital['last_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($vital['temperature']); ?>°C</td>
                                                        <td><?php echo htmlspecialchars($vital['blood_pressure']); ?></td>
                                                        <td><?php echo htmlspecialchars($vital['pulse_rate']); ?> bpm</td>
                                                        <td><?php echo date('H:i', strtotime($vital['recorded_at'])); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="p-3">
                                        <a href="vitals_records.php" class="btn btn-outline-info">View All Vitals</a>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="bi bi-heart-pulse"></i>
                                        <h5>No recent vitals</h5>
                                        <p class="text-muted">No vital signs recorded yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0"><i class="bi bi-calendar-check me-2"></i>Upcoming Appointments</h5>
                                <span class="badge bg-success"><?php echo count($upcomingAppointments); ?> upcoming</span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (!empty($upcomingAppointments)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Patient</th>
                                                    <th>Time</th>
                                                    <th>Department</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($upcomingAppointments as $appointment): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']); ?></td>
                                                        <td><?php echo date('M j, H:i', strtotime($appointment['appointment_date'])); ?></td>
                                                        <td><?php echo htmlspecialchars($appointment['department']); ?></td>
                                                        <td>
                                                            <?php 
                                                            $statusClass = '';
                                                            switch($appointment['status']) {
                                                                case 'Scheduled': $statusClass = 'badge-scheduled'; break;
                                                                case 'Confirmed': $statusClass = 'badge-confirmed'; break;
                                                                case 'Completed': $statusClass = 'badge-completed'; break;
                                                            }
                                                            ?>
                                                            <span class="badge status-badge <?php echo $statusClass; ?>">
                                                                <?php echo htmlspecialchars($appointment['status']); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="p-3">
                                        <a href="track_appointments.php" class="btn btn-outline-success">View All Appointments</a>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="bi bi-calendar-check"></i>
                                        <h5>No upcoming appointments</h5>
                                        <p class="text-muted">No appointments scheduled yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
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