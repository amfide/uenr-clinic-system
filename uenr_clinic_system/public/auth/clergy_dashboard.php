<?php
require_once '../includes/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'clergy') {
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

// Handle financial transaction
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['record_transaction'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $transactionType = $_POST['transaction_type'];
        $amount = $_POST['amount'];
        $category = $_POST['category'];
        $description = $_POST['description'];
        
        try {
            $stmt = $pdo->prepare("INSERT INTO financial_transactions (transaction_type, amount, category, description, recorded_by) 
                                  VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$transactionType, $amount, $category, $description, $user['id']]);
            
            $_SESSION['success'] = "Financial transaction recorded successfully";
            logActivity($user['id'], "Recorded $transactionType transaction: $category");
        } catch (PDOException $e) {
            $error = "Error recording transaction: " . $e->getMessage();
        }
    }
}

// Get recent financial transactions
$stmt = $pdo->prepare("SELECT ft.*, u.full_name 
                       FROM financial_transactions ft
                       JOIN users u ON ft.recorded_by = u.id
                       ORDER BY ft.transaction_date DESC
                       LIMIT 50");
$stmt->execute();
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$incomeTotal = 0;
$expenseTotal = 0;

$stmt = $pdo->prepare("SELECT SUM(amount) FROM financial_transactions WHERE transaction_type = 'Income'");
$stmt->execute();
$incomeTotal = $stmt->fetchColumn() ?? 0;

$stmt = $pdo->prepare("SELECT SUM(amount) FROM financial_transactions WHERE transaction_type = 'Expense'");
$stmt->execute();
$expenseTotal = $stmt->fetchColumn() ?? 0;

$balance = $incomeTotal - $expenseTotal;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clergy Dashboard - UENR Clinic</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
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
                    <small>(Clergy)</small>
                </div>
                <a href="clergy_dashboard.php" class="active"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="#financial" data-bs-toggle="collapse"><i class="bi bi-cash-stack"></i> Financial Management</a>
                <a href="#expenses" data-bs-toggle="collapse"><i class="bi bi-receipt"></i> Expense Tracking</a>
                <a href="../auth/logout.php"><i class="bi bi-box-arrow-left"></i> Logout</a>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 main-content">
                <h2 class="mb-4">Clergy Dashboard</h2>
                
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

                <!-- Financial Summary -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <h5 class="card-title">Total Income</h5>
                                <h3 class="card-text">GH₵<?php echo number_format($incomeTotal, 2); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-white bg-danger">
                            <div class="card-body">
                                <h5 class="card-title">Total Expenses</h5>
                                <h3 class="card-text">GH₵<?php echo number_format($expenseTotal, 2); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-white bg-primary">
                            <div class="card-body">
                                <h5 class="card-title">Current Balance</h5>
                                <h3 class="card-text">GH₵<?php echo number_format($balance, 2); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Record Transaction -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">Record Financial Transaction</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="transaction_type" class="form-label">Transaction Type</label>
                                        <select class="form-select" id="transaction_type" name="transaction_type" required>
                                            <option value="">Select Type</option>
                                            <option value="Income">Income</option>
                                            <option value="Expense">Expense</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="amount" class="form-label">Amount (GH₵)</label>
                                        <input type="number" step="0.01" class="form-control" id="amount" name="amount" required>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="category" class="form-label">Category</label>
                                        <select class="form-select" id="category" name="category" required>
                                            <option value="">Select Category</option>
                                            <option value="Consultation Fees">Consultation Fees</option>
                                            <option value="Lab Test Fees">Lab Test Fees</option>
                                            <option value="Medicine Sales">Medicine Sales</option>
                                            <option value="Donation">Donation</option>
                                            <option value="Salary Payment">Salary Payment</option>
                                            <option value="Equipment Purchase">Equipment Purchase</option>
                                            <option value="Medicine Purchase">Medicine Purchase</option>
                                            <option value="Utility Bills">Utility Bills</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                            </div>
                            <button type="submit" name="record_transaction" class="btn btn-primary" 
                                    onclick="return confirm('Record this financial transaction?')">Record Transaction</button>
                        </form>
                    </div>
                </div>

                <!-- Recent Transactions -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0">Recent Financial Transactions</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($transactions)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Category</th>
                                            <th>Amount</th>
                                            <th>Description</th>
                                            <th>Recorded By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transactions as $transaction): ?>
                                            <tr>
                                                <td><?php echo date('d/m/Y H:i', strtotime($transaction['transaction_date'])); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $transaction['transaction_type'] == 'Income' ? 'success' : 'danger'; ?>">
                                                        <?php echo $transaction['transaction_type']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($transaction['category']); ?></td>
                                                <td>GH₵<?php echo number_format($transaction['amount'], 2); ?></td>
                                                <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                                                <td><?php echo htmlspecialchars($transaction['full_name']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">No financial transactions found.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
</body>
</html>