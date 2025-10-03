<?php
// admin/dashboard.php
// Single-file admin dashboard — create / update / delete / view / analytics-filter
// Requires: ../helpers.php and ../db.php (which must set $conn (mysqli), and helpers must provide
// start_session_30d(), generate_csrf(), verify_csrf(), is_admin or similar auth helpers)

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

// helper escape
function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// normalize meeting_slot -> 'Y-m-d H:i:s' or null
function normalize_meeting_slot($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    // if HTML datetime-local is used it might be "YYYY-MM-DDTHH:MM" or with seconds
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

/* --- Handle POST actions: create_client, update_client (existing), delete --- */
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

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Dashboard — ITZ</title>
<style>
/* (CSS unchanged — copy your existing styles or keep this block) */
:root{--accent:#0b5cff;--muted:#6b7280;--bg:#f3f6fb;--card:#fff;--radius:12px}
*{box-sizing:border-box}
body{margin:0;font-family:Inter,system-ui,Arial;background:var(--bg);color:#0b1220;padding:18px}
.container{max-width:1200px;margin:0 auto}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}
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
.actions button{margin-right:6px;padding:6px 8px;border-radius:8px;border:0;cursor:pointer}
.view-btn{background:#eef2ff;color:var(--accent)}
.del-btn{background:#fff;border:1px solid rgba(239,68,68,0.12);color:#ef4444}
.pill{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-weight:700;font-size:0.82rem}
.st-booked{background:#ecfdf3;color:#065f46}
.st-pending{background:#fff7ed;color:#92400e}
.st-fixed{background:#eff6ff;color:#1e3a8a}
.st-paid{background:#ecfdf5;color:#065f46}
.st-cancel{background:#fff1f2;color:#7f1d1d}
.sidebar{position:sticky;top:18px;background:#fff;border-radius:12px;padding:14px;box-shadow:0 8px 30px rgba(12,20,40,0.06);height:fit-content;display:none}
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
.mobile-nav{display:none;position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid #eef4ff;padding:8px 12px;box-shadow:0 -8px 30px rgba(11,15,35,0.06);z-index:1400;display:flex;gap:8px;justify-content:space-around}
.mobile-nav button{background:transparent;border:0;padding:8px 12px;border-radius:8px;font-weight:700;color:var(--muted)}
.mobile-nav button.active{background:var(--accent);color:#fff}
@media(max-width:900px){ .grid{grid-template-columns:1fr} .sidebar{position:relative;display:block;margin-top:16px} .photo{height:160px} .mobile-nav{display:flex} .header .controls{display:none} }
.notice { background:#fff3cd; border:1px solid #ffeeba; padding:10px; border-radius:8px; color:#856404; margin-bottom:12px; }
</style>
</head>
<body>
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
      <div class="tile" id="tile_pending" data-filter="pending" title="Show pending">
        <div class="title">Pending</div>
        <div class="num"><?php echo (int)$pending_appointments; ?></div>
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
          <strong>Clients (recent)</strong>
          <span class="meta">Sorted by created date</span>
        </div>

        <div style="margin-top:10px" class="filter-bar">
          <label style="font-weight:700;color:var(--muted)">Quick filter:</label>
          <button class="ghost filterBtn" data-filter="all">All</button>
          <button class="ghost filterBtn" data-filter="paid">Paid</button>
          <button class="ghost filterBtn" data-filter="booked">Booked</button>
          <button class="ghost filterBtn" data-filter="pending">Pending</button>
          <button class="ghost filterBtn" data-filter="cancelled">Cancelled</button>
        </div>

        <div style="margin-top:10px" class="table-wrap">
          <table id="clientsTable" role="table" aria-label="Client submissions">
            <thead>
              <tr>
                <th style="width:30%">Name</th>
                <th style="width:22%">Email</th>
                <th style="width:18%">Business</th>
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
                <td><?php echo e($r['name'] ?: '-'); ?></td>
                <td><?php echo e($r['email'] ?: '-'); ?></td>
                <td><?php echo e($r['business_name'] ?: '-'); ?></td>
                <td><span class="pill <?php echo e($s['class']); ?>"><?php echo e($s['label']); ?></span></td>
                <td class="actions">
                  <button class="view-btn" type="button" data-action="details">Details</button>
                  <form method="post" style="display:inline" onsubmit="return confirm('Delete this record?');">
                    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                    <input type="hidden" name="delete_id" value="<?php echo (int)$r['id']; ?>">
                    <button type="submit" class="del-btn">Delete</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

      </div>

      <!-- sidebar -->
      <aside class="sidebar" id="detailsSidebar" aria-hidden="true">
        <h3 id="detName">Details</h3>
        <div class="photo" id="detPhoto"><span style="color:var(--muted)">No photo</span></div>

        <form id="detailsForm" method="post">
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

          <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
            <button type="button" class="ghost" id="closeDetails">Close</button>
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

  <!-- mobile nav -->
  <div class="mobile-nav" id="mobileNav" aria-hidden="true">
    <button id="mbAll" class="active">All</button>
    <button id="mbBookings">Bookings</button>
    <button id="mbRevenue">Revenue</button>
    <button id="mbCreate">Create</button>
  </div>

  <!-- Create client modal -->
  <div id="createModal" style="display:none;position:fixed;inset:0;align-items:center;justify-content:center;background:rgba(0,0,0,0.45);z-index:9999;">
    <div style="margin:auto;max-width:720px;background:#fff;padding:18px;border-radius:12px;">
      <h3 style="margin-top:0">Create client</h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="action" value="create_client">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <label>Full name<input name="name" required class="input"></label>
          <label>Email<input name="email" type="email" required class="input"></label>
          <label>Business name<input name="business_name" class="input"></label>
          <label>Contact<input name="contact" class="input"></label>
        </div>
        <label>Meeting slot (optional)<input name="meeting_slot" type="datetime-local" class="input"></label>
        <label>Business address<textarea name="business_address" class="input" rows="3"></textarea></label>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
          <button type="button" id="createCancel" class="ghost">Cancel</button>
          <button type="submit" class="btn">Create</button>
        </div>
      </form>
    </div>
  </div>

<script>
(function(){
  // helpers
  const rows = Array.from(document.querySelectorAll('#clientsTable tbody tr'));
  const tiles = Array.from(document.querySelectorAll('.tile'));
  const filterBtns = Array.from(document.querySelectorAll('.filterBtn'));

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
    Array.from(document.querySelectorAll('#clientsTable tbody tr')).forEach(r=>{
      const st = r.dataset.status || '';
      r.style.display = (st==='booked' || st==='appointment_fixed') ? 'table-row' : 'none';
    });
  });

  document.getElementById('showRevenueBtn')?.addEventListener('click', ()=> {
    filterBtns.forEach(x=>x.classList.remove('active'));
    clearActiveTiles();
    showOnlyStatuses('paid');
  });

  // Details sidebar show
  const table = document.getElementById('clientsTable');
  const sidebar = document.getElementById('detailsSidebar');
  table.addEventListener('click', function(e){
    const btn = e.target.closest('button[data-action="details"], button.view-btn');
    if (!btn) return;
    const tr = btn.closest('tr');
    if (!tr) return;
    try {
      const json = tr.getAttribute('data-client') || 'null';
      const data = JSON.parse(json);
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
      sidebar.style.display = 'block';
      sidebar.setAttribute('aria-hidden','false');
      sidebar.scrollIntoView({behavior:'smooth'});
    } catch (err) {
      alert('Failed to parse client data: ' + err.message);
    }
  });

  document.getElementById('closeDetails')?.addEventListener('click', ()=> {
    const sb = document.getElementById('detailsSidebar'); sb.style.display='none'; sb.setAttribute('aria-hidden','true');
  });

  // Create modal
  const createModal = document.getElementById('createModal');
  document.getElementById('openCreateBtn')?.addEventListener('click', ()=> {
    createModal.style.display = 'flex'; document.body.style.overflow='hidden';
  });
  document.getElementById('createCancel')?.addEventListener('click', ()=> {
    createModal.style.display = 'none'; document.body.style.overflow='';
  });
  document.getElementById('mbCreate')?.addEventListener('click', ()=> {
    createModal.style.display = 'flex'; document.body.style.overflow='hidden';
  });

  // mobile nav show/hide
  function updateMobileNav(){ const el = document.getElementById('mobileNav'); if (!el) return; if (window.matchMedia('(max-width:900px)').matches) el.style.display='flex'; else el.style.display='none'; }
  updateMobileNav(); window.addEventListener('resize', updateMobileNav);

  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') {
    document.getElementById('createModal').style.display='none'; document.body.style.overflow=''; document.getElementById('detailsSidebar').style.display='none';
  }});
})();
</script>
</body>
</html>
