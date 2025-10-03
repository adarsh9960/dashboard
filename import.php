<?php
// admin/import.php
// CSV import for clients (admin-only). Put this in public_html/form/admin/import.php
require_once '../helpers.php';
require_once '../db.php';
start_session_30d();
if (empty($_SESSION['admin_id'])) { header('Location: login.php'); exit; }

$msg = '';
$errors = [];
$inserted = 0;
$skipped = 0;
$maxFileBytes = 4 * 1024 * 1024; // 4 MB max upload

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
  // basic upload checks
  if ($_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
    $errors[] = 'Upload error. Please try again.';
  } elseif ($_FILES['csv']['size'] > $maxFileBytes) {
    $errors[] = 'File too large. Max 4MB.';
  } else {
    $tmp = $_FILES['csv']['tmp_name'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmp);
    finfo_close($finfo);

    // allow text/csv, application/csv and a few others
    $allowedMimes = ['text/plain','text/csv','application/vnd.ms-excel','application/csv','text/comma-separated-values'];
    $ext = strtolower(pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION));
    if (!in_array($mime, $allowedMimes, true) && $ext !== 'csv') {
      $errors[] = "Invalid file type ($mime). Upload a CSV file.";
    } else {
      // open and parse
      if (($handle = fopen($tmp, 'r')) !== false) {
        // Start transaction for bulk insert if supported
        $useTransaction = $conn->query('BEGIN');
        $rowNum = 0;
        // Prepare statement once
        $stmt = $conn->prepare("INSERT INTO clients (user_email, name, email, business_name, business_address, contact_number, description, meeting_slot, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
        if (!$stmt) {
          $errors[] = 'DB prepare failed: ' . htmlspecialchars($conn->error);
        } else {
          while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $rowNum++;
            // skip blank lines
            if (count($data) === 1 && trim($data[0]) === '') { $skipped++; continue; }
            if ($rowNum === 1) {
              // assume header row — basic check (optional)
              $hdr = array_map('strtolower', array_map('trim', $data));
              // If header doesn't look like expected, still continue but warn
              if (!in_array('email', $hdr, true) && !in_array('user_email', $hdr, true)) {
                $errors[] = "Row 1 looks like data (no header detected). Make sure CSV has header. Continuing...";
                // don't `continue` — treat row 1 as data if needed
              } else {
                continue; // skip header
              }
            }

            // Expect at least 8 columns (if more, ignore extras)
            if (count($data) < 8) {
              $errors[] = "Row {$rowNum}: not enough columns (found " . count($data) . ").";
              $skipped++;
              continue;
            }

            // map & sanitize fields
            $user_email       = trim($data[0]);
            $name             = trim($data[1]);
            $email            = trim($data[2]);
            $business_name    = trim($data[3]);
            $business_address = trim($data[4]);
            $contact_number   = trim($data[5]);
            $description      = trim($data[6]);
            $meeting_slot     = trim($data[7]);

            // basic validation
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
              $errors[] = "Row {$rowNum}: invalid email '{$email}'.";
              $skipped++;
              continue;
            }
            // optional: validate user_email if present
            if ($user_email !== '' && !filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
              // allow blank user_email but flag malformed ones
              $errors[] = "Row {$rowNum}: invalid user_email '{$user_email}'.";
            }
            // sanitize length (prevent overly long fields)
            $name = mb_substr($name, 0, 255);
            $business_name = mb_substr($business_name, 0, 255);
            $business_address = mb_substr($business_address, 0, 512);
            $contact_number = mb_substr($contact_number, 0, 64);
            $description = mb_substr($description, 0, 2000);
            $meeting_slot = mb_substr($meeting_slot, 0, 255);

            // Bind and execute
            $stmt->bind_param('ssssssss',
              $user_email, $name, $email, $business_name, $business_address, $contact_number, $description, $meeting_slot
            );

            if ($stmt->execute()) {
              $inserted++;
            } else {
              $errors[] = "Row {$rowNum}: DB insert failed (" . htmlspecialchars($stmt->error) . ")";
              $skipped++;
            }
          } // end while

          $stmt->close();
        } // end prepared

        fclose($handle);

        // commit or rollback
        if ($useTransaction) {
          if (count($errors) === 0) {
            $conn->query('COMMIT');
          } else {
            // partial failures still commit inserted rows — adjust as needed
            $conn->query('COMMIT');
          }
        }

        $msg = "Import finished. Inserted: {$inserted}, Skipped: {$skipped}, Errors: " . count($errors);
      } else {
        $errors[] = 'Failed to open uploaded file.';
      }
    } // mime ok
  } // upload ok
} // POST
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — Import CSV</title>
<style>
:root{--accent:#0b5cff;--muted:#6b7280;--bg:#fbfdff;--card:#fff;--radius:12px;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial}
body{margin:0;padding:18px;background:var(--bg);color:#0b1220}
.container{max-width:920px;margin:0 auto}
.card{background:var(--card);border-radius:var(--radius);padding:18px;box-shadow:0 12px 40px rgba(11,15,35,0.06)}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.logo{width:48px;height:48px;border-radius:10px;background:var(--accent);display:grid;place-items:center;color:#fff;font-weight:700}
h1{margin:0;font-size:1.15rem}
.note{color:var(--muted);margin-top:6px}
.form-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px}
.input-file{display:flex;gap:8px;align-items:center}
.input-file input[type="file"]{padding:6px}
.btn{background:var(--accent);color:#fff;border:0;padding:10px 14px;border-radius:10px;cursor:pointer}
.ghost{background:transparent;border:1px solid rgba(11,92,255,0.12);color:var(--accent);padding:8px 12px;border-radius:10px;text-decoration:none}
.result{margin-top:12px;padding:12px;border-radius:10px;background:#f8fafc;border:1px solid #eef6ff}
.err{color:#7f1d1d;background:#fff1f2;padding:10px;border-radius:8px;margin-top:8px}
.ok{color:#064e3b;background:#ecfdf5;padding:10px;border-radius:8px;margin-top:8px}
.pre{white-space:pre-wrap;font-family:monospace;background:#fbfdff;padding:8px;border-radius:8px;border:1px solid #eef6ff;margin-top:8px}
.small{font-size:0.9rem;color:var(--muted);margin-top:8px}
.table{width:100%;border-collapse:collapse;margin-top:12px}
.table th,.table td{padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:left}
.actions{display:flex;gap:8px;margin-top:12px}
.sample{display:inline-block;padding:8px 10px;border-radius:8px;background:#eef2ff;color:var(--accent);text-decoration:none}
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <div class="header">
      <div style="display:flex;gap:12px;align-items:center">
        <div class="logo">ITZ</div>
        <div>
          <h1>Import clients (CSV)</h1>
          <div class="note">Upload CSV with client submissions (header required).</div>
        </div>
      </div>
      <div>
        <a class="ghost" href="dashboard.php">Back to dashboard</a>
      </div>
    </div>

    <form method="post" enctype="multipart/form-data" aria-labelledby="importTitle">
      <div class="form-row">
        <div class="input-file">
          <input type="file" name="csv" accept=".csv,text/csv" required>
          <button class="btn" type="submit">Import</button>
        </div>

        <!-- small sample CSV downloadable inline -->
        <a class="sample" href="data:text/csv;charset=utf-8,<?php echo rawurlencode('user_email,name,email,business_name,business_address,contact_number,description,meeting_slot' . "\n" . 'admin@itzadarsh.co.in,John Doe,john@example.com,Acme Ltd,Street 123,9876543210,"Discussion about product",2025-09-10 14:00'); ?>" download="sample_clients.csv">Download sample CSV</a>
      </div>
    </form>

    <?php if ($msg): ?>
      <div class="result">
        <div class="<?php echo count($errors) ? 'err' : 'ok'; ?>">
          <?php echo htmlspecialchars($msg); ?>
        </div>

        <?php if (!empty($errors)): ?>
          <div class="small">Errors (first 20):</div>
          <div class="pre">
<?php
  $i = 0;
  foreach ($errors as $e) {
    $i++;
    if ($i > 20) break;
    echo ($i . '. ' . htmlspecialchars($e) . "\n");
  }
?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div style="margin-top:14px">
      <strong>CSV header order</strong>
      <table class="table" role="table" aria-label="CSV header example">
        <thead><tr><th>Column</th><th>Meaning</th></tr></thead>
        <tbody>
          <tr><td>user_email</td><td>Email of the logged-in user who submitted (optional)</td></tr>
          <tr><td>name</td><td>Client full name</td></tr>
          <tr><td>email</td><td>Client email (required)</td></tr>
          <tr><td>business_name</td><td>Company or brand</td></tr>
          <tr><td>business_address</td><td>Address line</td></tr>
          <tr><td>contact_number</td><td>Phone / contact</td></tr>
          <tr><td>description</td><td>Discussion notes</td></tr>
          <tr><td>meeting_slot</td><td>Meeting time string (store as text)</td></tr>
        </tbody>
      </table>
    </div>

  </div>
</div>
</body>
</html>
