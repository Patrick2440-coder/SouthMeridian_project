<?php
session_start();

// ===================== DB CONNECTION =====================
$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ===================== AJAX LOGIN PROCESS =====================
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $email    = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        echo "Email and password are required";
        exit;
    }

    // ----------------- CLEAR PREVIOUS LOGIN KEYS (prevents conflicts) -----------------
    unset(
        $_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['admin_phase'],
        $_SESSION['homeowner_id'], $_SESSION['homeowner_role'], $_SESSION['homeowner_phase'],
        $_SESSION['tenant_id'], $_SESSION['tenant_homeowner_id'], $_SESSION['tenant_role'], $_SESSION['tenant_phase'],
        $_SESSION['user_id'], $_SESSION['role'], $_SESSION['phase']
    );

    // 1) Try admins first
    $stmt = $conn->prepare("SELECT id, email, password, role, phase FROM admins WHERE email=? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($admin) {
        if ($password === $admin['password']) {
            $_SESSION['admin_id']    = (int)$admin['id'];
            $_SESSION['admin_role']  = (string)$admin['role'];
            $_SESSION['admin_phase'] = (string)$admin['phase'];
            $_SESSION['role']    = $_SESSION['admin_role'];
            $_SESSION['phase']   = $_SESSION['admin_phase'];
            $_SESSION['user_id'] = $_SESSION['admin_id'];

            echo ($_SESSION['admin_role'] === 'superadmin')
                ? "superadmin/dashboard.php"
                : "admin/dashboard.php";
            exit;
        } else {
            echo "Incorrect password";
            exit;
        }
    }

   // 2) If not admin, try homeowners
    $stmt = $conn->prepare("
        SELECT id, email, password, status, phase, IFNULL(must_change_password, 1) AS must_change_password
        FROM homeowners
        WHERE email=?
        LIMIT 1
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $home = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($home) {
        if ($home['status'] !== 'approved') {
            echo "Your account is not approved yet.";
            exit;
        }

        if (!password_verify($password, $home['password'])) {
            echo "Incorrect password";
            exit;
        }

        $_SESSION['homeowner_id']    = (int)$home['id'];
        $_SESSION['homeowner_role']  = 'homeowner';
        $_SESSION['homeowner_phase'] = (string)$home['phase'];
        $_SESSION['role']            = 'homeowner';
        $_SESSION['phase']           = $_SESSION['homeowner_phase'];
        $_SESSION['user_id']         = $_SESSION['homeowner_id'];

        echo "homeowner/homeowner_dashboard.php";
        exit;
    }

    // 3) If not homeowner, try tenants
    $stmt = $conn->prepare("
        SELECT id, homeowner_id, email, password, status, phase
        FROM tenants
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tenant) {
        echo "Email not found";
        exit;
    }

    if ($tenant['status'] !== 'active') {
        echo "Your tenant account is inactive.";
        exit;
    }

    if (!password_verify($password, $tenant['password'])) {
        echo "Incorrect password";
        exit;
    }

    $_SESSION['tenant_id']           = (int)$tenant['id'];
    $_SESSION['tenant_homeowner_id'] = (int)$tenant['homeowner_id'];
    $_SESSION['tenant_role']         = 'tenant';
    $_SESSION['tenant_phase']        = (string)$tenant['phase'];
    $_SESSION['role']                = 'tenant';
    $_SESSION['phase']               = $_SESSION['tenant_phase'];
    $_SESSION['user_id']             = $_SESSION['tenant_id'];


    echo "homeowner/homeowner_dashboard.php";
    exit;
}

// ===================== FLASH MESSAGES =====================
$success_message = $_SESSION['success_message'] ?? '';
$email_error     = $_SESSION['email_error'] ?? '';

unset($_SESSION['success_message'], $_SESSION['email_error']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title>South Meridian Homes</title>
  <meta name="description" content="South Meridian Homeowners Association – Your secure, modern, and efficient HOA management platform.">
  <meta name="keywords" content="South Meridian Homes, HOA, Homeowners Association, Dasmariñas, Cavite">

  <!-- Favicons -->
  <link href="assets/img/sm_logo.png" rel="icon">
  <link href="assets/img/apple-touch-icon.png" rel="apple-touch-icon">

  <!-- Fonts -->
  <link href="https://fonts.googleapis.com" rel="preconnect">
  <link href="https://fonts.gstatic.com" rel="preconnect" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&family=Montserrat:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&family=Raleway:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/vendor/aos/aos.css" rel="stylesheet">
  <link href="assets/vendor/swiper/swiper-bundle.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Main CSS File -->
  <link href="assets/css/main.css" rel="stylesheet">
</head>

<body class="index-page">

  <?php if ($success_message !== ''): ?>
  <div class="position-fixed top-0 end-0 p-3" style="z-index: 9999">
    <div id="successToast" class="toast border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true" style="min-width: 360px; border-radius: 14px; overflow: hidden;">
      <div class="toast-header bg-success text-white border-0">
        <strong class="me-auto">
          <i class="bi bi-check-circle-fill me-2"></i>Registration Submitted
        </strong>
        <small>Just now</small>
        <button type="button" class="btn-close btn-close-white ms-2 mb-1" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
      <div class="toast-body bg-white text-dark">
        <?= esc($success_message) ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($email_error !== ''): ?>
  <div class="position-fixed top-0 end-0 p-3" style="z-index: 9998; margin-top: 110px;">
    <div id="emailErrorToast" class="toast border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true" style="min-width: 360px; border-radius: 14px; overflow: hidden;">
      <div class="toast-header bg-warning text-dark border-0">
        <strong class="me-auto">
          <i class="bi bi-exclamation-triangle-fill me-2"></i>Email Notice
        </strong>
        <small>Just now</small>
        <button type="button" class="btn-close ms-2 mb-1" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
      <div class="toast-body bg-white text-dark">
        Registration was saved, but the confirmation email could not be sent.
      </div>
    </div>
  </div>
  <?php endif; ?>

  <header id="header" class="header d-flex align-items-center sticky-top">
    <div class="container-fluid container-xl position-relative d-flex align-items-center justify-content-between">

      <a href="index.php" class="logo d-flex align-items-center">
        <img src="assets/img/sm_logo.png" alt="South Meridian Homes Logo" style="max-height: 70px;">
        <h1 class="sitename">South Meridian Homes</h1>
      </a>

      <nav id="navmenu" class="navmenu">
        <ul>
          <li><a href="#hero">Home</a></li>
          <li><a href="#about">About</a></li>
          <li><a href="#features">Features</a></li>
          <li><a href="#download-app">Download App</a></li>

          <a href="#" data-bs-toggle="modal" data-bs-target="#loginModal" style="color: #077f46; background-color: white; border-radius: 50px; width: 100px; height: 50px;">
            &nbsp;&nbsp; Log in
          </a>
        </ul>
        <i class="mobile-nav-toggle d-xl-none bi bi-list"></i>
      </nav>

    </div>
  </header>

  <!-- ================= LOGIN MODAL ================= -->
  <div class="modal fade" id="loginModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rounded-4 shadow">

        <div class="modal-header bg-success text-white rounded-top-4">
          <h5 class="modal-title" style="color: white;">South Meridian Homes</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body px-4 py-4 text-center">

          <img src="assets/img/sm_logo.png" alt="Logo" class="mb-3" style="max-width:90px;">
          <!-- CHANGED: "Homeowners Login" → "Member Login" (admins also log in here) -->
          <h6 class="fw-bold mb-1 text-success">Member Login</h6>
          <p class="text-muted small mb-3">Homeowners and administrators may log in here.</p>

          <form id="loginForm">
            <div class="form-floating mb-3">
              <input type="email" class="form-control" id="email" name="email" placeholder="Email" required>
              <label for="email">Email address</label>
            </div>

            <div class="form-floating mb-3">
              <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
              <label for="password">Password</label>
            </div>

            <div class="loading text-primary mb-2" style="display:none;">Checking credentials...</div>
            <div class="error-message text-danger mb-2" style="display:none;"></div>

            <button type="submit" class="btn btn-success w-100 py-2 fw-semibold">Log in</button>
          </form>

          <div class="mt-3">
            <div class="d-flex justify-content-start mt-3">
              <!-- CHANGED: "Forgot password?" now links to forgot_password.php instead of # -->
              <a href="forgot_password.php" class="text-success text-decoration-none">Forgot password?</a>
            </div>
            <span class="text-muted small d-block my-2">Don't have an account?
              <a href="register.html" class="text-success text-decoration-none">Create Account</a>
            </span>
          </div>

        </div>
      </div>
    </div>
  </div>

  <main class="main">

    <!-- Hero Section -->
    <section id="hero" class="hero section">
      <div class="container" data-aos="fade-up" data-aos-delay="100">
        <div class="hero-wrapper">
          <div class="row g-4">

            <div class="col-lg-7">
              <div class="hero-content" data-aos="zoom-in" data-aos-delay="200">
                <div class="content-header">
                  <h1>Welcome to South Meridian Homes</h1>
                  <!--
                    CHANGED: Generic description replaced with one that reflects all system modules:
                    authentication, communication, payments, parking, facility rental, voting,
                    homeowner management, financial management, and mobile access.
                  -->
                  <p>South Meridian Homes is an integrated HOA management platform built for the South Meridian Homeowners Association in Salitran 4, Dasmariñas, Cavite. It centralizes all essential community services — from online due payments, parking permits, and facility reservations, to community voting, complaint tracking, and announcements — into one secure and accessible digital system for both homeowners and administrators.</p>
                </div>

                <div class="achievement-grid" data-aos="fade-up" data-aos-delay="400">
                  <!--
                    CHANGED: Replaced "1250+ Active Communities / 89+ Active HOAs / 96% Active Homeowners"
                    with figures that are accurate and relevant to South Meridian HOA:
                    - 3 Community Phases (Phase 1, 2, 3 as referenced in the codebase)
                    - 8+ System Modules (the 8 functional modules described in the project context)
                    - 100% Online Services (the fully digital nature of the platform)
                    Update the homeowner count below with actual data when available.
                  -->
                  <div class="achievement-item">
                    <div class="achievement-number">
                      <span data-purecounter-start="0" data-purecounter-end="3" data-purecounter-duration="1" class="purecounter"></span>
                    </div>
                    <span class="achievement-text">Community Phases</span>
                  </div>
                  <div class="achievement-item">
                    <div class="achievement-number">
                      <span data-purecounter-start="0" data-purecounter-end="8" data-purecounter-duration="1" class="purecounter"></span>+
                    </div>
                    <span class="achievement-text">Integrated System Modules</span>
                  </div>
                  <div class="achievement-item">
                    <div class="achievement-number">
                      <span data-purecounter-start="0" data-purecounter-end="100" data-purecounter-duration="1" class="purecounter"></span>%
                    </div>
                    <span class="achievement-text">Online HOA Services</span>
                  </div>
                </div>

              </div>
            </div>

            <div class="col-lg-5">
              <div class="hero-visual" data-aos="fade-left" data-aos-delay="400">
                <div class="visual-container">
                  <div class="featured-property">
                    <img src="assets/img/real-estate/property-exterior-8.webp" alt="South Meridian Property" class="img-fluid">
                    <div class="property-info"></div>
                  </div>

                  <div class="overlay-images">
                    <div class="overlay-img overlay-1">
                      <img src="assets/img/real-estate/property-interior-4.webp" alt="Interior View" class="img-fluid">
                    </div>
                    <div class="overlay-img overlay-2">
                      <img src="assets/img/real-estate/property-exterior-2.webp" alt="Exterior View" class="img-fluid">
                    </div>
                  </div>

                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
    </section>

    <!-- About Section -->
    <section id="about" class="home-about section">
      <div class="container" data-aos="fade-up" data-aos-delay="100">
        <div class="row gy-5">

          <div class="col-lg-5" data-aos="zoom-in" data-aos-delay="200">
            <div class="image-gallery">
              <div class="primary-image">
                <img src="assets/img/real-estate/property-exterior-1.webp" alt="South Meridian Property" class="img-fluid">
              </div>
              <div class="secondary-image">
                <img src="assets/img/real-estate/property-interior-4.webp" alt="South Meridian Interior" class="img-fluid">
              </div>
            </div>
          </div>

          <div class="col-lg-7" data-aos="fade-left" data-aos-delay="300">
            <div class="content">
              <div class="section-header">
                <span class="section-label">About South Meridian Homes</span>
                <h2>Building Communities, One Home at a Time</h2>
              </div>

              <!--
                CHANGED: Description now references the integrated HOA system and its purpose,
                not just a generic community description.
              -->
              <p>South Meridian Homes is a residential community located in Salitran 4, Dasmariñas, Cavite, guided by a Homeowners Association committed to transparency, order, and community participation. This platform was developed to centralize and digitize HOA operations — giving homeowners and administrators a secure, organized, and system to manage the community efficiently.</p>

              <div class="achievements-list">
                <!--
                  CHANGED: Expanded from 3 generic items to 6 items that reflect the actual
                  modules in the project context: Communication & Complaint, Payments,
                  Parking, Facility Rental, Voting, Financial & Reporting.
                -->
                <div class="achievement-item">
                  <div class="achievement-icon">
                    <i class="bi bi-megaphone"></i>
                  </div>
                  <div class="achievement-content">
                    <h4>Communication &amp; Complaint Management</h4>
                    <p>Stay informed through announcements, community chat, and private messaging. Homeowners can submit and track complaints while administrators manage resolutions.</p>
                  </div>
                </div>

                <div class="achievement-item">
                  <div class="achievement-icon">
                    <i class="bi bi-credit-card"></i>
                  </div>
                  <div class="achievement-content">
                    <h4>Online Payment Management</h4>
                    <p>Pay monthly dues and other fees securely online. Administrators can monitor payments, track records, and maintain full transaction histories.</p>
                  </div>
                </div>

                <div class="achievement-item">
                  <div class="achievement-icon">
                    <i class="bi bi-car-front"></i>
                  </div>
                  <div class="achievement-content">
                    <h4>Parking Management</h4>
                    <p>Apply for or renew parking permits. Administrators manage vehicle registration, approvals, and parking compliance within the subdivision.</p>
                  </div>
                </div>

                <div class="achievement-item">
                  <div class="achievement-icon">
                    <i class="bi bi-building"></i>
                  </div>
                  <div class="achievement-content">
                    <h4>Facility Rental Management</h4>
                    <p>Reserve community facilities — courts, tables, and function areas — online, with scheduling features and administrator approval management.</p>
                  </div>
                </div>

                <div class="achievement-item">
                  <div class="achievement-icon">
                    <i class="bi bi-check2-square"></i>
                  </div>
                  <div class="achievement-content">
                    <h4>Community Voting System</h4>
                    <p>Participate in secure online HOA elections. The system enforces one vote per resident and automatically tallies and displays results when voting ends.</p>
                  </div>
                </div>

                <div class="achievement-item">
                  <div class="achievement-icon">
                    <i class="bi bi-graph-up-arrow"></i>
                  </div>
                  <div class="achievement-content">
                    <h4>Financial &amp; Reporting Management</h4>
                    <p>Track all HOA financial activities including dues collection, transaction records, and system-wide reports that support transparency and informed decisions.</p>
                  </div>
                </div>

              </div>
            </div>
          </div>

        </div>
      </div>
    </section>

    <!-- ================= FEATURES SECTION (NEW) ================= -->
    <!--
      NEW: Added a dedicated module overview section with all 9 system modules
      from the project context displayed as cards for clear visibility.
    -->
    <section id="features" class="section" style="background: #f8f9fa;">
      <div class="container" data-aos="fade-up">

        <div class="row justify-content-center text-center mb-5">
          <div class="col-lg-8">
            <span class="section-label">What the System Offers</span>
            <h2 class="fw-bold mt-2">Complete HOA Management in One Platform</h2>
            <p class="text-muted">
              The South Meridian HOA Management System covers all operational needs of your community —
              accessible on both web and mobile.
            </p>
          </div>
        </div>

        <div class="row g-4">

          <div class="col-md-4 col-sm-6" data-aos="fade-up" data-aos-delay="100">
            <div class="card border-0 shadow-sm h-100 p-4 text-center">
              <div class="mb-3"><i class="bi bi-shield-lock" style="font-size:36px; color:#077f46;"></i></div>
              <h6 class="fw-bold">User Authentication</h6>
              <p class="text-muted small">Secure login and account registration for homeowners. Administrators access the system via default accounts without registration.</p>
            </div>
          </div>

          <div class="col-md-4 col-sm-6" data-aos="fade-up" data-aos-delay="150">
            <div class="card border-0 shadow-sm h-100 p-4 text-center">
              <div class="mb-3"><i class="bi bi-chat-dots" style="font-size:36px; color:#077f46;"></i></div>
              <h6 class="fw-bold">Communication &amp; Complaints</h6>
              <p class="text-muted small">Announcements, community chat, private messaging, and a structured complaint submission and tracking system for homeowners and admins.</p>
            </div>
          </div>

          <div class="col-md-4 col-sm-6" data-aos="fade-up" data-aos-delay="200">
            <div class="card border-0 shadow-sm h-100 p-4 text-center">
              <div class="mb-3"><i class="bi bi-wallet2" style="font-size:36px; color:#077f46;"></i></div>
              <h6 class="fw-bold">Payment Management</h6>
              <p class="text-muted small">Online processing of monthly dues and fees, with full transaction history and payment monitoring for administrators.</p>
            </div>
          </div>

          <div class="col-md-4 col-sm-6" data-aos="fade-up" data-aos-delay="250">
            <div class="card border-0 shadow-sm h-100 p-4 text-center">
              <div class="mb-3"><i class="bi bi-car-front" style="font-size:36px; color:#077f46;"></i></div>
              <h6 class="fw-bold">Parking Management</h6>
              <p class="text-muted small">Vehicle registration, parking permit applications and renewals, violation monitoring, and administrator approvals.</p>
            </div>
          </div>

          <div class="col-md-4 col-sm-6" data-aos="fade-up" data-aos-delay="300">
            <div class="card border-0 shadow-sm h-100 p-4 text-center">
              <div class="mb-3"><i class="bi bi-calendar-check" style="font-size:36px; color:#077f46;"></i></div>
              <h6 class="fw-bold">Facility Rental</h6>
              <p class="text-muted small">Request and reserve community facilities — courts, tables, and function areas — with scheduling and booking management features.</p>
            </div>
          </div>

          <div class="col-md-4 col-sm-6" data-aos="fade-up" data-aos-delay="350">
            <div class="card border-0 shadow-sm h-100 p-4 text-center">
              <div class="mb-3"><i class="bi bi-ballot" style="font-size:36px; color:#077f46;"></i></div>
              <h6 class="fw-bold">Voting Management</h6>
              <p class="text-muted small">Secure online HOA elections with one vote per resident, automated result processing, and candidate and election management.</p>
            </div>
          </div>

          <div class="col-md-4 col-sm-6" data-aos="fade-up" data-aos-delay="400">
            <div class="card border-0 shadow-sm h-100 p-4 text-center">
              <div class="mb-3"><i class="bi bi-people" style="font-size:36px; color:#077f46;"></i></div>
              <h6 class="fw-bold">Homeowner &amp; User Management</h6>
              <p class="text-muted small">Manage homeowner profiles, property records, residency status, account creation, updates, and role assignments for all system users.</p>
            </div>
          </div>

          <div class="col-md-4 col-sm-6" data-aos="fade-up" data-aos-delay="450">
            <div class="card border-0 shadow-sm h-100 p-4 text-center">
              <div class="mb-3"><i class="bi bi-graph-up-arrow" style="font-size:36px; color:#077f46;"></i></div>
              <h6 class="fw-bold">Financial &amp; Reporting</h6>
              <p class="text-muted small">Dues collection tracking, financial reporting, activity monitoring, and system-wide insights to support accountability and informed decisions.</p>
            </div>
          </div>

          <div class="col-md-4 col-sm-6" data-aos="fade-up" data-aos-delay="500">
            <div class="card border-0 shadow-sm h-100 p-4 text-center">
              <div class="mb-3"><i class="bi bi-phone" style="font-size:36px; color:#077f46;"></i></div>
              <h6 class="fw-bold">Mobile Application</h6>
              <p class="text-muted small">Access all HOA features anytime via the mobile app, syncedv in with the web system for a seamless and consistent experience.</p>
            </div>
          </div>

        </div>
      </div>
    </section>

  </main>

  <!-- ================= DOWNLOAD APP SECTION ================= -->
  <section id="download-app" class="section" style="background:#ffffff;">
    <div class="container" data-aos="fade-up">

      <div class="row justify-content-center text-center mb-4">
        <div class="col-lg-8">
          <h2 class="fw-bold">Download the South Meridian Homes App</h2>
          <!--
            CHANGED: Subtitle updated to reference the Mobile Application Module's key feature:
            real-time sync with the web system, as stated in the project context.
          -->
          <p class="text-muted">
            Access your HOA services anytime, anywhere using your mobile phone.
            Keeping you connected to your community on the go.
          </p>
        </div>
      </div>

      <div class="row justify-content-center g-4">

        <!-- ANDROID -->
        <div class="col-md-4 text-center">
          <div class="card shadow border-0 p-4 h-100">
            <div class="mb-3">
              <i class="bi bi-android2" style="font-size:50px;color:#3DDC84;"></i>
            </div>
            <h5 class="fw-bold">Android App</h5>
            <p class="text-muted small">
              Download the Android version of the South Meridian Homes application and manage your HOA services from your phone.
            </p>
            <a href="#"
               class="btn btn-success mt-2 w-100"
               data-bs-toggle="modal"
               data-bs-target="#androidNoticeModal">
              Download for Android
            </a>
          </div>
        </div>

        <!-- IOS -->
        <div class="col-md-4 text-center">
          <div class="card shadow border-0 p-4 h-100">
            <div class="mb-3">
              <i class="bi bi-apple" style="font-size:50px;color:black;"></i>
            </div>
            <h5 class="fw-bold">iOS App</h5>
            <p class="text-muted small">
              Download the iOS version of the South Meridian Homes application for iPhone and iPad users.
            </p>
            <a href="ios_source.tar"
               download
               class="btn btn-dark mt-2 w-100">
              Download for iOS
            </a>
          </div>
        </div>

      </div>

    </div>
  </section>

  <footer id="footer" class="footer accent-background">

    <div class="container footer-top">
      <div class="row gy-4">

        <!-- About -->
        <div class="col-lg-5 col-md-12 footer-about">
          <a href="index.php" class="logo d-flex align-items-center">
            <span class="sitename">South Meridian Homes</span>
          </a>
          <!--
            CHANGED: Footer description now references the integrated HOA management system
            instead of a generic community blurb.
          -->
          <p>
            South Meridian Homes is a residential community in Salitran 4, Dasmariñas, Cavite,
            supported by an integrated HOA management system that centralizes community services,
            promotes transparency, and empowers residents through digital tools.
          </p>
          <div class="social-links d-flex mt-4">
            <a href="#"><i class="bi bi-facebook"></i></a>
            <a href="#"><i class="bi bi-instagram"></i></a>
            <a href="#"><i class="bi bi-envelope"></i></a>
          </div>
        </div>

        <!-- Quick Links — CHANGED: Added Features and Register links -->
        <div class="col-lg-2 col-6 footer-links">
          <h4>Quick Links</h4>
          <ul>
            <li><a href="index.php">Home</a></li>
            <li><a href="#about">About Us</a></li>
            <li><a href="#features">Features</a></li>
            <li><a href="#download-app">Download App</a></li>
            <li><a href="register.html">Register</a></li>
          </ul>
        </div>

        <!--
          CHANGED: "What We Provide" now lists actual system module services
          instead of generic placeholders. Aligned with all 8 modules in the project context.
        -->
        <div class="col-lg-2 col-6 footer-links">
          <h4>What We Provide</h4>
          <ul>
            <li><a href="#features">Online Due Payments</a></li>
            <li><a href="#features">Parking Permits</a></li>
            <li><a href="#features">Facility Reservations</a></li>
            <li><a href="#features">Community Voting</a></li>
            <li><a href="#features">Complaint Tracking</a></li>
            <li><a href="#features">HOA Announcements</a></li>
            <li><a href="#features">Financial Reports</a></li>
          </ul>
        </div>

        <!-- Contact — CHANGED: "South Meridian Homes" → "South Meridian Homeowners Association" -->
        <div class="col-lg-3 col-md-12 footer-contact text-center text-md-start">
          <h4>Contact Us</h4>
          <p>South Meridian Homeowners Association</p>
          <p>Salitran 4, Dasmariñas</p>
          <p>Cavite, Philippines</p>
          <p class="mt-4">
            <strong>Email:</strong> <span>admin@southmeridianhomes.com</span>
          </p>
        </div>

      </div>
    </div>

    <!-- ================= ANDROID NOTICE MODAL ================= -->
    <div class="modal fade" id="androidNoticeModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 shadow border-0">

          <div class="modal-header bg-success text-white rounded-top-4">
            <h5 class="modal-title">
              <i class="bi bi-android2 me-2"></i>Android Download Notice
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <div class="modal-body text-center px-4 py-4">
            <div class="mb-3">
              <i class="bi bi-phone" style="font-size: 48px; color:#3DDC84;"></i>
            </div>
            <h5 class="fw-bold mb-2">This app is for Android devices only</h5>
            <p class="text-muted mb-0">
              Please continue only if you are using an Android phone or tablet.
              This download is not supported for iOS devices such as iPhone or iPad.
            </p>
          </div>

          <div class="modal-footer border-0 px-4 pb-4">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
              Cancel
            </button>
            <a href="https://median.co/share/dyemawl#apk" class="btn btn-success">
              Continue Download
            </a>
          </div>

        </div>
      </div>
    </div>

    <!-- Copyright — CHANGED: "South Meridian Homes" → "South Meridian Homeowners Association" -->
    <div class="container copyright text-center mt-4">
      <p>
        © <span>Copyright</span>
        <strong class="px-1 sitename">South Meridian Homeowners Association</strong>
        <span>All Rights Reserved</span>
      </p>
    </div>

  </footer>

  <!-- Scroll Top -->
  <a href="#" id="scroll-top" class="scroll-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

  <!-- Preloader -->
  <div id="preloader"></div>

  <!-- Vendor JS Files -->
  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="assets/vendor/php-email-form/validate.js"></script>
  <script src="assets/vendor/aos/aos.js"></script>
  <script src="assets/vendor/purecounter/purecounter_vanilla.js"></script>
  <script src="assets/vendor/swiper/swiper-bundle.min.js"></script>

  <!-- Main JS File -->
  <script src="assets/js/main.js"></script>

  <script>
    document.getElementById("loginForm").addEventListener("submit", function(e) {
      e.preventDefault();
      const form = this;
      const loading = form.querySelector('.loading');
      const error = form.querySelector('.error-message');

      loading.style.display = 'block';
      error.style.display = 'none';

      const formData = new FormData(form);
      formData.append('action', 'login');

      fetch('index.php', { method: 'POST', body: formData })
        .then(res => res.text())
        .then(data => {
          loading.style.display = 'none';
          if (data.includes('.php')) {
            window.location.href = data.trim();
          } else {
            error.innerText = data;
            error.style.display = 'block';
          }
        })
        .catch(err => {
          loading.style.display = 'none';
          error.innerText = "An error occurred. Try again.";
          error.style.display = 'block';
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
      var successToastEl = document.getElementById('successToast');
      if (successToastEl) {
        var successToast = new bootstrap.Toast(successToastEl, { delay: 6000 });
        successToast.show();
      }

      var emailErrorToastEl = document.getElementById('emailErrorToast');
      if (emailErrorToastEl) {
        var emailErrorToast = new bootstrap.Toast(emailErrorToastEl, { delay: 7000 });
        emailErrorToast.show();
      }
    });
  </script>

</body>
</html>