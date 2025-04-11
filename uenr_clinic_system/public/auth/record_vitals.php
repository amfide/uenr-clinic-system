<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'nurse') {
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

// Initialize variables
$patient = null;
$searchResults = [];

// Handle patient search
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['patient_search'])) {
    $searchTerm = "%{$_GET['patient_search']}%";
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR phone LIKE ?");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If only one result, automatically select that patient
    if (count($searchResults) === 1) {
        $patient = $searchResults[0];
    }
}

// Get patient if ID is provided directly
if (isset($_GET['patient_id']) && empty($searchResults)) {
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->execute([$_GET['patient_id']]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle vitals submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['record_vitals'])) {
    $patientId = $_POST['patient_id'];
    $temperature = $_POST['temperature'];
    $bloodPressure = $_POST['blood_pressure'];
    $pulseRate = $_POST['pulse_rate'];
    $respiratoryRate = $_POST['respiratory_rate'];
    $weight = $_POST['weight'];
    $height = $_POST['height'];
    $oxygenSaturation = $_POST['oxygen_saturation'];
    $notes = $_POST['notes'];
    
    // Calculate BMI
    $bmi = $weight / (($height/100) * ($height/100));
    
    try {
        $stmt = $pdo->prepare("INSERT INTO vitals 
                              (patient_id, temperature, blood_pressure, pulse_rate, respiratory_rate, 
                               weight, height, bmi, oxygen_saturation, notes, recorded_by) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $patientId, $temperature, $bloodPressure, $pulseRate, $respiratoryRate,
            $weight, $height, $bmi, $oxygenSaturation, $notes, $user['id']
        ]);
        
        $_SESSION['success'] = "Vitals recorded successfully";
        header("Location: nurse_dashboard.php");
        exit();
    } catch (PDOException $e) {
        $error = "Error recording vitals: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Vitals - UENR Clinic</title>
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
        
        .patient-info {
            background-color: var(--light);
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--secondary);
        }
        
        .search-results {
            max-height: 300px;
            overflow-y: auto;
            margin-top: 1rem;
            border-radius: 0.5rem;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--dark);
        }
        
        .form-control, .form-select {
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
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
                        <a href="manage_patients.php" class="nav-link">
                            <i class="bi bi-people"></i> Manage Patients
                        </a>
                        <a href="record_vitals.php" class="nav-link active">
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
                        <h2 class="mb-1">Record Patient Vitals</h2>
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

                <!-- Patient Search/Selection -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-search me-2"></i>Select Patient</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="mb-3">
                            <div class="input-group">
                                <input type="text" class="form-control" name="patient_search" placeholder="Search by Patient ID or Name" value="<?php echo isset($_GET['patient_search']) ? htmlspecialchars($_GET['patient_search']) : ''; ?>">
                                <button class="btn btn-outline-secondary" type="submit">Search</button>
                            </div>
                        </form>

                        <?php if (!empty($searchResults)): ?>
                            <div class="search-results">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Patient ID</th>
                                            <th>Name</th>
                                            <th>Gender</th>
                                            <th>Phone</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($searchResults as $result): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($result['patient_id']); ?></td>
                                                <td><?php echo htmlspecialchars($result['first_name'] . ' ' . $result['last_name']); ?></td>
                                                <td><?php echo htmlspecialchars($result['gender']); ?></td>
                                                <td><?php echo htmlspecialchars($result['phone']); ?></td>
                                                <td>
                                                    <a href="record_vitals.php?patient_id=<?php echo $result['id']; ?>" class="btn btn-sm btn-primary">Select</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif (isset($_GET['patient_search'])): ?>
                            <div class="empty-state">
                                <i class="bi bi-search"></i>
                                <h5>No patient found</h5>
                                <p class="text-muted">Please try another search</p>
                            </div>
                        <?php endif; ?>

                        <?php if ($patient): ?>
                            <div class="patient-info mt-4">
                                <h5><i class="bi bi-person-circle me-2"></i>Patient Information</h5>
                                <div class="row">
                                    <div class="col-md-4">
                                        <p><strong>Name:</strong> <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></p>
                                    </div>
                                    <div class="col-md-4">
                                        <p><strong>Patient ID:</strong> <?php echo htmlspecialchars($patient['patient_id']); ?></p>
                                    </div>
                                    <div class="col-md-4">
                                        <p><strong>Gender:</strong> <?php echo htmlspecialchars($patient['gender']); ?></p>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <p><strong>Age:</strong> <?php echo date_diff(date_create($patient['dob']), date_create('today'))->y; ?> years</p>
                                    </div>
                                    <div class="col-md-4">
                                        <p><strong>Blood Group:</strong> <?php echo htmlspecialchars($patient['blood_group']); ?></p>
                                    </div>
                                    <div class="col-md-4">
                                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($patient['phone']); ?></p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Vitals Form -->
                <?php if ($patient): ?>
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-heart-pulse me-2"></i>Vitals Measurement</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="patient_id" value="<?php echo $patient['id']; ?>">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="temperature" class="form-label">Temperature (°C)</label>
                                        <input type="number" step="0.1" class="form-control" id="temperature" name="temperature" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="blood_pressure" class="form-label">Blood Pressure (mmHg)</label>
                                        <input type="text" class="form-control" id="blood_pressure" name="blood_pressure" placeholder="e.g. 120/80" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="pulse_rate" class="form-label">Pulse Rate (bpm)</label>
                                        <input type="number" class="form-control" id="pulse_rate" name="pulse_rate" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="respiratory_rate" class="form-label">Respiratory Rate (breaths/min)</label>
                                        <input type="number" class="form-control" id="respiratory_rate" name="respiratory_rate" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="oxygen_saturation" class="form-label">Oxygen Saturation (%)</label>
                                        <input type="number" class="form-control" id="oxygen_saturation" name="oxygen_saturation" min="0" max="100">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="weight" class="form-label">Weight (kg)</label>
                                        <input type="number" step="0.1" class="form-control" id="weight" name="weight" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="height" class="form-label">Height (cm)</label>
                                        <input type="number" step="0.1" class="form-control" id="height" name="height" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="notes" class="form-label">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                            </div>
                            
                            <button type="submit" name="record_vitals" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i> Record Vitals
                            </button>
                        </form>
                    </div>
                </div>
                <?php elseif (!isset($_GET['patient_search'])): ?>
                    <div class="empty-state">
                        <i class="bi bi-person-plus"></i>
                        <h5>No patient selected</h5>
                        <p class="text-muted">Please search for and select a patient to record vitals</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Input validation
        document.querySelector('form[method="POST"]')?.addEventListener('submit', function(e) {
            const bp = document.getElementById('blood_pressure').value;
            if (bp && !/^\d+\/\d+$/.test(bp)) {
                alert('Please enter blood pressure in the format "120/80"');
                e.preventDefault();
            }
            
            const temp = parseFloat(document.getElementById('temperature').value);
            if (temp && (temp < 30 || temp > 45)) {
                alert('Please enter a valid temperature between 30°C and 45°C');
                e.preventDefault();
            }
        });
    </script>
</body>
</html>