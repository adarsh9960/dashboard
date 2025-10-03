<?php
// admin/reset_password.php
// Secure password reset page for admins.
// Place this file in public_html/form/admin/reset_password.php (paths assume that location).

require_once '../helpers.php';
require_once '../db.php';

start_session_30d();

$error = '';
$success = '';
$showForm = false;
$displayEmail = '';
$minLength = 8;

// Read inputs
$email = trim($_GET['email'] ?? $_POST['email'] ?? '');
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

// Helper: verify token against admins table
function validate_admin_token($conn, $email, $token) {
    if (!$email || !$token) return [false, 'Invalid link.'];
    $stmt = $conn->prepare("SELECT id, reset_token_hash, reset_expires_at FROM admins WHERE email = ? LIMIT 1");
    if (!$stmt) return [false, 'Server error (DB).'];
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['reset_token_hash']) || empty($row['reset_expires_at'])) {
        return [false, 'Invalid or expired token.'];
    }
    $tokenHash = hash('sha256', $token);
    $expiresAt = strtotime($row['reset_expires_at']);
    if (!hash_equals($row['reset_token_hash'], $tokenHash)) {
        return [false, 'Invalid or expired token.'];
    }
    if ($expiresAt < time()) {
        return [false, 'This reset link has expired.'];
    }
    return [true, $row['id']];
}

// If GET: validate token first so we don't show a useless form
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($email && $token) {
        list($ok, $result) = validate_admin_token($conn, $email, $token);
        if ($ok) {
            $showForm = true;
            $displayEmail = $email;
        } else {
            $error = $result;
        }
    } else {
        $error = 'Invalid reset link.';
    }
}

// If POST: process password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!verify_csrf($csrf)) {
        $error = 'Security token mismatch.';
    } else {
        $password = trim($_POST['password'] ?? '');
        $password2 = trim($_POST['password2'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $token = trim($_POST['token'] ?? '');

        if (!$email || !$token) {
            $error = 'Invalid request.';
        } elseif ($password === '' || $password !== $password2) {
            $error = 'Passwords must match and not be empty.';
            $showForm = true;
            $displayEmail = $email;
        } elseif (strlen($password) < $minLength) {
            $error = "Password must be at least {$minLength} characters.";
            $showForm = true;
            $displayEmail = $email;
        } else {
            // validate token again (defense in depth)
            list($ok, $result) = validate_admin_token($conn, $email, $token);
            if (!$ok) {
                $error = $result;
            } else {
                $adminId = (int)$result;
                $newHash = password_hash($password, PASSWORD_DEFAULT);

                $upd = $conn->prepare("UPDATE admins SET password_hash = ?, reset_token_hash = NULL, reset_expires_at = NULL WHERE id = ?");
                if (!$upd) {
                    $error = 'Server error (DB).';
                } else {
                    $upd->bind_param('si', $newHash, $adminId);
                    if ($upd->execute()) {
                        $success = 'Password updated. You can now <a href="login.php">login</a>.';
                        // Optionally auto-login admin: uncomment next lines if desired
                        // $_SESSION['admin_id'] = $adminId;
                        // $_SESSION['admin_email'] = $email;
                    } else {
                        $error = 'Failed to update password. Try again.';
                    }
                    $upd->close();
                }
            }
        }
    }
}

$csrf = generate_csrf();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Set new password — Admin</title>
<meta name="robots" content="noindex">
<style>
:root{--accent:#0b5cff;--muted:#6b7280;--card:#fff;--radius:12px;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,"Helvetica Neue",Arial}
*{box-sizing:border-box}
body{margin:0;background:#fbfdff;color:#0b1220;padding:18px;display:flex;align-items:center;justify-content:center;min-height:100vh}
.container{max-width:560px;width:100%}
.card{background:var(--card);padding:20px;border-radius:var(--radius);box-shadow:0 12px 40px rgba(11,15,35,0.06)}
h1{margin:0 0 8px;font-size:1.2rem}
.lead{color:var(--muted);margin-bottom:12px}
label{display:block;margin-top:10px;font-weight:600}
input[type="password"]{width:100%;padding:10px;border-radius:8px;border:1px solid #e6eef9;margin-top:6px;font-size:15px}
.btn{margin-top:14px;padding:10px 14px;border-radius:10px;background:var(--accent);color:#fff;border:0;cursor:pointer}
.error{color:#7f1d1d;background:#fff1f2;padding:10px;border-radius:8px}
.success{color:#064e3b;background:#ecfdf5;padding:10px;border-radius:8px}
.note{font-size:0.9rem;color:var(--muted);margin-top:10px}
.helper{font-size:0.9rem;color:var(--muted);margin-top:6px}
.passok{color:green;font-weight:600}
.passbad{color:#b91c1c;font-weight:600}
</style>
</head>
<body>
  <div class="container">
    <div class="card" role="main" aria-live="polite">
      <h1>Set a new password</h1>
      <div class="lead">Create a strong password for your admin account.</div>

      <?php if ($error): ?>
        <div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="success" role="status"><?php echo $success; ?></div>
        <p class="note">You will be redirected to the login page shortly.</p>
        <script>setTimeout(function(){ window.location.href = 'login.php'; }, 3500);</script>
      <?php endif; ?>

      <?php if ($showForm || ($_SERVER['REQUEST_METHOD'] === 'POST' && $error)): ?>
        <form method="post" novalidate>
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
          <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

          <label for="password">New password</label>
          <input id="password" type="password" name="password" required placeholder="Choose a strong password" autocomplete="new-password" minlength="<?php echo $minLength; ?>">

          <label for="password2">Confirm password</label>
          <input id="password2" type="password" name="password2" required placeholder="Repeat the password" autocomplete="new-password">

          <div class="helper" id="pwHelper">Minimum <?php echo $minLength; ?> characters. Use letters, numbers & symbols.</div>
          <div style="margin-top:10px">
            <button class="btn" type="submit" id="submitBtn" disabled>Set password</button>
          </div>
        </form>

        <p class="note">If your link expired you can <a href="reset_request.php">request a new reset</a>.</p>

        <script>
        // Minimal client-side check: enable submit only when passwords match and length ok
        (function(){
          const pw = document.getElementById('password');
          const pw2 = document.getElementById('password2');
          const btn = document.getElementById('submitBtn');
          const helper = document.getElementById('pwHelper');
          const minLen = <?php echo (int)$minLength; ?>;

          function update() {
            const a = pw.value || '';
            const b = pw2.value || '';
            if (a.length < minLen) {
              helper.textContent = 'Password must be at least ' + minLen + ' characters.';
              helper.className = 'helper passbad';
              btn.disabled = true;
              return;
            }
            if (a !== b) {
              helper.textContent = 'Passwords do not match.';
              helper.className = 'helper passbad';
              btn.disabled = true;
              return;
            }
            helper.textContent = 'Passwords match.';
            helper.className = 'helper passok';
            btn.disabled = false;
          }
          pw.addEventListener('input', update);
          pw2.addEventListener('input', update);
          // initial check for autofill cases
          setTimeout(update, 250);
        })();
        </script>

      <?php endif; ?>
    </div>
  </div>
</body>
</html>
