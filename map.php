<?php
session_start();

// ===================== DB CONNECTION =====================
$host = "localhost";
$db   = "south_meridian_hoa";
$user = "root";
$pass = ""; // your DB password
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ===================== HANDLE FINAL SUBMISSION =====================
if(isset($_POST['submit_location'])) {
    // Homeowner basic info
    $first_name = $_POST['first_name'];
    $middle_name = $_POST['middle_name'];
    $last_name = $_POST['last_name'];
    $contact_number = $_POST['contact_number'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT); // hashed password
    $phase = $_POST['phase'];
    $house_lot_number = $_POST['house_lot_number'];
    $latitude = $_POST['latitude'];
    $longitude = $_POST['longitude'];

    // Assign admin based on phase
    $stmtAdmin = $conn->prepare("SELECT id FROM admins WHERE phase=? LIMIT 1");
    $stmtAdmin->bind_param("s", $phase);
    $stmtAdmin->execute();
    $resAdmin = $stmtAdmin->get_result()->fetch_assoc();
    $admin_id = $resAdmin['id'] ?? NULL;

    // Handle file uploads
    $uploadDir = "uploads/";
    if(!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $valid_id_path = $uploadDir . time() . "_id_" . basename($_FILES['valid_id']['name']);
    $proof_path = $uploadDir . time() . "_proof_" . basename($_FILES['proof_of_billing']['name']);

    move_uploaded_file($_FILES['valid_id']['tmp_name'], $valid_id_path);
    move_uploaded_file($_FILES['proof_of_billing']['tmp_name'], $proof_path);

    // Insert homeowner
    $stmtHome = $conn->prepare("INSERT INTO homeowners 
        (first_name,middle_name,last_name,contact_number,email,password,phase,house_lot_number,valid_id_path,proof_of_billing_path,latitude,longitude,admin_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmtHome->bind_param("sssssssssssdi",
        $first_name, $middle_name, $last_name, $contact_number, $email, $password, $phase, $house_lot_number,
        $valid_id_path, $proof_path, $latitude, $longitude, $admin_id
    );
    $stmtHome->execute();
    $homeowner_id = $stmtHome->insert_id;

    // Insert household members
    if(isset($_POST['member_first_name'])) {
        foreach($_POST['member_first_name'] as $i => $mfname) {
            $mmname = $_POST['member_middle_name'][$i];
            $mlname = $_POST['member_last_name'][$i];
            $relation = $_POST['member_relation'][$i];

            $stmtMember = $conn->prepare("INSERT INTO household_members
                (homeowner_id, first_name, middle_name, last_name, relation)
                VALUES (?,?,?,?,?)");
            $stmtMember->bind_param("issss", $homeowner_id, $mfname, $mmname, $mlname, $relation);
            $stmtMember->execute();
        }
    }

    // Success
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

    <!-- Bootstrap & Fonts -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/main.css" rel="stylesheet">

    <!-- Leaflet -->
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

        <form method="POST" enctype="multipart/form-data">
            <?php
            // Pass all previous registration fields as hidden
            foreach($_POST as $key => $value){
                if(is_array($value)){
                    foreach($value as $v){
                        echo '<input type="hidden" name="'.$key.'[]" value="'.$v.'">';
                    }
                } else {
                    echo '<input type="hidden" name="'.$key.'" value="'.$value.'">';
                }
            }
            ?>
            <input type="hidden" name="latitude" id="latitude">
            <input type="hidden" name="longitude" id="longitude">

            <div id="map" class="mb-3"></div>

            <button type="submit" name="submit_location" class="btn btn-success w-100 py-2">Submit Registration</button>
        </form>
    </div>
</div>

<script src="./leaflet/dist/leaflet.js"></script>
<script>
    var map = L.map('map').setView([14.3545, 120.946], 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    // Allowed area polygon
    var allowedArea = L.polygon([
        [14.357391, 120.943993],
        [14.351903, 120.944937],
        [14.352257, 120.948118],
        [14.357828, 120.947329]
    ], {color: 'green', fillColor: 'rgba(0, 255, 0, 0.2)'}).addTo(map);

    map.fitBounds(allowedArea.getBounds());

    // Draggable marker
    var center = allowedArea.getBounds().getCenter();
    var marker = L.marker(center, {draggable:true}).addTo(map);

    // Tooltip for restriction
    var tooltip = L.tooltip({
        permanent: false,
        direction: 'top',
        className: 'text-danger fw-bold'
    });

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
            tooltip
                .setLatLng(marker.getLatLng())
                .setContent("You can only pin within the allowed area!")
                .addTo(map);
        } else {
            e.target._origPos = latLng;
            map.removeLayer(tooltip);
        }
    });

    marker.on('dragend', function(e){
        var latLng = e.target.getLatLng();
        document.getElementById('latitude').value = latLng.lat;
        document.getElementById('longitude').value = latLng.lng;
    });

    // Initialize hidden inputs
    var initialPos = marker.getLatLng();
    document.getElementById('latitude').value = initialPos.lat;
    document.getElementById('longitude').value = initialPos.lng;
</script>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
