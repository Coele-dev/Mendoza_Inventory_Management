<?php
// Start the session layer before any other execution happens
session_start();

// 1. Hook up the database connection by moving up one directory level
require_once '../config/database.php';

// If the user is already logged in, bypass this page and send them to their designated dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: ../admin_dashboard.php");
    } else {
        header("Location: ../dashboard.php");
    }
    exit;
}

$error = '';

// 2. Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        // Fetch the user data purely by username to inspect status explicitly
        $stmt = $pdo->prepare("SELECT * FROM accounts WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Check status strictly after verifying password authenticity
            if ($user['status'] !== 'active') {
                $error = 'Your account is pending administrator activation.';
            } else {
                // Log the user in and persist authorization contexts inside session data
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'] ?? 'manager'; 

                if ($_SESSION['role'] === 'admin') {
                    header("Location: ../admin_dashboard.php");
                } else {
                    header("Location: ../dashboard.php");
                }
                exit;
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse IMS - Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-image: url('../image/login bg1.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            color: #e0e0e0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            padding: 20px;
            position: relative;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.45);
            z-index: 1;
        }

        .split-container {
            position: relative;
            z-index: 2;
            display: flex;
            background-color: rgba(20, 20, 22, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 24px;
            width: 100%;
            max-width: 850px;
            height: 520px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.06);
            box-shadow: 0 30px 70px rgba(0, 0, 0, 0.8);
        }

        .form-pane {
            flex: 1;
            padding: 45px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .graphic-pane {
            flex: 1.1;
            background-image: url('../image/warehouse bg2.jpg');
            background-size: cover;
            background-position: center;
            position: relative;
            margin: 16px;
            border-radius: 18px;
        }

        .graphic-pane::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(to bottom, rgba(0,0,0,0), rgba(0,0,0,0.4));
            border-radius: 18px;
        }

        h2 {
            font-size: 28px;
            margin-bottom: 6px;
            color: #ffffff;
            font-weight: 600;
        }

        p.subtitle {
            color: #a0a0ab;
            font-size: 14px;
            margin-bottom: 30px;
        }

        .error-msg {
            background-color: rgba(207, 102, 121, 0.15);
            color: #ff798e;
            border: 1px solid rgba(207, 102, 121, 0.25);
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #a0a0a0;
            font-size: 13px;
            font-weight: 500;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }

        input {
            width: 100%;
            padding: 13px 16px;
            background-color: #1e1e22;
            border: 1px solid #2d2d32;
            border-radius: 10px;
            color: #ffffff;
            font-size: 15px;
            outline: none;
            transition: all 0.25s ease;
        }

        input:focus {
            border-color: #03dac6;
            box-shadow: 0 0 0 4px rgba(3, 218, 198, 0.15);
            background-color: #222226;
        }

        .toggle-password {
            position: absolute;
            right: 16px;
            cursor: pointer;
            color: #72727a;
            transition: color 0.2s;
            font-size: 15px;
        }

        .toggle-password:hover {
            color: #03dac6;
        }

        button {
            width: 100%;
            padding: 13px;
            background-color: #03dac6;
            color: #0c0c0e;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
            margin-top: 10px;
        }

        button:hover {
            background-color: #01bfa5;
        }

        button:active {
            transform: scale(0.99);
        }

        .footer-links {
            text-align: center;
            margin-top: 25px;
            font-size: 13px;
            color: #8e8e93;
        }

        .footer-links a {
            color: #03dac6;
            text-decoration: none;
            font-weight: 600;
        }

        .footer-links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="split-container">
    <div class="form-pane">
        <h2>Welcome back</h2>
        <p class="subtitle">Access your warehouse inventory management system</p>

        <?php if (!empty($error)): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-wrapper">
                    <input type="text" id="username" name="username" required autocomplete="username" 
                        value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                    <i class="fa-solid fa-eye toggle-password" id="eyeIcon"></i>
                </div>
            </div>

            <button type="submit">Log In</button>
        </form>

        <div class="footer-links">
            Don't have an account? <a href="register.php">Sign Up</a>
        </div>
    </div>

    <div class="graphic-pane"></div>
</div>

<script>
    const passwordInput = document.getElementById('password');
    const eyeIcon = document.getElementById('eyeIcon');

    eyeIcon.addEventListener('click', function () {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        this.classList.toggle('fa-eye');
        this.classList.toggle('fa-eye-slash');
    });
</script>

</body>
</html>
