<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'doctor') {
    header("Location: /public/dashboards/{$user['role']}_dashboard.php");
    exit();
}

// Handle appointment confirmation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_appointment'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $appointmentId = $_POST['appointment_id'];
        
        try {
            $stmt = $pdo->prepare("UPDATE appointments SET status = 'Confirmed', confirmed_by = ?, confirmed_at = NOW() WHERE id = ?");
            $stmt->execute([$user['id'], $appointmentId]);
            
            $_SESSION['success'] = "Appointment confirmed successfully";
            logActivity($user['id'], "Confirmed appointment ID: $appointmentId");
        } catch (PDOException $e) {
            $error = "Error confirming appointment: " . $e->getMessage();
        }
    }
}

// Handle lab test request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_lab_test'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $patientId = $_POST['patient_id'];
        $testId = $_POST['test_id'];
        $notes = $_POST['notes'];
        
        try {
            $stmt = $pdo->prepare("INSERT INTO lab_requests (patient_id, requested_by, notes) VALUES (?, ?, ?)");
            $stmt->execute([$patientId, $user['id'], $notes]);
            $requestId = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("INSERT INTO lab_request_items (request_id, test_id) VALUES (?, ?)");
            $stmt->execute([$requestId, $testId]);
            
            $_SESSION['success'] = "Lab test requested successfully";
            logActivity($user['id'], "Requested lab test for patient ID: $patientId");
        } catch (PDOException $e) {
            $error = "Error requesting lab test: " . $e->getMessage();
        }
    }
}

// Handle prescription
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['prescribe_medicine'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $patientId = $_POST['patient_id'];
        $medicineId = $_POST['medicine_id'];
        $dosage = $_POST['dosage'];
        $frequency = $_POST['frequency'];
        $duration = $_POST['duration'];
        $notes = $_POST['notes'];
        
        try {
            $stmt = $pdo->prepare("INSERT INTO prescriptions (patient_id, prescribed_by, notes) VALUES (?, ?, ?)");
            $stmt->execute([$patientId, $user['id'], $notes]);
            $prescriptionId = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("INSERT INTO prescription_items (prescription_id, medicine_id, dosage, frequency, duration, quantity) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$prescriptionId, $medicineId, $dosage, $frequency, $duration, 1]);
            
            $_SESSION['success'] = "Medicine prescribed successfully";
            logActivity($user['id'], "Prescribed medicine for patient ID: $patientId");
        } catch (PDOException $e) {
            $error = "Error prescribing medicine: " . $e->getMessage();
        }
    }
}

// Initialize variables
$pendingAppointments = [];
$confirmedAppointments = [];
$todayAppointments = [];
$recentPatients = [];
$lastLogin = isset($user['last_login']) ? $user['last_login'] : 'Never';

try {
    // Get pending appointments (without department filter)
    $stmt = $pdo->prepare("SELECT a.*, p.first_name, p.last_name, p.patient_id 
                          FROM appointments a 
                          JOIN patients p ON a.patient_id = p.id 
                          WHERE a.status = 'Scheduled'
                          ORDER BY a.appointment_date ASC");
    $stmt->execute();
    $pendingAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get confirmed appointments (without department filter)
    $stmt = $pdo->prepare("SELECT a.*, p.first_name, p.last_name, p.patient_id 
                          FROM appointments a 
                          JOIN patients p ON a.patient_id = p.id 
                          WHERE a.status = 'Confirmed'
                          ORDER BY a.appointment_date ASC");
    $stmt->execute();
    $confirmedAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get today's appointments
    $stmt = $pdo->prepare("SELECT a.*, p.first_name, p.last_name, p.patient_id 
                          FROM appointments a 
                          JOIN patients p ON a.patient_id = p.id 
                          WHERE DATE(a.appointment_date) = CURDATE()
                          ORDER BY a.appointment_date ASC");
    $stmt->execute();
    $todayAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recently registered patients (last 7 days)
    $stmt = $pdo->prepare("SELECT *, 
                          TIMESTAMPDIFF(YEAR, dob, CURDATE()) as age 
                          FROM patients 
                          WHERE DATE(registered_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                          ORDER BY registered_at DESC 
                          LIMIT 5");
    $stmt->execute();
    $recentPatients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get lab tests
    $stmt = $pdo->prepare("SELECT * FROM lab_tests ORDER BY test_name");
    $stmt->execute();
    $labTests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get medicines
    $stmt = $pdo->prepare("SELECT * FROM medicines WHERE stock_quantity > 0 ORDER BY name");
    $stmt->execute();
    $medicines = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Doctor Dashboard - UENR Clinic</title>
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
        
        /* Custom status badges */
        .badge-scheduled {
            background-color: var(--warning);
            color: var(--dark);
        }
        
        .badge-confirmed {
            background-color: var(--success);
            color: white;
        }
        
        .badge-completed {
            background-color: var(--info);
            color: white;
        }
        
        .badge-cancelled {
            background-color: var(--danger);
            color: white;
        }
        
        /* Quick action cards */
        .card-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--secondary);
        }
        
        .action-btns .btn {
            margin-right: 5px;
            margin-bottom: 5px;
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
                        <a href="doctor_dashboard.php" class="nav-link active">
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
                        <h2 class="mb-1">Doctor Dashboard</h2>
                        <p class="text-muted mb-0">Welcome back, Dr. <?php echo htmlspecialchars($user['full_name']); ?></p>
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
                    <div class="col-md-3">
                        <div class="card border-start border-primary border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-2">Today's Appointments</h6>
                                        <h3 class="mb-0"><?php echo count($todayAppointments); ?></h3>
                                    </div>
                                    <div class="bg-primary bg-opacity-10 p-3 rounded">
                                        <i class="bi bi-calendar-check text-primary" style="font-size: 1.5rem;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-start border-warning border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-2">Pending Appointments</h6>
                                        <h3 class="mb-0"><?php echo count($pendingAppointments); ?></h3>
                                    </div>
                                    <div class="bg-warning bg-opacity-10 p-3 rounded">
                                        <i class="bi bi-hourglass text-warning" style="font-size: 1.5rem;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-start border-success border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-2">Confirmed Appointments</h6>
                                        <h3 class="mb-0"><?php echo count($confirmedAppointments); ?></h3>
                                    </div>
                                    <div class="bg-success bg-opacity-10 p-3 rounded">
                                        <i class="bi bi-check-circle text-success" style="font-size: 1.5rem;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-start border-info border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-2">Recent Patients</h6>
                                        <h3 class="mb-0"><?php echo count($recentPatients); ?></h3>
                                    </div>
                                    <div class="bg-info bg-opacity-10 p-3 rounded">
                                        <i class="bi bi-people text-info" style="font-size: 1.5rem;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Today's Appointments -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-calendar-check me-2"></i>Today's Appointments</h5>
                        <span class="badge bg-primary"><?php echo count($todayAppointments); ?> today</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($todayAppointments)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Time</th>
                                            <th>Patient</th>
                                            <th>Patient ID</th>
                                            <th>Reason</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($todayAppointments as $appointment): ?>
                                            <tr>
                                                <td><?php echo date('H:i', strtotime($appointment['appointment_date'])); ?></td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']); ?></div>
                                                </td>
                                                <td><?php echo htmlspecialchars($appointment['patient_id']); ?></td>
                                                <td><?php echo htmlspecialchars($appointment['purpose']); ?></td>
                                                <td>
                                                    <?php 
                                                    $statusClass = '';
                                                    switch($appointment['status']) {
                                                        case 'Scheduled': $statusClass = 'badge-scheduled'; break;
                                                        case 'Confirmed': $statusClass = 'badge-confirmed'; break;
                                                        case 'Completed': $statusClass = 'badge-completed'; break;
                                                        case 'Cancelled': $statusClass = 'badge-cancelled'; break;
                                                    }
                                                    ?>
                                                    <span class="badge <?php echo $statusClass; ?> status-badge">
                                                        <?php echo htmlspecialchars($appointment['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($appointment['status'] == 'Scheduled'): ?>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                            <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                                            <button type="submit" name="confirm_appointment" class="btn btn-sm btn-success" 
                                                                    onclick="return confirm('Confirm this appointment?')">
                                                                <i class="bi bi-check-circle"></i> Confirm
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <a href="appointment_management.php?id=<?php echo $appointment['patient_id']; ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-eye"></i> View
                                                    </a>
                                                    <a href="medical_record.php?patient_id=<?php echo $appointment['patient_id']; ?>" class="btn btn-sm btn-outline-info">
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
                                <i class="bi bi-calendar"></i>
                                <h5>No appointments today</h5>
                                <p class="text-muted">No appointments scheduled for today</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row">
                    <!-- Recent Patients -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0"><i class="bi bi-people-fill me-2"></i>Recent Patients</h5>
                                <span class="badge bg-info"><?php echo count($recentPatients); ?> new</span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (!empty($recentPatients)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Patient</th>
                                                    <th>ID</th>
                                                    <th>Age</th>
                                                    <th>Gender</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recentPatients as $patient): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="fw-bold"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></div>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($patient['patient_id']); ?></td>
                                                        <td><?php echo htmlspecialchars($patient['age']); ?> yrs</td>
                                                        <td><?php echo htmlspecialchars($patient['gender']); ?></td>
                                                        <td>
                                                            <a href="view_patient.php?id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                                <i class="bi bi-eye"></i> View
                                                            </a>
                                                            <a href="medical_record.php?patient_id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-outline-info">
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
                                        <h5>No recent patients</h5>
                                        <p class="text-muted">No patients registered recently</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Lab Test Request -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0"><i class="bi bi-droplet me-2"></i>Request Lab Test</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <div class="mb-3">
                                        <label for="patient_id" class="form-label">Patient ID</label>
                                        <input type="text" class="form-control" id="patient_id" name="patient_id" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="test_id" class="form-label">Lab Test</label>
                                        <select class="form-select" id="test_id" name="test_id" required>
                                            <option value="">Select Test</option>
                                            <?php foreach ($labTests as $test): ?>
                                                <option value="<?php echo $test['id']; ?>"><?php echo htmlspecialchars($test['test_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="notes" class="form-label">Notes</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                                    </div>
                                    <button type="submit" name="request_lab_test" class="btn btn-success" 
                                            onclick="return confirm('Submit this lab test request?')">
                                        <i class="bi bi-send"></i> Request Test
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh the page every 5 minutes to get updates
        setTimeout(function(){
            window.location.reload();
        }, 300000);
        
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