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
$appointments = [];
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'upcoming';
$search = isset($_GET['search']) ? $_GET['search'] : '';

try {
    // Base query
    $sql = "SELECT a.*, p.first_name, p.last_name, p.patient_id, u.full_name as doctor_name 
            FROM appointments a
            LEFT JOIN patients p ON a.patient_id = p.id
            LEFT JOIN users u ON a.doctor_id = u.id";
    
    // Add conditions based on filter
    $conditions = [];
    $params = [];
    
    if (!empty($search)) {
        $conditions[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_id LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    switch ($filter) {
        case 'upcoming':
            $conditions[] = "a.appointment_date >= CURDATE()";
            break;
        case 'past':
            $conditions[] = "a.appointment_date < CURDATE()";
            break;
        case 'today':
            $conditions[] = "DATE(a.appointment_date) = CURDATE()";
            break;
        case 'cancelled':
            $conditions[] = "a.status = 'Cancelled'";
            break;
    }
    
    // Combine conditions
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }
    
    // Add sorting
    $sql .= " ORDER BY a.appointment_date " . ($filter == 'past' ? 'DESC' : 'ASC');
    
    // Prepare and execute query
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $_SESSION['error'] = "A database error occurred while fetching appointments.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Appointments - UENR Clinic</title>
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
        
        .status-badge {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
        
        .badge-cancelled {
            background-color: var(--danger);
            color: white;
        }
        
        .badge-no-show {
            background-color: var(--dark);
            color: white;
        }
        
        .filter-active {
            font-weight: 600;
            color: var(--primary);
            border-bottom: 3px solid var(--secondary);
        }
        
        .appointment-card {
            height: 100%;
        }
        
        .patient-photo {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid var(--light);
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
        
        .search-box {
            position: relative;
        }
        
        .search-btn {
            border-radius: 0 0.5rem 0.5rem 0;
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
                        <a href="schedule_appointment.php" class="nav-link">
                            <i class="bi bi-calendar-plus"></i> Schedule Appointment
                        </a>
                        <a href="track_appointments.php" class="nav-link active">
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
                        <h2 class="mb-1">Track Appointments</h2>
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
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filter and Search Bar -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-8 mb-3 mb-md-0">
                                <div class="btn-group" role="group">
                                    <a href="?filter=upcoming" class="btn btn-outline-primary <?php echo $filter == 'upcoming' ? 'filter-active' : ''; ?>">
                                        <i class="bi bi-calendar-event"></i> Upcoming
                                    </a>
                                    <a href="?filter=today" class="btn btn-outline-primary <?php echo $filter == 'today' ? 'filter-active' : ''; ?>">
                                        <i class="bi bi-calendar-day"></i> Today
                                    </a>
                                    <a href="?filter=past" class="btn btn-outline-primary <?php echo $filter == 'past' ? 'filter-active' : ''; ?>">
                                        <i class="bi bi-calendar-check"></i> Past
                                    </a>
                                    <a href="?filter=cancelled" class="btn btn-outline-primary <?php echo $filter == 'cancelled' ? 'filter-active' : ''; ?>">
                                        <i class="bi bi-calendar-x"></i> Cancelled
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <form method="get" class="d-flex">
                                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                                    <input type="text" name="search" class="form-control rounded-start" 
                                           placeholder="Search patients..." value="<?php echo htmlspecialchars($search); ?>">
                                    <button type="submit" class="btn btn-primary search-btn">
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
                                            <img src="<?php echo !empty($appt['photo']) ? htmlspecialchars($appt['photo']) : '../assets/default_profile.jpg'; ?>" 
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
                                            <p class="mb-1"><strong><i class="bi bi-calendar me-1"></i> Date & Time:</strong></p>
                                            <p><?php echo date('F j, Y g:i A', strtotime($appt['appointment_date'])); ?></p>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <p class="mb-1"><strong><i class="bi bi-info-circle me-1"></i> Purpose:</strong></p>
                                            <p><?php echo htmlspecialchars($appt['purpose']); ?></p>
                                        </div>
                                        
                                        <?php if (!empty($appt['department'])): ?>
                                            <div class="mb-3">
                                                <p class="mb-1"><strong><i class="bi bi-building me-1"></i> Department:</strong></p>
                                                <p><?php echo htmlspecialchars($appt['department']); ?></p>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($appt['doctor_name'])): ?>
                                            <div class="mb-3">
                                                <p class="mb-1"><strong><i class="bi bi-person-badge me-1"></i> Doctor:</strong></p>
                                                <p>Dr. <?php echo htmlspecialchars($appt['doctor_name']); ?></p>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="d-flex justify-content-between mt-3">
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" 
                                                    data-bs-target="#notesModal<?php echo $appt['id']; ?>">
                                                <i class="bi bi-journal-text me-1"></i> Notes
                                            </button>
                                            
                                            <div class="btn-group">
                                                <?php if ($appt['status'] == 'Scheduled' || $appt['status'] == 'Confirmed'): ?>
                                                    <a href="update_appointment_status.php?id=<?php echo $appt['id']; ?>&status=completed" 
                                                       class="btn btn-sm btn-success">
                                                        <i class="bi bi-check-circle me-1"></i> Complete
                                                    </a>
                                                    <a href="update_appointment_status.php?id=<?php echo $appt['id']; ?>&status=cancelled" 
                                                       class="btn btn-sm btn-danger">
                                                        <i class="bi bi-x-circle me-1"></i> Cancel
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Notes Modal -->
                                <div class="modal fade" id="notesModal<?php echo $appt['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Appointment Notes</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <?php if (!empty($appt['notes'])): ?>
                                                    <?php echo nl2br(htmlspecialchars($appt['notes'])); ?>
                                                <?php else: ?>
                                                    <div class="empty-state">
                                                        <i class="bi bi-journal-x"></i>
                                                        <p class="text-muted">No notes available for this appointment.</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            </div>
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
                        <p class="text-muted">No appointments match your current filter criteria</p>
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
    </script>
</body>
</html>