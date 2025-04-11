<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'records_keeper') {
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

// Handle patient registration
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register_patient'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $patientId = 'PAT-' . strtoupper(uniqid());
        $firstName = $_POST['first_name'];
        $lastName = $_POST['last_name'];
        $dob = $_POST['dob'];
        $gender = $_POST['gender'];
        $address = $_POST['address'];
        $phone = $_POST['phone'];
        $email = $_POST['email'];
        $bloodGroup = $_POST['blood_group'];
        
        try {
            $stmt = $pdo->prepare("INSERT INTO patients (patient_id, first_name, last_name, dob, gender, address, phone, email, blood_group, registered_by) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$patientId, $firstName, $lastName, $dob, $gender, $address, $phone, $email, $bloodGroup, $user['id']]);
            
            $_SESSION['success'] = "Patient registered successfully with ID: $patientId";
            logActivity($user['id'], "Registered new patient: $patientId");
            header("Location: records_keeper_dashboard.php");
            exit();
        } catch (PDOException $e) {
            $error = "Error registering patient: " . $e->getMessage();
        }
    }
}

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
    <title>Records Keeper Dashboard - UENR Clinic</title>
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
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: var(--dark);
            white-space: nowrap;
        }
        
        .table-responsive {
            border-radius: 0.5rem;
        }
        
        .search-box {
    position: relative;
    display: flex; /* Add flex display */
    align-items: center; /* Center items vertically */
        }

        .search-box input {
            padding-right: 40px; /* Make space for the button */
            width: 100%; /* Ensure input takes full width */
        }

        .search-box .btn {
            position: absolute;
            right: 0;
            top: 0;
            height: 100%;
            border-radius: 0 0.5rem 0.5rem 0;
            z-index: 2; /* Ensure button stays above input */
            pointer-events: none; /* Allow clicks to pass through to input */
        }

        .search-box .btn i {
            pointer-events: auto; /* Re-enable clicks for the icon */
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
                        <a href="records_keeper_dashboard.php" class="nav-link active">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                        <a href="manage_patients.php" class="nav-link">
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
                        <h2 class="mb-1">Records Management</h2>
                        <p class="text-muted mb-0">Welcome back, <?php echo $user['full_name']; ?></p>
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

                <!-- Patient Registration -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-person-plus me-2"></i>Patient Registration</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="first_name" name="first_name" placeholder="First Name" required>
                                        <label for="first_name">First Name</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="last_name" name="last_name" placeholder="Last Name" required>
                                        <label for="last_name">Last Name</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="date" class="form-control" id="dob" name="dob" placeholder="Date of Birth" required>
                                        <label for="dob">Date of Birth</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select class="form-select" id="gender" name="gender" required>
                                            <option value="">Select Gender</option>
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                            <option value="Other">Other</option>
                                        </select>
                                        <label for="gender">Gender</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-floating">
                                        <textarea class="form-control" id="address" name="address" placeholder="Address" style="height: 100px"></textarea>
                                        <label for="address">Address</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="tel" class="form-control" id="phone" name="phone" placeholder="Phone Number" required>
                                        <label for="phone">Phone Number</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="email" class="form-control" id="email" name="email" placeholder="Email">
                                        <label for="email">Email (optional)</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select class="form-select" id="blood_group" name="blood_group">
                                            <option value="Unknown">Unknown</option>
                                            <option value="A+">A+</option>
                                            <option value="A-">A-</option>
                                            <option value="B+">B+</option>
                                            <option value="B-">B-</option>
                                            <option value="AB+">AB+</option>
                                            <option value="AB-">AB-</option>
                                            <option value="O+">O+</option>
                                            <option value="O-">O-</option>
                                        </select>
                                        <label for="blood_group">Blood Group</label>
                                    </div>
                                </div>
                                <div class="col-12 mt-3">
                                    <button type="submit" name="register_patient" class="btn btn-primary px-4 py-2">
                                        <i class="bi bi-save me-2"></i>Register Patient
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Patient Search and Management 
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-search me-2"></i>Patient Search</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="mb-4">
                            <div class="input-group search-box">
                                <input type="text" class="form-control" name="search" placeholder="Search by Patient ID, Name or Phone" 
                                       value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                                <button class="btn btn-primary" type="submit">
                                    <i class="bi bi-search"></i> Search
                                </button>
                            </div>
                        </form>

                        <?php if (!empty($searchResults)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Patient ID</th>
                                            <th>Name</th>
                                            <th>Gender</th>
                                            <th>Age</th>
                                            <th>Phone</th>
                                            <th>Blood Group</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($searchResults as $patient): 
                                            $dob = new DateTime($patient['dob']);
                                            $now = new DateTime();
                                            $age = $dob->diff($now)->y;
                                        ?>
                                            <tr>
                                                <td class="fw-bold"><?php echo htmlspecialchars($patient['patient_id']); ?></td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></div>
                                                    <small class="text-muted"><?php echo date('M j, Y', strtotime($patient['dob'])); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($patient['gender']); ?></td>
                                                <td><?php echo $age; ?> years</td>
                                                <td><?php echo htmlspecialchars($patient['phone']); ?></td>
                                                <td>
                                                    <?php if ($patient['blood_group'] != 'Unknown'): ?>
                                                        <span class="badge bg-danger"><?php echo htmlspecialchars($patient['blood_group']); ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Unknown</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-2">
                                                        <a href="view_patient.php?id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="bi bi-eye"></i> View
                                                        </a>
                                                        <a href="edit_patient.php?id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                                            <i class="bi bi-pencil"></i> Edit
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif (isset($_GET['search'])): ?>
                            <div class="empty-state text-center py-5">
                                <i class="bi bi-search" style="font-size: 3rem; color: #dee2e6;"></i>
                                <h5 class="mt-3">No patients found</h5>
                                <p class="text-muted">No records match your search criteria</p>
                            </div>
                        <?php else: ?>
                            <div class="empty-state text-center py-5">
                                <i class="bi bi-search" style="font-size: 3rem; color: #dee2e6;"></i>
                                <h5 class="mt-3">Search for patients</h5>
                                <p class="text-muted">Enter patient details in the search box above</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>-->
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        (function() {
            'use strict';
            
            // Fetch all the forms we want to apply custom Bootstrap validation styles to
            const forms = document.querySelectorAll('form');
            
            // Loop over them and prevent submission
            Array.from(forms).forEach(form => {
                form.addEventListener('submit', event => {
                    if (!form.checkValidity()) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                    
                    form.classList.add('was-validated');
                }, false);
            });
        })();
        
        // Calculate age from DOB
        document.getElementById('dob').addEventListener('change', function() {
            const dob = new Date(this.value);
            const today = new Date();
            let age = today.getFullYear() - dob.getFullYear();
            const m = today.getMonth() - dob.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) {
                age--;
            }
            // You can display the age somewhere if needed
        });
    </script>
</body>
</html>