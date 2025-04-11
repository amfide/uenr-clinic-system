<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'doctor') {
    header("Location: /public/dashboards/{$user['role']}_dashboard.php");
    exit();
}

// Handle appointment actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid security token.";
    } else {
        $appointmentId = $_POST['appointment_id'];
        $patientId = $_POST['patient_id'];
        
        try {
            if (isset($_POST['confirm_appointment'])) {
                // Confirm appointment
                $stmt = $pdo->prepare("UPDATE appointments 
                                      SET status = 'Confirmed', 
                                          confirmed_by = ?, 
                                          confirmed_at = NOW() 
                                      WHERE id = ?");
                $stmt->execute([$user['id'], $appointmentId]);
                $_SESSION['success'] = "Appointment confirmed successfully";
                
            } elseif (isset($_POST['complete_appointment'])) {
                // Complete appointment
                $stmt = $pdo->prepare("UPDATE appointments 
                                      SET status = 'Completed', 
                                          completed_at = NOW(),
                                          notes = ?
                                      WHERE id = ?");
                $stmt->execute([$_POST['notes'], $appointmentId]);
                $_SESSION['success'] = "Appointment marked as completed";
                
            } elseif (isset($_POST['cancel_appointment'])) {
                // Cancel appointment
                $stmt = $pdo->prepare("UPDATE appointments 
                                      SET status = 'Cancelled', 
                                          cancelled_at = NOW(),
                                          cancellation_reason = ?
                                      WHERE id = ?");
                $stmt->execute([$_POST['cancellation_reason'], $appointmentId]);
                $_SESSION['success'] = "Appointment cancelled";
                
            } elseif (isset($_POST['request_lab_test'])) {
                // Request lab test
                $stmt = $pdo->prepare("INSERT INTO test_requests 
                                     (patient_id, requested_by, appointment_id, notes) 
                                     VALUES (?, ?, ?, ?)");
                $stmt->execute([$patientId, $user['id'], $appointmentId, $_POST['test_notes']]);
                $requestId = $pdo->lastInsertId();
                
                // Add test items
                foreach ($_POST['test_ids'] as $testId) {
                    $stmt = $pdo->prepare("INSERT INTO test_request_items 
                                         (request_id, test_id) 
                                         VALUES (?, ?)");
                    $stmt->execute([$requestId, $testId]);
                }
                
                $_SESSION['success'] = "Lab test requested successfully";
            }
            
            logActivity($user['id'], "Appointment action for ID: $appointmentId");
            header("Location: manage_appointments.php");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'upcoming';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Get appointments based on filter
try {
    $sql = "SELECT a.*, 
                   p.first_name, p.last_name, p.patient_id, p.phone,
                   d.name AS department_name,
                   u.full_name AS doctor_name
            FROM appointments a
            JOIN patients p ON a.patient_id = p.id
            LEFT JOIN departments d ON a.department_id = d.id
            LEFT JOIN users u ON a.doctor_id = u.id
            WHERE 1=1";
    
    $params = [];
    
    // Apply filters
    if (!empty($search)) {
        $sql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_id LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    switch ($filter) {
        case 'upcoming':
            $sql .= " AND a.appointment_date >= NOW() AND a.status IN ('Scheduled', 'Confirmed')";
            break;
        case 'today':
            $sql .= " AND DATE(a.appointment_date) = CURDATE()";
            break;
        case 'past':
            $sql .= " AND a.appointment_date < NOW()";
            break;
        case 'completed':
            $sql .= " AND a.status = 'Completed'";
            break;
        case 'cancelled':
            $sql .= " AND a.status = 'Cancelled'";
            break;
    }
    
    $sql .= " ORDER BY a.appointment_date " . ($filter == 'past' ? 'DESC' : 'ASC');
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all lab tests for the lab test request modal
    $stmt = $pdo->prepare("SELECT * FROM lab_tests ORDER BY test_name");
    $stmt->execute();
    $labTests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $_SESSION['error'] = "Failed to load appointments. Please try again later.";
    $appointments = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Appointments - UENR Clinic</title>
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
        
        .patient-photo {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid var(--light);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .badge {
            font-weight: 500;
            padding: 0.35em 0.65em;
        }
        
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
        
        .status-badge {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-active {
            font-weight: bold;
            border-bottom: 3px solid var(--secondary);
        }
        
        .appointment-card {
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .appointment-card .card-body {
            flex-grow: 1;
        }
        
        .action-btns .btn {
            margin-right: 5px;
            margin-bottom: 5px;
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
        
        .search-card .form-control {
            border-radius: 0.5rem;
            border-right: none;
        }
        
        .search-card .btn {
            border-radius: 0.5rem;
        }
        
        .modal-content {
            border-radius: 0.5rem;
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
                        <a href="manage_patients_doctor.php" class="nav-link">
                            <i class="bi bi-people-fill"></i> Patient Management
                        </a>
                        <a href="manage_appointments.php" class="nav-link active">
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
                        <h2 class="mb-1">Appointment Management</h2>
                        <p class="text-muted mb-0">Manage and track patient appointments</p>
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
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filter and Search Card -->
                <div class="card search-card">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <div class="btn-group" role="group">
                                    <a href="?filter=upcoming" class="btn btn-outline-primary <?php echo $filter == 'upcoming' ? 'filter-active' : ''; ?>">
                                        <i class="bi bi-calendar-plus"></i> Upcoming
                                    </a>
                                    <a href="?filter=today" class="btn btn-outline-primary <?php echo $filter == 'today' ? 'filter-active' : ''; ?>">
                                        <i class="bi bi-calendar-day"></i> Today
                                    </a>
                                    <a href="?filter=past" class="btn btn-outline-primary <?php echo $filter == 'past' ? 'filter-active' : ''; ?>">
                                        <i class="bi bi-calendar-minus"></i> Past
                                    </a>
                                    <a href="?filter=completed" class="btn btn-outline-primary <?php echo $filter == 'completed' ? 'filter-active' : ''; ?>">
                                        <i class="bi bi-check-circle"></i> Completed
                                    </a>
                                    <a href="?filter=cancelled" class="btn btn-outline-primary <?php echo $filter == 'cancelled' ? 'filter-active' : ''; ?>">
                                        <i class="bi bi-x-circle"></i> Cancelled
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <form method="get" class="d-flex">
                                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                                    <input type="text" name="search" class="form-control" 
                                           placeholder="Search patients..." 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                    <button type="submit" class="btn btn-primary ms-2">
                                        <i class="bi bi-search"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Appointments List -->
                <?php if (!empty($appointments)): ?>
                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                        <?php foreach ($appointments as $appt): ?>
                            <div class="col">
                                <div class="card appointment-card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center">
                                            <img src="<?php echo !empty($appt['photo']) ? htmlspecialchars($appt['photo']) : 'assets/images/default_profile.jpg'; ?>" 
                                                 alt="Patient" class="patient-photo me-3">
                                            <div>
                                                <h5 class="mb-0"><?php echo htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']); ?></h5>
                                                <small class="text-muted">ID: <?php echo htmlspecialchars($appt['patient_id']); ?></small>
                                            </div>
                                        </div>
                                        <span class="badge status-badge badge-<?php echo strtolower($appt['status']); ?>">
                                            <?php echo htmlspecialchars($appt['status']); ?>
                                        </span>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <p class="mb-1"><strong><i class="bi bi-clock"></i> Date & Time:</strong></p>
                                            <p><?php echo date('F j, Y g:i A', strtotime($appt['appointment_date'])); ?></p>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <p class="mb-1"><strong><i class="bi bi-building"></i> Department:</strong></p>
                                            <p><?php echo htmlspecialchars($appt['department_name'] ?? 'General'); ?></p>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <p class="mb-1"><strong><i class="bi bi-clipboard"></i> Reason:</strong></p>
                                            <p><?php echo htmlspecialchars($appt['purpose']); ?></p>
                                        </div>
                                        
                                        <div class="d-flex flex-wrap action-btns">
                                            <?php if ($appt['status'] == 'Scheduled'): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="appointment_id" value="<?php echo $appt['id']; ?>">
                                                    <input type="hidden" name="patient_id" value="<?php echo $appt['patient_id']; ?>">
                                                    <button type="submit" name="confirm_appointment" class="btn btn-sm btn-success">
                                                        <i class="bi bi-check-circle"></i> Confirm
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <?php if ($appt['status'] == 'Confirmed' || $appt['status'] == 'Scheduled'): ?>
                                                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" 
                                                        data-bs-target="#completeModal<?php echo $appt['id']; ?>">
                                                    <i class="bi bi-check2-all"></i> Complete
                                                </button>
                                                
                                                <button class="btn btn-sm btn-danger" data-bs-toggle="modal" 
                                                        data-bs-target="#cancelModal<?php echo $appt['id']; ?>">
                                                    <i class="bi bi-x-circle"></i> Cancel
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button class="btn btn-sm btn-info" data-bs-toggle="modal" 
                                                    data-bs-target="#labTestModal<?php echo $appt['id']; ?>">
                                                <i class="bi bi-droplet"></i> Lab Test
                                            </button>
                                            
                                            <a href="medical_record.php?patient_id=<?php echo $appt['patient_id']; ?>" 
                                               class="btn btn-sm btn-warning">
                                                <i class="bi bi-file-earmark-medical"></i> Record
                                            </a>
                                            
                                            <a href="appointment_management.php?id=<?php echo $appt['patient_id']; ?>" 
                                               class="btn btn-sm btn-secondary">
                                                <i class="bi bi-person"></i> Profile
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Complete Appointment Modal -->
                                <div class="modal fade" id="completeModal<?php echo $appt['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Complete Appointment</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="appointment_id" value="<?php echo $appt['id']; ?>">
                                                    <input type="hidden" name="patient_id" value="<?php echo $appt['patient_id']; ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label for="notes<?php echo $appt['id']; ?>" class="form-label">Notes</label>
                                                        <textarea class="form-control" id="notes<?php echo $appt['id']; ?>" 
                                                                  name="notes" rows="3"></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                    <button type="submit" name="complete_appointment" class="btn btn-primary">Complete Appointment</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Cancel Appointment Modal -->
                                <div class="modal fade" id="cancelModal<?php echo $appt['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Cancel Appointment</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="appointment_id" value="<?php echo $appt['id']; ?>">
                                                    <input type="hidden" name="patient_id" value="<?php echo $appt['patient_id']; ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label for="reason<?php echo $appt['id']; ?>" class="form-label">Reason for Cancellation</label>
                                                        <textarea class="form-control" id="reason<?php echo $appt['id']; ?>" 
                                                                  name="cancellation_reason" rows="3" required></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                    <button type="submit" name="cancel_appointment" class="btn btn-danger">Cancel Appointment</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Lab Test Request Modal -->
                                <div class="modal fade" id="labTestModal<?php echo $appt['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Request Lab Test</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="appointment_id" value="<?php echo $appt['id']; ?>">
                                                    <input type="hidden" name="patient_id" value="<?php echo $appt['patient_id']; ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label">Select Tests</label>
                                                        <div class="row">
                                                            <?php foreach ($labTests as $test): ?>
                                                                <div class="col-md-6">
                                                                    <div class="form-check">
                                                                        <input class="form-check-input" type="checkbox" 
                                                                               name="test_ids[]" value="<?php echo $test['id']; ?>" 
                                                                               id="test<?php echo $test['id']; ?>_<?php echo $appt['id']; ?>">
                                                                        <label class="form-check-label" for="test<?php echo $test['id']; ?>_<?php echo $appt['id']; ?>">
                                                                            <?php echo htmlspecialchars($test['test_name']); ?>
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label for="test_notes<?php echo $appt['id']; ?>" class="form-label">Notes</label>
                                                        <textarea class="form-control" id="test_notes<?php echo $appt['id']; ?>" 
                                                                  name="test_notes" rows="3"></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                    <button type="submit" name="request_lab_test" class="btn btn-primary">Request Tests</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-calendar-x"></i>
                        <h5>No appointments found</h5>
                        <p class="text-muted"><?php echo empty($search) ? 'No appointments match the selected filter' : 'No appointments match your search criteria'; ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh the page every 5 minutes to get new appointments
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