<?php
// admin/login.php
// Styled admin login (mobile-first) with CSRF, session setup and secure password_verify()
require_once '../helpers.php';
require_once '../db.php';

start_session_30d();

$error = '';
// Handle POST login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!verify_csrf($csrf)) {
        $error = 'Security token mismatch. Try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Please fill both fields.';
        } else {
            // Fetch admin record
            $stmt = $conn->prepare("SELECT id, password_hash FROM admins WHERE email = ? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();

            if ($res) {
                $hashFromDb = $res['password_hash'];
                if (password_verify($password, $hashFromDb)) {
                    // Login success: set admin session and redirect
                    $_SESSION['admin_id'] = $res['id'];
                    $_SESSION['admin_email'] = $email;
                    header("Location: dashboard.php");
                    exit;
                } else {
                    $error = 'Invalid credentials.';
                }
            } else {
                $error = 'No admin found with that email.';
            }
        }
    }
}

// Generate CSRF for form
$csrf = generate_csrf();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Login — ITZ Adarsh</title>
<style>
:root{
  --accent: #0b5cff;
  --bg: #f6f8fb;
  --card: #ffffff;
  --muted: #6b7280;
  --danger: #ef4444;
  --radius: 12px;
  font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
}
*{box-sizing:border-box}
body{
  margin:0;
  background: linear-gradient(180deg,#fbfdff,#eef6ff);
  color:#0b1220;
  -webkit-font-smoothing:antialiased;
  -moz-osx-font-smoothing:grayscale;
  padding:18px;
  display:flex;
  min-height:100vh;
  align-items:center;
  justify-content:center;
}
.wrap{
  width:100%;
  max-width:420px;
  background:var(--card);
  border-radius:var(--radius);
  box-shadow:0 12px 40px rgba(11,17,34,0.08);
  padding:18px;
  border:1px solid rgba(11,92,255,0.04);
}
.header{
  display:flex;
  gap:12px;
  align-items:center;
  margin-bottom:12px;
}
.logo{
  width:46px;height:46px;border-radius:10px;background:var(--accent);display:grid;place-items:center;color:#fff;font-weight:700;font-size:1rem;
}
h1{margin:0;font-size:1.05rem}
.subtitle{margin:4px 0 0;color:var(--muted);font-size:0.9rem}

/* form */
form{margin-top:12px}
label{display:block;font-weight:600;font-size:0.95rem;margin-top:10px}
input[type="email"],input[type="password"]{
  width:100%;padding:12px;border-radius:10px;border:1px solid #e8eefc;background:#fbfdff;font-size:1rem;
  margin-top:6px;outline:none;
}
input[type="email"]:focus,input[type="password"]:focus{box-shadow:0 6px 18px rgba(11,92,255,0.08);border-color:var(--accent)}
.row{display:flex;gap:8px;margin-top:14px;align-items:center}
.btn{flex:1;background:var(--accent);color:#fff;border:0;padding:12px;border-radius:10px;font-weight:700;cursor:pointer}
.secondary{background:transparent;border:1px solid rgba(11,92,255,0.12);color:var(--accent);padding:10px;border-radius:10px;cursor:pointer}
.hint{font-size:0.9rem;color:var(--muted);margin-top:10px}
.error{color:var(--danger);background:rgba(239,68,68,0.06);padding:10px;border-radius:8px;margin-top:12px;border:1px solid rgba(239,68,68,0.06)}
.note{font-size:0.85rem;color:var(--muted);margin-top:12px}
.footer{margin-top:14px;text-align:center;font-size:0.85rem;color:var(--muted)}
@media(max-width:420px){body{padding:12px}}
</style>
</head>
<body>
  <div class="wrap" role="main" aria-labelledby="loginTitle">
    <div class="header">
     <div class="logo"><img src="https://itzadarsh.co.in/logo.svg" alt="itzadarsh-logo"></div>
      <div>
        <h1 id="loginTitle">Admin sign in</h1>
        <div class="subtitle">Use your admin credentials to access dashboard</div>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off" novalidate>
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
      <label for="email">Email</label>
      <input id="email" type="email" name="email" required placeholder="username@example.com" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">

      <label for="password">Password</label>
      <input id="password" type="password" name="password" required placeholder="Your password">

      <div class="row">
        <button type="submit" class="btn">Sign in</button>
        <a href="reset_request.php" class="secondary" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center">Reset password</a>
      </div>
    </form>

    <div class="footer">ITZ Adarsh &copy all rights reserved</div>
  </div>
</body>
</html>
