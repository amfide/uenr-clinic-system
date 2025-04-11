<?php
require_once __DIR__ . '/../../config/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'records_keeper') {
    die("Unauthorized access");
}

$patientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($patientId <= 0) {
    die("Invalid patient ID");
}

// Fetch patient data
$stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
$stmt->execute([$patientId]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    die("Patient not found");
}

// Calculate age
$dob = new DateTime($patient['dob']);
$now = new DateTime();
$age = $dob->diff($now)->y;
?>

<div class="row">
    <div class="col-md-6">
        <div class="patient-info-row">
            <div class="patient-info-label">Patient ID</div>
            <div class="patient-info-value"><?php echo htmlspecialchars($patient['patient_id']); ?></div>
        </div>
        <div class="patient-info-row">
            <div class="patient-info-label">Full Name</div>
            <div class="patient-info-value"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></div>
        </div>
        <div class="patient-info-row">
            <div class="patient-info-label">Date of Birth</div>
            <div class="patient-info-value"><?php echo date('M j, Y', strtotime($patient['dob'])); ?> (<?php echo $age; ?> years)</div>
        </div>
        <div class="patient-info-row">
            <div class="patient-info-label">Gender</div>
            <div class="patient-info-value"><?php echo htmlspecialchars($patient['gender']); ?></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="patient-info-row">
            <div class="patient-info-label">Blood Group</div>
            <div class="patient-info-value">
                <?php if ($patient['blood_group'] != 'Unknown'): ?>
                    <span class="badge bg-danger"><?php echo htmlspecialchars($patient['blood_group']); ?></span>
                <?php else: ?>
                    <span class="badge bg-secondary">Unknown</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="patient-info-row">
            <div class="patient-info-label">Phone Number</div>
            <div class="patient-info-value"><?php echo htmlspecialchars($patient['phone']); ?></div>
        </div>
        <div class="patient-info-row">
            <div class="patient-info-label">Email</div>
            <div class="patient-info-value"><?php echo htmlspecialchars($patient['email'] ?: 'N/A'); ?></div>
        </div>
        <div class="patient-info-row">
            <div class="patient-info-label">Registered On</div>
            <div class="patient-info-value"><?php echo date('M j, Y H:i', strtotime($patient['registered_at'])); ?></div>
        </div>
    </div>
</div>
<div class="row mt-3">
    <div class="col-12">
        <div class="patient-info-row">
            <div class="patient-info-label">Address</div>
            <div class="patient-info-value"><?php echo htmlspecialchars($patient['address'] ?: 'N/A'); ?></div>
        </div>
    </div>
</div>