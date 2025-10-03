<?php
// admin/save_call.php
// POST: csrf, client_id, call_status, notes, followup_date (optional)
// Returns JSON:
// { success: true, call_id: int, saved: {...}, client: {...} }

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
start_session_30d();

// Ensure we don't accidentally emit warnings before JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF
$post_csrf = $_POST['csrf'] ?? '';
if (!verify_csrf($post_csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']);
    exit;
}

// sanitize inputs
$client_id = (int)($_POST['client_id'] ?? 0);
$call_status = trim($_POST['call_status'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$followup_raw = trim($_POST['followup_date'] ?? '');
$followup_date = $followup_raw === '' ? null : date('Y-m-d H:i:s', strtotime($followup_raw));

// validate
if ($client_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid client_id']);
    exit;
}
$allowed = ['connected', 'not_connected', 'voicemail', 'busy'];
if (!in_array($call_status, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid call_status']);
    exit;
}

// determine agent_id: if session admin id exists in users table, use it; otherwise use NULL
$session_admin = (int)($_SESSION['admin_id'] ?? 0);
$agent_id = null;
if ($session_admin > 0) {
    $u = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
    if ($u) {
        $u->bind_param('i', $session_admin);
        $u->execute();
        $res = $u->get_result();
        if ($res && $res->num_rows > 0) {
            $agent_id = $session_admin;
        }
        $u->close();
    }
}

// Build insert SQL dynamically so NULL values are inserted properly
$cols = ["client_id", "call_status", "notes"];
$placeholders = ["?", "?", "?"];
$types = "iss"; // client_id int, call_status string, notes string
$params = [$client_id, $call_status, $notes];

if ($agent_id !== null) {
    // insert agent_id
    array_splice($cols, 1, 0, "agent_id"); // client_id, agent_id, call_status, notes
    array_splice($placeholders, 1, 0, "?");
    $types = "i" . $types; // agent_id is int, then client_id,int ??? we need reorder -> instead rebuild
    // easier: rebuild with consistent ordering: client_id, agent_id, call_status, notes, followup_date?
    $cols = ["client_id","agent_id","call_status","notes"];
    $placeholders = ["?","?","?","?"];
    $types = "iiss";
    $params = [$client_id, $agent_id, $call_status, $notes];
}

if ($followup_date !== null) {
    $cols[] = "followup_date";
    $placeholders[] = "?";
    $types .= "s";
    $params[] = $followup_date;
}

// always add created_at = NOW() using SQL
$sql = "INSERT INTO client_calls (" . implode(",", $cols) . ", created_at) VALUES (" . implode(",", $placeholders) . ", NOW())";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB prepare failed: ' . $conn->error]);
    exit;
}

// bind params dynamically
$bind_names = [];
$bind_names[] = $types;
for ($i = 0; $i < count($params); $i++) {
    $bind_names[] = &$params[$i];
}
call_user_func_array([$stmt, 'bind_param'], $bind_names);

if (!$stmt->execute()) {
    http_response_code(500);
    // If FK problem still happens, give safe error
    echo json_encode(['success' => false, 'message' => 'Failed to save call: ' . $stmt->error]);
    $stmt->close();
    exit;
}
$call_id = $stmt->insert_id;
$stmt->close();

// fetch saved record with agent name (if any)
$saved_stmt = $conn->prepare("
    SELECT cc.id, cc.client_id, cc.agent_id, cc.call_status, cc.notes, cc.followup_date, cc.created_at,
           COALESCE(u.name, '') AS agent_name
    FROM client_calls cc
    LEFT JOIN users u ON cc.agent_id = u.id
    WHERE cc.id = ? LIMIT 1
");
if (!$saved_stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error retrieving saved call']);
    exit;
}
$saved_stmt->bind_param('i', $call_id);
$saved_stmt->execute();
$saved_res = $saved_stmt->get_result();
$saved = $saved_res ? $saved_res->fetch_assoc() : null;
$saved_stmt->close();

// fetch client basic info
$client = null;
$cstmt = $conn->prepare("SELECT id, name, contact_number FROM clients WHERE id = ? LIMIT 1");
if ($cstmt) {
    $cstmt->bind_param('i', $client_id);
    $cstmt->execute();
    $cres = $cstmt->get_result();
    $client = $cres ? $cres->fetch_assoc() : null;
    $cstmt->close();
}

echo json_encode([
    'success' => true,
    'call_id' => (int)$call_id,
    'saved' => $saved,
    'client' => $client
]);
exit;
