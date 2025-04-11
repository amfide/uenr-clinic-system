<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'records_keeper') {
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

// Get patient ID from URL
$patientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch patient data
$patient = [];
if ($patientId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$patient) {
    $_SESSION['error'] = "Patient not found";
    header("Location: manage_patients.php");
    exit();
}

// Calculate age from DOB
$dob = new DateTime($patient['dob']);
$now = new DateTime();
$age = $dob->diff($now)->y;

// Handle patient update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_patient'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $firstName = $_POST['first_name'];
        $lastName = $_POST['last_name'];
        $dob = $_POST['dob'];
        $gender = $_POST['gender'];
        $address = $_POST['address'];
        $phone = $_POST['phone'];
        $email = $_POST['email'];
        $bloodGroup = $_POST['blood_group'];
        
        try {
            $stmt = $pdo->prepare("UPDATE patients SET 
                                  first_name = ?, 
                                  last_name = ?, 
                                  dob = ?, 
                                  gender = ?, 
                                  address = ?, 
                                  phone = ?, 
                                  email = ?, 
                                  blood_group = ?,
                                  updated_at = NOW()
                                  WHERE id = ?");
            $stmt->execute([$firstName, $lastName, $dob, $gender, $address, $phone, $email, $bloodGroup, $patientId]);
            
            $_SESSION['success'] = "Patient information updated successfully";
            $_SESSION['show_success_modal'] = true;
            logActivity($user['id'], "Updated patient record: {$patient['patient_id']}");
            header("Location: patient_details_records.php?id=$patientId");
            exit();
        } catch (PDOException $e) {
            $error = "Error updating patient: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Details - UENR Clinic</title>
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
        
        .form-control, .form-select {
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            border: 1px solid #e0e0e0;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 0.25rem rgba(52,152,219,0.25);
        }
        
        .btn-primary {
            background-color: var(--primary);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
        }
        
        .btn-primary:hover {
            background-color: #1a252f;
        }
        
        .alert {
            border-radius: 0.5rem;
        }
        
        .patient-info-card {
            border-left: 4px solid var(--primary);
        }
        
        .info-label {
            font-weight: 600;
            color: var(--dark);
        }
        
        .info-value {
            color: #495057;
        }
        
        .edit-toggle {
            cursor: pointer;
            color: var(--secondary);
        }
        
        .edit-toggle:hover {
            text-decoration: underline;
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
                        <h2 class="mb-1">Patient Details</h2>
                        <p class="text-muted mb-0">View and manage patient information</p>
                    </div>
                    <div>
                        <a href="manage_patients.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Patients
                        </a>
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

                <!-- Patient Information -->
                <div class="card patient-info-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-person-circle me-2"></i>
                            <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?>
                        </h5>
                        <span class="badge bg-primary">Patient ID: <?php echo htmlspecialchars($patient['patient_id']); ?></span>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="patientForm">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="first_name" class="form-label">First Name</label>
                                        <input type="text" class="form-control" id="first_name" name="first_name" 
                                               value="<?php echo htmlspecialchars($patient['first_name']); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="last_name" class="form-label">Last Name</label>
                                        <input type="text" class="form-control" id="last_name" name="last_name" 
                                               value="<?php echo htmlspecialchars($patient['last_name']); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="dob" class="form-label">Date of Birth</label>
                                        <input type="date" class="form-control" id="dob" name="dob" 
                                               value="<?php echo htmlspecialchars($patient['dob']); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Age</label>
                                        <input type="text" class="form-control" value="<?php echo $age; ?> years" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="gender" class="form-label">Gender</label>
                                        <select class="form-select" id="gender" name="gender" required>
                                            <option value="Male" <?php echo $patient['gender'] == 'Male' ? 'selected' : ''; ?>>Male</option>
                                            <option value="Female" <?php echo $patient['gender'] == 'Female' ? 'selected' : ''; ?>>Female</option>
                                            <option value="Other" <?php echo $patient['gender'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="blood_group" class="form-label">Blood Group</label>
                                        <select class="form-select" id="blood_group" name="blood_group">
                                            <option value="Unknown" <?php echo $patient['blood_group'] == 'Unknown' ? 'selected' : ''; ?>>Unknown</option>
                                            <option value="A+" <?php echo $patient['blood_group'] == 'A+' ? 'selected' : ''; ?>>A+</option>
                                            <option value="A-" <?php echo $patient['blood_group'] == 'A-' ? 'selected' : ''; ?>>A-</option>
                                            <option value="B+" <?php echo $patient['blood_group'] == 'B+' ? 'selected' : ''; ?>>B+</option>
                                            <option value="B-" <?php echo $patient['blood_group'] == 'B-' ? 'selected' : ''; ?>>B-</option>
                                            <option value="AB+" <?php echo $patient['blood_group'] == 'AB+' ? 'selected' : ''; ?>>AB+</option>
                                            <option value="AB-" <?php echo $patient['blood_group'] == 'AB-' ? 'selected' : ''; ?>>AB-</option>
                                            <option value="O+" <?php echo $patient['blood_group'] == 'O+' ? 'selected' : ''; ?>>O+</option>
                                            <option value="O-" <?php echo $patient['blood_group'] == 'O-' ? 'selected' : ''; ?>>O-</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <input type="tel" class="form-control" id="phone" name="phone" 
                                               value="<?php echo htmlspecialchars($patient['phone']); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email</label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo htmlspecialchars($patient['email']); ?>">
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="mb-3">
                                        <label for="address" class="form-label">Address</label>
                                        <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($patient['address']); ?></textarea>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Registered On</label>
                                        <input type="text" class="form-control" 
                                               value="<?php echo date('M j, Y H:i', strtotime($patient['registered_at'])); ?>" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Last Updated</label>
                                        <input type="text" class="form-control" 
                                               value="<?php echo $patient['updated_at'] ? date('M j, Y H:i', strtotime($patient['updated_at'])) : 'Never'; ?>" readonly>
                                    </div>
                                </div>
                                <div class="col-12 mt-4">
                                    <button type="submit" name="update_patient" class="btn btn-primary me-2">
                                        <i class="bi bi-save"></i> Save Changes
                                    </button>
                                    <a href="records_keeper_dashboard.php?id=<?php echo $patientId; ?>" class="btn btn-outline-secondary">
                                        <i class="bi bi-x-circle"></i> Cancel
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Patient History Section -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Patient History</h5>
                    </div>
                    <div class="card-body">
                        <ul class="nav nav-tabs" id="patientHistoryTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="visits-tab" data-bs-toggle="tab" data-bs-target="#visits" type="button" role="tab">
                                    <i class="bi bi-calendar-check"></i> Visits
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="lab-tab" data-bs-toggle="tab" data-bs-target="#lab" type="button" role="tab">
                                    <i class="bi bi-flask"></i> Lab Tests
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="prescriptions-tab" data-bs-toggle="tab" data-bs-target="#prescriptions" type="button" role="tab">
                                    <i class="bi bi-capsule"></i> Prescriptions
                                </button>
                            </li>
                        </ul>
                        <div class="tab-content p-3 border border-top-0 rounded-bottom" id="patientHistoryTabsContent">
                            <div class="tab-pane fade show active" id="visits" role="tabpanel">
                                <div class="empty-state">
                                    <i class="bi bi-calendar-check" style="font-size: 3rem; color: #dee2e6;"></i>
                                    <h5 class="mt-3">No visit history</h5>
                                    <p class="text-muted">This patient has no recorded visits yet</p>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="lab" role="tabpanel">
                                <div class="empty-state">
                                    <i class="bi bi-flask" style="font-size: 3rem; color: #dee2e6;"></i>
                                    <h5 class="mt-3">No lab tests</h5>
                                    <p class="text-muted">This patient has no lab test records</p>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="prescriptions" role="tabpanel">
                                <div class="empty-state">
                                    <i class="bi bi-capsule" style="font-size: 3rem; color: #dee2e6;"></i>
                                    <h5 class="mt-3">No prescriptions</h5>
                                    <p class="text-muted">This patient has no prescription records</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-check-circle-fill me-2"></i>Success</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Patient information has been updated successfully.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show success modal if session variable is set
        <?php if (isset($_SESSION['show_success_modal']) && $_SESSION['show_success_modal']): ?>
            document.addEventListener('DOMContentLoaded', function() {
                var successModal = new bootstrap.Modal(document.getElementById('successModal'));
                successModal.show();
                
                // Clear the session flag
                fetch('clear_modal_session.php')
                    .then(response => response.text())
                    .then(data => console.log('Modal session cleared'))
                    .catch(error => console.error('Error:', error));
            });
        <?php unset($_SESSION['show_success_modal']); endif; ?>

        // Calculate age when DOB changes
        document.getElementById('dob').addEventListener('change', function() {
            const dob = new Date(this.value);
            const today = new Date();
            let age = today.getFullYear() - dob.getFullYear();
            const monthDiff = today.getMonth() - dob.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
                age--;
            }
            
            // Update age display
            const ageInputs = document.querySelectorAll('input[value*="years"]');
            ageInputs.forEach(input => {
                input.value = age + ' years';
            });
        });
        
        // Form validation
        document.getElementById('patientForm').addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    isValid = false;
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields');
            }
        });
    </script>
</body>
</html>