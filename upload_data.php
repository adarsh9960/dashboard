<?php
require_once '../helpers.php';
require_once '../db.php';
start_session_30d();

// simple auth check (you already used admin_id)
if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$csrf = generate_csrf();
$messages = [];
?>
<!doctype html>
<html>
<head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin panel</title></head>
<body style="font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;padding:12px">
  <h2>Admin — Upload clients (CSV / optional XLSX)</h2>
  <form action="upload_data.php" method="post" enctype="multipart/form-data">
    <label>Choose CSV file (UTF-8, headers: name,phone,business,email,notes)</label><br>
    <input type="file" name="file" accept=".csv, .xlsx"><br><br>
    <button type="submit">Upload & Import</button>
  </form>
  <p><a href="logout.php">Logout</a></p>
  <hr>
  <h3>Instructions</h3>
  <ul>
    <li>CSV must have header row: <code>name,phone,business,email,notes</code>.</li>
    <li>Phone will be normalized to digits only.</li>
  </ul>
</body>
</html>
