<?php
require_once '../helpers.php';
require_once '../db.php';
start_session_30d();

const PAGE_PASSWORD = '1M9960';

$err = '';
$ok  = '';
$csrf = generate_csrf();
$allowed = !empty($_SESSION['create_admin_allowed']);

if (!$allowed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['page_password_submit'])) {
    $posted_csrf = $_POST['csrf'] ?? '';
    if (!verify_csrf($posted_csrf)) {
        $err = 'Security token mismatch. Try again.';
    } else {
        $pw = $_POST['page_password'] ?? '';
        if ($pw === PAGE_PASSWORD) {
            $_SESSION['create_admin_allowed'] = 1;
            $allowed = true;
        } else {
            $err = 'Incorrect page password.';
        }
    }
}
if ($allowed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_admin_submit'])) {
    $posted_csrf = $_POST['csrf'] ?? '';
    if (!verify_csrf($posted_csrf)) {
        $err = 'Security token mismatch. Try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Please enter a valid email address.';
        } elseif (strlen($pass) < 8) {
            $err = 'Password must be at least 8 characters.';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO admins (email, password_hash) VALUES (?, ?)");
            if ($stmt) {
                $stmt->bind_param('ss', $email, $hash);
                if ($stmt->execute()) {
                    $ok = 'Admin account created successfully. <strong>DELETE this file now</strong>.';
                } else {
                    // friendly message; duplicate email likely
                    $err = 'Failed to create admin (email may already exist).';
                }
                $stmt->close();
            } else {
                $err = 'Server error. Try again later.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create admin — one time</title>
<meta name="robots" content="noindex">
<style>
:root{
  --accent:#0b5cff; --card:#fff; --muted:#6b7280; --danger:#ef4444; --ok:#065f46; --radius:12px;
  font-family:Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial;
}
*{box-sizing:border-box}
body{margin:0;background:linear-gradient(180deg,#ffffff,#ffffff);display:flex;align-items:center;justify-content:center;padding:18px}
.container{width:100%;max-width:560px}
.card{background:var(--card);border-radius:var(--radius);box-shadow:0 12px 36px rgba(11,17,34,0.08);padding:20px;border:1px solid rgba(11,92,255,0.04)}
.header{display:flex;gap:12px;align-items:center;margin-bottom:12px}
.logo{width:52px;height:52px;border-radius:10px;background:var(--accent);display:grid;place-items:center;color:#fff;font-weight:700}
h1{margin:0;font-size:1.15rem}
.lead{margin-top:6px;color:var(--muted)}
label{display:block;font-weight:700;margin-top:12px}
.input, input[type="email"], input[type="password"]{width:100%;padding:12px;border-radius:10px;border:1px solid #e6eefc;margin-top:8px;background:#fbfdff;font-size:1rem}
.btn{background:var(--accent);color:#fff;border:0;padding:10px 14px;border-radius:10px;cursor:pointer;font-weight:700}
.btn.ghost{background:transparent;border:1px solid rgba(11,92,255,0.12);color:var(--accent);padding:10px 12px;border-radius:10px}
.msg{margin-top:12px;padding:12px;border-radius:10px}
.msg.error{background:#fff1f2;color:var(--danger)}
.msg.ok{background:#ecfdf5;color:var(--ok)}
.small{font-size:0.9rem;color:var(--muted);margin-top:8px}
</style>
</head>
<body>
  <div class="container">
    <div class="card" role="main" aria-labelledby="title">
      <div class="header">
        <div class="logo">ITZ</div>
        <div>
          <h1 id="title">Create admin (one-time)</h1>
          <div class="lead">Protected page — enter page password to continue. Delete this file after use.</div>
        </div>
      </div>

      <?php if ($err): ?>
        <div class="msg error" role="alert"><?php echo htmlspecialchars($err); ?></div>
      <?php endif; ?>

      <?php if ($ok): ?>
        <div class="msg ok" role="status"><?php echo $ok; ?></div>
      <?php endif; ?>

      <?php if (!$allowed): ?>
        <form method="post" novalidate>
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
          <label for="page_password">Page password</label>
          <input id="page_password" class="input" type="password" name="page_password" required placeholder="Enter page password">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px">
            <button type="submit" name="page_password_submit" class="btn">Unlock</button>
            <a class="btn ghost" href="../index.php">Cancel</a>
          </div>
          <div class="small">If you don't know the page password, ask the site owner.</div>
        </form>
      <?php else: ?>
        <form method="post" novalidate>
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
          <label for="email">Admin email</label>
          <input id="email" class="input" type="email" name="email" required placeholder="admin@example.com" autocomplete="email">

          <label for="password">Password</label>
          <input id="password" class="input" type="password" name="password" required minlength="8" placeholder="At least 8 characters" autocomplete="new-password">

          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px">
            <button type="submit" name="create_admin_submit" class="btn">Create admin</button>
            <a class="btn ghost" href="login.php">Back to login</a>
          </div>
        </form>
      <?php endif; ?>

    </div>
  </div>
</body>
</html>
