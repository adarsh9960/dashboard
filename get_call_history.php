<?php
// get_call_history.php
require_once '../helpers.php';
require_once '../db.php';
start_session_30d();

header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit;
}

$client_id = (int)($_GET['client_id'] ?? 0);
$offset = (int)($_GET['offset'] ?? 0);
$limit = (int)($_GET['limit'] ?? 10);

if ($client_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid client ID']);
    exit;
}

// Get call history with agent names
$stmt = $conn->prepare("
    SELECT cc.*, u.name as agent_name 
    FROM client_calls cc 
    LEFT JOIN users u ON cc.agent_id = u.id 
    WHERE cc.client_id = ? 
    ORDER BY cc.created_at DESC 
    LIMIT ? OFFSET ?
");
if ($stmt) {
    $stmt->bind_param('iii', $client_id, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    $calls = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo json_encode($calls);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}