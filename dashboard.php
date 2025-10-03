<?php
// admin/dashboard.php
require_once '../helpers.php';
require_once '../db.php';
start_session_30d();

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$csrf = generate_csrf();
$messages = [];

// helper escape
function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// normalize meeting_slot -> 'Y-m-d H:i:s' or null
function normalize_meeting_slot($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    $raw = str_replace('T', ' ', $raw);
    try {
        $dt = new DateTime($raw);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $ex) {
        return null;
    }
}


/* --- Fetch agents for assign dropdown --- */
$agents = [];
$aq = $conn->query("SELECT id, name, email FROM users ORDER BY name ASC");
if ($aq) {
    while ($r = $aq->fetch_assoc()) $agents[] = $r;
}

/* --- Handle POST actions --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // delete by delete_id (form includes csrf)
    if (!empty($_POST['delete_id'])) {
        $post_csrf = $_POST['csrf'] ?? '';
        if (!verify_csrf($post_csrf)) {
            $messages[] = ['type'=>'error','text'=>'CSRF error on delete'];
        } else {
            $did = (int)$_POST['delete_id'];
            $stmt = $conn->prepare("DELETE FROM clients WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $did);
                if ($stmt->execute()) {
                    $messages[] = ['type'=>'ok','text'=>"Client #{$did} deleted."];
                } else {
                    $messages[] = ['type'=>'error','text'=>"Delete failed: " . e($stmt->error)];
                }
                $stmt->close();
            } else {
                $messages[] = ['type'=>'error','text'=>"DB prepare failed (delete): " . e($conn->error)];
            }
        }
     }

        // create client
     if (!empty($_POST['action']) && $_POST['action'] === 'create_client') {
        $post_csrf = $_POST['csrf'] ?? '';
        if (!verify_csrf($post_csrf)) {
            $messages[] = ['type'=>'error','text'=>'CSRF error on create'];
        } else {
            // required fields
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $business_name = trim($_POST['business_name'] ?? '');
            $contact = trim($_POST['contact'] ?? '');
            $meeting_slot = normalize_meeting_slot($_POST['meeting_slot'] ?? '');
            $business_address = trim($_POST['business_address'] ?? '');
            $status = 'pending';

            if ($name === '' || $email === '') {
                $messages[] = ['type'=>'error','text'=>'Name and email are required to create a client.'];
            } else {
                // note the order: name,email,business_name,contact,meeting_slot?,business_address,status
                $sql = "INSERT INTO clients (name, email, business_name, contact_number, meeting_slot, business_address, status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, " . ($meeting_slot ? "?" : "NULL") . ", ?, ?, NOW(), NOW())";

                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    $messages[] = ['type'=>'error','text'=>"DB prepare failed (create): " . e($conn->error)];
                } else {
                    if ($meeting_slot) {
                        // 7 placeholders: name,email,business_name,contact,meeting_slot,business_address,status
                        $stmt->bind_param('sssssss', $name, $email, $business_name, $contact, $meeting_slot, $business_address, $status);
                    } else {
                        // 6 placeholders: name,email,business_name,contact,business_address,status
                        $stmt->bind_param('ssssss', $name, $email, $business_name, $contact, $business_address, $status);
                    }
                    if ($stmt->execute()) {
                        $newId = $stmt->insert_id;
                        $messages[] = ['type'=>'ok','text'=>"Client created (ID: {$newId})."];
                    } else {
                        $messages[] = ['type'=>'error','text'=>"Insert failed: " . e($stmt->error)];
                    }
                    $stmt->close();
                }
            }
        }
    }


    // update_client (your existing block — keep and reuse)
    if (!empty($_POST['action']) && $_POST['action'] === 'update_client' && !empty($_POST['client_id'])) {
        $post_csrf = $_POST['csrf'] ?? '';
        if (!verify_csrf($post_csrf)) {
            $messages[] = ['type'=>'error','text'=>'CSRF error on update'];
        } else {
            $clientId = (int)$_POST['client_id'];

            // sanitize incoming
            $status = trim($_POST['status'] ?? 'pending');
            $allowed = ['pending','booked','appointment_fixed','paid','cancelled'];
            if (!in_array($status, $allowed, true)) $status = 'pending';

            $package_name = trim($_POST['package_name'] ?? '');
            $package_price_raw = trim($_POST['package_price'] ?? '');
            $photo_url = trim($_POST['photo_url'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $agent_id = isset($_POST['agent_id']) && $_POST['agent_id'] !== '' ? (int)$_POST['agent_id'] : null;
            $meeting_slot_input = trim($_POST['meeting_slot'] ?? '');

            // normalize meeting_slot
            $meeting_slot = normalize_meeting_slot($meeting_slot_input); // returns string 'Y-m-d H:i:s' or null

            // normalize price to decimal
            $package_price = 0.00;
            if ($package_price_raw !== '') {
                $pp = str_replace([',','₹',' '], '', $package_price_raw);
                $package_price = (float)$pp;
            }

            // Build dynamic SQL depending on nullability
            $parts = [];
            $params = [];
            $types = '';

            $parts[] = "status = ?";
            $params[] = $status; $types .= 's';

            $parts[] = "package_name = ?";
            $params[] = $package_name; $types .= 's';

            $parts[] = "package_price = ?";
            $params[] = $package_price; $types .= 'd';

            $parts[] = "photo_url = ?";
            $params[] = $photo_url; $types .= 's';

            $parts[] = "description = ?";
            $params[] = $notes; $types .= 's';

            if ($agent_id === null) {
                $parts[] = "agent_id = NULL";
            } else {
                $parts[] = "agent_id = ?";
                $params[] = $agent_id; $types .= 'i';
            }

            if ($meeting_slot === null) {
                $parts[] = "meeting_slot = NULL";
            } else {
                $parts[] = "meeting_slot = ?";
                $params[] = $meeting_slot; $types .= 's';
            }

            $parts[] = "updated_at = NOW()";

            $sql = "UPDATE clients SET " . implode(", ", $parts) . " WHERE id = ? LIMIT 1";
            $params[] = $clientId; $types .= 'i';

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $messages[] = ['type'=>'error','text'=>"DB prepare failed: " . e($conn->error)];
            } else {
                if (!empty($params)) {
                    $bind_names = [];
                    $bind_names[] = $types;
                    for ($i = 0; $i < count($params); $i++) {
                        if ($types[$i] === 'd') {
                            $params[$i] = (float)$params[$i];
                        } elseif ($types[$i] === 'i') {
                            $params[$i] = (int)$params[$i];
                        } else {
                            $params[$i] = (string)$params[$i];
                        }
                        $bind_names[] = &$params[$i];
                    }
                    call_user_func_array([$stmt, 'bind_param'], $bind_names);
                }

                $ok = $stmt->execute();
                if ($ok) {
                    $messages[] = ['type'=>'ok','text'=>"Client #{$clientId} updated."];
                } else {
                    $messages[] = ['type'=>'error','text'=>"Update failed: " . e($stmt->error)];
                }
                $stmt->close();
            }
        }
    }

    // After handling POSTs, regenerate CSRF for new page
    $csrf = generate_csrf();
}

/* --- Fetch rows & analytics --- */
$rows = [];
$res = $conn->query("SELECT id, name, email, business_name, business_address, contact_number, description, meeting_slot, created_at, status, package_name, package_price, photo_url, agent_id FROM clients ORDER BY created_at DESC LIMIT 1000");
if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);

// analytics numbers
$total_clients = (int) ($conn->query("SELECT COUNT(*) AS cnt FROM clients")->fetch_assoc()['cnt'] ?? 0);
$tmp = $conn->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(package_price),0) AS revenue FROM clients WHERE status = 'paid'")->fetch_assoc();
$paid_clients = (int)($tmp['cnt'] ?? 0);
$revenue_paid = (float)($tmp['revenue'] ?? 0.00);
$pending_appointments = (int) ($conn->query("SELECT COUNT(*) AS cnt FROM clients WHERE status = 'pending'")->fetch_assoc()['cnt'] ?? 0);
$appointments_30d = (int) ($conn->query("SELECT COUNT(*) AS cnt FROM clients WHERE meeting_slot >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND meeting_slot IS NOT NULL")->fetch_assoc()['cnt'] ?? 0);

function status_label($s) {
    switch ($s) {
        case 'booked': return ['label'=>'Booked','class'=>'st-booked'];
        case 'pending': return ['label'=>'Pending','class'=>'st-pending'];
        case 'appointment_fixed': return ['label'=>'Appointment','class'=>'st-fixed'];
        case 'paid': return ['label'=>'Paid','class'=>'st-paid'];
        case 'cancelled': return ['label'=>'Cancelled','class'=>'st-cancel'];
        default: return ['label'=>ucfirst($s),'class'=>'st-unknown'];
    }
}

// Get admin user details for header
$admin_id = $_SESSION['admin_id'];
$admin_query = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
$admin_query->bind_param('i', $admin_id);
$admin_query->execute();
$admin_result = $admin_query->get_result();
$admin_data = $admin_result->fetch_assoc();
$admin_name = $admin_data['name'] ?? 'Admin';
$admin_email = $admin_data['email'] ?? '';


?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Dashboard — ITZ</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<style>
:root{--accent:#0b5cff;--muted:#6b7280;--bg:#f3f6fb;--card:#fff;--radius:12px}
*{box-sizing:border-box}
body{margin:0;font-family:Inter,system-ui,Arial;background:var(--bg);color:#0b1220;padding:0}
.container{max-width:1200px;margin:0 auto;padding:12px}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;padding:12px;background:#fff;border-radius:var(--radius);box-shadow:0 4px 12px rgba(12,20,40,0.06)}
.brand{display:flex;gap:12px;align-items:center}
.logo{width:44px;height:44px;border-radius:10px;background:var(--accent);display:grid;place-items:center;color:#fff;font-weight:700}
.hgroup h1{margin:0;font-size:1.05rem}
.hgroup p{margin:0;color:var(--muted);font-size:0.9rem}
.controls{display:flex;gap:8px;align-items:center}
.btn{background:var(--accent);color:#fff;padding:10px 12px;border-radius:10px;border:none;cursor:pointer;font-weight:600}
.ghost{background:transparent;border:1px solid rgba(11,92,255,0.14);color:var(--accent);padding:8px 10px;border-radius:10px;text-decoration:none}
.grid{display:grid;grid-template-columns:1fr 360px;gap:16px}
.card{background:var(--card);border-radius:var(--radius);padding:14px;box-shadow:0 8px 30px rgba(12,20,40,0.06)}
.table-wrap{overflow:auto;border-radius:10px}
table{width:100%;border-collapse:collapse;font-size:0.95rem}
th,td{padding:10px 12px;text-align:left;border-bottom:1px solid #eef4ff}
th{color:var(--muted);font-weight:700}
.actions{display:flex;gap:4px;flex-wrap:nowrap}
.actions button{padding:6px 8px;border-radius:8px;border:0;cursor:pointer;white-space:nowrap}
.view-btn{background:#eef2ff;color:var(--accent)}
.del-btn{background:#fff;border:1px solid rgba(239,68,68,0.12);color:#ef4444}
.pill{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-weight:700;font-size:0.82rem}
.st-booked{background:#ecfdf3;color:#065f46}
.st-pending{background:#fff7ed;color:#92400e}
.st-fixed{background:#eff6ff;color:#1e3a8a}
.st-paid{background:#ecfdf5;color:#065f46}
.st-cancel{background:#fff1f2;color:#7f1d1d}
.photo{width:100%;height:200px;background:#f5f7fb;border-radius:8px;display:grid;place-items:center;overflow:hidden}
.photo img{max-width:100%;max-height:100%;object-fit:cover}
.field{margin-top:10px}
.label{font-weight:700;color:var(--muted);font-size:0.9rem}
.input,textarea,select{width:100%;padding:10px;border-radius:8px;border:1px solid #e6eef9;margin-top:6px;font-size:14px}
.save{margin-top:12px;background:#0b5cff;color:#fff;padding:10px;border-radius:10px;border:0;cursor:pointer}
.meta{font-size:0.9rem;color:var(--muted);margin-top:8px}
.analytics{display:flex;gap:12px;margin-bottom:12px;flex-wrap:wrap}
.tile{background:#fff;padding:12px;border-radius:10px;box-shadow:0 6px 20px rgba(12,20,40,0.04);flex:1;min-width:160px;cursor:pointer;border:2px solid transparent}
.tile.active{border-color:var(--accent);box-shadow:0 8px 30px rgba(11,92,255,0.06)}
.tile .title{color:var(--muted);font-weight:700}
.tile .num{font-size:1.4rem;font-weight:800}
.filter-bar{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 6px}
.badge{padding:6px 10px;border-radius:999px;background:#f1f5f9;color:#0b1220;font-weight:700}
.err{background:#fff1f2;color:#7f1d1d;padding:8px;border-radius:6px}
.ok{background:#ecfdf5;color:#065f46;padding:8px;border-radius:6px}
.mobile-menu-btn{display:none;background:transparent;border:none;font-size:1.2rem;cursor:pointer;color:var(--muted)}
.mobile-menu{display:none;position:absolute;top:100%;right:12px;background:#fff;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.15);padding:8px;z-index:1000;min-width:140px}
.mobile-menu button{display:block;width:100%;text-align:left;padding:8px 12px;background:none;border:none;cursor:pointer;color:var(--muted);border-radius:4px}
.mobile-menu button:hover{background:#f3f6fb}
.call-history-item{padding:8px 0;border-bottom:1px solid #f1f5f9}
.call-history-item:last-child{border-bottom:none}
.call-history-status{display:inline-block;padding:2px 6px;border-radius:4px;font-size:0.8rem;margin-right:6px}
.call-history-status.connected{background:#ecfdf3;color:#065f46}
.call-history-status.not_connected{background:#fef3c7;color:#92400e}
.call-history-status.voicemail{background:#e0e7ff;color:#3730a3}
.call-history-status.busy{background:#fee2e2;color:#dc2626}

/* Mobile optimized table */
@media (max-width: 900px) {
  .container {padding: 0;}
  .header {border-radius: 0; margin-bottom: 0; box-shadow: none; border-bottom: 1px solid #eef4ff;}
  .grid{grid-template-columns:1fr}
  .header .controls{display:none}
  .mobile-menu-btn{display:block}
  
  table, thead, tbody, th, td, tr { display: block; width: 100%; }
  thead { display: none; }
  tr { margin-bottom: 10px; background: var(--card); border-radius: 8px; padding: 10px; box-shadow: 0 6px 20px rgba(12,20,40,0.04); }
  td { display: flex; align-items: center; justify-content: space-between; padding: 8px 6px; border: 0; }
  td:before { content: attr(data-label); font-weight: 700; color: var(--muted); margin-right: 10px; }
  .actions { display:flex; gap:4px; align-items:center; justify-content: center; margin-top: 8px; }
  .actions button { padding:8px; border-radius:8px; font-size:0; width:36px; height:36px }
  .actions button i { font-size:14px; margin:0 }
  .view-btn { background:transparent; border:1px solid rgba(11,92,255,0.08); color:var(--accent); }
  .del-btn { background:transparent; border:1px solid rgba(239,68,68,0.12); color:#ef4444; }
}

/* Keep compact paddings on small screens even if table collapse doesn't apply */
@media (max-width: 480px) {
  .view-btn, .del-btn { width:32px; height:32px }
}

/* Call notes modal styling */
.modal-content { border-radius: var(--radius); border: none; box-shadow: 0 10px 40px rgba(12,20,40,0.12); }
.modal-header { border-bottom: 1px solid #eef4ff; }
.modal-footer { border-top: 1px solid #eef4ff; }
.overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1040}
.overlay.active{display:block}
/* Sidebar base (desktop: sticky column; mobile: slide-in overlay) */
/* SIDEBAR: slide-in behaviour for mobile */
.sidebar {
  /* default desktop behaviour kept (sticky within grid) */
  transition: transform 0.28s cubic-bezier(.2,.9,.2,1), box-shadow .2s;
  will-change: transform;
}

/* Mobile: transform-based off-canvas */
@media (max-width: 900px) {
  .sidebar {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: 86%;
    max-width: 420px;
    z-index: 1105;
    display: block;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    background: #fff;
    border-radius: 0;
    box-shadow: -12px 0 30px rgba(12,20,40,0.14);
    transform: translateX(100%); /* hidden offscreen by default */
    opacity: 1;
    pointer-events: auto;
  }

  /* shown state */
  .sidebar.active {
    transform: translateX(0) !important;
  }

  /* overlay sits behind sidebar but above page content */
  .overlay {
    position: fixed;
    inset: 0;
    z-index: 1100;
    background: rgba(0,0,0,0.42);
    display: none;
    transition: opacity .18s ease;
    opacity: 0;
    will-change: opacity;
  }
  .overlay.active {
    display: block;
    opacity: 1;
  }
}

/* Desktop: ensure sidebar does not use transforms (keeps sticky column) */
@media (min-width: 901px) {
  .sidebar {
    transform: none !important;
    position: sticky;
    top: 18px;
    box-shadow: 0 8px 30px rgba(12,20,40,0.06);
  }
}
.header {
  position: relative; /* add this */
}

.mobile-menu {
  position: absolute;
  top: 100%;   /* just below the 3-dot button */
  right: 0;    /* align with right edge of header */
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.15);
  padding: 8px;
  z-index: 1000;
  min-width: 160px; /* slightly wider */
}


</style>
</head>
<body>
  <div class="overlay" id="sidebarOverlay"></div>

  <div class="container">
    <div class="header">
      <div class="brand">
        <div class="logo">ITZ</div>
        <div class="hgroup">
          <h1>Admin dashboard</h1>
          <p>Manage clients, bookings & revenue</p>
        </div>
      </div>

      <div class="controls">
        <a class="ghost" href="upload_data.php">Upload Data</a>
        <a class="ghost" href="import.php">Import CSV</a>
        <a class="ghost" href="export.php">Export CSV</a>
        <button class="ghost" id="openCreateBtn">Create client</button>
        <a class="ghost" href="logout.php">Logout</a>
      </div>
      
      <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="bi bi-three-dots-vertical"></i>
      </button>
      <div class="mobile-menu" id="mobileMenu">
        <button data-action="create">Create client</button>
        <button data-action="navigate" data-url="import.php">Import</button>
        <button data-action="navigate" data-url="export.php">Export</button>
      </div>
    </div>

    <!-- messages -->
    <?php foreach ($messages as $m): ?>
      <div style="margin-bottom:12px">
        <div class="<?php echo $m['type']==='error' ? 'err' : 'ok'; ?>"><?php echo e($m['text']); ?></div>
      </div>
    <?php endforeach; ?>

    <!-- analytics tiles (clickable) -->
    <div class="analytics">
      <div class="tile" id="tile_total" data-filter="all" title="Show all clients">
        <div class="title">Total clients</div>
        <div class="num"><?php echo (int)$total_clients; ?></div>
      </div>
      <div class="tile" id="tile_paid" data-filter="paid" title="Show paid clients">
        <div class="title">Paid clients</div>
        <div class="num"><?php echo (int)$paid_clients; ?></div>
      </div>
      
      <div class="tile" id="tile_30d" data-filter="30d" title="Show appointments in last 30 days">
        <div class="title">Appointments (30d)</div>
        <div class="num"><?php echo (int)$appointments_30d; ?></div>
      </div>
      <div class="tile" id="tile_revenue" data-filter="paid" title="Show revenue (paid)">
        <div class="title">Revenue (paid)</div>
        <div class="num">₹ <?php echo number_format($revenue_paid,2); ?></div>
      </div>
    </div>

    <div style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <div class="badge">Total shown: <?php echo count($rows); ?></div>
      <div style="margin-left:auto;display:flex;gap:8px">
        <button id="showBookingsBtn" class="btn">Bookings</button>
        <button id="showRevenueBtn" class="btn" style="background:#06b6d4">Marketing: Revenue</button>
      </div>
    </div>

    <div class="grid">
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <strong>Clients</strong>
        
        </div>

        <div style="margin-top:10px" class="filter-bar">
  <label style="font-weight:700;color:var(--muted)">Quick filter:</label>
  <button type="button" class="ghost filterBtn" data-filter="all">All</button>
  <button type="button" class="ghost filterBtn" data-filter="paid">Paid</button>
  <button type="button" class="ghost filterBtn" data-filter="booked">Booked</button>
  <button type="button" class="ghost filterBtn" data-filter="pending">Pending</button>
  <button type="button" class="ghost filterBtn" data-filter="cancelled">Cancelled</button>
</div>


        <div style="margin-top:10px" class="table-wrap">
          <table id="clientsTable" role="table" aria-label="Client submissions">
            <thead>
              <tr>
                <th style="width:30%">Name</th>
                <th style="width:22%">Business</th>
                <th style="width:18%">Contact</th>
                <th style="width:10%">Status</th>
                <th style="width:20%">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="5" style="padding:18px;color:var(--muted)">No clients yet.</td></tr>
              <?php else: foreach ($rows as $r):
                $s = status_label($r['status'] ?? 'pending');
                // ensure JSON is safe for embedding
                $jsonData = json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
              ?>
              <tr data-client='<?php echo e($jsonData); ?>' data-status="<?php echo e($r['status'] ?? ''); ?>" data-meeting="<?php echo e($r['meeting_slot'] ?? ''); ?>">
                <td data-label="Name"><?php echo e($r['name'] ?: '-'); ?></td>
                <td data-label="Business"><?php echo e($r['business_name'] ?: '-'); ?></td>
                <td data-label="Contact"><?php echo e($r['contact_number'] ?: '-'); ?></td>
                <td data-label="Status"><span class="pill <?php echo e($s['class']); ?>"><?php echo e($s['label']); ?></span></td>
                <td class="actions" data-label="Actions">
                  <!-- Call button -->
                  <button type="button" class="view-btn call-btn" data-action="call" aria-label="Call <?php echo e($r['name']); ?>">
                    <i class="bi bi-telephone-fill" aria-hidden="true"></i>
                    <span class="action-text">Call</span>
                  </button>

                  <!-- History button -->
                  <button type="button" class="view-btn history-btn" data-action="history" aria-label="Call history for <?php echo e($r['name']); ?>">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                    <span class="action-text">History</span>
                  </button>

                  <!-- Details button -->
                  <button class="view-btn details-btn" type="button" data-action="details" aria-label="View details for <?php echo e($r['name']); ?>">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <span class="action-text">Details</span>
                  </button>

                  <form method="post" style="display:inline" onsubmit="return confirm('Delete this record?');">
                    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                    <input type="hidden" name="delete_id" value="<?php echo (int)$r['id']; ?>">
                    <button type="submit" class="del-btn" aria-label="Delete <?php echo e($r['name']); ?>">
                      <i class="bi bi-trash" aria-hidden="true"></i>
                      <span class="action-text">Delete</span>
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      
   
<aside class="sidebar" id="detailsSidebar" role="region" aria-labelledby="detName" aria-hidden="true">
  <div class="sidebar-header" style="display:flex;align-items:center;justify-content:space-between;gap:8px">
    <h3 id="detName" style="margin:0;font-size:1rem">Details</h3>
    <button type="button" id="closeDetailsTop" class="ghost" aria-label="Close details panel" style="padding:6px 8px;">
      <!-- small X icon -->
      <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M3.72 3.72a.75.75 0 0 1 1.06 0L8 6.94l3.22-3.22a.75.75 0 1 1 1.06 1.06L9.06 8l3.22 3.22a.75.75 0 1 1-1.06 1.06L8 9.06l-3.22 3.22a.75.75 0 1 1-1.06-1.06L6.94 8 3.72 4.78a.75.75 0 0 1 0-1.06z"/></svg>
    </button>
  </div>

  <div class="photo" id="detPhoto" style="margin-top:12px">
    <span style="color:var(--muted)">No photo</span>
  </div>

  <form id="detailsForm" method="post" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="action" value="update_client">
    <input type="hidden" name="client_id" id="detId" value="">

    <div class="field">
      <div class="label">Client name</div>
      <div id="detNameText" class="meta"></div>
    </div>

    <div class="field">
      <div class="label">Email</div>
      <div id="detEmail" class="meta"></div>
    </div>

    <div class="field">
      <div class="label">Business</div>
      <div id="detBusiness" class="meta"></div>
    </div>

    <div class="field">
      <div class="label">Contact</div>
      <div id="detContact" class="meta"></div>
    </div>

    <div class="field">
      <div class="label">Meeting slot</div>
      <input class="input" id="detMeeting" name="meeting_slot" placeholder="YYYY-MM-DD HH:MM" />
    </div>

    <div class="field">
      <div class="label">Assign to agent</div>
      <select class="input" id="detAgent" name="agent_id">
        <option value="">— Unassigned —</option>
        <?php foreach ($agents as $a): ?>
          <option value="<?php echo (int)$a['id']; ?>"><?php echo e($a['name'].' <'.$a['email'].'>'); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <div class="label">Package name</div>
      <input class="input" id="package_name" name="package_name" placeholder="Package name">
    </div>

    <div class="field">
      <div class="label">Package price (INR)</div>
      <input class="input" id="package_price" name="package_price" placeholder="0.00">
    </div>

    <div class="field">
      <div class="label">Photo URL</div>
      <input class="input" id="photo_url" name="photo_url" placeholder="https://...">
    </div>

    <div class="field">
      <div class="label">Status</div>
      <select class="input" id="status" name="status">
        <option value="pending">Pending</option>
        <option value="booked">Booked</option>
        <option value="appointment_fixed">Appointment fixed</option>
        <option value="paid">Paid</option>
        <option value="cancelled">Cancelled</option>
      </select>
    </div>

    <div class="field">
      <div class="label">Notes / Description</div>
      <textarea class="input" id="notes" name="notes" rows="4" placeholder="Client notes"></textarea>
    </div>

    <div class="field">
      <div class="label">Recent Calls</div>
      <div id="callHistoryCompact" class="meta" style="min-height:36px;color:var(--muted)">—</div>
    </div>

    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
      <button type="button" class="ghost" id="closeDetails" aria-label="Close details">Close</button>
      <button type="submit" class="save">Save changes</button>
    </div>
  </form>

  <div style="margin-top:12px" class="revenue">
    <div>Total revenue (paid)</div>
    <div class="amount">₹ <?php echo number_format($revenue_paid,2); ?></div>
  </div>
</aside>
    </div>
  </div>

  <!-- Create client modal -->
  <div id="createModal" class="modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Create client</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post">
          <div class="modal-body">
            <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="create_client">
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Full name</label>
                <input type="text" name="name" required class="form-control">
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" required class="form-control">
              </div>
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Business name</label>
                <input type="text" name="business_name" class="form-control">
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Contact</label>
                <input type="text" name="contact" class="form-control">
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label">Meeting slot (optional)</label>
              <input type="datetime-local" name="meeting_slot" class="form-control">
            </div>
            <div class="mb-3">
              <label class="form-label">Business address</label>
              <textarea name="business_address" class="form-control" rows="3"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Create</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Call Modal -->
  <div class="modal fade" id="callModal" tabindex="-1" aria-labelledby="callModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="callModalLabel">Call Client</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form id="callForm" method="post" novalidate>
          <div class="modal-body">
            <input type="hidden" name="csrf" id="call_csrf" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="client_id" id="call_client_id" value="">
            
            <div class="mb-3">
              <label class="form-label">Client</label>
              <div id="call_client_name" class="fw-bold" style="color:var(--accent);"></div>
              <div id="call_client_phone" class="small"></div>
              <div class="text-muted small mt-1">Click the number above to start the call</div>
            </div>

            <div class="mb-3">
              <label for="call_status" class="form-label">Call Status</label>
              <select id="call_status" name="call_status" class="form-select" required>
                <option value="connected">Connected</option>
                <option value="not_connected">Not connected</option>
                <option value="voicemail">Voicemail</option>
                <option value="busy">Busy</option>
              </select>
            </div>

            <div class="mb-3">
              <label for="call_notes" class="form-label">Notes</label>
              <textarea id="call_notes" name="notes" class="form-control" rows="4" placeholder="Notes about the call..."></textarea>
            </div>

            <div class="mb-3">
              <label for="followup_date" class="form-label">Follow-up Date (optional)</label>
              <input type="datetime-local" id="followup_date" name="followup_date" class="form-control">
            </div>

            <div id="call_feedback" class="small" style="display:none;margin-top:6px;"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save & Close</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- History Modal -->
  <div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="historyModalLabel">Call History</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="historyContent"></div>
          <div class="text-center mt-3">
            <button id="loadMoreHistory" class="btn btn-outline-primary" style="display:none">Load More</button>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Ensure this runs inside your existing IIFE after DOM elements exist
(function(){
  const sidebar = document.getElementById('detailsSidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const closeBtn = document.getElementById('closeDetails');
  const closeBtnTop = document.getElementById('closeDetailsTop');

  function openSidebar() {
    if (!sidebar) return;
    sidebar.classList.add('active');
    sidebar.setAttribute('aria-hidden','false');
    overlay.classList.add('active');
    overlay.setAttribute('aria-hidden','false');
    // focus first focusable element for accessibility (client name)
    setTimeout(()=> {
      const focusable = sidebar.querySelector('input,button,textarea,select,a');
      if (focusable) focusable.focus();
    }, 200);
  }

  function closeSidebar() {
    if (!sidebar) return;
    sidebar.classList.remove('active');
    sidebar.setAttribute('aria-hidden','true');
    overlay.classList.remove('active');
    overlay.setAttribute('aria-hidden','true');
  }

  // top close & bottom close
  closeBtn?.addEventListener('click', closeSidebar);
  closeBtnTop?.addEventListener('click', closeSidebar);

  // overlay click closes
  overlay?.addEventListener('click', closeSidebar);

  // Escape key closes
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeSidebar();
  });

  // When showing details in your existing click handler, call openSidebar()
  // e.g. after populating details:
  // openSidebar();
  // (Your code that handles the Details button should call openSidebar instead of directly toggling classes)
})();

(function(){
  // Initialize modals
  const createModal = new bootstrap.Modal(document.getElementById('createModal'));
  const callModal = new bootstrap.Modal(document.getElementById('callModal'));
  const historyModal = new bootstrap.Modal(document.getElementById('historyModal'));
  
  // helpers
  const rows = Array.from(document.querySelectorAll('#clientsTable tbody tr'));
  const tiles = Array.from(document.querySelectorAll('.tile'));
  const filterBtns = Array.from(document.querySelectorAll('.filterBtn'));
  const sidebar = document.getElementById('detailsSidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const mobileMenu = document.getElementById('mobileMenu');
  let currentClientId = null;
  let historyOffset = 0;
  const historyLimit = 10;

  function clearActiveTiles(){ tiles.forEach(t=>t.classList.remove('active')); }
  function showOnlyStatuses(filter){
    const now = new Date();
    rows.forEach(r=>{
      r.style.display = '';
      if (filter === 'all' || !filter) { r.style.display='table-row'; return; }
      if (filter === '30d') {
        const ms = r.dataset.meeting || '';
        if (!ms) { r.style.display='none'; return; }
        const d = new Date(ms.replace(' ', 'T'));
        if (isNaN(d.getTime())) { r.style.display='none'; return; }
        const diff = (now - d) / (1000*60*60*24);
        r.style.display = diff <= 30 ? 'table-row' : 'none';
        return;
      }
      const st = r.dataset.status || '';
      r.style.display = (st === filter) ? 'table-row' : 'none';
    });
  }

  // Mobile menu toggle
  document.getElementById('mobileMenuBtn').addEventListener('click', function(e) {
    e.stopPropagation();
    mobileMenu.style.display = mobileMenu.style.display === 'block' ? 'none' : 'block';
  });

  // Mobile menu actions
  document.querySelectorAll('#mobileMenu button').forEach(btn => {
    btn.addEventListener('click', function() {
      const action = this.dataset.action;
      mobileMenu.style.display = 'none';
      
      if (action === 'filter') {
        showOnlyStatuses(this.dataset.filter);
      } else if (action === 'create') {
        createModal.show();
      } else if (action === 'navigate') {
        window.location.href = this.dataset.url;
      }
    });
  });

  // Close mobile menu when clicking outside
  document.addEventListener('click', function() {
    mobileMenu.style.display = 'none';
  });

  // tile click -> filter
  tiles.forEach(t=>{
    t.addEventListener('click', ()=>{
      const f = t.dataset.filter || 'all';
      const isActive = t.classList.contains('active');
      clearActiveTiles();
      if (!isActive) {
        t.classList.add('active');
        showOnlyStatuses(f);
      } else {
        showOnlyStatuses('all');
      }
    });
  });

  // filter buttons
  filterBtns.forEach(b=>{
    b.addEventListener('click', ()=>{
      filterBtns.forEach(x=>x.classList.remove('active'));
      b.classList.add('active');
      const f = b.dataset.filter || 'all';
      clearActiveTiles();
      showOnlyStatuses(f);
    });
  });

  // bookings & revenue quick buttons
  document.getElementById('showBookingsBtn')?.addEventListener('click', ()=> {
    filterBtns.forEach(x=>x.classList.remove('active'));
    clearActiveTiles();
    showOnlyStatuses('booked');
  });

  document.getElementById('showRevenueBtn')?.addEventListener('click', ()=> {
    filterBtns.forEach(x=>x.classList.remove('active'));
    clearActiveTiles();
    showOnlyStatuses('paid');
  });

  // Details sidebar show
  const table = document.getElementById('clientsTable');
  table.addEventListener('click', function(e){
    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    
    const action = btn.dataset.action;
    const tr = btn.closest('tr');
    if (!tr) return;
    
    try {
      const json = tr.getAttribute('data-client') || 'null';
      const data = JSON.parse(json);
      currentClientId = data.id;
      
      if (action === 'details') {
        document.getElementById('detId').value = data.id || '';
        document.getElementById('detName').textContent = data.name || 'Details';
        document.getElementById('detNameText').textContent = data.name || '-';
        document.getElementById('detEmail').textContent = data.email || '-';
        document.getElementById('detBusiness').textContent = data.business_name || '-';
        document.getElementById('detContact').textContent = data.contact_number || '-';
        document.getElementById('detMeeting').value = data.meeting_slot || '';
        document.getElementById('package_name').value = data.package_name || '';
        document.getElementById('package_price').value = data.package_price || '';
        document.getElementById('photo_url').value = data.photo_url || '';
        document.getElementById('status').value = data.status || 'pending';
        document.getElementById('notes').value = data.description || '';
        try { document.getElementById('detAgent').value = data.agent_id || ''; } catch(e){}
        const photoWrap = document.getElementById('detPhoto'); photoWrap.innerHTML = '';
        if (data.photo_url) {
          const img = document.createElement('img'); img.src = data.photo_url; img.alt = data.name || 'photo'; photoWrap.appendChild(img);
        } else { photoWrap.textContent = 'No photo'; }
        
        // Load compact call history
        loadCompactCallHistory(data.id);
        
        // Show sidebar
        sidebar.classList.add('active');
        overlay.classList.add('active');
      } else if (action === 'call') {
        document.getElementById('call_client_id').value = data.id || '';
        document.getElementById('call_client_name').textContent = data.name || '—';
        
        const phoneElem = document.getElementById('call_client_phone');
        const phone = data.contact_number || '';
        if (phone) {
          phoneElem.innerHTML = `<a href="tel:${phone.replace(/\s+/g, '')}">${phone}</a>`;
        } else {
          phoneElem.textContent = 'No phone number';
        }
        
        // Reset form
        document.getElementById('call_status').value = 'connected';
        document.getElementById('call_notes').value = '';
        document.getElementById('followup_date').value = '';
        
        callModal.show();
      } else if (action === 'history') {
        loadCallHistory(data.id, true);
        historyModal.show();
      }
    } catch (err) {
      alert('Failed to parse client data: ' + err.message);
    }
  });

  // Close sidebar
  document.getElementById('closeDetails').addEventListener('click', closeSidebar);
  overlay.addEventListener('click', closeSidebar);
  
  function closeSidebar() {
    sidebar.classList.remove('active');
    overlay.classList.remove('active');
  }

  document.addEventListener('keydown', function(e){ 
    if (e.key === 'Escape') {
      closeSidebar();
    }
  });

  // Create modal
  document.getElementById('openCreateBtn').addEventListener('click', ()=> {
    createModal.show();
  });

  // Call form submission
  const callForm = document.getElementById('callForm');
  callForm.addEventListener('submit', function (ev) {
    ev.preventDefault();
    const btn = callForm.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    const formData = new FormData(callForm);
    fetch('save_call.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    }).then(r => r.json())
      .then(json => {
        btn.disabled = false;
        btn.textContent = 'Save & Close';
        const fb = document.getElementById('call_feedback');
        if (json && json.success) {
          fb.style.display = 'block';
          fb.style.color = '#065f46';
          fb.textContent = 'Call saved successfully.';
          
          // Close modal after short delay
          setTimeout(() => {
            callModal.hide();
            fb.style.display = 'none';
            
            // Refresh history if needed
            if (currentClientId) {
              loadCompactCallHistory(currentClientId);
            }
          }, 1000);
        } else {
          fb.style.display = 'block';
          fb.style.color = '#7f1d1d';
          fb.textContent = json && json.message ? json.message : 'Failed to save. Try again.';
        }
      }).catch(err => {
        btn.disabled = false;
        btn.textContent = 'Save & Close';
        const fb = document.getElementById('call_feedback');
        fb.style.display = 'block';
        fb.style.color = '#7f1d1d';
        fb.textContent = 'Network error — could not save.';
      });
  });
  

  // Load call history
  function loadCallHistory(clientId, reset = false) {
    if (reset) historyOffset = 0;
    
    fetch(`get_call_history.php?client_id=${clientId}&offset=${historyOffset}&limit=${historyLimit}`)
      .then(r => r.json())
      .then(data => {
        const historyContent = document.getElementById('historyContent');
        
        if (reset || historyOffset === 0) {
          historyContent.innerHTML = '';
        }
        
        if (data.length === 0 && historyOffset === 0) {
          historyContent.innerHTML = '<p class="text-center text-muted">No call history found</p>';
          document.getElementById('loadMoreHistory').style.display = 'none';
          return;
        }
        
        data.forEach(call => {
          const callDate = new Date(call.created_at).toLocaleString();
          const statusClass = `call-history-status ${call.call_status}`;
          
          const callElement = document.createElement('div');
          callElement.className = 'call-history-item';
          callElement.innerHTML = `
            <div class="d-flex justify-content-between">
              <div>
                <span class="${statusClass}">${call.call_status}</span>
                <strong>${call.agent_name || 'Admin'}</strong>
              </div>
              <div class="text-muted small">${callDate}</div>
            </div>
            <div class="mt-1">${call.notes || '<em>No notes</em>'}</div>
          `;
          
          historyContent.appendChild(callElement);
        });
        
        // Show load more button if we might have more results
        document.getElementById('loadMoreHistory').style.display = data.length === historyLimit ? 'block' : 'none';
        historyOffset += data.length;
      })
      .catch(err => {
        console.error('Error loading call history:', err);
        document.getElementById('historyContent').innerHTML = '<p class="text-center text-danger">Error loading call history</p>';
      });
  }

  // Load compact call history for sidebar
  function loadCompactCallHistory(clientId) {
    fetch(`get_call_history.php?client_id=${clientId}&limit=3`)
      .then(r => r.json())
      .then(data => {
        const historyContainer = document.getElementById('callHistoryCompact');
        
        if (data.length === 0) {
          historyContainer.innerHTML = '<p class="text-muted small">No call history</p>';
          return;
        }
        
        let html = '';
        data.forEach(call => {
          const callDate = new Date(call.created_at).toLocaleDateString();
          const statusClass = `call-history-status ${call.call_status}`;
          
          html += `
            <div class="call-history-item">
              <div class="d-flex justify-content-between">
                <span class="${statusClass}">${call.call_status}</span>
                <span class="text-muted small">${callDate}</span>
              </div>
              <div class="small mt-1 text-truncate">${call.notes || ''}</div>
            </div>
          `;
        });
        
        historyContainer.innerHTML = html;
      })
      .catch(err => {
        console.error('Error loading compact call history:', err);
        document.getElementById('callHistoryCompact').innerHTML = '<p class="text-muted small">Error loading history</p>';
      });
  }

  // Load more history
  document.getElementById('loadMoreHistory').addEventListener('click', function() {
    if (currentClientId) {
      loadCallHistory(currentClientId);
    }
  });
})();
</script>

</body>
</html>