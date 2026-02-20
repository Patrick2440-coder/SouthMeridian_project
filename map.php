<?php
session_start();

// ===================== DB CONNECTION =====================
$host = "localhost";
$db   = "south_meridian_hoa";
$user = "root";
$pass = "";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

// ===================== STEP 1: Coming from register form =====================
// This happens when register form posts to map.php (WITH FILES) but NOT yet submit_location
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['submit_location'])) {

    // Basic fields (validate as needed)
    $first_name       = trim($_POST['first_name'] ?? '');
    $middle_name      = trim($_POST['middle_name'] ?? '');
    $last_name        = trim($_POST['last_name'] ?? '');
    $contact_number   = trim($_POST['contact_number'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $raw_password     = (string)($_POST['password'] ?? '');
    $phase            = trim($_POST['phase'] ?? '');
    $house_lot_number = trim($_POST['house_lot_number'] ?? '');

    if ($first_name === '' || $last_name === '' || $email === '' || $raw_password === '' || $phase === '' || $house_lot_number === '') {
        die("Missing required fields.");
    }

    // ===================== CHECK IF EMAIL EXISTS =====================
    $checkEmail = $conn->prepare("SELECT id FROM homeowners WHERE email = ? LIMIT 1");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    $checkEmail->store_result();

    if ($checkEmail->num_rows > 0) {
        $checkEmail->close();
        echo "<script>
            alert('This email address is already registered. Please use another email.');
            window.location.href = 'register.html';
        </script>";
        exit;
    }
    $checkEmail->close();

    // ===================== ASSIGN ADMIN BASED ON PHASE =====================
    $stmtAdmin = $conn->prepare("SELECT id FROM admins WHERE phase=? LIMIT 1");
    $stmtAdmin->bind_param("s", $phase);
    $stmtAdmin->execute();
    $resAdmin = $stmtAdmin->get_result()->fetch_assoc();
    $stmtAdmin->close();
    $admin_id = $resAdmin['id'] ?? null; // can be NULL

    // ===================== HANDLE FILE UPLOADS (ONLY ON STEP 1) =====================
    $uploadDir = __DIR__ . "/uploads/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    // Validate file keys exist
    if (
        empty($_FILES['valid_id']['name']) ||
        empty($_FILES['proof_of_billing']['name']) ||
        !is_uploaded_file($_FILES['valid_id']['tmp_name']) ||
        !is_uploaded_file($_FILES['proof_of_billing']['tmp_name'])
    ) {
        die("Please upload Valid ID and Proof of Billing before pinning your location.");
    }

    $ts = time();
    $valid_id_name = basename($_FILES['valid_id']['name']);
    $proof_name    = basename($_FILES['proof_of_billing']['name']);

    $valid_id_rel = "uploads/{$ts}_id_{$valid_id_name}";
    $proof_rel    = "uploads/{$ts}_proof_{$proof_name}";

    $valid_id_abs = __DIR__ . "/" . $valid_id_rel;
    $proof_abs    = __DIR__ . "/" . $proof_rel;

    if (!move_uploaded_file($_FILES['valid_id']['tmp_name'], $valid_id_abs)) {
        die("Failed to upload Valid ID.");
    }
    if (!move_uploaded_file($_FILES['proof_of_billing']['tmp_name'], $proof_abs)) {
        die("Failed to upload Proof of Billing.");
    }

    // ===================== STORE EVERYTHING IN SESSION =====================
    $_SESSION['reg'] = [
        'first_name' => $first_name,
        'middle_name' => $middle_name,
        'last_name' => $last_name,
        'contact_number' => $contact_number,
        'email' => $email,
        'password_hash' => password_hash($raw_password, PASSWORD_DEFAULT),
        'phase' => $phase,
        'house_lot_number' => $house_lot_number,
        'valid_id_path' => $valid_id_rel,
        'proof_of_billing_path' => $proof_rel,
        'admin_id' => $admin_id,

        // household members arrays (optional)
        'member_first_name' => $_POST['member_first_name'] ?? [],
        'member_middle_name' => $_POST['member_middle_name'] ?? [],
        'member_last_name' => $_POST['member_last_name'] ?? [],
        'member_relation' => $_POST['member_relation'] ?? [],
    ];

    // Now show the map page normally (no redirect needed)
}

// ===================== STEP 2: Final submit from MAP =====================
if (isset($_POST['submit_location'])) {

    if (empty($_SESSION['reg'])) {
        die("Session expired. Please register again.");
    }

    $latitude  = (float)($_POST['latitude'] ?? 0);
    $longitude = (float)($_POST['longitude'] ?? 0);

    if ($latitude == 0 || $longitude == 0) {
        die("Invalid location. Please pin your location on the map.");
    }

    $reg = $_SESSION['reg'];

    // ===================== INSERT HOMEOWNER =====================
    $stmtHome = $conn->prepare("
        INSERT INTO homeowners 
        (first_name, middle_name, last_name, contact_number, email, password, phase, house_lot_number,
         valid_id_path, proof_of_billing_path, latitude, longitude, admin_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    // 10 strings + 2 doubles + 1 int  => ssssssssssddi
    $stmtHome->bind_param(
        "ssssssssssddi",
        $reg['first_name'],
        $reg['middle_name'],
        $reg['last_name'],
        $reg['contact_number'],
        $reg['email'],
        $reg['password_hash'],
        $reg['phase'],
        $reg['house_lot_number'],
        $reg['valid_id_path'],
        $reg['proof_of_billing_path'],
        $latitude,
        $longitude,
        $reg['admin_id']
    );

    $stmtHome->execute();
    $homeowner_id = $stmtHome->insert_id;
    // ===================== SET public_id (NO TRIGGER) =====================
// Example format: PHASE-000123 (edit to your preferred format)
$phaseCode = strtoupper(preg_replace('/\s+/', '', $reg['phase'])); // e.g. "Phase 1" -> "PHASE1"

// If you want shorter like P1/P2/P3:
if (preg_match('/(\d+)/', $reg['phase'], $m)) {
    $phaseCode = 'P' . $m[1]; // "Phase 1" -> "P1"
}

$public_id = $phaseCode . '-' . str_pad((string)$homeowner_id, 6, '0', STR_PAD_LEFT);

$stmtPub = $conn->prepare("UPDATE homeowners SET public_id=? WHERE id=?");
$stmtPub->bind_param("si", $public_id, $homeowner_id);
$stmtPub->execute();
$stmtPub->close();
    $stmtHome->close();

    // ===================== INSERT HOUSEHOLD MEMBERS =====================
    if (!empty($reg['member_first_name']) && is_array($reg['member_first_name'])) {
        foreach ($reg['member_first_name'] as $i => $mfname) {
            $mfname   = trim((string)$mfname);
            $mmname   = trim((string)($reg['member_middle_name'][$i] ?? ''));
            $mlname   = trim((string)($reg['member_last_name'][$i] ?? ''));
            $relation = trim((string)($reg['member_relation'][$i] ?? ''));

            if ($mfname === '' && $mlname === '' && $relation === '') continue;

            $stmtMember = $conn->prepare("
                INSERT INTO household_members
                (homeowner_id, first_name, middle_name, last_name, relation)
                VALUES (?,?,?,?,?)
            ");
            $stmtMember->bind_param("issss", $homeowner_id, $mfname, $mmname, $mlname, $relation);
            $stmtMember->execute();
            $stmtMember->close();
        }
    }

    // Clear session after successful insert
    unset($_SESSION['reg']);

    echo "<script>
        alert('Registration complete! Wait for 2 to 3 days for approval.');
        window.location.href='index.php';
    </script>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Pin Your Location | South Meridian Homes</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/main.css" rel="stylesheet">
    <link rel="stylesheet" href="./leaflet/dist/leaflet.css" />
    <style>
        #map { height: 500px; border-radius: 12px; border: 2px solid #077f46; }
    </style>
</head>

<body class="index-page">

<div class="container my-5">
    <div class="card shadow-lg p-4">
        <h3 class="text-success mb-3">Pin Your Exact Location</h3>
        <p>Drag the marker to your house within your phase area.</p>

        <form method="POST">
            <input type="hidden" name="latitude" id="latitude">
            <input type="hidden" name="longitude" id="longitude">

            <div id="map" class="mb-3"></div>

            <button type="submit" name="submit_location" class="btn btn-success w-100 py-2">
                Submit Registration
            </button>
        </form>
    </div>
</div>

<script src="./leaflet/dist/leaflet.js"></script>
<script>
    var map = L.map('map').setView([14.3545, 120.946], 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    var allowedArea = L.polygon([
        [14.357391, 120.943993],
        [14.351903, 120.944937],
        [14.352257, 120.948118],
        [14.357828, 120.947329]
    ], {color: 'green', fillColor: 'rgba(0, 255, 0, 0.2)'}).addTo(map);

    map.fitBounds(allowedArea.getBounds());

    var center = allowedArea.getBounds().getCenter();
    var marker = L.marker(center, {draggable:true}).addTo(map);

    function isInsidePolygon(point, polygon) {
        var x = point.lat, y = point.lng;
        var inside = false;
        var vs = polygon.getLatLngs()[0];
        for (var i = 0, j = vs.length - 1; i < vs.length; j = i++) {
            var xi = vs[i].lat, yi = vs[i].lng;
            var xj = vs[j].lat, yj = vs[j].lng;
            var intersect = ((yi > y) != (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi);
            if (intersect) inside = !inside;
        }
        return inside;
    }

    marker.on('drag', function(e){
        var latLng = e.target.getLatLng();
        if(!isInsidePolygon(latLng, allowedArea)) {
            marker.setLatLng(e.target._origPos || center);
        } else {
            e.target._origPos = latLng;
        }
    });

    marker.on('dragend', function(e){
        var latLng = e.target.getLatLng();
        document.getElementById('latitude').value = latLng.lat;
        document.getElementById('longitude').value = latLng.lng;
    });

    var initialPos = marker.getLatLng();
    document.getElementById('latitude').value = initialPos.lat;
    document.getElementById('longitude').value = initialPos.lng;
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
