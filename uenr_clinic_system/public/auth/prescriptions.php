<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

// Get current user with proper error handling
$user = getCurrentUser();
if ($user['role'] != 'doctor') {
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

// Initialize variables
$patient = [];
$vitals = [];
$labTests = [];
$prescriptions = [];
$medications = [];
$searchResults = [];
$patient_id = null;

// Handle patient search
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['patient_search'])) {
    $searchTerm = trim($_GET['patient_search']);
    if (!empty($searchTerm)) {
        $searchTerm = "%{$searchTerm}%";
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR phone LIKE ?");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($searchResults) === 1) {
            $patient = $searchResults[0];
            $patient_id = $patient['id'];
            // Clear search term after selection
            header("Location: prescription.php?id=".$patient_id);
            exit();
        }
    }
}

// Check if patient ID is provided
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $patient_id = intval($_GET['id']);
    
    try {
        // Get patient details
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
        $stmt->execute([$patient_id]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($patient) {
            // Get most recent vital records
            $stmt = $pdo->prepare("SELECT v.*, u.full_name as nurse_name 
                                 FROM vitals v
                                 LEFT JOIN users u ON v.recorded_by = u.id
                                 WHERE v.patient_id = ? 
                                 ORDER BY v.recorded_at DESC LIMIT 1");
            $stmt->execute([$patient_id]);
            $vitals = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get lab tests with results
            $stmt = $pdo->prepare("SELECT 
                    lt.*,
                    tr.status,
                    tr.request_date,
                    tr.results,
                    tr.results_date,
                    u.full_name AS doctor_name,
                    tech.full_name AS technician_name
                FROM lab_tests lt
                JOIN test_requests tr ON lt.id = tr.test_id
                LEFT JOIN users u ON tr.requested_by = u.id
                LEFT JOIN users tech ON tr.completed_by = tech.id
                WHERE tr.patient_id = ?
                ORDER BY tr.request_date DESC");
            $stmt->execute([$patient_id]);
            $labTests = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get prescription history
            $stmt = $pdo->prepare("SELECT p.*, m.name as medication_name, u.full_name as doctor_name 
                                 FROM prescriptions p
                                 JOIN medications m ON p.medication_id = m.id
                                 LEFT JOIN users u ON p.prescribed_by = u.id
                                 WHERE p.patient_id = ?
                                 ORDER BY p.prescribed_at DESC");
            $stmt->execute([$patient_id]);
            $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get all medications for dropdown
            $stmt = $pdo->prepare("SELECT * FROM medications ORDER BY name");
            $stmt->execute();
            $medications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $_SESSION['error'] = "A database error occurred. Please try again later.";
    }
}

// Handle form submission for new prescription
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_prescription'])) {
    try {
        $medication_id = intval($_POST['medication_id']);
        $dosage = htmlspecialchars($_POST['dosage']);
        $frequency = htmlspecialchars($_POST['frequency']);
        $duration = htmlspecialchars($_POST['duration']);
        $instructions = htmlspecialchars($_POST['instructions']);
        $notes = htmlspecialchars($_POST['notes']);
        
        $stmt = $pdo->prepare("INSERT INTO prescriptions 
                             (patient_id, medication_id, dosage, frequency, duration, instructions, notes, prescribed_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$patient_id, $medication_id, $dosage, $frequency, $duration, $instructions, $notes, $user['id']]);
        
        $_SESSION['success'] = "Prescription added successfully!";
        header("Location: prescription.php?id=$patient_id");
        exit();
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $_SESSION['error'] = "Failed to add prescription. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription Management - UENR Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>
        .sidebar {
            min-height: 100vh;
            background-color: rgb(37, 41, 46);
            color: white;
        }
        .sidebar a {
            color: white;
            text-decoration: none;
            display: block;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 5px;
        }
        .sidebar a:hover, .sidebar a.active {
            background-color: #0b5ed7;
        }
        .main-content {
            padding: 20px;
        }
        .profile-header {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .profile-img {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 50%;
        }
        .section-title {
            border-left: 4px solid #0d6efd;
            padding-left: 10px;
            margin: 20px 0 15px;
        }
        .record-card {
            margin-bottom: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .badge-status {
            font-size: 0.8rem;
            padding: 5px 10px;
            border-radius: 10px;
        }
        .badge-pending {
            background-color: #ffc107;
            color: #212529;
        }
        .badge-completed {
            background-color: #198754;
        }
        .badge-abnormal {
            background-color: #dc3545;
        }
        .vital-stats {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
        }
        .vital-stat {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            text-align: center;
        }
        .vital-stat-value {
            font-size: 1.2rem;
            font-weight: bold;
        }
        .nav-tabs .nav-link.active {
            font-weight: bold;
            border-bottom: 3px solid #0d6efd;
        }
        .medication-search {
            width: 100%;
        }
        .prescription-item {
            border-left: 3px solid #0d6efd;
            padding-left: 10px;
            margin-bottom: 15px;
        }
        .prescription-actions {
            opacity: 0;
            transition: opacity 0.2s;
        }
        .prescription-item:hover .prescription-actions {
            opacity: 1;
        }
        .search-container {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .lab-test-item {
            border-left: 3px solid #6c757d;
            padding-left: 10px;
            margin-bottom: 10px;
        }
        .test-result {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-top: 5px;
        }
        .abnormal-result {
            border-left: 3px solid #dc3545;
            background: #fff5f5;
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-lg-2 sidebar p-3">
            <div class="text-center mb-4">
                <h4>UENR Clinic</h4>
                <hr>
                <?php if (isset($user['full_name'])): ?>
                    <p class="mb-0">Welcome, Dr. <?php echo htmlspecialchars($user['full_name']); ?></p>
                <?php endif; ?>
            </div> 
            <a href="doctor_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a href="manage_patients_doctor.php"><i class="bi bi-people"></i> Manage Patients</a>
            <a href="request_lab_test.php"><i class="bi bi-clipboard2-pulse"></i> Request Lab Test</a>
            <a href="prescription.php" class="active"><i class="bi bi-prescription2"></i> Prescriptions</a>
            <a href="track_appointments.php"><i class="bi bi-calendar-check"></i> Appointments</a>
            <a href="logout.php"><i class="bi bi-box-arrow-left"></i> Logout</a>
        </div>

        <!-- Main Content -->
        <div class="col-md-9 col-lg-10 main-content">
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Patient Search -->
            <div class="search-container">
                <form method="GET" action="prescription.php" class="row g-3">
                    <div class="col-md-8">
                        <label for="patient_search" class="form-label">Search Patient</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="patient_search" name="patient_search" 
                                   value="<?php echo isset($_GET['patient_search']) ? htmlspecialchars($_GET['patient_search']) : ''; ?>" 
                                   placeholder="Search by ID, name or phone">
                            <button class="btn btn-primary" type="submit">
                                <i class="bi bi-search"></i> Search
                            </button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label for="patientSelect" class="form-label">Or Select Patient</label>
                        <select class="form-select" id="patientSelect" onchange="if(this.value) window.location.href='prescription.php?id='+this.value">
                            <option value="">-- Select patient --</option>
                            <?php 
                            $stmt = $pdo->prepare("SELECT id, first_name, last_name, patient_id FROM patients ORDER BY last_name");
                            $stmt->execute();
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $selected = ($row['id'] == $patient_id) ? 'selected' : '';
                                echo '<option value="'.$row['id'].'" '.$selected.'>'.$row['last_name'].', '.$row['first_name'].' ('.$row['patient_id'].')</option>';
                            }
                            ?>
                        </select>
                    </div>
                </form>

                <?php if (!empty($searchResults) && count($searchResults) > 1): ?>
                    <div class="mt-3">
                        <h6>Search Results:</h6>
                        <div class="list-group">
                            <?php foreach ($searchResults as $result): ?>
                                <a href="prescription.php?id=<?php echo $result['id']; ?>" class="list-group-item list-group-item-action">
                                    <?php echo htmlspecialchars($result['last_name'].', '.$result['first_name']); ?> 
                                    (ID: <?php echo htmlspecialchars($result['patient_id']); ?>)
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($patient): ?>
                <!-- Patient Profile Header -->
                <div class="profile-header">
                    <div class="row">
                        <div class="col-md-2 text-center">
                            <img src="<?php echo !empty($patient['photo']) ? htmlspecialchars($patient['photo']) : 'default_profile.jpg'; ?>" 
                                 alt="Profile" class="profile-img mb-3">
                        </div>
                        <div class="col-md-6">
                            <h2><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h2>
                            <p class="text-muted">Patient ID: <?php echo htmlspecialchars($patient['patient_id']); ?></p>
                            
                            <div class="row mt-3">
                                <div class="col-6">
                                    <p><strong>Age:</strong> <?php echo htmlspecialchars($patient['age']); ?> years</p>
                                    <p><strong>Gender:</strong> <?php echo htmlspecialchars($patient['gender']); ?></p>
                                    <p><strong>Blood Type:</strong> <?php echo htmlspecialchars($patient['blood_type'] ?? 'N/A'); ?></p>
                                </div>
                                <div class="col-6">
                                    <p><strong>Date of Birth:</strong> <?php echo date('F j, Y', strtotime($patient['dob'])); ?></p>
                                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($patient['phone']); ?></p>
                                    <p><strong>Email:</strong> <?php echo htmlspecialchars($patient['email'] ?? 'N/A'); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex flex-column h-100 justify-content-between">
                                <div>
                                    <h5>Medical Notes</h5>
                                    <p><?php echo !empty($patient['medical_notes']) ? htmlspecialchars($patient['medical_notes']) : 'No medical notes available'; ?></p>
                                </div>
                                <div class="d-grid gap-2">
                                    <a href="request_lab_test.php?patient_id=<?php echo $patient['id']; ?>" class="btn btn-primary">
                                        <i class="bi bi-clipboard2-pulse"></i> Request Lab Test
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Patient Health Summary -->
                <div class="row mb-4">
                    <!-- Vitals Card -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">Latest Vital Signs</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($vitals): ?>
                                    <div class="vital-stats">
                                        <div class="vital-stat">
                                            <div class="vital-stat-label">Temperature</div>
                                            <div class="vital-stat-value"><?php echo htmlspecialchars($vitals['temperature']); ?>°C</div>
                                        </div>
                                        <div class="vital-stat">
                                            <div class="vital-stat-label">Blood Pressure</div>
                                            <div class="vital-stat-value"><?php echo htmlspecialchars($vitals['blood_pressure']); ?></div>
                                        </div>
                                        <div class="vital-stat">
                                            <div class="vital-stat-label">Pulse Rate</div>
                                            <div class="vital-stat-value"><?php echo htmlspecialchars($vitals['pulse_rate']); ?> bpm</div>
                                        </div>
                                        <div class="vital-stat">
                                            <div class="vital-stat-label">SpO2</div>
                                            <div class="vital-stat-value"><?php echo htmlspecialchars($vitals['oxygen_saturation']); ?>%</div>
                                        </div>
                                    </div>
                                    <p class="mt-2 mb-0"><small class="text-muted">Recorded: <?php echo date('M j, Y g:i a', strtotime($vitals['recorded_at'])); ?> by <?php echo htmlspecialchars($vitals['nurse_name'] ?? 'Staff'); ?></small></p>
                                <?php else: ?>
                                    <div class="alert alert-info">No vital records found for this patient.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Lab Tests Card -->
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">Recent Lab Tests</h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($labTests)): ?>
                                    <div class="list-group">
                                        <?php foreach (array_slice($labTests, 0, 3) as $test): ?>
                                            <div class="list-group-item lab-test-item">
                                                <div class="d-flex justify-content-between">
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($test['test_name']); ?></strong>
                                                        <div><small><?php echo date('M j, Y', strtotime($test['request_date'])); ?></small></div>
                                                    </div>
                                                    <div>
                                                        <span class="badge rounded-pill <?php echo strtolower($test['status']) === 'completed' ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                                            <?php echo htmlspecialchars($test['status']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <?php if (!empty($test['results'])): ?>
                                            <div class="test-result <?php echo (strpos(strtolower($test['results']), 'abnormal') !== false ? 'abnormal-result' : ''; ?>">
                                                <small><strong>Results:</strong> <?php echo nl2br(htmlspecialchars(truncateText($test['results'], 100))); ?></small>
                                                <?php if (strlen($test['results']) > 100): ?>
                                                    <button class="btn btn-sm btn-outline-primary mt-1" data-bs-toggle="modal" data-bs-target="#labResultsModal<?php echo $test['id']; ?>">
                                                        View Full Results
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (count($labTests) > 3): ?>
                                        <div class="text-center mt-2">
                                            <a href="#labTests" class="btn btn-sm btn-outline-primary">View All Lab Tests</a>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="alert alert-info">No laboratory tests found for this patient.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Prescription Management -->
                <div class="row">
                    <!-- New Prescription Form -->
                    <div class="col-md-5">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0">New Prescription</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="mb-3">
                                        <label for="medication_id" class="form-label">Medication</label>
                                        <select class="form-select medication-search" id="medication_id" name="medication_id" required>
                                            <option value="">Select medication...</option>
                                            <?php foreach ($medications as $med): ?>
                                                <option value="<?php echo $med['id']; ?>" data-description="<?php echo htmlspecialchars($med['description']); ?>">
                                                    <?php echo htmlspecialchars($med['name']); ?> (<?php echo htmlspecialchars($med['form']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div id="medicationDescription" class="form-text mt-2"></div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="dosage" class="form-label">Dosage</label>
                                            <input type="text" class="form-control" id="dosage" name="dosage" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="frequency" class="form-label">Frequency</label>
                                            <select class="form-select" id="frequency" name="frequency" required>
                                                <option value="">Select frequency...</option>
                                                <option value="Once daily">Once daily</option>
                                                <option value="Twice daily">Twice daily</option>
                                                <option value="Three times daily">Three times daily</option>
                                                <option value="Four times daily">Four times daily</option>
                                                <option value="Every 4 hours">Every 4 hours</option>
                                                <option value="Every 6 hours">Every 6 hours</option>
                                                <option value="Every 8 hours">Every 8 hours</option>
                                                <option value="Every 12 hours">Every 12 hours</option>
                                                <option value="As needed">As needed</option>
                                                <option value="At bedtime">At bedtime</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="duration" class="form-label">Duration</label>
                                            <input type="text" class="form-control" id="duration" name="duration" required placeholder="e.g. 7 days, 2 weeks">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="refills" class="form-label">Refills</label>
                                            <select class="form-select" id="refills" name="refills">
                                                <option value="0">No refills</option>
                                                <option value="1">1 refill</option>
                                                <option value="2">2 refills</option>
                                                <option value="3">3 refills</option>
                                                <option value="4">4 refills</option>
                                                <option value="5">5 refills</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="instructions" class="form-label">Instructions</label>
                                        <textarea class="form-control" id="instructions" name="instructions" rows="2"></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="notes" class="form-label">Notes (Optional)</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                                    </div>
                                    
                                    <button type="submit" name="add_prescription" class="btn btn-success">
                                        <i class="bi bi-save"></i> Save Prescription
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Prescription History -->
                    <div class="col-md-7">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">Prescription History</h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($prescriptions)): ?>
                                    <div class="list-group">
                                        <?php foreach ($prescriptions as $prescription): ?>
                                            <div class="list-group-item prescription-item">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6><?php echo htmlspecialchars($prescription['medication_name']); ?></h6>
                                                        <div class="mb-1">
                                                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars($prescription['dosage']); ?></span>
                                                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars($prescription['frequency']); ?></span>
                                                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars($prescription['duration']); ?></span>
                                                        </div>
                                                        <?php if (!empty($prescription['instructions'])): ?>
                                                            <p class="mb-1"><small><?php echo htmlspecialchars($prescription['instructions']); ?></small></p>
                                                        <?php endif; ?>
                                                        <?php if (!empty($prescription['notes'])): ?>
                                                            <p class="mb-1 text-muted"><small>Notes: <?php echo htmlspecialchars($prescription['notes']); ?></small></p>
                                                        <?php endif; ?>
                                                        <small class="text-muted">
                                                            Prescribed by Dr. <?php echo htmlspecialchars($prescription['doctor_name']); ?> on 
                                                            <?php echo date('M j, Y g:i a', strtotime($prescription['prescribed_at'])); ?>
                                                        </small>
                                                    </div>
                                                    <div class="prescription-actions">
                                                        <button class="btn btn-sm btn-outline-secondary">
                                                            <i class="bi bi-printer"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">No prescriptions found for this patient.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Lab Results Modals -->
                <?php if (!empty($labTests)): ?>
                    <?php foreach ($labTests as $test): ?>
                        <?php if (!empty($test['results'])): ?>
                            <div class="modal fade" id="labResultsModal<?php echo $test['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Lab Results - <?php echo htmlspecialchars($test['test_name']); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <strong>Test Date:</strong> <?php echo date('F j, Y', strtotime($test['request_date'])); ?><br>
                                                <?php if ($test['results_date']): ?>
                                                    <strong>Completed:</strong> <?php echo date('F j, Y', strtotime($test['results_date'])); ?><br>
                                                <?php endif; ?>
                                                <strong>Status:</strong> <span class="badge <?php echo strtolower($test['status']) === 'completed' ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                                    <?php echo htmlspecialchars($test['status']); ?>
                                                </span><br>
                                                <strong>Ordered by:</strong> Dr. <?php echo htmlspecialchars($test['doctor_name'] ?? 'Unknown'); ?><br>
                                                <?php if (!empty($test['technician_name'])): ?>
                                                    <strong>Completed by:</strong> <?php echo htmlspecialchars($test['technician_name']); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="card <?php echo (strpos(strtolower($test['results']), 'abnormal') !== false ? 'border-danger' : ''; ?>">
                                                <div class="card-header">
                                                    <h6>Test Results</h6>
                                                </div>
                                                <div class="card-body">
                                                    <pre><?php echo htmlspecialchars($test['results']); ?></pre>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            <button type="button" class="btn btn-primary">
                                                <i class="bi bi-download"></i> Download
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>

            <?php elseif (!empty($searchResults)): ?>
                <div class="alert alert-info">
                    Please select a patient from the search results above.
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    No patient selected. Please search for or select a patient to view their prescription history.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        // Initialize Select2 for medication search
        $('.medication-search').select2({
            placeholder: "Search for a medication...",
            allowClear: true
        });

        // Show medication description when selected
        $('#medication_id').on('change', function() {
            var description = $(this).find(':selected').data('description');
            $('#medicationDescription').text(description || 'No description available');
        });

        // Auto-focus on search field if no patient selected
        <?php if (!$patient && !empty($_GET['patient_search'])): ?>
            $('#patient_search').focus();
        <?php endif; ?>
    });
</script>
</body>
</html>
<?php
// Helper function to truncate text
function truncateText($text, $length) {
    if (strlen($text) > $length) {
        return substr($text, 0, $length) . '...';
    }
    return $text;
}
?>