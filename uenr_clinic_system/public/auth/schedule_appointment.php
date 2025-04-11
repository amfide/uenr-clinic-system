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
$departments = [];
$doctors = [];
$patients = [];
$success = '';
$error = '';

// Get list of departments
try {
    $stmt = $pdo->query("SELECT * FROM departments ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Failed to load departments: " . $e->getMessage();
}

// Get list of doctors
try {
    $stmt = $pdo->query("SELECT id, full_name FROM users WHERE role = 'doctor' ORDER BY full_name");
    $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Failed to load doctors: " . $e->getMessage();
}

// Check if patient ID is provided in URL
$patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : null;

// Get patient details if ID is provided
if ($patient_id) {
    try {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM patients WHERE id = ?");
        $stmt->execute([$patient_id]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Failed to load patient: " . $e->getMessage();
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patient_id = $_POST['patient_id'] ?? null;
    $department_id = $_POST['department_id'] ?? null;
    $doctor_id = $_POST['doctor_id'] ?? null;
    $appointment_date = $_POST['appointment_date'] ?? null;
    $purpose = $_POST['purpose'] ?? '';
    $notes = $_POST['notes'] ?? '';

    // Validate inputs
    if (empty($patient_id)) {
        $error = "Please select a patient";
    } elseif (empty($department_id)) {
        $error = "Please select a department";
    } elseif (empty($appointment_date)) {
        $error = "Please select appointment date and time";
    } else {
        try {
            // Insert new appointment
            $stmt = $pdo->prepare("INSERT INTO appointments 
                                 (patient_id, department_id, doctor_id, appointment_date, purpose, notes, status, created_by)
                                 VALUES (?, ?, ?, ?, ?, ?, 'Scheduled', ?)");
            $stmt->execute([
                $patient_id,
                $department_id,
                $doctor_id,
                $appointment_date,
                $purpose,
                $notes,
                $user['id']
            ]);
            
            $success = "Appointment scheduled successfully!";
            
            // Clear form if needed
            $_POST = [];
            
        } catch (PDOException $e) {
            $error = "Failed to schedule appointment: " . $e->getMessage();
        }
    }
}

// Get list of patients for search/select
try {
    $stmt = $pdo->query("SELECT id, first_name, last_name, patient_id FROM patients ORDER BY first_name, last_name");
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Failed to load patients: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Appointment - UENR Clinic</title>
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
        
        .form-section {
            background-color: var(--light);
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--secondary);
        }
        
        .patient-info {
            background-color: var(--light);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--success);
        }
        
        .search-results {
            position: absolute;
            z-index: 1000;
            width: 100%;
            max-height: 200px;
            overflow-y: auto;
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.1);
            display: none;
        }
        
        .search-results a {
            padding: 0.5rem 1rem;
            display: block;
            color: var(--dark);
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .search-results a:hover {
            background-color: var(--light);
        }
        
        .form-label {
            font-weight: 600;
            color: var(--dark);
        }
        
        .form-control, .form-select {
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            border: 1px solid #dee2e6;
        }
        
        .alert {
            border-radius: 0.5rem;
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
                        <a href="manage_patients_nurse.php" class="nav-link">
                            <i class="bi bi-people"></i> Manage Patients
                        </a>
                        <a href="record_vitals.php" class="nav-link">
                            <i class="bi bi-heart-pulse"></i> Record Vitals
                        </a>
                        <a href="schedule_appointment.php" class="nav-link active">
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
                        <h2 class="mb-1">Schedule New Appointment</h2>
                        <p class="text-muted mb-0">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?></p>
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

                <div class="card">
                    <div class="card-body">
                        <form method="post" action="schedule_appointment.php">
                            <!-- Patient Selection -->
                            <div class="mb-4">
                                <h4 class="section-title">1. Select Patient</h4>
                                
                                <?php if (isset($patient)): ?>
                                    <div class="patient-info">
                                        <h5><i class="bi bi-person-circle me-2"></i><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h5>
                                        <input type="hidden" name="patient_id" value="<?php echo $patient['id']; ?>">
                                        <a href="schedule_appointment.php" class="btn btn-sm btn-outline-secondary">Change Patient</a>
                                    </div>
                                <?php else: ?>
                                    <div class="mb-3 position-relative">
                                        <label for="patientSearch" class="form-label">Search Patient</label>
                                        <input type="text" class="form-control" id="patientSearch" placeholder="Start typing patient name...">
                                        <div id="searchResults" class="search-results"></div>
                                        <input type="hidden" name="patient_id" id="selectedPatientId">
                                    </div>
                                    
                                    <div id="selectedPatientInfo" class="patient-info d-none">
                                        <h5 id="selectedPatientName"></h5>
                                        <button type="button" id="clearPatientSelection" class="btn btn-sm btn-outline-secondary">Change</button>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Or select from list</label>
                                        <select class="form-select" id="patientSelect" name="patient_id">
                                            <option value="">Select a patient</option>
                                            <?php foreach ($patients as $p): ?>
                                                <option value="<?php echo $p['id']; ?>" <?php echo ($patient_id == $p['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name'] . ' (ID: ' . $p['patient_id'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Appointment Details -->
                            <div class="mb-4">
                                <h4 class="section-title">2. Appointment Details</h4>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="department_id" class="form-label">Department</label>
                                        <select class="form-select" id="department_id" name="department_id" required>
                                            <option value="">Select department</option>
                                            <?php foreach ($departments as $dept): ?>
                                                <option value="<?php echo $dept['id']; ?>" <?php echo (isset($_POST['department_id']) && $_POST['department_id'] == $dept['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($dept['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="doctor_id" class="form-label">Doctor (Optional)</label>
                                        <select class="form-select" id="doctor_id" name="doctor_id">
                                            <option value="">Any available doctor</option>
                                            <?php foreach ($doctors as $doc): ?>
                                                <option value="<?php echo $doc['id']; ?>" <?php echo (isset($_POST['doctor_id']) && $_POST['doctor_id'] == $doc['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($doc['full_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="appointment_date" class="form-label">Date & Time</label>
                                        <input type="datetime-local" class="form-control" id="appointment_date" name="appointment_date" 
                                               value="<?php echo $_POST['appointment_date'] ?? ''; ?>" required>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="purpose" class="form-label">Purpose</label>
                                        <input type="text" class="form-control" id="purpose" name="purpose" 
                                               value="<?php echo htmlspecialchars($_POST['purpose'] ?? ''); ?>" required>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-calendar-plus me-1"></i> Schedule Appointment
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Patient search functionality
        document.getElementById('patientSearch').addEventListener('input', function() {
            const searchTerm = this.value.trim();
            const resultsContainer = document.getElementById('searchResults');
            
            if (searchTerm.length < 2) {
                resultsContainer.innerHTML = '';
                resultsContainer.style.display = 'none';
                return;
            }
            
            fetch(`search_patients.php?term=${encodeURIComponent(searchTerm)}`)
                .then(response => response.json())
                .then(patients => {
                    resultsContainer.innerHTML = '';
                    
                    if (patients.length > 0) {
                        patients.forEach(patient => {
                            const item = document.createElement('a');
                            item.className = 'dropdown-item';
                            item.href = '#';
                            item.textContent = `${patient.first_name} ${patient.last_name} (ID: ${patient.patient_id})`;
                            item.dataset.id = patient.id;
                            item.addEventListener('click', function(e) {
                                e.preventDefault();
                                document.getElementById('patientSearch').value = '';
                                document.getElementById('selectedPatientId').value = patient.id;
                                document.getElementById('selectedPatientName').textContent = `${patient.first_name} ${patient.last_name}`;
                                document.getElementById('selectedPatientInfo').classList.remove('d-none');
                                document.getElementById('patientSelect').value = patient.id;
                                resultsContainer.innerHTML = '';
                                resultsContainer.style.display = 'none';
                            });
                            resultsContainer.appendChild(item);
                        });
                        resultsContainer.style.display = 'block';
                    } else {
                        const item = document.createElement('div');
                        item.className = 'dropdown-item text-muted';
                        item.textContent = 'No patients found';
                        resultsContainer.appendChild(item);
                        resultsContainer.style.display = 'block';
                    }
                });
        });
        
        // Clear patient selection
        document.getElementById('clearPatientSelection')?.addEventListener('click', function() {
            document.getElementById('selectedPatientId').value = '';
            document.getElementById('patientSelect').value = '';
            document.getElementById('selectedPatientInfo').classList.add('d-none');
        });
        
        // Hide search results when clicking elsewhere
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#patientSearch') && !e.target.closest('#searchResults')) {
                document.getElementById('searchResults').style.display = 'none';
            }
        });
        
        // Sync patient select dropdown with search selection
        document.getElementById('patientSelect').addEventListener('change', function() {
            if (this.value) {
                const selectedOption = this.options[this.selectedIndex];
                document.getElementById('selectedPatientId').value = this.value;
                document.getElementById('selectedPatientName').textContent = selectedOption.text.split(' (ID:')[0];
                document.getElementById('selectedPatientInfo').classList.remove('d-none');
            }
        });
    </script>
</body>
</html>