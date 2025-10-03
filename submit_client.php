<?php
// submit_client.php — safer form handler, inserts client and emails admin + client ack
require_once 'helpers.php';
require_once __DIR__ . '/mailer_wrapper.php'; // new wrapper
require_once 'db.php';

start_session_30d();

/* simple file logger used below */
function dbg_log($msg) {
    $logfile = __DIR__ . '/../logs/form_submit.log';
    @mkdir(dirname($logfile), 0755, true);
    file_put_contents($logfile, "[".date('c')."] " . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/* ensure correct method and authentication (if required) */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php'); exit;
}
if (empty($_SESSION['user_email'])) {
    // If anonymous submissions allowed, remove this block.
    header('Location: index.php'); exit;
}

/* CSRF check */
$csrf = $_POST['csrf'] ?? '';
if (!verify_csrf($csrf)) {
    dbg_log("CSRF failed for user " . ($_SESSION['user_email'] ?? 'unknown'));
    die('Invalid CSRF');
}

/* Sanitize inputs */
$name             = trim((string)($_POST['name'] ?? ''));
$email_raw        = trim((string)($_POST['email'] ?? ''));
$business_name    = trim((string)($_POST['business_name'] ?? ''));
$business_address = trim((string)($_POST['business_address'] ?? ''));
$contact          = trim((string)($_POST['contact'] ?? ''));
$description      = trim((string)($_POST['description'] ?? ''));
$meeting_slot_raw = trim((string)($_POST['meeting_slot'] ?? ''));

/* Basic validation */
if ($name === '' || $email_raw === '' || $meeting_slot_raw === '') {
    dbg_log("Validation failed — missing required: name:'{$name}', email:'{$email_raw}', meeting:'{$meeting_slot_raw}'");
    header('Location: index.php?status=error'); exit;
}

/* sanitize & validate email */
$email = filter_var($email_raw, FILTER_SANITIZE_EMAIL);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    dbg_log("Invalid email after sanitize: '{$email_raw}' -> '{$email}'");
    header('Location: index.php?status=error'); exit;
}

/* Normalize meeting slot (store as 'Y-m-d H:i:s' or raw if parse fails) */
$meeting_slot = $meeting_slot_raw;
try {
    $dt = new DateTime($meeting_slot_raw);
    $meeting_slot = $dt->format('Y-m-d H:i:s');
} catch (Exception $e) {
    dbg_log("Meeting parse failed, storing raw: '{$meeting_slot_raw}'");
    $meeting_slot = $meeting_slot_raw;
}

/* Insert into DB (prepared statement) */
$stmt = $conn->prepare("
    INSERT INTO clients
      (user_email, name, email, business_name, business_address, contact_number, description, meeting_slot, status, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
");
if (!$stmt) {
    dbg_log("DB prepare failed: " . $conn->error);
    header('Location: index.php?status=error'); exit;
}

$bindOk = $stmt->bind_param(
    'ssssssss',
    $_SESSION['user_email'],
    $name,
    $email,
    $business_name,
    $business_address,
    $contact,
    $description,
    $meeting_slot
);
if (!$bindOk) {
    dbg_log("bind_param failed: " . $stmt->error);
    $stmt->close();
    header('Location: index.php?status=error'); exit;
}
$execOk = $stmt->execute();
if (!$execOk) {
    dbg_log("Execute failed: " . $stmt->error);
    $stmt->close();
    header('Location: index.php?status=error'); exit;
}
$insertId = (int)$stmt->insert_id;
$stmt->close();

/* ---------- Prepare emails ---------- */
/* admin & client addresses (use env or defaults) */
$adminEmail = getenv('ADMIN_EMAIL') ?: 'admin@itzadarsh.co.in';
$adminName  = getenv('ADMIN_NAME')  ?: 'ITZ Admin';
$siteUrl    = getenv('SITE_URL') ?: 'https://itzadarsh.co.in';
$fromName   = getenv('MAIL_FROM_NAME') ?: 'ITZ Adarsh';

$esc = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); };

$clientNameEsc = $esc($name);
$clientEmailEsc = $esc($email);
$businessEsc = $esc($business_name);
$addressEsc = $esc($business_address);
$contactEsc = $esc($contact);
$meetingEsc = $esc($meeting_slot);
$descEsc = $esc($description);
$agentWho = $esc($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'Unknown');

/* admin subject & body */
$adminSubject = "[Booking] New submission from " . ($clientNameEsc ?: $clientEmailEsc);
$adminBody  = '<!doctype html><html><body>';
$adminBody .= '<h2>New Booking / Request</h2>';
$adminBody .= '<p><strong>Submitted at:</strong> ' . $esc((new DateTime())->format('Y-m-d H:i:s')) . '</p>';
$adminBody .= '<p><strong>Client DB id:</strong> ' . intval($insertId) . '</p>';
$adminBody .= '<p><strong>Client name:</strong> ' . $clientNameEsc . '</p>';
$adminBody .= '<p><strong>Client email:</strong> ' . $clientEmailEsc . '</p>';
if ($businessEsc !== '') $adminBody .= '<p><strong>Business name:</strong> ' . $businessEsc . '</p>';
if ($addressEsc !== '') $adminBody .= '<p><strong>Business address:</strong> ' . $addressEsc . '</p>';
if ($contactEsc !== '') $adminBody .= '<p><strong>Contact number:</strong> ' . $contactEsc . '</p>';
if ($meetingEsc !== '') $adminBody .= '<p><strong>Meeting slot:</strong> ' . $meetingEsc . '</p>';
if ($descEsc !== '') $adminBody .= '<p><strong>Description:</strong><br>' . nl2br($descEsc) . '</p>';
$adminBody .= '<hr>';
$adminBody .= '<p>Submitted by agent: ' . $agentWho . '</p>';
$adminBody .= '<p><a href="' . $esc($siteUrl . '/admin/dashboard.php') . '">Open admin dashboard</a></p>';
$adminBody .= '</body></html>';

/* client ack email */
$clientSubject = "We received your booking request";
$clientBody  = '<!doctype html><html><body>';
$clientBody .= '<p>Hi ' . ($clientNameEsc !== '' ? $clientNameEsc : 'there') . ',</p>';
$clientBody .= '<p>Thanks — we have received your booking request. Here are the details we got:</p>';
$clientBody .= '<ul>';
if ($businessEsc !== '') $clientBody .= '<li><strong>Business:</strong> ' . $businessEsc . '</li>';
if ($contactEsc !== '') $clientBody .= '<li><strong>Contact:</strong> ' . $contactEsc . '</li>';
if ($meetingEsc !== '') $clientBody .= '<li><strong>Meeting time:</strong> ' . $meetingEsc . '</li>';
$clientBody .= '</ul>';
$clientBody .= '<p>We will contact you shortly to confirm. Regards,<br>' . $esc($fromName) . '</p>';
$clientBody .= '</body></html>';

/* ---------- Send emails (best-effort) ---------- */
try {
    dbg_log("MAILER_ENVELOPE admin={$adminEmail}, client={$clientEmailEsc}, client_id={$insertId}");
} catch (Exception $e) { /* ignore logging errors */ }

$adminSent = false;
$clientSent = false;

try {
    $adminSent = mailer_send_to($adminEmail, $adminName, $adminSubject, $adminBody);
    dbg_log("ADMIN_SEND_RESULT: " . ($adminSent ? 'SENT' : 'FAILED') . " to={$adminEmail} client_id={$insertId}");
} catch (Throwable $e) {
    dbg_log("ADMIN_SEND_EXCEPTION: " . $e->getMessage());
}

try {
    $clientSent = mailer_send_to($email, $name, $clientSubject, $clientBody);
    dbg_log("CLIENT_ACK_RESULT: " . ($clientSent ? 'SENT' : 'FAILED') . " to={$email} client_id={$insertId}");
} catch (Throwable $e) {
    dbg_log("CLIENT_SEND_EXCEPTION: " . $e->getMessage());
}

/* redirect user to thank-you */
header('Location: ' . $siteUrl . '/form/thankyou?status=success');
exit;
