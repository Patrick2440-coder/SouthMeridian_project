<?php
session_start();

// ===================== SESSION CHECK =====================
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','superadmin'])) {
    echo "<script>alert('Access denied.'); window.location='index.php';</script>";
    exit();
}

// ===================== DB CONNECTION =====================
$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ===================== GET ID =====================
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) die("Invalid Homeowner ID.");

// ===================== GET ADMIN INFO =====================
$admin_id = $_SESSION['user_id'];
$sqlAdmin = $conn->prepare("SELECT phase, role FROM admins WHERE id=?");
$sqlAdmin->bind_param("i", $admin_id);
$sqlAdmin->execute();
$admin = $sqlAdmin->get_result()->fetch_assoc();
$admin_phase = $admin['phase'] ?? '';
$admin_role  = $admin['role'] ?? '';

// ===================== FETCH HOMEOWNER =====================
if ($admin_role === 'superadmin') {
    $stmt = $conn->prepare("SELECT * FROM homeowners WHERE id=?");
    $stmt->bind_param("i", $id);
} else {
    $stmt = $conn->prepare("SELECT * FROM homeowners WHERE id=? AND phase=?");
    $stmt->bind_param("is", $id, $admin_phase);
}
$stmt->execute();
$homeowner = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$homeowner) die("Homeowner not found or not allowed.");

// ===================== FETCH HOUSEHOLD MEMBERS =====================
$stmt = $conn->prepare("SELECT * FROM household_members WHERE homeowner_id=? ORDER BY id ASC");
$stmt->bind_param("i", $id);
$stmt->execute();
$members = $stmt->get_result();
$stmt->close();

// Values
$lat = $homeowner['latitude'] ?? '';
$lng = $homeowner['longitude'] ?? '';
$validId = $homeowner['valid_id_path'] ?? '';
$proof   = $homeowner['proof_of_billing_path'] ?? '';

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// initials for avatar
$fn = trim((string)($homeowner['first_name'] ?? ''));
$ln = trim((string)($homeowner['last_name'] ?? ''));
$initials = strtoupper(($fn ? $fn[0] : 'H') . ($ln ? $ln[0] : 'O'));

$status = strtolower((string)($homeowner['status'] ?? 'pending'));
$badgeClass = 'badge-soft-warning';
if ($status === 'approved') $badgeClass = 'badge-soft-success';
if ($status === 'rejected') $badgeClass = 'badge-soft-danger';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>View Homeowner</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Leaflet -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

    <style>
        :root{
            --brand: #077f46;
            --ink: #0f172a;
            --muted: #64748b;
            --bg: #f4f6fb;
            --card: #ffffff;
            --border: #e5e7eb;
        }
        body{ background: var(--bg); color: var(--ink); }
        .page-wrap{ max-width: 1120px; margin: 0 auto; padding: 24px 16px 64px; }

        /* Facebook-like cover */
        .profile-shell{
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 12px 32px rgba(15,23,42,.08);
            background: var(--card);
        }
        .cover{
            position: relative;
            height: 320px;
            background: #e9eef6;
        }
        #coverMap{
            height: 100%;
            width: 100%;
        }
        /* keep leaflet layers behind UI */
        .leaflet-container, .leaflet-pane, .leaflet-top, .leaflet-bottom{ z-index: 1 !important; }

        .cover-overlay{
            position: absolute;
            inset: 0;
            background: linear-gradient(to bottom, rgba(0,0,0,.15), rgba(0,0,0,.45));
            z-index: 2;
            pointer-events: none;
        }

        /* profile header content */
        .profile-header{
            position: relative;
            padding: 0 24px 18px;
        }
        .avatar{
            width: 130px;
            height: 130px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--brand), #0bbf6a);
            border: 6px solid #fff;
            display: grid;
            place-items: center;
            color: #fff;
            font-weight: 800;
            font-size: 40px;
            position: absolute;
            top: -65px;
            left: 24px;
            z-index: 4;
            box-shadow: 0 10px 25px rgba(15,23,42,.18);
        }
        .profile-meta{
            padding-top: 16px;
            padding-left: 160px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .name{
            font-size: 26px;
            font-weight: 800;
            margin: 0;
            line-height: 1.15;
        }
        .subline{
            margin: 6px 0 0;
            color: var(--muted);
            font-weight: 600;
        }
        .pill{
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #f1f5f9;
            color: #0f172a;
            font-weight: 700;
            font-size: 13px;
            border: 1px solid var(--border);
        }
        .badge-soft-warning{ background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; }
        .badge-soft-success{ background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; }
        .badge-soft-danger{  background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

        /* content cards */
        .cardx{
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 10px 24px rgba(15,23,42,.06);
        }
        .cardx .cardx-head{
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .cardx-title{
            margin: 0;
            font-weight: 800;
            font-size: 16px;
        }
        .cardx-body{ padding: 16px 18px; }

        .kv{
            display: grid;
            grid-template-columns: 160px 1fr;
            gap: 10px 14px;
        }
        .k{ color: var(--muted); font-weight: 700; font-size: 13px; }
        .v{ font-weight: 700; color: var(--ink); }
        .v small{ color: var(--muted); font-weight: 700; }

        .doc-card{
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            background: #fff;
        }
        .doc-thumb{
            width: 100%;
            height: 180px;
            object-fit: cover;
            background: #f1f5f9;
            display: block;
        }
        .doc-meta{
            padding: 12px 12px 14px;
        }
        .doc-title{
            font-weight: 800;
            margin: 0 0 8px;
        }

        .table thead th{
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        /* top actions */
        .topbar{
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        @media (max-width: 768px){
            .cover{ height: 260px; }
            .avatar{ left: 50%; transform: translateX(-50%); }
            .profile-meta{ padding-left: 0; padding-top: 84px; text-align: center; justify-content: center; }
        }
    </style>
</head>
<body>

<div class="page-wrap">

    <div class="topbar">
        <div>
            <h4 class="mb-0 fw-bold">Homeowner Profile</h4>
            <small class="text-muted fw-semibold">View full registration details</small>
        </div>
        <a href="HO-management.php" class="btn btn-outline-secondary">
            ← Back
        </a>
    </div>

    <!-- FB STYLE PROFILE SHELL -->
    <div class="profile-shell mb-4">
        <div class="cover">
            <?php if (!empty($lat) && !empty($lng)): ?>
                <div id="coverMap"></div>
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
                    <p class="name"><?= esc($homeowner['first_name'].' '.($homeowner['middle_name'] ?? '').' '.$homeowner['last_name']) ?></p>
                    <p class="subline">
                        <?= esc($homeowner['phase']) ?> • <?= esc($homeowner['house_lot_number']) ?> •
                        <span class="pill <?= $badgeClass ?>"><?= esc(ucfirst($status)) ?></span>
                    </p>
                </div>

                <div class="d-flex gap-2 flex-wrap">
                    <span class="pill">📧 <?= esc($homeowner['email']) ?></span>
                    <span class="pill">📞 <?= esc($homeowner['contact_number']) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- CONTENT GRID like FB (left column cards, right column cards) -->
    <div class="row g-4">

        <!-- LEFT: INFO -->
        <div class="col-lg-5">
            <div class="cardx mb-4">
                <div class="cardx-head">
                    <h5 class="cardx-title">Homeowner Information</h5>
                    <span class="pill">🕒 <small><?= esc($homeowner['created_at']) ?></small></span>
                </div>
                <div class="cardx-body">
                    <div class="kv">
                        <div class="k">Full Name</div>
                        <div class="v"><?= esc($homeowner['first_name'].' '.($homeowner['middle_name'] ?? '').' '.$homeowner['last_name']) ?></div>

                        <div class="k">Email</div>
                        <div class="v"><?= esc($homeowner['email']) ?></div>

                        <div class="k">Contact</div>
                        <div class="v"><?= esc($homeowner['contact_number']) ?></div>

                        <div class="k">Phase</div>
                        <div class="v"><?= esc($homeowner['phase']) ?></div>

                        <div class="k">House / Lot</div>
                        <div class="v"><?= esc($homeowner['house_lot_number']) ?></div>



                        <div class="k">Status</div>
                        <div class="v text-capitalize"><?= esc($status) ?></div>
                    </div>
                </div>
            </div>

            <div class="cardx">
                <div class="cardx-head">
                    <h5 class="cardx-title">Uploaded Documents</h5>
                </div>
                <div class="cardx-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="doc-card">
                                <?php if ($validId): ?>
                                    <img class="doc-thumb" src="<?= esc($validId) ?>" alt="Valid ID"
                                         onerror="this.style.display='none'">
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
                                    <img class="doc-thumb" src="<?= esc($proof) ?>" alt="Proof of Billing"
                                         onerror="this.style.display='none'">
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

        <!-- RIGHT: MEMBERS -->
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
                                            <?= esc($m['first_name']) ?>
                                            <?= esc($m['middle_name'] ?? '') ?>
                                            <?= esc($m['last_name']) ?>
                                        </td>
                                        <td>
                                            <span class="pill"><?= esc($m['relation']) ?></span>
                                        </td>
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

<?php if (!empty($lat) && !empty($lng)): ?>
<script>
const lat = <?= json_encode((float)$lat) ?>;
const lng = <?= json_encode((float)$lng) ?>;

// cover map
const coverMap = L.map('coverMap', {
    zoomControl: false,
    dragging: true,
    scrollWheelZoom: true,
    doubleClickZoom: true,
    boxZoom: false,
    keyboard: false,
    tap: false
}).setView([lat, lng], 18);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '&copy; OpenStreetMap contributors'
}).addTo(coverMap);

L.marker([lat, lng]).addTo(coverMap).bindPopup("Homeowner Location");

// IMPORTANT: Leaflet inside a "cover" needs this
setTimeout(() => coverMap.invalidateSize(), 250);
</script>
<?php endif; ?>

</body>
</html>
