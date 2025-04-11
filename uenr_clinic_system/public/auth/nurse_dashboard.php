<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

// Get current user with proper error handling
$user = getCurrentUser();
if (!$user || !isset($user['role']) || $user['role'] != 'nurse') {
    header("Location: login.php");
    exit();
}

// Initialize variables to prevent undefined variable warnings
$recentVitals = [];
$upcomingAppointments = [];
$recentPatients = [];
$todayAppointmentsCount = 0;
$lastLogin = isset($user['last_login']) ? $user['last_login'] : 'Never';

try {
    // Get recent vitals records
    $stmt = $pdo->prepare("SELECT v.*, p.first_name, p.last_name, p.patient_id 
                         FROM vitals v 
                         JOIN patients p ON v.patient_id = p.id 
                         ORDER BY v.recorded_at DESC 
                         LIMIT 5");
    $stmt->execute();
    $recentVitals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get upcoming appointments
    $stmt = $pdo->prepare("SELECT 
        a.*, 
        p.first_name, 
        p.last_name,
        d.name AS department
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN departments d ON a.department_id = d.id
    WHERE a.appointment_date >= NOW()
    ORDER BY a.appointment_date ASC
    LIMIT 10");
    $stmt->execute();
    $upcomingAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count today's appointments
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM appointments 
                          WHERE DATE(appointment_date) = CURDATE()");
    $stmt->execute();
    $todayAppointmentsCount = $stmt->fetchColumn();

    // Get recently registered patients (last 7 days)
    $stmt = $pdo->prepare("SELECT *, 
                          TIMESTAMPDIFF(YEAR, dob, CURDATE()) as age 
                          FROM patients 
                          WHERE DATE(registered_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                          ORDER BY registered_at DESC 
                          LIMIT 5");
    $stmt->execute();
    $recentPatients = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $_SESSION['error'] = "A database error occurred. Please try again later.";
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
        .quick-action-card {
            border-left: 4px solid var(--secondary);
            height: 100%;
            text-align: center;
        }
        
        .quick-action-icon {
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
                        <a href="nurse_dashboard.php" class="nav-link active">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                        <a href="manage_patients_nurse.php" class="nav-link">
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
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
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
                                        <h6 class="text-muted mb-2">Today's Appointments</h6>
                                        <h3 class="mb-0"><?php echo $todayAppointmentsCount; ?></h3>
                                    </div>
                                    <div class="bg-primary bg-opacity-10 p-3 rounded">
                                        <i class="bi bi-calendar-check text-primary" style="font-size: 1.5rem;"></i>
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
                                        <h6 class="text-muted mb-2">Upcoming Appointments</h6>
                                        <h3 class="mb-0"><?php echo count($upcomingAppointments); ?></h3>
                                    </div>
                                    <div class="bg-info bg-opacity-10 p-3 rounded">
                                        <i class="bi bi-calendar-plus text-info" style="font-size: 1.5rem;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-start border-success border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-2">Last Login</h6>
                                        <h5 class="mb-0"><?php echo $lastLogin === 'Never' ? 'Never' : date('M j, Y g:i a', strtotime($lastLogin)); ?></h5>
                                    </div>
                                    <div class="bg-success bg-opacity-10 p-3 rounded">
                                        <i class="bi bi-clock-history text-success" style="font-size: 1.5rem;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row mb-4">
                    <div class="col-md-6 col-lg-3">
                        <div class="card quick-action-card">
                            <div class="card-body">
                                <i class="bi bi-people quick-action-icon"></i>
                                <h5 class="card-title">Manage Patients</h5>
                                <p class="card-text">Register and manage patient records</p>
                                <a href="manage_patients.php" class="btn btn-primary">Go to Patients</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="card quick-action-card">
                            <div class="card-body">
                                <i class="bi bi-heart-pulse quick-action-icon"></i>
                                <h5 class="card-title">Record Vitals</h5>
                                <p class="card-text">Take and record patient vital signs</p>
                                <a href="record_vitals.php" class="btn btn-primary">Record Vitals</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="card quick-action-card">
                            <div class="card-body">
                                <i class="bi bi-calendar-plus quick-action-icon"></i>
                                <h5 class="card-title">Schedule Appointment</h5>
                                <p class="card-text">Book appointments for patients</p>
                                <a href="schedule_appointment.php" class="btn btn-primary">Schedule Now</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="card quick-action-card">
                            <div class="card-body">
                                <i class="bi bi-calendar-check quick-action-icon"></i>
                                <h5 class="card-title">Track Appointments</h5>
                                <p class="card-text">View and manage appointments</p>
                                <a href="track_appointments.php" class="btn btn-primary">View Appointments</a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recently Registered Patients -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-people me-2"></i>Recently Registered Patients</h5>
                        <span class="badge bg-primary"><?php echo count($recentPatients); ?> new</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($recentPatients)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Patient ID</th>
                                            <th>Name</th>
                                            <th>Gender</th>
                                            <th>Age</th>
                                            <th>Phone</th>
                                            <th>Type</th>
                                            <th>Registered On</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentPatients as $patient): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($patient['patient_id']); ?></td>
                                                <td><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></td>
                                                <td><?php echo htmlspecialchars($patient['gender']); ?></td>
                                                <td><?php echo htmlspecialchars($patient['age']); ?> yrs</td>
                                                <td><?php echo htmlspecialchars($patient['phone']); ?></td>
                                                <td><?php echo htmlspecialchars($patient['patient_type'] ?? 'N/A'); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($patient['registered_at'])); ?></td>
                                                <td>
                                                    <a href="nurse_view_patient.php?id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-info" title="View Details">
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
                            <div class="p-3">
                                <a href="manage_patients.php" class="btn btn-outline-primary">View All Patients</a>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-people"></i>
                                <h5>No recent patients</h5>
                                <p class="text-muted">No patients registered in the last 7 days</p>
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