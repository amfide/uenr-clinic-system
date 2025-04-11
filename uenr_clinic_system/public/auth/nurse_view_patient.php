<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

// Get current user with proper error handling
$user = getCurrentUser();
if ($user['role'] != 'nurse') {
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

// Initialize variables
$patient = [];
$vitals = [];
$labTests = [];
$appointments = [];
$age = 0;

// Check if patient ID is provided
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $patient_id = intval($_GET['id']);
    
    try {
        // Get patient details
        $stmt = $pdo->prepare("SELECT *, TIMESTAMPDIFF(YEAR, dob, CURDATE()) as age FROM patients WHERE id = ?");
        $stmt->execute([$patient_id]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($patient) {
            // Get vital records with nurse name
            $stmt = $pdo->prepare("SELECT v.*, u.full_name as nurse_name 
                                 FROM vitals v
                                 LEFT JOIN users u ON v.recorded_by = u.id
                                 WHERE v.patient_id = ? 
                                 ORDER BY v.recorded_at DESC");
            $stmt->execute([$patient_id]);
            $vitals = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get lab tests with doctor name - corrected query
            $stmt = $pdo->prepare("SELECT 
        lt.*,
        tr.status,
        tr.request_date,
        u.full_name AS doctor_name
    FROM test_requests tr
    JOIN test_request_items tri ON tr.id = tri.request_id
    JOIN lab_tests lt ON tri.test_id = lt.id
    LEFT JOIN users u ON tr.requested_by = u.id
    WHERE tr.patient_id = ?
    ORDER BY tr.request_date DESC
");
$stmt->execute([$patient_id]);
$labTests = $stmt->fetchAll(PDO::FETCH_ASSOC);


            // Get appointments
            $stmt = $pdo->prepare("SELECT * FROM appointments 
                                 WHERE patient_id = ? 
                                 ORDER BY appointment_date DESC");
            $stmt->execute([$patient_id]);
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $_SESSION['error'] = "A database error occurred. Please try again later. Error: " . $e->getMessage();
    }
} else {
    $_SESSION['error'] = "No patient specified";
    header("Location: manage_patients_nurse.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Records - UENR Clinic</title>
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
        
        /* Patient Profile Specific Styles */
        .profile-header {
            background-color: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
        }
        
        .profile-img {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid var(--light);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .vital-stats {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .vital-stat {
            background: var(--light);
            padding: 1rem;
            border-radius: 0.5rem;
            text-align: center;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        
        .vital-stat-label {
            font-size: 0.8rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }
        
        .vital-stat-value {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        .nav-tabs {
            border-bottom: 1px solid rgba(0,0,0,0.05);
            margin-bottom: 1.5rem;
        }
        
        .nav-tabs .nav-link {
            color: var(--dark);
            padding: 0.75rem 1.25rem;
            border: none;
            margin-right: 0.5rem;
            border-radius: 0.5rem 0.5rem 0 0;
            background: transparent;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--secondary);
            font-weight: 600;
            border-bottom: 3px solid var(--secondary);
            background: transparent;
        }
        
        .tab-content {
            background: white;
            border-radius: 0 0.5rem 0.5rem 0.5rem;
            padding: 1.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
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
                            <i class="bi bi-calendar-check"></i> Track Appointment
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
                        <h2 class="mb-1">Patient Records</h2>
                        <p class="text-muted mb-0">Nurse: <?php echo htmlspecialchars($user['full_name']); ?></p>
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

                <?php if ($patient): ?>
                    <!-- Patient Profile Header -->
                    <div class="profile-header">
                        <div class="row align-items-center">
                            <div class="col-md-2 text-center">
                                <img src="<?php echo !empty($patient['photo']) ? htmlspecialchars($patient['photo']) : 'default_profile.jpg'; ?>" 
                                     alt="Profile" class="profile-img mb-3">
                            </div>
                            <div class="col-md-5">
                                <h2 class="mb-2"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h2>
                                <div class="d-flex flex-wrap gap-2 mb-3">
                                    <span class="badge bg-light text-dark">ID: <?php echo htmlspecialchars($patient['patient_id']); ?></span>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($patient['gender']); ?></span>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($patient['age']); ?> years</span>
                                    <?php if (!empty($patient['blood_type'])): ?>
                                        <span class="badge bg-danger">Blood: <?php echo htmlspecialchars($patient['blood_type']); ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="row">
                                    <div class="col-6">
                                        <p class="mb-1"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($patient['phone']); ?></p>
                                        <?php if (!empty($patient['email'])): ?>
                                            <p class="mb-1"><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($patient['email']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-6">
                                        <p class="mb-1"><i class="bi bi-calendar"></i> <?php echo date('F j, Y', strtotime($patient['dob'])); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="d-flex flex-column h-100 justify-content-between">
                                    <div>
                                        <h5 class="mb-2">Medical Notes</h5>
                                        <div class="alert alert-light mb-3">
                                            <?php echo !empty($patient['medical_notes']) ? nl2br(htmlspecialchars($patient['medical_notes'])) : 'No medical notes available'; ?>
                                        </div>
                                    </div>
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <a href="record_vitals.php?patient_id=<?php echo $patient['id']; ?>" class="btn btn-primary">
                                            <i class="bi bi-heart-pulse"></i> Record Vitals
                                        </a>
                                        <a href="schedule_appointment.php?patient_id=<?php echo $patient['id']; ?>" class="btn btn-success">
                                            <i class="bi bi-calendar-plus"></i> Schedule Appointment
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Navigation Tabs -->
                    <ul class="nav nav-tabs" id="patientTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="vitals-tab" data-bs-toggle="tab" data-bs-target="#vitals" type="button" role="tab">
                                <i class="bi bi-heart-pulse"></i> Vitals
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="lab-tab" data-bs-toggle="tab" data-bs-target="#lab" type="button" role="tab">
                                <i class="bi bi-flask"></i> Lab Tests
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="appointments-tab" data-bs-toggle="tab" data-bs-target="#appointments" type="button" role="tab">
                                <i class="bi bi-calendar"></i> Appointments
                            </button>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content" id="patientTabsContent">
                        <!-- Vitals Tab -->
                        <div class="tab-pane fade show active" id="vitals" role="tabpanel">
                            <h4 class="section-title">Vital Signs History</h4>
                            
                            <?php if (!empty($vitals)): ?>
                                <?php foreach ($vitals as $vital): ?>
                                    <div class="card">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0"><?php echo date('F j, Y, g:i a', strtotime($vital['recorded_at'])); ?></h5>
                                            <small class="text-muted">Recorded by <?php echo htmlspecialchars($vital['nurse_name'] ?? 'Staff'); ?></small>
                                        </div>
                                        <div class="card-body">
                                            <div class="vital-stats">
                                                <div class="vital-stat">
                                                    <div class="vital-stat-label">Temperature</div>
                                                    <div class="vital-stat-value"><?php echo htmlspecialchars($vital['temperature']); ?>°C</div>
                                                </div>
                                                <div class="vital-stat">
                                                    <div class="vital-stat-label">Blood Pressure</div>
                                                    <div class="vital-stat-value"><?php echo htmlspecialchars($vital['blood_pressure']); ?></div>
                                                </div>
                                                <div class="vital-stat">
                                                    <div class="vital-stat-label">Pulse Rate</div>
                                                    <div class="vital-stat-value"><?php echo htmlspecialchars($vital['pulse_rate']); ?> bpm</div>
                                                </div>
                                                <div class="vital-stat">
                                                    <div class="vital-stat-label">Resp. Rate</div>
                                                    <div class="vital-stat-value"><?php echo htmlspecialchars($vital['respiratory_rate']); ?></div>
                                                </div>
                                                <div class="vital-stat">
                                                    <div class="vital-stat-label">SpO2</div>
                                                    <div class="vital-stat-value"><?php echo htmlspecialchars($vital['oxygen_saturation']); ?>%</div>
                                                </div>
                                                <div class="vital-stat">
                                                    <div class="vital-stat-label">Height</div>
                                                    <div class="vital-stat-value"><?php echo htmlspecialchars($vital['height']); ?> cm</div>
                                                </div>
                                                <div class="vital-stat">
                                                    <div class="vital-stat-label">Weight</div>
                                                    <div class="vital-stat-value"><?php echo htmlspecialchars($vital['weight']); ?> kg</div>
                                                </div>
                                                <div class="vital-stat">
                                                    <div class="vital-stat-label">BMI</div>
                                                    <div class="vital-stat-value"><?php echo htmlspecialchars($vital['bmi']); ?></div>
                                                </div>
                                            </div>
                                            
                                            <?php if (!empty($vital['notes'])): ?>
                                                <div class="alert alert-light mt-3">
                                                    <strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($vital['notes'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="bi bi-heart-pulse"></i>
                                    <h5>No Vital Records</h5>
                                    <p class="text-muted">No vital signs have been recorded for this patient yet</p>
                                    <a href="record_vitals.php?patient_id=<?php echo $patient['id']; ?>" class="btn btn-primary">
                                        <i class="bi bi-plus-circle"></i> Record Vitals
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Lab Tests Tab -->
                        <div class="tab-pane fade" id="lab" role="tabpanel">
                            <h4 class="section-title">Laboratory Test Results</h4>
                            
                            <?php if (!empty($labTests)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Test Date</th>
                                                <th>Test Name</th>
                                                <th>Status</th>
                                                <th>Results</th>
                                                <th>Ordered By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($labTests as $test): ?>
                                                <tr>
                                                    <td><?php echo date('M j, Y', strtotime($test['request_date'])); ?></td>
                                                    <td><?php echo htmlspecialchars($test['test_name']); ?></td>
                                                    <td>
                                                        <?php $statusClass = strtolower($test['status']) === 'completed' ? 'bg-success' : 'bg-warning text-dark'; ?>
                                                        <span class="badge <?php echo $statusClass; ?> status-badge">
                                                            <?php echo htmlspecialchars($test['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($test['results'])): ?>
                                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#resultsModal<?php echo $test['id']; ?>">
                                                                View Results
                                                            </button>
                                                            
                                                            <!-- Results Modal -->
                                                            <div class="modal fade" id="resultsModal<?php echo $test['id']; ?>" tabindex="-1">
                                                                <div class="modal-dialog modal-lg">
                                                                    <div class="modal-content">
                                                                        <div class="modal-header">
                                                                            <h5 class="modal-title">Test Results - <?php echo htmlspecialchars($test['test_name']); ?></h5>
                                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                        </div>
                                                                        <div class="modal-body">
                                                                            <pre><?php echo htmlspecialchars($test['results']); ?></pre>
                                                                        </div>
                                                                        <div class="modal-footer">
                                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted">Not available</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>Dr. <?php echo htmlspecialchars($test['doctor_name'] ?? 'Unknown'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="bi bi-flask"></i>
                                    <h5>No Lab Tests</h5>
                                    <p class="text-muted">No laboratory tests have been ordered for this patient</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Appointments Tab -->
                        <div class="tab-pane fade" id="appointments" role="tabpanel">
                            <h4 class="section-title">Appointment History</h4>
                            
                            <?php if (!empty($appointments)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date & Time</th>
                                                <th>Purpose</th>
                                                <?php if (isset($appointments[0]['department'])): ?>
                                                    <th>Department</th>
                                                <?php endif; ?>
                                                <th>Status</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($appointments as $appt): ?>
                                                <tr>
                                                    <td><?php echo date('M j, Y g:i a', strtotime($appt['appointment_date'])); ?></td>
                                                    <td><?php echo htmlspecialchars($appt['purpose'] ?? 'N/A'); ?></td>
                                                    <?php if (isset($appointments[0]['department'])): ?>
                                                        <td><?php echo htmlspecialchars($appt['department'] ?? 'N/A'); ?></td>
                                                    <?php endif; ?>
                                                    <td>
                                                        <?php $statusClass = strtolower($appt['status'] ?? 'scheduled') === 'completed' ? 'bg-success' : 'bg-warning text-dark'; ?>
                                                        <span class="badge <?php echo $statusClass; ?> status-badge">
                                                            <?php echo htmlspecialchars($appt['status'] ?? 'Scheduled'); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($appt['notes'])): ?>
                                                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#notesModal<?php echo $appt['id']; ?>">
                                                                View Notes
                                                            </button>
                                                            
                                                            <!-- Notes Modal -->
                                                            <div class="modal fade" id="notesModal<?php echo $appt['id']; ?>" tabindex="-1">
                                                                <div class="modal-dialog">
                                                                    <div class="modal-content">
                                                                        <div class="modal-header">
                                                                            <h5 class="modal-title">Appointment Notes</h5>
                                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                        </div>
                                                                        <div class="modal-body">
                                                                            <?php echo nl2br(htmlspecialchars($appt['notes'])); ?>
                                                                        </div>
                                                                        <div class="modal-footer">
                                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted">None</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="bi bi-calendar"></i>
                                    <h5>No Appointments</h5>
                                    <p class="text-muted">No appointment records found for this patient</p>
                                    <a href="schedule_appointment.php?patient_id=<?php echo $patient['id']; ?>" class="btn btn-primary">
                                        <i class="bi bi-plus-circle"></i> Schedule Appointment
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i> Patient not found. Please check the patient ID.
                        <a href="manage_patients_nurse.php" class="alert-link">Return to patient list</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>