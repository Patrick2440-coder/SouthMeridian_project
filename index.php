<?php
session_start();

// ===================== DB CONNECTION =====================
$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

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
        $_SESSION['user_id'], $_SESSION['role'], $_SESSION['phase']
    );

    // 1) Try admins first
    $stmt = $conn->prepare("SELECT id, email, password, role, phase FROM admins WHERE email=? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($admin) {
        // Admin passwords are currently plain text (keep as-is for now)
        if ($password === $admin['password']) {

            // ✅ ADMIN SESSION (separate keys)
            $_SESSION['admin_id']    = (int)$admin['id'];
            $_SESSION['admin_role']  = (string)$admin['role'];   // admin / superadmin
            $_SESSION['admin_phase'] = (string)$admin['phase'];  // Phase 1/2/3/Superadmin

            // (optional generic keys for easy checks elsewhere)
            $_SESSION['role']  = $_SESSION['admin_role'];
            $_SESSION['phase'] = $_SESSION['admin_phase'];
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

    if (!$home) {
        echo "Email not found";
        exit;
    }

    // must be approved
    if ($home['status'] !== 'approved') {
        echo "Your account is not approved yet.";
        exit;
    }

    // homeowners password is hashed
    if (!password_verify($password, $home['password'])) {
        echo "Incorrect password";
        exit;
    }

    // ✅ HOMEOWNER SESSION (separate keys)
    $_SESSION['homeowner_id']    = (int)$home['id'];
    $_SESSION['homeowner_role']  = 'homeowner';
    $_SESSION['homeowner_phase'] = (string)$home['phase'];

    // (optional generic keys for easy checks elsewhere)
    $_SESSION['role']  = 'homeowner';
    $_SESSION['phase'] = $_SESSION['homeowner_phase'];

    echo "homeowner/homeowner_dashboard.php";
    exit;
}
?>



<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title>South Meridian Homes</title>
  <meta name="description" content="">
  <meta name="keywords" content="">

  <!-- Favicons -->
  <link href="assets/img/favicon.png" rel="icon">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


  <!-- Main CSS File -->
  <link href="assets/css/main.css" rel="stylesheet">

  <!-- =======================================================
  * Template Name: HomeSpace
  * Template URL: https://bootstrapmade.com/homespace-bootstrap-real-estate-template/
  * Updated: Jul 05 2025 with Bootstrap v5.3.7
  * Author: BootstrapMade.com
  * License: https://bootstrapmade.com/license/
  ======================================================== -->
</head>

<body class="index-page">

  <header id="header" class="header d-flex align-items-center sticky-top">
    <div class="container-fluid container-xl position-relative d-flex align-items-center justify-content-between">

      <a href="index.php" class="logo d-flex align-items-center">
        <!-- Uncomment the line below if you also wish to use an image logo -->
        <!-- <img src="assets/img/logo.webp" alt=""> -->
        <svg class="my-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <g id="bgCarrier" stroke-width="0"></g>
          <g id="tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g>
          <g id="iconCarrier">
            <path d="M22 22L2 22" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path>
            <path d="M2 11L6.06296 7.74968M22 11L13.8741 4.49931C12.7784 3.62279 11.2216 3.62279 10.1259 4.49931L9.34398 5.12486" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path>
            <path d="M15.5 5.5V3.5C15.5 3.22386 15.7239 3 16 3H18.5C18.7761 3 19 3.22386 19 3.5V8.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path>
            <path d="M4 22V9.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path>
            <path d="M20 9.5V13.5M20 22V17.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path>
            <path d="M15 22V17C15 15.5858 15 14.8787 14.5607 14.4393C14.1213 14 13.4142 14 12 14C10.5858 14 9.87868 14 9.43934 14.4393M9 22V17" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
            <path d="M14 9.5C14 10.6046 13.1046 11.5 12 11.5C10.8954 11.5 10 10.6046 10 9.5C10 8.39543 10.8954 7.5 12 7.5C13.1046 7.5 14 8.39543 14 9.5Z" stroke="currentColor" stroke-width="1.5"></path>
          </g>
        </svg>
        <h1 class="sitename">South Meridian Homes</h1>
      </a>

      <nav id="navmenu" class="navmenu">
        <ul>
          <li><a href="index.php" class="active">Home</a></li>
          <li><a href="#about">About</a></li>
          <li><a href="announcements">Announcements</a></li>

           <a href="#" data-bs-toggle="modal" data-bs-target="#loginModal" style="color: #077f46;background-color: white;border-radius: 50px;width: 100px;height: 50px;">
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

            <img src="#" alt="Logo" class="mb-3" style="max-width:90px;">
            <h6 class="fw-bold mb-3 text-success">Homeowners Login</h6>

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
                <a href="#" class="text-success text-decoration-none">Forgot password?</a>
              </div>
              <span class="text-muted small d-block my-2">Don’t have an account? <a href="register.html" class="text-success text-decoration-none">Create Account</a></span>
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
             <p>South Meridian Homes is a modern community platform designed to make homeowners’ association processes simple, transparent, and efficient. Our system streamlines essential HOA activities such as community voting, parking management, and homeowner documentation—bringing everything you need into one secure place.</p>
                </div>

          
                <div class="achievement-grid" data-aos="fade-up" data-aos-delay="400">
                  <div class="achievement-item">
                    <div class="achievement-number">
                      <span data-purecounter-start="0" data-purecounter-end="1250" data-purecounter-duration="1" class="purecounter"></span>+
                    </div>
                    <span class="achievement-text">Active Communities</span>
                  </div>
                  <div class="achievement-item">
                    <div class="achievement-number">
                      <span data-purecounter-start="0" data-purecounter-end="89" data-purecounter-duration="1" class="purecounter"></span>+
                    </div>
                    <span class="achievement-text">Active Home Owners Association</span>
                  </div>
                  <div class="achievement-item">
                    <div class="achievement-number">
                      <span data-purecounter-start="0" data-purecounter-end="96" data-purecounter-duration="1" class="purecounter"></span>%
                    </div>
                    <span class="achievement-text">Active Home Owner</span>
                  </div>
                </div>
              </div>
            </div><!-- End Hero Content -->

            <div class="col-lg-5">
              <div class="hero-visual" data-aos="fade-left" data-aos-delay="400">
                <div class="visual-container">
                  <div class="featured-property">
                    <img src="assets/img/real-estate/property-exterior-8.webp" alt="Featured Property" class="img-fluid">
                    <div class="property-info">
                 
                    </div>
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
            </div><!-- End Hero Visual -->

          </div>
        </div>

      </div>

    </section><!-- /Hero Section -->

    <!-- Home About Section -->
    <section id="home-about" class="home-about section" id="about_this">

      <div class="container" data-aos="fade-up" data-aos-delay="100">

        <div class="row gy-5">

          <div class="col-lg-5" data-aos="zoom-in" data-aos-delay="200">
            <div class="image-gallery">
              <div class="primary-image">
                <img src="assets/img/real-estate/property-exterior-1.webp" alt="Modern Property" class="img-fluid">
             
              </div>
              <div class="secondary-image">
                <img src="assets/img/real-estate/property-interior-4.webp" alt="Luxury Interior" class="img-fluid">
              </div>
            </div>
          </div>

          <div class="col-lg-7" data-aos="fade-left" data-aos-delay="300">
            <div class="content">
              <div class="section-header">
                <span class="section-label">About South Meridian Homes</span>
                <h2>Building Communities, One Home at a Time</h2>
              </div>

             <p>South Meridian Homes is a growing residential community located in Salitran 4, Dasmariñas, Cavite, built to provide a safe, organized, and comfortable living environment for its homeowners and residents. The community is guided by a homeowners association committed to maintaining order, transparency, and active participation among residents.</p>
             
           <div class="achievements-list">

  <div class="achievement-item">
    <div class="achievement-icon">
      <i class="bi bi-check2-square"></i>
    </div>
    <div class="achievement-content">
      <h4>Community Voting System</h4>
      <p>Secure and transparent voting for HOA decisions and community matters</p>
    </div>
  </div>

  <div class="achievement-item">
    <div class="achievement-icon">
      <i class="bi bi-car-front"></i>
    </div>
    <div class="achievement-content">
      <h4>Parking Management</h4>
      <p>Organized parking registration, monitoring, and resident coordination</p>
    </div>
  </div>

  <div class="achievement-item">
    <div class="achievement-icon">
      <i class="bi bi-file-earmark-text"></i>
    </div>
    <div class="achievement-content">
      <h4>HOA Paperwork & Records</h4>
      <p>Easy access to official forms, announcements, and homeowner documents</p>
    </div>
  </div>

</div>


         
            </div>
          </div>

        </div>

      </div>

    </section><!-- /Home About Section -->


 
  

  </main>
<footer id="footer" class="footer accent-background">

  <div class="container footer-top">
    <div class="row gy-4">

      <!-- About -->
      <div class="col-lg-5 col-md-12 footer-about">
        <a href="index.html" class="logo d-flex align-items-center">
          <span class="sitename">South Meridian Homes</span>
        </a>
        <p>
          South Meridian Homes is a residential community in Salitran 4, Dasmariñas, 
          dedicated to organized living through transparent HOA services, community 
          participation, and modern management solutions.
        </p>
        <div class="social-links d-flex mt-4">
          <a href="#"><i class="bi bi-facebook"></i></a>
          <a href="#"><i class="bi bi-instagram"></i></a>
          <a href="#"><i class="bi bi-envelope"></i></a>
        </div>
      </div>

      <!-- Useful Links -->
      <div class="col-lg-2 col-6 footer-links">
        <h4>Quick Links</h4>
        <ul>
          <li><a href="index.html">Home</a></li>
          <li><a href="about.html">About Us</a></li>
          <li><a href="services.html">Services</a></li>
          <li><a href="announcements.html">Announcements</a></li>
        
        </ul>
      </div>

      <!-- Services -->
      <div class="col-lg-2 col-6 footer-links">
        <h4>What We Provide</h4>
        <ul>
          <li><a href="#">Community Voting</a></li>
          <li><a href="#">Parking Management</a></li>
          <li><a href="#">HOA Documents</a></li>
          <li><a href="#">Resident Records</a></li>
          <li><a href="#">Community Notices</a></li>
        </ul>
      </div>

      <!-- Contact -->
      <div class="col-lg-3 col-md-12 footer-contact text-center text-md-start">
        <h4>Contact Us</h4>
        <p>South Meridian Homes</p>
        <p>Salitran 4, Dasmariñas</p>
        <p>Cavite, Philippines</p>
        <p class="mt-4">
          <strong>Email:</strong> <span>admin@southmeridianhomes.com</span>
        </p>
      </div>

    </div>
  </div>

  <!-- Copyright -->
  <div class="container copyright text-center mt-4">
    <p>
      © <span>Copyright</span>
      <strong class="px-1 sitename">South Meridian Homes</strong>
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
          if(data.includes('.php')){
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
  </script>

</body>

</html>