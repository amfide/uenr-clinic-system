<?php
require_once '../includes/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'pharmacist') {
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

// Handle medicine dispensing
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['dispense_medicine'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $prescriptionId = $_POST['prescription_id'];
        $itemId = $_POST['item_id'];
        $quantity = $_POST['quantity'];
        
        try {
            // Check medicine stock
            $stmt = $pdo->prepare("SELECT m.stock_quantity, pi.quantity 
                                  FROM prescription_items pi
                                  JOIN medicines m ON pi.medicine_id = m.id
                                  WHERE pi.id = ?");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            
            if ($item['stock_quantity'] >= $quantity) {
                // Update dispensed quantity
                $stmt = $pdo->prepare("UPDATE prescription_items SET dispensed_quantity = ? WHERE id = ?");
                $stmt->execute([$quantity, $itemId]);
                
                // Update medicine stock
                $stmt = $pdo->prepare("UPDATE medicines SET stock_quantity = stock_quantity - ? WHERE id = (SELECT medicine_id FROM prescription_items WHERE id = ?)");
                $stmt->execute([$quantity, $itemId]);
                
                // Check if all items are dispensed
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM prescription_items WHERE prescription_id = ? AND dispensed_quantity < quantity");
                $stmt->execute([$prescriptionId]);
                $pendingItems = $stmt->fetchColumn();
                
                if ($pendingItems == 0) {
                    $stmt = $pdo->prepare("UPDATE prescriptions SET status = 'Fulfilled' WHERE id = ?");
                    $stmt->execute([$prescriptionId]);
                }
                
                $_SESSION['success'] = "Medicine dispensed successfully";
                logActivity($user['id'], "Dispensed medicine for prescription item ID: $itemId");
            } else {
                $error = "Insufficient stock to dispense this quantity";
            }
        } catch (PDOException $e) {
            $error = "Error dispensing medicine: " . $e->getMessage();
        }
    }
}

// Handle stock update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_stock'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $medicineId = $_POST['medicine_id'];
        $quantity = $_POST['quantity'];
        
        try {
            $stmt = $pdo->prepare("UPDATE medicines SET stock_quantity = stock_quantity + ?, last_restocked = NOW() WHERE id = ?");
            $stmt->execute([$quantity, $medicineId]);
            
            $_SESSION['success'] = "Medicine stock updated successfully";
            logActivity($user['id'], "Updated stock for medicine ID: $medicineId");
        } catch (PDOException $e) {
            $error = "Error updating medicine stock: " . $e->getMessage();
        }
    }
}

// Get pending prescriptions
$stmt = $pdo->prepare("SELECT p.*, pt.first_name, pt.last_name, pt.patient_id 
                       FROM prescriptions p
                       JOIN patients pt ON p.patient_id = pt.id
                       WHERE p.status = 'Pending'
                       ORDER BY p.prescription_date ASC");
$stmt->execute();
$pendingPrescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get medicines low in stock
$stmt = $pdo->prepare("SELECT * FROM medicines WHERE stock_quantity <= reorder_level ORDER BY stock_quantity ASC");
$stmt->execute();
$lowStockMedicines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all medicines
$stmt = $pdo->prepare("SELECT * FROM medicines ORDER BY name");
$stmt->execute();
$allMedicines = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacist Dashboard - UENR Clinic</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .sidebar {
            min-height: 100vh;
            background-color: #198754; /* Pharmacy green */
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
            background-color: #157347;
        }
        .main-content {
            padding: 20px;
        }
        .card {
            margin-bottom: 20px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .out-of-stock {
            color: #dc3545;
            font-weight: bold;
        }
        .low-stock {
            color: #fd7e14;
            font-weight: bold;
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
                    <p class="mb-0">Welcome, <?php echo $user['full_name']; ?></p>
                    <small>(Pharmacist)</small>
                </div>
                <a href="pharmacist_dashboard.php" class="active"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="#dispense" data-bs-toggle="collapse"><i class="bi bi-capsule"></i> Dispense Medicine</a>
                <a href="#stock" data-bs-toggle="collapse"><i class="bi bi-box-seam"></i> Medicine Stock</a>
                <a href="../auth/logout.php"><i class="bi bi-box-arrow-left"></i> Logout</a>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 main-content">
                <h2 class="mb-4">Pharmacist Dashboard</h2>
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Pending Prescriptions -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">Pending Prescriptions</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($pendingPrescriptions)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Prescription ID</th>
                                            <th>Patient ID</th>
                                            <th>Patient Name</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pendingPrescriptions as $prescription): ?>
                                            <tr>
                                                <td>RX-<?php echo $prescription['id']; ?></td>
                                                <td><?php echo htmlspecialchars($prescription['patient_id']); ?></td>
                                                <td><?php echo htmlspecialchars($prescription['first_name'] . ' ' . $prescription['last_name']); ?></td>
                                                <td><?php echo date('d/m/Y', strtotime($prescription['prescription_date'])); ?></td>
                                                <td>
                                                    <span class="badge bg-warning text-dark">Pending</span>
                                                </td>
                                                <td>
                                                    <a href="view_prescription.php?id=<?php echo $prescription['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-eye"></i> View & Dispense
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">No pending prescriptions found.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Low Stock Medicines -->
                <div class="card mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="card-title mb-0">Medicines Low in Stock</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($lowStockMedicines)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Medicine Name</th>
                                            <th>Current Stock</th>
                                            <th>Reorder Level</th>
                                            <th>Unit Price</th>
                                            <th>Last Restocked</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($lowStockMedicines as $medicine): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($medicine['name']); ?></td>
                                                <td class="<?php echo $medicine['stock_quantity'] == 0 ? 'out-of-stock' : 'low-stock'; ?>">
                                                    <?php echo $medicine['stock_quantity']; ?>
                                                </td>
                                                <td><?php echo $medicine['reorder_level']; ?></td>
                                                <td>GH₵<?php echo number_format($medicine['unit_price'], 2); ?></td>
                                                <td><?php echo $medicine['last_restocked'] ? date('d/m/Y', strtotime($medicine['last_restocked'])) : 'Never'; ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#restockModal" 
                                                            data-medicine-id="<?php echo $medicine['id']; ?>" 
                                                            data-medicine-name="<?php echo htmlspecialchars($medicine['name']); ?>">
                                                        <i class="bi bi-plus-circle"></i> Restock
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success">All medicines have sufficient stock.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Medicine Stock Management -->
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0">Medicine Stock Management</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Medicine Name</th>
                                        <th>Description</th>
                                        <th>Current Stock</th>
                                        <th>Unit Price</th>
                                        <th>Reorder Level</th>
                                        <th>Last Restocked</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allMedicines as $medicine): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($medicine['name']); ?></td>
                                            <td><?php echo htmlspecialchars($medicine['description']); ?></td>
                                            <td class="<?php echo $medicine['stock_quantity'] <= $medicine['reorder_level'] ? ($medicine['stock_quantity'] == 0 ? 'out-of-stock' : 'low-stock') : ''; ?>">
                                                <?php echo $medicine['stock_quantity']; ?>
                                            </td>
                                            <td>GH₵<?php echo number_format($medicine['unit_price'], 2); ?></td>
                                            <td><?php echo $medicine['reorder_level']; ?></td>
                                            <td><?php echo $medicine['last_restocked'] ? date('d/m/Y', strtotime($medicine['last_restocked'])) : 'Never'; ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#restockModal" 
                                                        data-medicine-id="<?php echo $medicine['id']; ?>" 
                                                        data-medicine-name="<?php echo htmlspecialchars($medicine['name']); ?>">
                                                    <i class="bi bi-plus-circle"></i> Restock
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Restock Modal -->
    <div class="modal fade" id="restockModal" tabindex="-1" aria-labelledby="restockModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="restockModalLabel">Restock Medicine</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="medicine_id" id="modalMedicineId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="medicineName" class="form-label">Medicine</label>
                            <input type="text" class="form-control" id="medicineName" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="quantity" class="form-label">Quantity to Add</label>
                            <input type="number" class="form-control" id="quantity" name="quantity" min="1" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_stock" class="btn btn-primary" 
                                onclick="return confirm('Confirm this stock update?')">
                            <i class="bi bi-check-circle"></i> Update Stock
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Dispense Modal (would be loaded via view_prescription.php) -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
        // Handle modal data for restocking
        var restockModal = document.getElementById('restockModal');
        restockModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var medicineId = button.getAttribute('data-medicine-id');
            var medicineName = button.getAttribute('data-medicine-name');
            
            document.getElementById('modalMedicineId').value = medicineId;
            document.getElementById('medicineName').value = medicineName;
        });

        // Auto-focus quantity field when modal opens
        restockModal.addEventListener('shown.bs.modal', function () {
            document.getElementById('quantity').focus();
        });
    </script>
</body>
</html>