<?php
// admin/reset_request.php
require_once '../helpers.php';
require_once '../db.php';
require_once '../mailer_wrapper.php'; // optional helper; we fallback to PHPMailer if needed

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

start_session_30d();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = $_POST['csrf'] ?? '';
  if (!verify_csrf($csrf)) {
    $error = 'Security token mismatch.';
  } else {
    $email = trim($_POST['email'] ?? '');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $error = 'Enter a valid email.';
    } else {
      // lookup admin
      $stmt = $conn->prepare("SELECT id FROM admins WHERE email = ? LIMIT 1");
      $stmt->bind_param('s', $email);
      $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      // Always show a generic success message to avoid account enumeration
      if (!$row) {
        $success = 'If that email exists, a reset link has been sent.';
      } else {
        // create token
        $rawToken = bin2hex(random_bytes(24));           // 48 hex chars
        $tokenHash = hash('sha256', $rawToken);
        $expiry = date('Y-m-d H:i:s', time() + 3600);    // 1 hour expiry

        $upd = $conn->prepare("UPDATE admins SET reset_token_hash = ?, reset_expires_at = ? WHERE id = ?");
        $upd->bind_param('ssi', $tokenHash, $expiry, $row['id']);
        $upd->execute();
        $upd->close();

        // IMPORTANT: point to your actual reset_password.php location
        // You said the file lives at public_html/form/admin/reset_password.php
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $resetUrl = $scheme . $_SERVER['HTTP_HOST'] . '/form/admin/reset_password.php?email=' . urlencode($email) . '&token=' . urlencode($rawToken);

        // email content
        $subject = 'Admin password reset';
        $body = "<p>Hello,</p>
                 <p>A request was made to reset your admin password. Click the link below to reset (valid 1 hour):</p>
                 <p><a href=\"{$resetUrl}\">Reset admin password</a></p>
                 <p>If you didn't request this, ignore this email.</p>";

        // Send using helper if available
        $sent = false;
        if (function_exists('mailer_send_to')) {
          // preferred helper: mailer_send_to($toEmail, $toName, $subject, $htmlBody)
          $sent = mailer_send_to($email, 'Admin', $subject, $body);
        } else {
          // Fallback: attempt to send directly with PHPMailer (using same SMTP details as mailer_wrapper)
          try {
            // Ensure PHPMailer classes are available in ../PHPMailer/src/
            require_once __DIR__ . '/../PHPMailer/src/Exception.php';
            require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
            require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = 'sg2plzcpnl509587.prod.sin2.secureserver.net';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'postman@itzadarsh.co.in';
            $mail->Password   = 'Adarsh_1M9960';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom('postman@itzadarsh.co.in', 'ITZ Adarsh');
            $mail->addAddress($email, 'Admin');
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);

            $mail->send();
            $sent = true;
          } catch (Exception $e) {
            error_log('Reset email send failed: ' . $e->getMessage());
            $sent = false;
          }
        }

        // We don't reveal success/failure to user for security; still set generic message
        $success = 'If that email exists, a reset link has been sent.';
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
<title>Reset password</title>
<style>
body{
  font-family:Inter,system-ui;
  padding:18px;
  background:#fbfdff;
  margin:0;
}
.box{
  max-width:520px;
  margin:3rem auto;
  background:#fff;
  padding:18px;
  border-radius:12px;
  box-shadow:0 12px 40px rgba(11,17,34,0.06);
}
label{
  display:block;
  margin-top:10px;
  font-weight:600;
}
input{
  display:block;
  width:100%;
  max-width:100%;
  padding:10px;
  border-radius:8px;
  border:1px solid #e6eef9;
  margin-top:6px;
  font-size:1rem;
  box-sizing:border-box; /* 👈 ensures padding/border included */
}
.actions{
  display:flex;
  justify-content:space-between;
  gap:8px;
  margin-top:12px;
  flex-wrap:wrap; /* 👈 keeps buttons inside on small screens */
}
.btn{
  flex:1;
  padding:10px 14px;
  border-radius:10px;
  background:#0b5cff;
  color:#fff;
  border:0;
  cursor:pointer;
  text-align:center;
  text-decoration:none;
  font-weight:600;
  white-space:nowrap;
}
.btn.secondary{
  background:#eef2ff;
  color:#0b5cff;
}
.notice{color:#0b1220}
.error{color:#b91c1c;background:#fff1f2;padding:8px;border-radius:8px}
.success{color:#065f46;background:#ecfdf5;padding:8px;border-radius:8px}
</style>
</head>
<body>
<div class="box">
  <h2>Reset admin password</h2>
  <p class="notice">Enter your admin email and we'll send a reset link if the account exists.</p>

  <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">

    <label for="email">Email</label>
    <input id="email" type="email" name="email" required placeholder="admin@itzadarsh.co.in">

    <div class="actions">
      <button class="btn" type="submit">Send reset link</button>
      <a class="btn secondary" href="create_admin.php">Get Access</a>
    </div>
  </form>

  <p style="margin-top:12px;text-align:center">
    <a href="login.php" style="color:#0b5cff;text-decoration:none">Back to login</a>
  </p>
</div>
</body>
</html>
