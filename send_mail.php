<?php
// send_mail.php
require_once '../helpers.php';
require_once '../db.php';
// require your mailer wrapper (see below) — adjust path
require_once __DIR__ . 'form/mailer_wrapper.php';

start_session_30d();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id'])) { echo json_encode(['success'=>false,'message'=>'Not authorized']); exit; }
$csrf = $_POST['csrf'] ?? '';
if (!verify_csrf($csrf)) { echo json_encode(['success'=>false,'message'=>'CSRF']); exit; }

$client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
$subject = trim($_POST['subject'] ?? '');
$body_raw = trim($_POST['body'] ?? '');

if (!$client_id || !$subject || !$body_raw) {
  echo json_encode(['success'=>false,'message'=>'Missing fields']); exit;
}

// fetch client
$stmt = $conn->prepare("SELECT id,name,email,meeting_slot FROM clients WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$client || !filter_var($client['email'], FILTER_VALIDATE_EMAIL)) {
  echo json_encode(['success'=>false,'message'=>'Client not found or invalid email']); exit;
}

// replace placeholders
$replacements = [
  '{{name}}' => $client['name'] ?? '',
  '{{email}}' => $client['email'] ?? '',
  '{{meeting_slot}}' => $client['meeting_slot'] ?? '',
];

$body = strtr($body_raw, $replacements);

// Optionally build HTML body
$html = nl2br(htmlspecialchars($body, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'));

// Use mailer wrapper — implement mailer_send($fromName, $toEmail, $toName, $subject, $htmlBody, $altText = '')
try {
  $fromName = 'ITZ Adarsh';
  $toEmail = $client['email'];
  $toName = $client['name'] ?: $toEmail;
  $alt = strip_tags($body);

  $ok = mailer_send($fromName, $toEmail, $toName, $subject, $html, $alt);
  if ($ok) {
    echo json_encode(['success'=>true]);
  } else {
    echo json_encode(['success'=>false,'message'=>'Mailer failed']);
  }
} catch (Exception $e) {
  error_log('send_mail error: '.$e->getMessage());
  echo json_encode(['success'=>false,'message'=>'Exception: '.$e->getMessage()]);
}
