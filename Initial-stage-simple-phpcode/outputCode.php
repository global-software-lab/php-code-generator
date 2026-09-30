<?php
// Single-file PHP CRUD Application with SQLite (simulating MySQL for self-contained execution)
// Includes Bootstrap 5, Bootstrap Table, Validation, Filtering, and Pagination.

$dbFile = __DIR__ . '/database.sqlite';
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create sample table
    $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        age INTEGER NOT NULL,
        joining_date TEXT NOT NULL,
        department TEXT NOT NULL
    )");

    // Seed data if empty
    $stmt = $pdo->query("SELECT COUNT(*) FROM employees");
    if ($stmt->fetchColumn() == 0) {
        $seed = $pdo->prepare("INSERT INTO employees (name, email, age, joining_date, department) VALUES (?, ?, ?, ?, ?)");
        $seed->execute(['John Doe', 'john@example.com', 28, '2023-01-15', 'Engineering']);
        $seed->execute(['Jane Smith', 'jane@example.com', 34, '2022-05-20', 'Marketing']);
        $seed->execute(['Bob Johnson', 'bob@example.com', 45, '2021-11-10', 'Sales']);
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Handle AJAX requests for Bootstrap Table data
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    $search = $_GET['search'] ?? '';
    $offset = intval($_GET['offset'] ?? 0);
    $limit = intval($_GET['limit'] ?? 10);
    
    $where = "1=1";
    $params = [];
    if (!empty($search)) {
        $where .= " AND (name LIKE ? OR email LIKE ? OR department LIKE ?)";
        $searchTerm = "%$search%";
        $params = [$searchTerm, $searchTerm, $searchTerm];
    }

    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE $where");
    $stmtTotal->execute($params);
    $total = $stmtTotal->fetchColumn();

    $stmtData = $pdo->prepare("SELECT * FROM employees WHERE $where LIMIT $limit OFFSET $offset");
    $stmtData->execute($params);
    $rows = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'total' => $total,
        'rows' => $rows
    ]);
    exit;
}

// Handle Form Actions (Add, Edit, Delete, Bulk Delete)
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = $_POST['id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $age = filter_var($_POST['age'], FILTER_VALIDATE_INT);
        $joining_date = $_POST['joining_date'] ?? '';
        $department = $_POST['department'] ?? '';

        // Validation
        if (empty($name)) $errors[] = "Name is required.";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
        if ($age === false || $age < 18 || $age > 100) $errors[] = "Age must be between 18 and 100.";
        if (empty($joining_date)) $errors[] = "Joining date is required.";
        if (empty($department)) $errors[] = "Department is required.";

        if (empty($errors)) {
            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO employees (name, email, age, joining_date, department) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $email, $age, $joining_date, $department]);
                $success = "Employee added successfully.";
            } else {
                $stmt = $pdo->prepare("UPDATE employees SET name = ?, email = ?, age = ?, joining_date = ?, department = ? WHERE id = ?");
                $stmt->execute([$name, $email, $age, $joining_date, $department, $id]);
                $success = "Employee updated successfully.";
            }
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
        $stmt->execute([$id]);
        exit('OK');
    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            $inQuery = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM employees WHERE id IN ($inQuery)");
            $stmt->execute($ids);
        }
        exit('OK');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP CRUD with Bootstrap Table</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Table CSS -->
    <link href="https://unpkg.com/bootstrap-table@1.22.1/dist/bootstrap-table.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light">

<div class="container my-5">
    <h2 class="mb-4 text-center">Employee Management CRUD</h2>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Toolbar & Table -->
    <div class="card shadow-sm">
        <div class="card-body">
            <div id="toolbar" class="mb-3">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#employeeModal" onclick="resetForm()">
                    <i class="fa fa-plus"></i> Add Employee
                </button>
                <button id="bulkDeleteBtn" class="btn btn-danger" disabled>
                    <i class="fa fa-trash"></i> Delete Selected
                </button>
            </div>

            <table id="table"
                   data-toggle="table"
                   data-toolbar="#toolbar"
                   data-search="true"
                   data-show-refresh="true"
                   data-show-toggle="true"
                   data-show-columns="true"
                   data-pagination="true"
                   data-side-pagination="server"
                   data-url="?ajax=1"
                   data-response-handler="responseHandler">
                <thead>
                    <tr>
                        <th data-field="state" data-checkbox="true"></th>
                        <th data-field="id" data-sortable="true">ID</th>
                        <th data-field="name" data-sortable="true">Name</th>
                        <th data-field="email" data-sortable="true">Email</th>
                        <th data-field="age" data-sortable="true">Age</th>
                        <th data-field="joining_date" data-sortable="true">Joining Date</th>
                        <th data-field="department" data-sortable="true">Department</th>
                        <th data-field="id" data-formatter="actionFormatter">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="employeeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="employeeForm" method="POST">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="empId">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" class="form-control" name="name" id="empName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email address</label>
                        <input type="email" class="form-control" name="email" id="empEmail" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Age</label>
                        <input type="number" class="form-control" name="age" id="empAge" min="18" max="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Joining Date</label>
                        <input type="date" class="form-control" name="joining_date" id="empDate" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Department</label>
                        <select class="form-select" name="department" id="empDept" required>
                            <option value="">Choose...</option>
                            <option value="Engineering">Engineering</option>
                            <option value="Marketing">Marketing</option>
                            <option value="Sales">Sales</option>
                            <option value="HR">HR</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Employee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- jQuery, Bootstrap JS, Bootstrap Table JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/bootstrap-table@1.22.1/dist/bootstrap-table.min.js"></script>

<script>
    var $table = $('#table');

    function responseHandler(res) {
        return res;
    }

    function actionFormatter(value, row) {
        return `
            <button class="btn btn-sm btn-warning" onclick='editEmployee(${JSON.stringify(row)})'><i class="fa fa-edit"></i></button>
            <button class="btn btn-sm btn-danger" onclick="deleteEmployee(${value})"><i class="fa fa-trash"></i></button>
        `;
    }

    function resetForm() {
        $('#employeeForm')[0].reset();
        $('#empId').val('');
        $('#modalTitle').text('Add Employee');
    }

    function editEmployee(row) {
        $('#empId').val(row.id);
        $('#empName').val(row.name);
        $('#empEmail').val(row.email);
        $('#empAge').val(row.age);
        $('#empDate').val(row.joining_date);
        $('#empDept').val(row.department);
        $('#modalTitle').text('Edit Employee');
        $('#employeeModal').modal('show');
    }

    function deleteEmployee(id) {
        if (confirm('Are you sure you want to delete this record?')) {
            $.post('', { action: 'delete', id: id }, function() {
                $table.bootstrapTable('refresh');
            });
        }
    }

    // Bulk actions
    $table.on('check.bs.table uncheck.bs.table check-all.bs.table uncheck-all.bs.table', function () {
        let ids = $.map($table.bootstrapTable('getSelections'), function (row) {
            return row.id;
        });
        $('#bulkDeleteBtn').prop('disabled', ids.length === 0);
    });

    $('#bulkDeleteBtn').click(function () {
        let ids = $.map($table.bootstrapTable('getSelections'), function (row) {
            return row.id;
        });
        if (ids.length > 0 && confirm('Delete selected records?')) {
            $.post('', { action: 'bulk_delete', ids: ids }, function() {
                $table.bootstrapTable('refresh');
                $('#bulkDeleteBtn').prop('disabled', true);
            });
        }
    });
</script>
</body>
</html>
<?php
/*
-- SAMPLE MYSQL DATABASE FILE (schema.sql)
CREATE DATABASE IF NOT EXISTS company_db;
USE company_db;

CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    age INT NOT NULL,
    joining_date DATE NOT NULL,
    department VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO employees (name, email, age, joining_date, department) VALUES 
('John Doe', 'john@example.com', 28, '2023-01-15', 'Engineering'),
('Jane Smith', 'jane@example.com', 34, '2022-05-20', 'Marketing'),
('Bob Johnson', 'bob@example.com', 45, '2021-11-10', 'Sales');
*/
