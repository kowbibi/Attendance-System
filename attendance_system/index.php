<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "attendance_system";

// Create connection
$conn = new mysqli($servername, $username, $password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if it doesn't exist
$sql = "CREATE DATABASE IF NOT EXISTS $dbname";
if ($conn->query($sql) === TRUE) {
    // Select the database
    $conn->select_db($dbname);
} else {
    die("Error creating database: " . $conn->error);
}

// Check if tables exist, if not show setup message
$tables_exist = true;
$required_tables = ['users', 'courses', 'attendance', 'excuse_letters'];
foreach ($required_tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows == 0) {
        $tables_exist = false;
        break;
    }
}

// Start session
session_start();

// Handle database setup
if (!$tables_exist && isset($_POST['setup_database'])) {
    // Read and execute the SQL setup file
    $sql_file = __DIR__ . '/database_setup.sql';
    if (file_exists($sql_file)) {
        $sql_content = file_get_contents($sql_file);
        $sql_statements = explode(';', $sql_content);
        
        foreach ($sql_statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                if ($conn->query($statement) === FALSE) {
                    $error = "Database setup error: " . $conn->error;
                    break;
                }
            }
        }
        
        if (!isset($error)) {
            $success = "Database setup completed successfully! You can now login.";
            $tables_exist = true;
        }
    } else {
        $error = "Database setup file not found. Please run the SQL file manually in phpMyAdmin.";
    }
}

// Handle login
if (isset($_POST['login']) && $tables_exist) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $role = $_POST['role'];
    
    // Check if user exists - using prepared statement to prevent SQL injection
    $sql = "SELECT * FROM users WHERE username = ? AND password = ? AND role = ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("sss", $username, $password, $role);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];
            
            if ($user['role'] == 'student') {
                $_SESSION['student_id'] = $user['student_id'];
                $_SESSION['course_id'] = $user['course_id'];
                $_SESSION['year_level'] = $user['year_level'];
                
                // Get course info
                $course_sql = "SELECT * FROM courses WHERE id = ?";
                $course_stmt = $conn->prepare($course_sql);
                if ($course_stmt) {
                    $course_stmt->bind_param("i", $user['course_id']);
                    $course_stmt->execute();
                    $course_result = $course_stmt->get_result();
                    
                    if ($course_result && $course_result->num_rows > 0) {
                        $course = $course_result->fetch_assoc();
                        $_SESSION['course_code'] = $course['code'];
                        $_SESSION['course_name'] = $course['name'];
                    }
                    $course_stmt->close();
                }
            }
            $stmt->close();
            
            // Redirect to prevent form resubmission
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } else {
            $error = "Invalid credentials. Please check your username, password, and role.";
        }
        $stmt->close();
    } else {
        $error = "Database error: " . $conn->error;
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Check if user is logged in
$logged_in = isset($_SESSION['user_id']);
$is_admin = $logged_in && $_SESSION['role'] == 'admin';
$is_student = $logged_in && $_SESSION['role'] == 'student';

// Handle form submissions for admin
if ($is_admin && isset($_POST['add_course'])) {
    $code = $_POST['course_code'];
    $name = $_POST['course_name'];
    $description = $_POST['course_description'];
    
    $sql = "INSERT INTO courses (code, name, description) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("sss", $code, $name, $description);
        if ($stmt->execute()) {
            $success = "Course added successfully!";
        } else {
            $error = "Error adding course: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error = "Database error: " . $conn->error;
    }
}

// Handle form submissions for student
if ($is_student && isset($_POST['mark_attendance'])) {
    $user_id = $_SESSION['user_id'];
    $date = date('Y-m-d');
    $time = date('H:i:s');
    
    // Check if already marked attendance today
    $check_sql = "SELECT * FROM attendance WHERE user_id = ? AND date = ?";
    $check_stmt = $conn->prepare($check_sql);
    
    if ($check_stmt) {
        $check_stmt->bind_param("is", $user_id, $date);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result && $check_result->num_rows == 0) {
            // Determine if late (after 9 AM)
            $status = (date('H') >= 9) ? 'Late' : 'On Time';
            
            $sql = "INSERT INTO attendance (user_id, date, time, status) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            
            if ($stmt) {
                $stmt->bind_param("isss", $user_id, $date, $time, $status);
                if ($stmt->execute()) {
                    $success = "Attendance marked successfully! Status: $status";
                } else {
                    $error = "Error marking attendance: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error = "Database error: " . $conn->error;
            }
        } else {
            $error = "You have already marked your attendance for today.";
        }
        $check_stmt->close();
    } else {
        $error = "Database error: " . $conn->error;
    }
}

// Handle excuse letter submission
if ($is_student && isset($_POST['submit_excuse'])) {
    $user_id = $_SESSION['user_id'];
    $date = $_POST['excuse_date'];
    $reason = $_POST['excuse_reason'];
    
    $sql = "INSERT INTO excuse_letters (user_id, date, reason, status) VALUES (?, ?, ?, 'pending')";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("iss", $user_id, $date, $reason);
        if ($stmt->execute()) {
            $success = "Excuse letter submitted successfully!";
        } else {
            $error = "Error submitting excuse letter: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error = "Database error: " . $conn->error;
    }
}

// Handle excuse letter approval/rejection
if ($is_admin && isset($_GET['action']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $action = $_GET['action'];
    
    if ($action == 'approve' || $action == 'reject') {
        $status = $action == 'approve' ? 'approved' : 'rejected';
        $sql = "UPDATE excuse_letters SET status = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("si", $status, $id);
            if ($stmt->execute()) {
                $success = "Excuse letter $status successfully!";
            } else {
                $error = "Error updating excuse letter: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Database error: " . $conn->error;
        }
    }
}

// Get courses for admin
$courses = array();
if ($is_admin) {
    $sql = "SELECT * FROM courses";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $courses[] = $row;
        }
    }
}

// Get attendance records for admin
$attendance_records = array();
if ($is_admin && isset($_GET['course_filter'])) {
    $course_filter = $_GET['course_filter'];
    $year_filter = $_GET['year_filter'];
    $date_filter = $_GET['date_filter'];
    
    $sql = "SELECT a.*, u.first_name, u.last_name, u.student_id, c.code as course_code, c.name as course_name, u.year_level 
            FROM attendance a 
            JOIN users u ON a.user_id = u.id 
            JOIN courses c ON u.course_id = c.id 
            WHERE u.role = 'student'";
    
    if (!empty($course_filter)) {
        $sql .= " AND c.code = '$course_filter'";
    }
    
    if (!empty($year_filter)) {
        $sql .= " AND u.year_level = $year_filter";
    }
    
    if (!empty($date_filter)) {
        $sql .= " AND a.date = '$date_filter'";
    }
    
    $sql .= " ORDER BY a.date DESC, a.time DESC";
    
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $attendance_records[] = $row;
        }
    }
}

// Get student attendance
$student_attendance = array();
if ($is_student) {
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT * FROM attendance WHERE user_id = ? ORDER BY date DESC, time DESC";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $student_attendance[] = $row;
            }
        }
        $stmt->close();
    }
}

// Get excuse letters for student
$student_excuses = array();
if ($is_student) {
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT * FROM excuse_letters WHERE user_id = ? ORDER BY date DESC";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $student_excuses[] = $row;
            }
        }
        $stmt->close();
    }
}

// Get excuse letters for admin
$excuse_letters = array();
if ($is_admin) {
    $course_filter = isset($_GET['excuse_course_filter']) ? $_GET['excuse_course_filter'] : '';
    $status_filter = isset($_GET['excuse_status_filter']) ? $_GET['excuse_status_filter'] : '';
    
    $sql = "SELECT e.*, u.first_name, u.last_name, u.student_id, c.code as course_code, c.name as course_name, u.year_level 
            FROM excuse_letters e 
            JOIN users u ON e.user_id = u.id 
            JOIN courses c ON u.course_id = c.id 
            WHERE u.role = 'student'";
    
    if (!empty($course_filter)) {
        $sql .= " AND c.code = '$course_filter'";
    }
    
    if (!empty($status_filter)) {
        $sql .= " AND e.status = '$status_filter'";
    }
    
    $sql .= " ORDER BY e.date DESC";
    
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $excuse_letters[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #4e73df;
            --secondary: #858796;
            --success: #1cc88a;
            --info: #36b9cc;
            --warning: #f6c23e;
            --danger: #e74a3b;
            --light: #f8f9fc;
            --dark: #5a5c69;
        }
        
        body {
            background-color: #f8f9fc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }
        
        .login-card {
            width: 400px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .system-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        
        .card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        
        .card-header {
            background-color: white;
            border-bottom: 1px solid #e3e6f0;
            font-weight: 600;
            padding: 15px 20px;
        }
        
        .nav-tabs .nav-link {
            border: none;
            padding: 10px 15px;
            color: var(--secondary);
            font-weight: 500;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary);
            border-bottom: 2px solid var(--primary);
            background: transparent;
        }
        
        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-primary:hover {
            background-color: #3a5fc8;
            border-color: #3a5fc8;
        }
        
        .status-on-time {
            color: var(--success);
            font-weight: 600;
        }
        
        .status-late {
            color: var(--danger);
            font-weight: 600;
        }
        
        .status-pending {
            color: var(--warning);
            font-weight: 600;
        }
        
        .status-approved {
            color: var(--success);
            font-weight: 600;
        }
        
        .status-rejected {
            color: var(--danger);
            font-weight: 600;
        }
        
        .dashboard-card {
            transition: transform 0.3s;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
        }
        
        .attendance-history {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .excuse-letter {
            border-left: 4px solid var(--primary);
            background-color: #f8f9fc;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        
        .filter-section {
            background-color: #f8f9fc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php if (!$tables_exist): ?>
    <!-- Database Setup Screen -->
    <div class="login-container">
        <div class="login-card">
            <div class="card">
                <div class="card-header text-center bg-warning text-dark">
                    <h4 class="mb-0">Database Setup Required</h4>
                </div>
                <div class="card-body p-4">
                    <p class="text-center">The attendance system database needs to be set up before you can use the application.</p>
                    
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($success)): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="d-grid">
                            <button type="submit" name="setup_database" class="btn btn-warning btn-lg">
                                <i class="fas fa-database me-2"></i>Setup Database
                            </button>
                        </div>
                    </form>
                    
                    <div class="mt-3">
                        <p class="small text-muted">This will create the necessary tables and insert sample data.</p>
                        <p class="small text-muted">Default credentials after setup:</p>
                        <p class="small mb-1">Admin: admin / admin123</p>
                        <p class="small">Student: student1 / password123</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php elseif (!$logged_in): ?>
    <!-- Login Screen -->
    <div class="login-container">
        <div class="login-card">
            <div class="card">
                <div class="card-header text-center bg-primary text-white">
                    <h4 class="mb-0">Attendance System Login</h4>
                </div>
                <div class="card-body p-4">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">Login as</label>
                            <select class="form-select" id="role" name="role">
                                <option value="student">Student</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <button type="submit" name="login" class="btn btn-primary w-100">Login</button>
                    </form>
                    <div class="mt-3 text-center">
                        <p class="mb-0">Demo Credentials:</p>
                        <p class="small mb-1">Student: student1 / password123</p>
                        <p class="small">Admin: admin / admin123</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- System Interface -->
    <div class="system-container">
        <!-- Header -->
        <div class="header d-flex justify-content-between align-items-center">
            <h2 class="mb-0">Attendance System</h2>
            <div>
                <span class="me-3">
                    <?php 
                    echo $_SESSION['name'] . ' (' . $_SESSION['role'] . ')';
                    if ($is_student) {
                        echo ' - ' . $_SESSION['course_code'] . ' - Year ' . $_SESSION['year_level'];
                    }
                    ?>
                </span>
                <a href="?logout=true" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-sign-out-alt me-1"></i>Logout
                </a>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($is_admin): ?>
        <!-- Admin Dashboard -->
        <ul class="nav nav-tabs mb-4" id="adminTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="excuse-tab" data-bs-toggle="tab" data-bs-target="#excuse" type="button" role="tab">Excuse Letters</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="courses-tab" data-bs-toggle="tab" data-bs-target="#courses" type="button" role="tab">Courses</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="attendance-tab" data-bs-toggle="tab" data-bs-target="#attendance" type="button" role="tab">Attendance Records</button>
            </li>
        </ul>

        <div class="tab-content" id="adminTabContent">
            <div class="tab-pane fade show active" id="excuse" role="tabpanel">
                <div class="filter-section">
                    <form method="GET">
                        <div class="row">
                            <div class="col-md-5">
                                <label for="excuse_course_filter" class="form-label">Filter by Course</label>
                                <select class="form-select" id="excuse_course_filter" name="excuse_course_filter">
                                    <option value="">All Courses</option>
                                    <?php foreach ($courses as $course): ?>
                                        <option value="<?php echo $course['code']; ?>" <?php if (isset($_GET['excuse_course_filter']) && $_GET['excuse_course_filter'] == $course['code']) echo 'selected'; ?>>
                                            <?php echo $course['name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label for="excuse_status_filter" class="form-label">Filter by Status</label>
                                <select class="form-select" id="excuse_status_filter" name="excuse_status_filter">
                                    <option value="">All Status</option>
                                    <option value="pending" <?php if (isset($_GET['excuse_status_filter']) && $_GET['excuse_status_filter'] == 'pending') echo 'selected'; ?>>Pending</option>
                                    <option value="approved" <?php if (isset($_GET['excuse_status_filter']) && $_GET['excuse_status_filter'] == 'approved') echo 'selected'; ?>>Approved</option>
                                    <option value="rejected" <?php if (isset($_GET['excuse_status_filter']) && $_GET['excuse_status_filter'] == 'rejected') echo 'selected'; ?>>Rejected</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Filter</button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <span>Excuse Letters</span>
                    </div>
                    <div class="card-body">
                        <?php if (count($excuse_letters) > 0): ?>
                            <?php foreach ($excuse_letters as $letter): ?>
                                <div class="excuse-letter">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h5><?php echo $letter['first_name'] . ' ' . $letter['last_name']; ?> (<?php echo $letter['student_id']; ?>)</h5>
                                            <p class="mb-1"><strong>Course:</strong> <?php echo $letter['course_code']; ?> - Year <?php echo $letter['year_level']; ?></p>
                                            <p class="mb-1"><strong>Date of Absence:</strong> <?php echo $letter['date']; ?></p>
                                            <p class="mb-1"><strong>Reason:</strong> <?php echo $letter['reason']; ?></p>
                                            <p class="mb-1"><strong>Submitted:</strong> <?php echo $letter['created_at']; ?></p>
                                            <?php if ($letter['status'] != 'pending'): ?>
                                                <p class="mb-1"><strong>Reviewed:</strong> <?php echo $letter['updated_at']; ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-<?php echo $letter['status'] == 'approved' ? 'success' : ($letter['status'] == 'rejected' ? 'danger' : 'warning'); ?>">
                                                <?php echo ucfirst($letter['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php if ($letter['status'] == 'pending'): ?>
                                    <div class="mt-3">
                                        <a href="?action=approve&id=<?php echo $letter['id']; ?>" class="btn btn-sm btn-success me-2">
                                            <i class="fas fa-check me-1"></i>Approve
                                        </a>
                                        <a href="?action=reject&id=<?php echo $letter['id']; ?>" class="btn btn-sm btn-danger">
                                            <i class="fas fa-times me-1"></i>Reject
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-center">No excuse letters found.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="tab-pane fade" id="courses" role="tabpanel">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Manage Courses</span>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addCourseModal">
                            <i class="fas fa-plus me-1"></i>Add Course
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Course Code</th>
                                        <th>Course Name</th>
                                        <th>Description</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($courses as $course): ?>
                                        <tr>
                                            <td><?php echo $course['id']; ?></td>
                                            <td><?php echo $course['code']; ?></td>
                                            <td><?php echo $course['name']; ?></td>
                                            <td><?php echo $course['description']; ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="tab-pane fade" id="attendance" role="tabpanel">
                <div class="filter-section">
                    <form method="GET">
                        <div class="row">
                            <div class="col-md-3">
                                <label for="course_filter" class="form-label">Course</label>
                                <select class="form-select" id="course_filter" name="course_filter">
                                    <option value="">All Courses</option>
                                    <?php foreach ($courses as $course): ?>
                                        <option value="<?php echo $course['code']; ?>" <?php if (isset($_GET['course_filter']) && $_GET['course_filter'] == $course['code']) echo 'selected'; ?>>
                                            <?php echo $course['name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="year_filter" class="form-label">Year Level</label>
                                <select class="form-select" id="year_filter" name="year_filter">
                                    <option value="">All Year Levels</option>
                                    <option value="1" <?php if (isset($_GET['year_filter']) && $_GET['year_filter'] == 1) echo 'selected'; ?>>1st Year</option>
                                    <option value="2" <?php if (isset($_GET['year_filter']) && $_GET['year_filter'] == 2) echo 'selected'; ?>>2nd Year</option>
                                    <option value="3" <?php if (isset($_GET['year_filter']) && $_GET['year_filter'] == 3) echo 'selected'; ?>>3rd Year</option>
                                    <option value="4" <?php if (isset($_GET['year_filter']) && $_GET['year_filter'] == 4) echo 'selected'; ?>>4th Year</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="date_filter" class="form-label">Date</label>
                                <input type="date" class="form-control" id="date_filter" name="date_filter" value="<?php echo isset($_GET['date_filter']) ? $_GET['date_filter'] : ''; ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Filter</button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <span>Attendance Records</span>
                    </div>
                    <div class="card-body">
                        <?php if (count($attendance_records) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Student ID</th>
                                            <th>Name</th>
                                            <th>Course</th>
                                            <th>Year Level</th>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($attendance_records as $record): ?>
                                            <tr>
                                                <td><?php echo $record['student_id']; ?></td>
                                                <td><?php echo $record['first_name'] . ' ' . $record['last_name']; ?></td>
                                                <td><?php echo $record['course_code']; ?></td>
                                                <td><?php echo $record['year_level']; ?></td>
                                                <td><?php echo $record['date']; ?></td>
                                                <td><?php echo $record['time']; ?></td>
                                                <td>
                                                    <span class="status-<?php echo strtolower(str_replace(' ', '-', $record['status'])); ?>">
                                                        <?php echo $record['status']; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-center">No attendance records found. Apply filters to view records.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Add Course Modal -->
        <div class="modal fade" id="addCourseModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Course</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="course_code" class="form-label">Course Code</label>
                                <input type="text" class="form-control" id="course_code" name="course_code" required>
                            </div>
                            <div class="mb-3">
                                <label for="course_name" class="form-label">Course Name</label>
                                <input type="text" class="form-control" id="course_name" name="course_name" required>
                            </div>
                            <div class="mb-3">
                                <label for="course_description" class="form-label">Description</label>
                                <textarea class="form-control" id="course_description" name="course_description" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="add_course" class="btn btn-primary">Add Course</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php elseif ($is_student): ?>
        <!-- Student Dashboard -->
        <div class="row">
            <div class="col-md-8">
                <div class="card dashboard-card">
                    <div class="card-header">
                        <h5 class="mb-0">Mark Attendance</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="d-grid">
                                <button type="submit" name="mark_attendance" class="btn btn-primary btn-lg">
                                    <i class="fas fa-check-circle me-2"></i>Mark My Attendance
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card dashboard-card">
                    <div class="card-header">
                        <h5 class="mb-0">Submit Excuse Letter</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label for="excuse_date" class="form-label">Date of Absence</label>
                                <input type="date" class="form-control" id="excuse_date" name="excuse_date" required>
                            </div>
                            <div class="mb-3">
                                <label for="excuse_reason" class="form-label">Reason for Absence</label>
                                <textarea class="form-control" id="excuse_reason" name="excuse_reason" rows="3" required placeholder="Please provide a detailed reason for your absence..."></textarea>
                            </div>
                            <button type="submit" name="submit_excuse" class="btn btn-warning">
                                <i class="fas fa-file-alt me-1"></i>Submit Excuse Letter
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card dashboard-card">
                    <div class="card-header">
                        <h5 class="mb-0">My Attendance History</h5>
                    </div>
                    <div class="card-body attendance-history">
                        <?php if (count($student_attendance) > 0): ?>
                            <?php foreach ($student_attendance as $attendance): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2 p-2 border rounded">
                                    <div>
                                        <div class="fw-bold"><?php echo $attendance['date']; ?></div>
                                        <small class="text-muted"><?php echo $attendance['time']; ?></small>
                                    </div>
                                    <span class="status-<?php echo strtolower(str_replace(' ', '-', $attendance['status'])); ?>">
                                        <?php echo $attendance['status']; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-center text-muted">No attendance records found.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card dashboard-card">
                    <div class="card-header">
                        <h5 class="mb-0">My Excuse Letters</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($student_excuses) > 0): ?>
                            <?php foreach ($student_excuses as $excuse): ?>
                                <div class="excuse-letter">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-bold"><?php echo $excuse['date']; ?></div>
                                            <div class="small text-muted"><?php echo $excuse['reason']; ?></div>
                                        </div>
                                        <span class="badge bg-<?php echo $excuse['status'] == 'approved' ? 'success' : ($excuse['status'] == 'rejected' ? 'danger' : 'warning'); ?>">
                                            <?php echo ucfirst($excuse['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-center text-muted">No excuse letters submitted.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>