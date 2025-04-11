<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'doctor') {
    header("Location: /public/dashboards/{$user['role']}_dashboard.php");
    exit();
}

// Validate patient ID
$patient_id = isset($_GET['id']) ? trim($_GET['id']) : null;
if (!$patient_id || !ctype_digit($patient_id)) {
    $_SESSION['error'] = "Invalid patient ID specified";
    header("Location: doctor_dashboard.php");
    exit();
}
$patient_id = intval($patient_id);

// Initialize variables
$patient = [];
$vitals = [];
$labTests = [];
$prescriptions = [];
$appointments = [];
$medicalRecords = [];
$pendingLabTests = [];

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
            
            $stmt = $pdo->prepare("SELECT 
                lt.test_name,
                u.full_name AS doctor_name,
                tr.request_date AS requested_at,
                tr.status
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
    } 
    catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $_SESSION['error'] = "A database error occurred. Please try again later. Error: " . $e->getMessage();
    }
} else {
    $_SESSION['error'] = "No patient specified";
    header("Location: doctor_dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Management - UENR Clinic</title>
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
        
        .profile-header {
            background-color: white;
            border-radius: 0.5rem;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
        }
        
        .profile-img {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid var(--light);
            box-shadow: 0 0.25rem 0.5rem rgba(0,0,0,0.1);
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
        
        .badge {
            font-weight: 500;
            padding: 0.35em 0.65em;
        }
        
        .status-badge {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .nav-tabs .nav-link {
            color: var(--dark);
            border: none;
            padding: 0.75rem 1.5rem;
            margin-right: 0.5rem;
        }
        
        .nav-tabs .nav-link.active {
            font-weight: 600;
            color: var(--secondary);
            border-bottom: 3px solid var(--secondary);
            background-color: transparent;
        }
        
        .nav-tabs .nav-link:hover:not(.active) {
            border-color: transparent;
            color: var(--secondary);
        }
        
        .tab-content {
            background-color: white;
            border-radius: 0 0 0.5rem 0.5rem;
            padding: 1.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
            border: 1px solid rgba(0,0,0,0.05);
            border-top: none;
        }
        
        .table-responsive {
            border-radius: 0.5rem;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: var(--dark);
            white-space: nowrap;
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
        
        .patient-detail-card {
            background-color: var(--light);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .patient-detail-label {
            font-weight: 600;
            color: var(--dark);
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
                        <p class="text-muted mb-0">Comprehensive patient health records</p>
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

                <!-- Patient Profile Header -->
                <div class="profile-header">
                    <div class="row">
                        <div class="col-md-2 text-center">
                            <img src="<?php echo !empty($patient['photo']) ? htmlspecialchars($patient['photo']) : 'assets/images/default_profile.jpg'; ?>" 
                                 alt="Profile" class="profile-img mb-3">
                        </div>
                        <div class="col-md-6">
                            <h2><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h2>
                            <p class="text-muted"><i class="bi bi-person-badge"></i> ID: <?php echo htmlspecialchars($patient['patient_id']); ?></p>
                            
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="patient-detail-card">
                                        <div class="patient-detail-label">Age</div>
                                        <div><?php echo htmlspecialchars($patient['age']); ?> years</div>
                                    </div>
                                    <div class="patient-detail-card">
                                        <div class="patient-detail-label">Gender</div>
                                        <div><?php echo htmlspecialchars($patient['gender']); ?></div>
                                    </div>
                                    <div class="patient-detail-card">
                                        <div class="patient-detail-label">Blood Type</div>
                                        <div><?php echo htmlspecialchars($patient['blood_type'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="patient-detail-card">
                                        <div class="patient-detail-label">Date of Birth</div>
                                        <div><?php echo date('F j, Y', strtotime($patient['dob'])); ?></div>
                                    </div>
                                    <div class="patient-detail-card">
                                        <div class="patient-detail-label">Phone</div>
                                        <div><?php echo htmlspecialchars($patient['phone']); ?></div>
                                    </div>
                                    <div class="patient-detail-card">
                                        <div class="patient-detail-label">Email</div>
                                        <div><?php echo htmlspecialchars($patient['email'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex flex-column h-100 justify-content-between">
                                <div class="patient-detail-card">
                                    <h5><i class="bi bi-file-earmark-text"></i> Medical Notes</h5>
                                    <p><?php echo !empty($patient['medical_notes']) ? htmlspecialchars($patient['medical_notes']) : 'No medical notes available'; ?></p>
                                </div>
                                <div class="d-grid gap-2 action-btns">
                                    <a href="add_medical_record.php?patient_id=<?php echo $patient['id']; ?>" class="btn btn-primary">
                                        <i class="bi bi-file-earmark-medical"></i> Add Record
                                    </a>
                                    <a href="prescriptions.php?patient_id=<?php echo $patient['id']; ?>" class="btn btn-success">
                                        <i class="bi bi-capsule"></i> Prescribe
                                    </a>
                                    <a href="request_lab_test.php?patient_id=<?php echo $patient['id']; ?>" class="btn btn-info">
                                        <i class="bi bi-droplet"></i> Lab Test
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
                            <i class="bi bi-droplet"></i> Lab Tests
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="prescriptions-tab" data-bs-toggle="tab" data-bs-target="#prescriptions" type="button" role="tab">
                            <i class="bi bi-capsule"></i> Prescriptions
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="records-tab" data-bs-toggle="tab" data-bs-target="#records" type="button" role="tab">
                            <i class="bi bi-file-earmark-medical"></i> Records
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="appointments-tab" data-bs-toggle="tab" data-bs-target="#appointments" type="button" role="tab">
                            <i class="bi bi-calendar-check"></i> Appointments
                        </button>
                    </li>
                </ul>

                <!-- Tab Content -->
                <div class="tab-content" id="patientTabsContent">
                    <!-- Vitals Tab -->
                    <div class="tab-pane fade show active" id="vitals" role="tabpanel">
                        <h4 class="section-title">Vital Signs History</h4>
                        
                        <?php if (!empty($vitals)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th><i class="bi bi-calendar"></i> Date</th>
                                            <th><i class="bi bi-person"></i> Recorded By</th>
                                            <th><i class="bi bi-heart"></i> BP</th>
                                            <th><i class="bi bi-thermometer"></i> Temp (°C)</th>
                                            <th><i class="bi bi-heart-pulse"></i> Pulse</th>
                                            <th><i class="bi bi-lungs"></i> SpO2</th>
                                            <th><i class="bi bi-speedometer2"></i> Weight</th>
                                            <th><i class="bi bi-chat-left-text"></i> Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($vitals as $vital): ?>
                                            <tr>
                                                <td><?php echo date('M j, Y g:i a', strtotime($vital['recorded_at'])); ?></td>
                                                <td><?php echo htmlspecialchars($vital['nurse_name']); ?></td>
                                                <td><?php echo htmlspecialchars($vital['blood_pressure']); ?></td>
                                                <td><?php echo htmlspecialchars($vital['temperature']); ?></td>
                                                <td><?php echo htmlspecialchars($vital['pulse_rate']); ?></td>
                                                <td><?php echo htmlspecialchars($vital['oxygen_saturation']); ?>%</td>
                                                <td><?php echo htmlspecialchars($vital['weight']); ?> kg</td>
                                                <td>
                                                    <?php if (!empty($vital['notes'])): ?>
                                                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#vitalNotesModal<?php echo $vital['id']; ?>">
                                                            <i class="bi bi-eye"></i> View
                                                        </button>
                                                        <!-- Modal for vital notes -->
                                                        <div class="modal fade" id="vitalNotesModal<?php echo $vital['id']; ?>" tabindex="-1">
                                                            <div class="modal-dialog">
                                                                <div class="modal-content">
                                                                    <div class="modal-header">
                                                                        <h5 class="modal-title">Vital Signs Notes</h5>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <?php echo nl2br(htmlspecialchars($vital['notes'])); ?>
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
                                <i class="bi bi-heart-pulse"></i>
                                <h5>No vital records found</h5>
                                <p class="text-muted">No vital signs recorded for this patient</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Lab Tests Tab -->
                    <div class="tab-pane fade" id="lab" role="tabpanel">
                        <h4 class="section-title">Lab Tests</h4>
                        
                        <?php if (!empty($labTests)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th><i class="bi bi-calendar"></i> Test Date</th>
                                            <th><i class="bi bi-droplet"></i> Test Name</th>
                                            <th><i class="bi bi-check-circle"></i> Status</th>
                                            <th><i class="bi bi-clipboard-data"></i> Results</th>
                                            <th><i class="bi bi-person"></i> Ordered By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($labTests as $test): ?>
                                            <tr>
                                                <td><?php echo date('M j, Y', strtotime($test['test_date'] ?? $test['requested_at'])); ?></td>
                                                <td><?php echo htmlspecialchars($test['test_name']); ?></td>
                                                <td>
                                                    <span class="badge status-badge bg-warning <?php echo strtolower($test['status']); ?>">
                                                        <?php echo htmlspecialchars($test['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($test['results'])): ?>
                                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#resultsModal<?php echo $test['id']; ?>">
                                                            <i class="bi bi-eye"></i> View Results
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
                                <i class="bi bi-droplet"></i>
                                <h5>No lab tests found</h5>
                                <p class="text-muted">No lab tests recorded for this patient</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Prescriptions Tab -->
                    <div class="tab-pane fade" id="prescriptions" role="tabpanel">
                        <h4 class="section-title">Prescription History</h4>
                        
                        <?php if (!empty($prescriptions)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th><i class="bi bi-calendar"></i> Date</th>
                                            <th><i class="bi bi-list-check"></i> # Items</th>
                                            <th><i class="bi bi-person"></i> Prescribed By</th>
                                            <th><i class="bi bi-check-circle"></i> Status</th>
                                            <th><i class="bi bi-chat-left-text"></i> Notes</th>
                                            <th><i class="bi bi-gear"></i> Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($prescriptions as $prescription): ?>
                                            <tr>
                                                <td><?php echo date('M j, Y', strtotime($prescription['prescribed_at'])); ?></td>
                                                <td><?php echo $prescription['item_count']; ?></td>
                                                <td>Dr. <?php echo htmlspecialchars($prescription['doctor_name']); ?></td>
                                                <td>
                                                    <?php if ($prescription['is_active']): ?>
                                                        <span class="badge status-badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge status-badge bg-secondary">Completed</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($prescription['notes'])): ?>
                                                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#prescriptionNotesModal<?php echo $prescription['id']; ?>">
                                                            <i class="bi bi-eye"></i> View Notes
                                                        </button>
                                                        <!-- Notes Modal -->
                                                        <div class="modal fade" id="prescriptionNotesModal<?php echo $prescription['id']; ?>" tabindex="-1">
                                                            <div class="modal-dialog">
                                                                <div class="modal-content">
                                                                    <div class="modal-header">
                                                                        <h5 class="modal-title">Prescription Notes</h5>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <?php echo nl2br(htmlspecialchars($prescription['notes'])); ?>
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
                                                <td>
                                                    <a href="view_prescription.php?id=<?php echo $prescription['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-eye"></i> View Details
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-capsule"></i>
                                <h5>No prescriptions found</h5>
                                <p class="text-muted">No prescriptions recorded for this patient</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Medical Records Tab -->
                    <div class="tab-pane fade" id="records" role="tabpanel">
                        <h4 class="section-title">Medical Records</h4>
                        
                        <?php if (!empty($medicalRecords)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th><i class="bi bi-calendar"></i> Date</th>
                                            <th><i class="bi bi-file-earmark-text"></i> Record Type</th>
                                            <th><i class="bi bi-person"></i> Recorded By</th>
                                            <th><i class="bi bi-chat-left-text"></i> Notes</th>
                                            <th><i class="bi bi-gear"></i> Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($medicalRecords as $record): ?>
                                            <tr>
                                                <td><?php echo date('M j, Y', strtotime($record['recorded_at'])); ?></td>
                                                <td><?php echo htmlspecialchars($record['record_type']); ?></td>
                                                <td>Dr. <?php echo htmlspecialchars($record['doctor_name']); ?></td>
                                                <td>
                                                    <?php if (!empty($record['notes'])): ?>
                                                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#recordNotesModal<?php echo $record['id']; ?>">
                                                            <i class="bi bi-eye"></i> View Notes
                                                        </button>
                                                        <!-- Notes Modal -->
                                                        <div class="modal fade" id="recordNotesModal<?php echo $record['id']; ?>" tabindex="-1">
                                                            <div class="modal-dialog">
                                                                <div class="modal-content">
                                                                    <div class="modal-header">
                                                                        <h5 class="modal-title">Medical Record Notes</h5>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <?php echo nl2br(htmlspecialchars($record['notes'])); ?>
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
                                                <td>
                                                    <a href="view_medical_record.php?id=<?php echo $record['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-eye"></i> View Details
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-file-earmark-medical"></i>
                                <h5>No medical records found</h5>
                                <p class="text-muted">No medical records for this patient</p>
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
                                            <th><i class="bi bi-calendar"></i> Date & Time</th>
                                            <th><i class="bi bi-building"></i> Department</th>
                                            <th><i class="bi bi-check-circle"></i> Status</th>
                                            <th><i class="bi bi-chat-left-text"></i> Notes</th>
                                            <th><i class="bi bi-gear"></i> Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($appointments as $appt): ?>
                                            <tr>
                                                <td><?php echo date('M j, Y g:i a', strtotime($appt['appointment_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($appt['department_name'] ?? 'General'); ?></td>
                                                <td>
                                                    <?php 
                                                    $statusClass = '';
                                                    switch($appt['status']) {
                                                        case 'Scheduled': $statusClass = 'bg-secondary'; break;
                                                        case 'Confirmed': $statusClass = 'bg-primary'; break;
                                                        case 'Completed': $statusClass = 'bg-success'; break;
                                                        case 'Cancelled': $statusClass = 'bg-danger'; break;
                                                    }
                                                    ?>
                                                    <span class="badge status-badge <?php echo $statusClass; ?>">
                                                        <?php echo htmlspecialchars($appt['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($appt['notes'])): ?>
                                                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#appointmentNotesModal<?php echo $appt['id']; ?>">
                                                            <i class="bi bi-eye"></i> View Notes
                                                        </button>
                                                        <!-- Notes Modal -->
                                                        <div class="modal fade" id="appointmentNotesModal<?php echo $appt['id']; ?>" tabindex="-1">
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
                                                <td>
                                                    <a href="view_appointment.php?id=<?php echo $appt['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-eye"></i> View Details
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-calendar-check"></i>
                                <h5>No appointments found</h5>
                                <p class="text-muted">No appointment records for this patient</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Activate the first tab
        var firstTabEl = document.querySelector('#patientTabs li:first-child button')
        var firstTab = new bootstrap.Tab(firstTabEl)
        firstTab.show()
    </script>
</body>
</html>