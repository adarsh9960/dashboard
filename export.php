<?php
// admin/export.php
require_once '../helpers.php';
require_once '../db.php';
start_session_30d();
if (empty($_SESSION['admin_id'])) { header('Location: login.php'); exit; }

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=clients_'.date('Ymd_His').'.csv');
$out = fopen('php://output','w');
fputcsv($out, ['id','user_email','name','email','business_name','business_address','contact_number','description','meeting_slot','created_at']);
$res = $conn->query("SELECT * FROM clients ORDER BY created_at DESC");
while ($r = $res->fetch_assoc()) {
  fputcsv($out, [$r['id'],$r['user_email'],$r['name'],$r['email'],$r['business_name'],$r['business_address'],$r['contact_number'],$r['description'],$r['meeting_slot'],$r['created_at']]);
}
fclose($out);
exit;
