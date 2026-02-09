<?php
session_start();
// ---------- Admin guard ----------
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {

    // If it's ajax, return 401 HTML; otherwise redirect.
    if (isset($_GET['ajax'])) {
        http_response_code(401);
        echo '<div class="p-4"><div class="alert alert-danger mb-0">Unauthorized</div></div>';
        exit;
    }

    echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
    exit;
}

// ---------- DB ----------
$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) {
    if (isset($_GET['ajax'])) {
        http_response_code(500);
        echo '<div class="p-4"><div class="alert alert-danger mb-0">Database connection failed.</div></div>';
        exit;
    }
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * Fix paths for uploads when page is inside /admin.
 * If DB stores "uploads/xxx.jpg", render "../uploads/xxx.jpg" from /admin.
 * If path already starts with http(s) or starts with "../" or "/", keep as-is.
 */
function asset_path(string $path): string {
    $p = trim($path);
    if ($p === '') return '';
    if (preg_match('#^(https?://)#i', $p)) return $p;
    if (str_starts_with($p, '../') || str_starts_with($p, '/')) return $p;
    if (str_starts_with($p, 'uploads/')) return '../' . $p; // from /admin
    return $p;
}

/**
 * Ensures we have admin role/phase correctly.
 * Uses session values if present; otherwise fetches from DB.
 */
function get_admin_phase_role(mysqli $conn): array {
    $admin_id = (int)($_SESSION['admin_id'] ?? 0);
    $role  = (string)($_SESSION['admin_role'] ?? '');
    $phase = (string)($_SESSION['admin_phase'] ?? '');

    if ($admin_id > 0 && ($role === '' || $phase === '')) {
        $stmt = $conn->prepare("SELECT role, phase FROM admins WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $admin_id);
        $stmt->execute();
        $a = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $role  = $a['role'] ?? $role;
        $phase = $a['phase'] ?? $phase;
    }

    return [$role, $phase];
}

/**
 * Renders the "Facebook-like" profile UI (map cover + avatar + cards)
 * Returns an HTML string used in the bootstrap modal.
 */
function render_homeowner_profile_html(mysqli $conn, string $admin_role, string $admin_phase, int $id): string {

    // Fetch homeowner (respect role/phase)
    if ($admin_role === 'superadmin') {
        $stmt = $conn->prepare("SELECT * FROM homeowners WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $id);
    } else {
        $stmt = $conn->prepare("SELECT * FROM homeowners WHERE id=? AND phase=? LIMIT 1");
        $stmt->bind_param("is", $id, $admin_phase);
    }
    $stmt->execute();
    $homeowner = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$homeowner) {
        return '<div class="p-4"><div class="alert alert-danger mb-0">Homeowner not found or not allowed.</div></div>';
    }

    // Members
    $stmt = $conn->prepare("SELECT * FROM household_members WHERE homeowner_id=? ORDER BY id ASC");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $members = $stmt->get_result();
    $stmt->close();

    $lat = (string)($homeowner['latitude'] ?? '');
    $lng = (string)($homeowner['longitude'] ?? '');

    $validIdRaw = (string)($homeowner['valid_id_path'] ?? '');
    $proofRaw   = (string)($homeowner['proof_of_billing_path'] ?? '');
    $validId = asset_path($validIdRaw);
    $proof   = asset_path($proofRaw);

    $fn = trim((string)($homeowner['first_name'] ?? ''));
    $ln = trim((string)($homeowner['last_name'] ?? ''));
    $initials = strtoupper(($fn ? $fn[0] : 'H') . ($ln ? $ln[0] : 'O'));

    $status = strtolower((string)($homeowner['status'] ?? 'pending'));
    $badgeClass = 'badge-soft-warning';
    if ($status === 'approved') $badgeClass = 'badge-soft-success';
    if ($status === 'rejected') $badgeClass = 'badge-soft-danger';

    ob_start();
    ?>
    <style>
      :root{--brand:#077f46;--ink:#0f172a;--muted:#64748b;--bg:#f4f6fb;--card:#fff;--border:#e5e7eb;}
      .profile-shell{border-radius:16px;overflow:hidden;box-shadow:0 12px 32px rgba(15,23,42,.08);background:var(--card);}
      .cover{position:relative;height:320px;background:#e9eef6;}
      #coverMap{height:100%;width:100%;}
      .leaflet-container,.leaflet-pane,.leaflet-top,.leaflet-bottom{z-index:1!important;}
      .cover-overlay{position:absolute;inset:0;background:linear-gradient(to bottom,rgba(0,0,0,.15),rgba(0,0,0,.45));z-index:2;pointer-events:none;}
      .profile-header{position:relative;padding:0 24px 18px;}
      .avatar{width:130px;height:130px;border-radius:50%;background:linear-gradient(135deg,var(--brand),#0bbf6a);
        border:6px solid #fff;display:grid;place-items:center;color:#fff;font-weight:800;font-size:40px;
        position:absolute;top:-65px;left:24px;z-index:4;box-shadow:0 10px 25px rgba(15,23,42,.18);}
      .profile-meta{padding-top:16px;padding-left:160px;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;}
      .name{font-size:26px;font-weight:800;margin:0;line-height:1.15;}
      .subline{margin:6px 0 0;color:var(--muted);font-weight:600;}
      .pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:#f1f5f9;color:#0f172a;
        font-weight:700;font-size:13px;border:1px solid var(--border);}
      .badge-soft-warning{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
      .badge-soft-success{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;}
      .badge-soft-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
      .cardx{background:var(--card);border:1px solid var(--border);border-radius:16px;box-shadow:0 10px 24px rgba(15,23,42,.06);}
      .cardx-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;}
      .cardx-title{margin:0;font-weight:800;font-size:16px;}
      .cardx-body{padding:16px 18px;}
      .kv{display:grid;grid-template-columns:160px 1fr;gap:10px 14px;}
      .k{color:var(--muted);font-weight:700;font-size:13px;}
      .v{font-weight:700;color:var(--ink);}
      .doc-card{border:1px solid var(--border);border-radius:14px;overflow:hidden;background:#fff;}
      .doc-thumb{width:100%;height:180px;object-fit:cover;background:#f1f5f9;display:block;}
      .doc-meta{padding:12px 12px 14px;}
      .doc-title{font-weight:800;margin:0 0 8px;}
      .table thead th{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;}
      @media (max-width:768px){
        .cover{height:260px;}
        .avatar{left:50%;transform:translateX(-50%);}
        .profile-meta{padding-left:0;padding-top:84px;text-align:center;justify-content:center;}
      }
    </style>

    <div class="p-3 p-lg-4" style="background:var(--bg);">
      <div class="profile-shell mb-4">
        <div class="cover">
          <?php if ($lat !== '' && $lng !== ''): ?>
            <div id="coverMap" data-lat="<?= esc($lat) ?>" data-lng="<?= esc($lng) ?>"></div>
            <div class="cover-overlay"></div>
          <?php else: ?>
            <div class="h-100 w-100 d-flex align-items-center justify-content-center bg-light">
              <div class="text-muted fw-semibold">No location saved for this homeowner.</div>
            </div>
          <?php endif; ?>
        </div>

        <div class="profile-header">
          <div class="avatar"><?= esc($initials) ?></div>

          <div class="profile-meta">
            <div>
              <p class="name"><?= esc(trim(($homeowner['first_name'] ?? '').' '.($homeowner['middle_name'] ?? '').' '.($homeowner['last_name'] ?? ''))) ?></p>
              <p class="subline">
                <?= esc($homeowner['phase'] ?? '') ?> • <?= esc($homeowner['house_lot_number'] ?? '') ?> •
                <span class="pill <?= esc($badgeClass) ?>"><?= esc(ucfirst($status)) ?></span>
              </p>
            </div>

            <div class="d-flex gap-2 flex-wrap">
              <span class="pill">📧 <?= esc($homeowner['email'] ?? '') ?></span>
              <span class="pill">📞 <?= esc($homeowner['contact_number'] ?? '') ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-lg-5">
          <div class="cardx mb-4">
            <div class="cardx-head">
              <h5 class="cardx-title">Homeowner Information</h5>
              <span class="pill">🕒 <small><?= esc($homeowner['created_at'] ?? '') ?></small></span>
            </div>
            <div class="cardx-body">
              <div class="kv">
                <div class="k">Full Name</div>
                <div class="v"><?= esc(trim(($homeowner['first_name'] ?? '').' '.($homeowner['middle_name'] ?? '').' '.($homeowner['last_name'] ?? ''))) ?></div>

                <div class="k">Email</div>
                <div class="v"><?= esc($homeowner['email'] ?? '') ?></div>

                <div class="k">Contact</div>
                <div class="v"><?= esc($homeowner['contact_number'] ?? '') ?></div>

                <div class="k">Phase</div>
                <div class="v"><?= esc($homeowner['phase'] ?? '') ?></div>

                <div class="k">House / Lot</div>
                <div class="v"><?= esc($homeowner['house_lot_number'] ?? '') ?></div>

                <div class="k">Status</div>
                <div class="v text-capitalize"><?= esc($status) ?></div>
              </div>
            </div>
          </div>

          <div class="cardx">
            <div class="cardx-head"><h5 class="cardx-title">Uploaded Documents</h5></div>
            <div class="cardx-body">
              <div class="row g-3">

                <div class="col-12">
                  <div class="doc-card">
                    <?php if ($validId): ?>
                      <img class="doc-thumb" src="<?= esc($validId) ?>" alt="Valid ID" onerror="this.style.display='none'">
                    <?php else: ?>
                      <div class="doc-thumb d-flex align-items-center justify-content-center text-muted fw-semibold">
                        No Valid ID uploaded
                      </div>
                    <?php endif; ?>
                    <div class="doc-meta">
                      <p class="doc-title mb-1">Valid ID</p>
                      <?php if ($validId): ?>
                        <a class="btn btn-sm btn-success" target="_blank" href="<?= esc($validId) ?>">Open File</a>
                      <?php else: ?>
                        <span class="text-muted fw-semibold">—</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="col-12">
                  <div class="doc-card">
                    <?php if ($proof): ?>
                      <img class="doc-thumb" src="<?= esc($proof) ?>" alt="Proof of Billing" onerror="this.style.display='none'">
                    <?php else: ?>
                      <div class="doc-thumb d-flex align-items-center justify-content-center text-muted fw-semibold">
                        No Proof of Billing uploaded
                      </div>
                    <?php endif; ?>
                    <div class="doc-meta">
                      <p class="doc-title mb-1">Proof of Billing</p>
                      <?php if ($proof): ?>
                        <a class="btn btn-sm btn-success" target="_blank" href="<?= esc($proof) ?>">Open File</a>
                      <?php else: ?>
                        <span class="text-muted fw-semibold">—</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="cardx">
            <div class="cardx-head">
              <h5 class="cardx-title">Household Members</h5>
              <span class="pill">👥 <?= (int)$members->num_rows ?> member(s)</span>
            </div>
            <div class="cardx-body">
              <?php if ($members->num_rows > 0): ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th style="width:60px;">#</th>
                        <th>Full Name</th>
                        <th style="width:180px;">Relation</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php $i=1; while($m = $members->fetch_assoc()): ?>
                        <tr>
                          <td class="fw-bold"><?= $i++ ?></td>
                          <td class="fw-semibold">
                            <?= esc($m['first_name'] ?? '') ?>
                            <?= esc($m['middle_name'] ?? '') ?>
                            <?= esc($m['last_name'] ?? '') ?>
                          </td>
                          <td><span class="pill"><?= esc($m['relation'] ?? '') ?></span></td>
                        </tr>
                      <?php endwhile; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <div class="text-muted fw-semibold">No household members found.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </div>
    </div>
    <?php
    return ob_get_clean();
}

// ============================================================
// AJAX ENDPOINT
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'homeowner_profile') {

    $idAjax = (int)($_GET['id'] ?? 0);
    if ($idAjax <= 0) {
        http_response_code(400);
        echo '<div class="p-4"><div class="alert alert-warning mb-0">Invalid ID</div></div>';
        exit;
    }

    [$admin_role, $admin_phase] = get_admin_phase_role($conn);

    echo render_homeowner_profile_html($conn, (string)$admin_role, (string)$admin_phase, $idAjax);
    exit;
}

// ============================================================
// If opened directly (not ajax), redirect to main HO page
// ============================================================
header("Location: ho_approval.php");
exit;
