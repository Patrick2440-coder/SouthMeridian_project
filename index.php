<?php
session_start();

// ===================== DB CONNECTION =====================
require_once 'config/database.php';
require_once 'login_security_helper.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function esc($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function login_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function login_admin_password_ok(string $entered, string $stored): bool
{
    $info = password_get_info($stored);

    if (!empty($info['algo'])) {
        return password_verify(
            $entered,
            $stored
        );
    }

    return hash_equals(
        $stored,
        $entered
    );
}

// ===================== AJAX LOGIN PROCESS =====================
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $email =
        strtolower(
            trim(
                (string)($_POST['email'] ?? '')
            )
        );

    $password =
        (string)($_POST['password'] ?? '');

    if (
        $email === '' ||
        $password === ''
    ) {
        login_json([
            'success' => false,
            'code' => 'validation',
            'message' =>
                'Email and password are required.'
        ], 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        login_json([
            'success' => false,
            'code' => 'validation',
            'message' =>
                'Please enter a valid email address.'
        ], 422);
    }

    // ----------------- CLEAR PREVIOUS LOGIN KEYS -----------------
    unset(
        $_SESSION['admin_id'],
        $_SESSION['admin_role'],
        $_SESSION['admin_phase'],
        $_SESSION['homeowner_id'],
        $_SESSION['homeowner_role'],
        $_SESSION['homeowner_phase'],
        $_SESSION['tenant_id'],
        $_SESSION['tenant_homeowner_id'],
        $_SESSION['tenant_role'],
        $_SESSION['tenant_phase'],
        $_SESSION['user_id'],
        $_SESSION['role'],
        $_SESSION['phase']
    );

    $account = null;

    // 1) Admins first - same priority as the original login flow.
    $stmt = $conn->prepare("
        SELECT
            id,
            email,
            full_name,
            password,
            role,
            phase,
            position
        FROM admins
        WHERE email = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        's',
        $email
    );

    $stmt->execute();

    $admin =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    if ($admin) {
        $account = [
            'type' => 'admin',
            'id' => (int)$admin['id'],
            'email' => (string)$admin['email'],
            'name' =>
                trim(
                    (string)($admin['full_name'] ?? '')
                ) ?: 'Administrator',
            'phase' => (string)($admin['phase'] ?? ''),
            'record' => $admin
        ];
    }

    // 2) Homeowners
    if (!$account) {
        $stmt = $conn->prepare("
            SELECT
                id,
                email,
                first_name,
                last_name,
                password,
                status,
                phase,
                IFNULL(
                    must_change_password,
                    1
                ) AS must_change_password
            FROM homeowners
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            's',
            $email
        );

        $stmt->execute();

        $home =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if ($home) {
            $account = [
                'type' => 'homeowner',
                'id' => (int)$home['id'],
                'email' => (string)$home['email'],
                'name' =>
                    trim(
                        (string)($home['first_name'] ?? '') .
                        ' ' .
                        (string)($home['last_name'] ?? '')
                    ),
                'phase' => (string)($home['phase'] ?? ''),
                'record' => $home
            ];
        }
    }

    // 3) Tenants
    if (!$account) {
        $stmt = $conn->prepare("
            SELECT
                id,
                homeowner_id,
                email,
                first_name,
                last_name,
                password,
                status,
                phase
            FROM tenants
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            's',
            $email
        );

        $stmt->execute();

        $tenant =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if ($tenant) {
            $account = [
                'type' => 'tenant',
                'id' => (int)$tenant['id'],
                'email' => (string)$tenant['email'],
                'name' =>
                    trim(
                        (string)($tenant['first_name'] ?? '') .
                        ' ' .
                        (string)($tenant['last_name'] ?? '')
                    ),
                'phase' => (string)($tenant['phase'] ?? ''),
                'record' => $tenant
            ];
        }
    }

    if (!$account) {
        login_json([
            'success' => false,
            'code' => 'not_found',
            'message' =>
                'Email not found.'
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Check existing cooldown / hard lock BEFORE password verification.
    |--------------------------------------------------------------------------
    */
    try {
        $block =
            security_current_block(
                $conn,
                $account
            );
    } catch (\mysqli_sql_exception $e) {
        error_log(
            'Login security tables missing/error: ' .
            $e->getMessage()
        );

        login_json([
            'success' => false,
            'code' => 'security_setup_required',
            'message' =>
                'Login security is not initialized yet. Run login_security_tables.sql in phpMyAdmin.'
        ], 500);
    }

    if (!empty($block['blocked'])) {
        login_json([
            'success' => false,
            'code' =>
                (string)($block['code'] ?? 'blocked'),
            'seconds' =>
                (int)($block['seconds'] ?? 0),
            'message' =>
                (string)($block['message'] ?? 'Login temporarily unavailable.')
        ], 423);
    }

    /*
    |--------------------------------------------------------------------------
    | Account-status checks
    |--------------------------------------------------------------------------
    */
    if (
        $account['type'] === 'homeowner' &&
        (string)$account['record']['status'] !== 'approved'
    ) {
        login_json([
            'success' => false,
            'code' => 'not_approved',
            'message' =>
                'Your account is not approved yet.'
        ], 403);
    }

    if (
        $account['type'] === 'tenant' &&
        (string)$account['record']['status'] !== 'active'
    ) {
        login_json([
            'success' => false,
            'code' => 'inactive',
            'message' =>
                'Your tenant account is inactive.'
        ], 403);
    }

    /*
    |--------------------------------------------------------------------------
    | Verify password
    |--------------------------------------------------------------------------
    */
    $passwordOk = false;

    if ($account['type'] === 'admin') {
        $passwordOk =
            login_admin_password_ok(
                $password,
                (string)$account['record']['password']
            );
    } else {
        $passwordOk =
            password_verify(
                $password,
                (string)$account['record']['password']
            );
    }

    if (!$passwordOk) {
        try {
            $failure =
                security_register_failure(
                    $conn,
                    $account
                );
        } catch (\mysqli_sql_exception $e) {
            error_log(
                'Login security failure error: ' .
                $e->getMessage()
            );

            login_json([
                'success' => false,
                'code' => 'security_error',
                'message' =>
                    'Unable to process login security. Please try again.'
            ], 500);
        }

        login_json([
            'success' => false,
            'code' =>
                (string)($failure['code'] ?? 'incorrect'),
            'seconds' =>
                (int)($failure['seconds'] ?? 0),
            'remaining_attempts' =>
                (int)($failure['remaining_attempts'] ?? 0),
            'mail_sent' =>
                (bool)($failure['mail_sent'] ?? false),
            'appeal_url' =>
                (string)($failure['appeal_url'] ?? ''),
            'message' =>
                (string)($failure['message'] ?? 'Incorrect password.')
        ], 401);
    }

    /*
    |--------------------------------------------------------------------------
    | Successful password resets the failed-attempt cycle
    |--------------------------------------------------------------------------
    */
    security_reset_after_success(
        $conn,
        $account
    );

    session_regenerate_id(true);

    if ($account['type'] === 'admin') {
        $admin = $account['record'];

        $_SESSION['admin_id'] =
            (int)$admin['id'];

        $_SESSION['admin_role'] =
            (string)$admin['role'];

        $_SESSION['admin_phase'] =
            (string)$admin['phase'];

        $_SESSION['role'] =
            $_SESSION['admin_role'];

        $_SESSION['phase'] =
            $_SESSION['admin_phase'];

        $_SESSION['user_id'] =
            $_SESSION['admin_id'];

        if (!empty($admin['position'])) {
            $_SESSION['position'] =
                (string)$admin['position'];
        }

        login_json([
            'success' => true,
            'redirect' =>
                $_SESSION['admin_role'] === 'superadmin'
                    ? 'superadmin/dashboard.php'
                    : 'admin/dashboard.php'
        ]);
    }

    if ($account['type'] === 'homeowner') {
        $home = $account['record'];

        $_SESSION['homeowner_id'] =
            (int)$home['id'];

        $_SESSION['homeowner_role'] =
            'homeowner';

        $_SESSION['homeowner_phase'] =
            (string)$home['phase'];

        $_SESSION['role'] =
            'homeowner';

        $_SESSION['phase'] =
            $_SESSION['homeowner_phase'];

        $_SESSION['user_id'] =
            $_SESSION['homeowner_id'];

        login_json([
            'success' => true,
            'redirect' =>
                'homeowner/homeowner_dashboard.php'
        ]);
    }

    $tenant = $account['record'];

    $_SESSION['tenant_id'] =
        (int)$tenant['id'];

    $_SESSION['tenant_homeowner_id'] =
        (int)$tenant['homeowner_id'];

    $_SESSION['tenant_role'] =
        'tenant';

    $_SESSION['tenant_phase'] =
        (string)$tenant['phase'];

    $_SESSION['role'] =
        'tenant';

    $_SESSION['phase'] =
        $_SESSION['tenant_phase'];

    $_SESSION['user_id'] =
        $_SESSION['tenant_id'];

    login_json([
        'success' => true,
        'redirect' =>
            'homeowner/homeowner_dashboard.php'
    ]);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">

  <title>South Meridian Homes</title>
  <meta name="description" content="South Meridian Homeowners Association – Your secure, modern, and efficient HOA management platform.">
  <meta name="keywords" content="South Meridian Homes, HOA, Homeowners Association, Dasmariñas, Cavite">

  <link href="assets/img/sm_logo.png" rel="icon">
  <link href="assets/img/apple-touch-icon.png" rel="apple-touch-icon">

  <link href="https://fonts.googleapis.com" rel="preconnect">
  <link href="https://fonts.gstatic.com" rel="preconnect" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&family=Montserrat:wght@500;600;700;800&family=Raleway:wght@600;700;800&display=swap" rel="stylesheet">

  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/vendor/aos/aos.css" rel="stylesheet">
  <link href="assets/vendor/swiper/swiper-bundle.min.css" rel="stylesheet">

  <link href="assets/css/main.css" rel="stylesheet">

  <!-- LANDING PAGE THEME: use the same hoa-theme preference as the rest of the system -->
  <script>
  (function () {
    try {
      const savedTheme = localStorage.getItem('hoa-theme');
      const dark =
        savedTheme === 'dark' ||
        (
          !savedTheme &&
          window.matchMedia &&
          window.matchMedia('(prefers-color-scheme: dark)').matches
        );

      document.documentElement.classList.toggle('dark', dark);
    } catch (e) {}
  })();
  </script>

  <!-- Landing-page-only refinements. main.css is NOT modified. -->
  <style>
    :root{
      --smh-green:#077f46;
      --smh-green-dark:#056437;
      --smh-deep:#0d3d2a;
      --smh-soft:#f3f8f5;
      --smh-line:#e4ece7;
      --smh-text:#294438;
      --smh-muted:#6f7f76;
    }

    body.index-page{
      background:#fff;
    }

    /* Header */
    #header{
      padding:10px 0;
      background:var(--smh-green);
      border-bottom:1px solid rgba(255,255,255,.12);
      box-shadow:0 8px 28px rgba(0,0,0,.06);
    }

    #header .logo{
      gap:10px;
    }

    #header .logo img{
      height:58px !important;
      width:58px;
      object-fit:contain;
      margin:0;
    }

    #header .sitename{
      font-size:24px;
      font-weight:800;
      letter-spacing:-.5px;
      margin:0;
      color:#fff;
    }

    @media(min-width:1200px){
      #navmenu ul{
        gap:3px;
      }

      #navmenu a,
      #navmenu a:focus{
        color:rgba(255,255,255,.78);
        padding:11px 14px;
        font-size:14px;
        font-weight:700;
        border-radius:10px;
      }

      #navmenu a:hover,
      #navmenu .active{
        color:#fff;
        background:rgba(255,255,255,.10);
      }

      #navmenu .landing-login{
        margin-left:8px;
        background:#fff !important;
        color:var(--smh-green) !important;
        border-radius:50px;
        padding:11px 20px !important;
        min-width:105px;
        justify-content:center;
        box-shadow:0 8px 20px rgba(0,0,0,.08);
      }

      #navmenu .landing-login:hover{
        background:#f4faf6 !important;
        transform:translateY(-1px);
      }
    }

    /* Hero */
    #hero{
      min-height:auto;
      padding:78px 0 86px;
      background:
        radial-gradient(circle at 85% 20%, rgba(7,127,70,.10), transparent 28%),
        linear-gradient(135deg,#ffffff 0%,#f7fbf8 55%,#edf6f1 100%);
    }

    #hero::before{
      display:none;
    }

    .landing-hero-copy{
      max-width:650px;
    }

    .landing-eyebrow{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:8px 13px;
      margin-bottom:18px;
      color:var(--smh-green);
      background:#eaf5ee;
      border:1px solid #dbece2;
      border-radius:50px;
      font-size:12px;
      font-weight:800;
      text-transform:uppercase;
      letter-spacing:.08em;
    }

    .landing-eyebrow i{
      font-size:13px;
    }

    .landing-hero-title{
      font-family:"Raleway",sans-serif;
      color:#123c2a;
      font-size:clamp(2.8rem,5vw,4.5rem);
      line-height:1.02;
      letter-spacing:-.055em;
      font-weight:800;
      margin:0 0 22px;
    }

    .landing-hero-title span{
      color:var(--smh-green);
    }

    .landing-hero-text{
      color:#64766c;
      font-size:1.05rem;
      line-height:1.8;
      max-width:620px;
      margin-bottom:28px;
    }

    .landing-hero-actions{
      display:flex;
      flex-wrap:wrap;
      gap:12px;
      margin-bottom:34px;
    }

    .landing-primary-btn,
    .landing-secondary-btn{
      min-height:50px;
      padding:0 20px;
      border-radius:12px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:9px;
      font-size:14px;
      font-weight:700;
      transition:.25s ease;
    }

    .landing-primary-btn{
      color:#fff;
      background:var(--smh-green);
      border:1px solid var(--smh-green);
      box-shadow:0 12px 28px rgba(7,127,70,.18);
    }

    .landing-primary-btn:hover{
      color:#fff;
      background:var(--smh-green-dark);
      transform:translateY(-2px);
    }

    .landing-secondary-btn{
      color:var(--smh-green);
      background:#fff;
      border:1px solid #dbe8e0;
    }

    .landing-secondary-btn:hover{
      color:var(--smh-green-dark);
      border-color:#b7d8c4;
      transform:translateY(-2px);
    }

    .landing-stats{
      display:grid;
      grid-template-columns:repeat(3,1fr);
      max-width:620px;
      background:#fff;
      border:1px solid var(--smh-line);
      border-radius:16px;
      box-shadow:0 16px 35px rgba(25,63,43,.06);
      overflow:hidden;
    }

    .landing-stat{
      padding:18px 20px;
      text-align:left;
      border-right:1px solid var(--smh-line);
    }

    .landing-stat:last-child{
      border-right:0;
    }

    .landing-stat strong{
      display:block;
      color:var(--smh-green);
      font-size:27px;
      line-height:1;
      font-weight:800;
      margin-bottom:6px;
    }

    .landing-stat span{
      color:#78887f;
      font-size:11px;
      font-weight:700;
    }

    .landing-hero-image{
      position:relative;
      max-width:520px;
      margin-left:auto;
    }

    .landing-hero-image-main{
      position:relative;
      height:540px;
      overflow:hidden;
      border-radius:28px;
      border:6px solid #fff;
      box-shadow:0 28px 65px rgba(25,63,43,.16);
    }

    .landing-hero-image-main img{
      width:100%;
      height:100%;
      object-fit:cover;
    }

    .landing-community-card{
      position:absolute;
      left:-34px;
      bottom:34px;
      min-width:255px;
      display:flex;
      align-items:center;
      gap:12px;
      padding:14px 16px;
      background:rgba(255,255,255,.96);
      border:1px solid #e6eee9;
      border-radius:16px;
      box-shadow:0 18px 42px rgba(20,58,39,.14);
    }

    .landing-community-card img{
      width:48px;
      height:48px;
      object-fit:contain;
    }

    .landing-community-card span,
    .landing-community-card strong{
      display:block;
    }

    .landing-community-card span{
      color:#7b8c83;
      font-size:10px;
      font-weight:700;
      text-transform:uppercase;
      letter-spacing:.07em;
    }

    .landing-community-card strong{
      margin-top:3px;
      color:#173d2b;
      font-size:12px;
      line-height:1.35;
    }

    /* Section heading */
    .landing-section-label{
      display:inline-block;
      color:var(--smh-green);
      font-size:12px;
      font-weight:800;
      text-transform:uppercase;
      letter-spacing:.09em;
      margin-bottom:10px;
    }

    .landing-section-title{
      color:#163d2b;
      font-size:clamp(2rem,3.6vw,3rem);
      font-weight:800;
      letter-spacing:-.035em;
      margin-bottom:16px;
    }

    .landing-section-text{
      color:#6d7d74;
      line-height:1.75;
    }

    /* About */
    #about{
      padding:88px 0;
      background:#fff;
    }

    .landing-about-image{
      position:relative;
      height:500px;
      overflow:hidden;
      border-radius:24px;
      box-shadow:0 24px 55px rgba(22,62,41,.12);
    }

    .landing-about-image img{
      width:100%;
      height:100%;
      object-fit:cover;
    }

    .landing-about-note{
      position:absolute;
      left:22px;
      bottom:22px;
      right:22px;
      padding:15px 17px;
      display:flex;
      align-items:center;
      gap:12px;
      color:#fff;
      background:rgba(11,73,46,.90);
      border-radius:14px;
      backdrop-filter:blur(8px);
    }

    .landing-about-note i{
      width:38px;
      height:38px;
      display:grid;
      place-items:center;
      flex:0 0 38px;
      border-radius:10px;
      background:rgba(255,255,255,.12);
      color:#fff;
    }

    .landing-about-note strong{
      display:block;
      font-size:13px;
    }

    .landing-about-note span{
      display:block;
      margin-top:2px;
      color:rgba(255,255,255,.70);
      font-size:11px;
    }

    .landing-about-content{
      padding-left:28px;
    }

    .landing-about-points{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:14px;
      margin-top:28px;
    }

    .landing-about-point{
      display:flex;
      gap:11px;
      padding:15px;
      border:1px solid var(--smh-line);
      border-radius:14px;
      background:#fbfdfc;
    }

    .landing-about-point i{
      width:36px;
      height:36px;
      display:grid;
      place-items:center;
      flex:0 0 36px;
      color:var(--smh-green);
      background:#eaf5ee;
      border-radius:10px;
    }

    .landing-about-point strong{
      display:block;
      color:#254536;
      font-size:13px;
      margin-bottom:3px;
    }

    .landing-about-point span{
      display:block;
      color:#7d8c84;
      font-size:11px;
      line-height:1.5;
    }

    /* Features */
    #features{
      padding:88px 0;
      background:#f6f9f7 !important;
    }

    .landing-feature-card{
      height:100%;
      min-height:245px;
      padding:24px;
      background:#fff;
      border:1px solid #e3ebe6;
      border-radius:18px;
      transition:.25s ease;
    }

    .landing-feature-card:hover{
      transform:translateY(-5px);
      border-color:#c7dfd1;
      box-shadow:0 18px 38px rgba(24,64,42,.08);
    }

    .landing-feature-icon{
      width:48px;
      height:48px;
      display:grid;
      place-items:center;
      margin-bottom:18px;
      border-radius:13px;
      color:var(--smh-green);
      background:#eaf5ee;
      font-size:20px;
    }

    .landing-feature-card h5{
      color:#173f2c;
      font-weight:800;
      font-size:16px;
      margin-bottom:10px;
    }

    .landing-feature-card p{
      color:#7a8a81;
      font-size:12px;
      line-height:1.7;
      margin:0;
    }

    /* App */
    #download-app{
      padding:88px 0;
      background:#fff !important;
    }

    .landing-app-wrap{
      padding:44px;
      border-radius:24px;
      background:linear-gradient(135deg,#0c422c 0%,#077f46 100%);
      box-shadow:0 25px 60px rgba(9,78,48,.16);
    }

    .landing-app-copy h2{
      color:#fff;
      font-size:clamp(2rem,3.3vw,2.8rem);
      font-weight:800;
      letter-spacing:-.035em;
      margin-bottom:14px;
    }

    .landing-app-copy p{
      color:rgba(255,255,255,.72);
      line-height:1.75;
      max-width:560px;
    }

    .landing-app-benefits{
      display:flex;
      flex-wrap:wrap;
      gap:12px 18px;
      margin:22px 0 28px;
    }

    .landing-app-benefits span{
      color:rgba(255,255,255,.82);
      font-size:12px;
      font-weight:600;
    }

    .landing-app-benefits i{
      color:#a9d18f;
      margin-right:5px;
    }

    .landing-download-buttons{
      display:flex;
      flex-wrap:wrap;
      gap:12px;
    }

    .landing-download-btn{
      min-width:170px;
      padding:12px 16px;
      display:flex;
      align-items:center;
      gap:10px;
      border-radius:12px;
      background:#fff;
      color:#173d2b;
      transition:.25s ease;
    }

    .landing-download-btn:hover{
      color:#173d2b;
      transform:translateY(-2px);
    }

    .landing-download-btn i{
      font-size:27px;
    }

    .landing-download-btn small,
    .landing-download-btn strong{
      display:block;
      line-height:1.1;
    }

    .landing-download-btn small{
      color:#819087;
      font-size:9px;
    }

    .landing-download-btn strong{
      margin-top:3px;
      font-size:13px;
    }

    .landing-app-logo-box{
      width:250px;
      height:250px;
      margin:0 auto;
      display:grid;
      place-items:center;
      border-radius:50%;
      background:rgba(255,255,255,.08);
      border:1px solid rgba(255,255,255,.12);
    }

    .landing-app-logo-box img{
      width:190px;
      height:190px;
      object-fit:contain;
      filter:drop-shadow(0 14px 24px rgba(0,0,0,.16));
    }

    /* Footer */
    #footer{
      background:#087a45;
    }

    #footer .footer-top{
      padding-top:55px;
      border-top:0;
    }

    #footer h4,
    #footer .sitename{
      color:#fff;
    }

    #footer p,
    #footer .footer-links a{
      color:rgba(255,255,255,.74);
    }

    #footer .footer-links a:hover{
      color:#fff;
    }

    #footer .copyright{
      background:rgba(0,0,0,.08);
    }

    /* Login modal */
    #loginModal .modal-content{
      overflow:hidden;
      border:0;
      border-radius:22px !important;
      box-shadow:0 28px 75px rgba(16,52,34,.22);
    }

    #loginModal .modal-header{
      background:var(--smh-green) !important;
      padding:18px 22px;
    }

    #loginModal .modal-body{
      padding:30px !important;
    }

    #loginModal .modal-body img{
      max-width:80px !important;
    }

    #loginModal .form-control{
      border-radius:12px;
      border-color:#dfe8e3;
    }

    #loginModal .form-control:focus{
      border-color:var(--smh-green);
      box-shadow:0 0 0 .2rem rgba(7,127,70,.10);
    }

    #loginModal .btn-success{
      border-radius:12px;
      background:var(--smh-green);
      border-color:var(--smh-green);
    }

    /* Mobile */
    @media(max-width:991px){
      #hero{
        padding:65px 0 75px;
      }

      .landing-hero-image{
        margin:25px auto 0;
      }

      .landing-about-content{
        padding-left:0;
        margin-top:18px;
      }

      .landing-app-logo-box{
        margin-top:20px;
      }
    }

    @media(max-width:767px){
      #header .sitename{
        font-size:18px;
      }

      #header .logo img{
        height:50px !important;
        width:50px;
      }

      .landing-stats{
        grid-template-columns:1fr;
      }

      .landing-stat{
        border-right:0;
        border-bottom:1px solid var(--smh-line);
      }

      .landing-stat:last-child{
        border-bottom:0;
      }

      .landing-hero-image-main{
        height:400px;
      }

      .landing-community-card{
        left:10px;
        right:10px;
        bottom:14px;
        min-width:0;
      }

      .landing-about-image{
        height:390px;
      }

      .landing-about-points{
        grid-template-columns:1fr;
      }

      .landing-app-wrap{
        padding:32px 22px;
      }

      .landing-download-buttons{
        flex-direction:column;
      }

      .landing-download-btn{
        width:100%;
      }

      .landing-app-logo-box{
        width:210px;
        height:210px;
      }

      .landing-app-logo-box img{
        width:160px;
        height:160px;
      }
    }


    /* =========================================================
       LANDING PAGE DARK MODE
       Uses html.dark + localStorage key: hoa-theme
       ========================================================= */

    html.dark{
      color-scheme:dark;

      --smh-green:#2fc27b;
      --smh-green-dark:#20a866;
      --smh-deep:#d7f5e5;
      --smh-soft:#111b17;
      --smh-line:#263a31;
      --smh-text:#e6f2ec;
      --smh-muted:#9fb0a7;
    }

    html.dark body.index-page{
      background:#0b1210;
      color:#e6f2ec;
    }

    html.dark .main{
      background:#0b1210;
    }

    /* Header stays green, but the dropdown/mobile surfaces follow dark mode */
    html.dark #header{
      background:#075f38;
      border-bottom-color:rgba(255,255,255,.10);
      box-shadow:0 8px 28px rgba(0,0,0,.24);
    }

    /* Theme toggle */
    .landing-theme-toggle{
      width:42px;
      height:42px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:0;
      border:1px solid rgba(255,255,255,.28);
      border-radius:50%;
      background:rgba(255,255,255,.10);
      color:#fff;
      font-size:18px;
      line-height:1;
      transition:.22s ease;
      cursor:pointer;
    }

    .landing-theme-toggle:hover,
    .landing-theme-toggle:focus{
      color:#fff;
      background:rgba(255,255,255,.18);
      border-color:rgba(255,255,255,.45);
      transform:translateY(-1px);
      outline:none;
    }

    @media(min-width:1200px){
      #navmenu .theme-toggle-item{
        display:flex;
        align-items:center;
        margin-left:5px;
      }
    }

    /* Hero */
    html.dark #hero{
      background:
        radial-gradient(circle at 85% 20%, rgba(47,194,123,.10), transparent 28%),
        linear-gradient(135deg,#0b1210 0%,#0e1713 55%,#101d17 100%);
    }

    html.dark .landing-eyebrow{
      color:#7ee2ad;
      background:rgba(47,194,123,.10);
      border-color:rgba(47,194,123,.24);
    }

    html.dark .landing-hero-title{
      color:#eef8f3;
    }

    html.dark .landing-hero-title span{
      color:#55d794;
    }

    html.dark .landing-hero-text{
      color:#a7b8af;
    }

    html.dark .landing-secondary-btn{
      color:#9ce8bd;
      background:#121d18;
      border-color:#2a4035;
    }

    html.dark .landing-secondary-btn:hover{
      color:#c9f7dc;
      background:#17251e;
      border-color:#3a5a49;
    }

    html.dark .landing-stats{
      background:#101915;
      border-color:#263a31;
      box-shadow:0 16px 35px rgba(0,0,0,.22);
    }

    html.dark .landing-stat{
      border-color:#263a31;
    }

    html.dark .landing-stat strong{
      color:#55d794;
    }

    html.dark .landing-stat span{
      color:#9eb0a7;
    }

    html.dark .landing-hero-image-main{
      border-color:#17241d;
      box-shadow:0 28px 65px rgba(0,0,0,.34);
    }

    html.dark .landing-community-card{
      background:rgba(16,25,21,.96);
      border-color:#2a4035;
      box-shadow:0 18px 42px rgba(0,0,0,.30);
    }

    html.dark .landing-community-card span{
      color:#8ea198;
    }

    html.dark .landing-community-card strong{
      color:#e5f4ec;
    }

    /* Shared section typography */
    html.dark .landing-section-label{
      color:#55d794;
    }

    html.dark .landing-section-title{
      color:#ecf7f1;
    }

    html.dark .landing-section-text{
      color:#9fb0a7;
    }

    /* About */
    html.dark #about{
      background:#0b1210;
    }

    html.dark .landing-about-image{
      box-shadow:0 24px 55px rgba(0,0,0,.30);
    }

    html.dark .landing-about-point{
      background:#101915;
      border-color:#263a31;
    }

    html.dark .landing-about-point i{
      color:#62dda0;
      background:rgba(47,194,123,.10);
    }

    html.dark .landing-about-point strong{
      color:#e0f0e8;
    }

    html.dark .landing-about-point span{
      color:#96a99f;
    }

    /* Features */
    html.dark #features{
      background:#0f1814 !important;
    }

    html.dark .landing-feature-card{
      background:#121d18;
      border-color:#263a31;
    }

    html.dark .landing-feature-card:hover{
      border-color:#365545;
      box-shadow:0 18px 38px rgba(0,0,0,.28);
    }

    html.dark .landing-feature-icon{
      color:#62dda0;
      background:rgba(47,194,123,.10);
    }

    html.dark .landing-feature-card h5{
      color:#e8f5ee;
    }

    html.dark .landing-feature-card p{
      color:#98aaa0;
    }

    /* App section */
    html.dark #download-app{
      background:#0b1210 !important;
    }

    html.dark .landing-app-wrap{
      background:linear-gradient(135deg,#092c1f 0%,#075f38 100%);
      box-shadow:0 25px 60px rgba(0,0,0,.32);
    }

    html.dark .landing-download-btn{
      background:#f4f8f6;
      color:#173d2b;
    }

    html.dark .landing-download-btn:hover{
      background:#fff;
      color:#173d2b;
    }

    /* Login + Android notice modals */
    html.dark .modal-backdrop.show{
      opacity:.72;
    }

    html.dark #loginModal .modal-content,
    html.dark #androidNoticeModal .modal-content{
      background:#111a16;
      color:#e7f1eb;
      border:1px solid #2a4035 !important;
      box-shadow:0 28px 75px rgba(0,0,0,.50);
    }

    html.dark #loginModal .modal-body,
    html.dark #androidNoticeModal .modal-body,
    html.dark #androidNoticeModal .modal-footer{
      background:#111a16;
      color:#e7f1eb;
    }

    html.dark #loginModal .modal-header,
    html.dark #androidNoticeModal .modal-header{
      background:#075f38 !important;
      border-bottom-color:#2a4035;
    }

    html.dark #loginModal .text-muted,
    html.dark #androidNoticeModal .text-muted{
      color:#9aaca2 !important;
    }

    html.dark #loginModal .text-success{
      color:#6ce0a5 !important;
    }

    html.dark #loginModal .form-control{
      background:#0c1410;
      color:#e7f1eb;
      border-color:#31483c;
    }

    html.dark #loginModal .form-control:focus{
      background:#0c1410;
      color:#fff;
      border-color:#3ecb83;
      box-shadow:0 0 0 .2rem rgba(62,203,131,.13);
    }

    html.dark #loginModal .form-floating > label{
      color:#8fa197;
    }

    html.dark #loginModal .form-floating > .form-control:focus ~ label,
    html.dark #loginModal .form-floating > .form-control:not(:placeholder-shown) ~ label{
      color:#8fa197;
    }

    html.dark #loginModal .form-floating > label::after{
      background:transparent !important;
    }

    html.dark #androidNoticeModal .modal-footer{
      border-top-color:#2a4035 !important;
    }

    html.dark #androidNoticeModal .btn-outline-secondary{
      color:#cbd7d0;
      border-color:#52665b;
    }

    html.dark #androidNoticeModal .btn-outline-secondary:hover{
      color:#fff;
      background:#26372f;
      border-color:#52665b;
    }

    /* Footer */
    html.dark #footer{
      background:#064f30;
    }

    html.dark #footer .copyright{
      background:rgba(0,0,0,.14);
    }

    /* Bootstrap/mobile navigation */
    html.dark .mobile-nav-active .navmenu{
      background:rgba(3,10,7,.62);
    }

    html.dark .mobile-nav-active .navmenu > ul{
      background:#111a16;
      border:1px solid #293d33;
      box-shadow:0 16px 42px rgba(0,0,0,.34);
    }

    html.dark .mobile-nav-active .navmenu a,
    html.dark .mobile-nav-active .navmenu a:focus{
      color:#dcebe3;
    }

    html.dark .mobile-nav-active .navmenu a:hover,
    html.dark .mobile-nav-active .navmenu .active{
      color:#72dfa8;
    }

    /* Scroll to top / preloader */
    html.dark #scroll-top{
      background:#15965a;
      color:#fff;
    }

    html.dark #preloader{
      background:#0b1210;
    }

    @media(max-width:1199px){
      .theme-toggle-item{
        padding:10px 20px;
      }

      .theme-toggle-item .landing-theme-toggle{
        width:100%;
        height:44px;
        border-radius:12px;
        background:#0f6f42;
        border-color:#0f6f42;
      }

      html.dark .theme-toggle-item .landing-theme-toggle{
        background:#173225;
        border-color:#315340;
      }
    }
  </style>
</head>

<body class="index-page">

  <header id="header" class="header d-flex align-items-center sticky-top">
    <div class="container-fluid container-xl position-relative d-flex align-items-center justify-content-between">

      <a href="index.php" class="logo d-flex align-items-center">
        <img src="assets/img/sm_logo.png" alt="South Meridian Homes Logo">
        <h1 class="sitename">South Meridian Homes</h1>
      </a>

      <nav id="navmenu" class="navmenu">
        <ul>
          <li><a href="#hero" class="active">Home</a></li>
          <li><a href="#about">About</a></li>
          <li><a href="#features">Features</a></li>
          <li><a href="#download-app">Download App</a></li>

          <li class="theme-toggle-item">
            <button
              type="button"
              id="landingThemeToggle"
              class="landing-theme-toggle"
              aria-label="Switch to dark mode"
              title="Switch theme"
            >
              <i id="landingThemeIcon" class="bi bi-moon-stars-fill"></i>
            </button>
          </li>

          <li>
            <a href="#" class="landing-login" data-bs-toggle="modal" data-bs-target="#loginModal">
              Log in
            </a>
          </li>
        </ul>
        <i class="mobile-nav-toggle d-xl-none bi bi-list"></i>
      </nav>

    </div>
  </header>


  <!-- LOGIN MODAL -->
  <div class="modal fade" id="loginModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content shadow">

        <div class="modal-header bg-success text-white">
          <h5 class="modal-title" style="color:white;">South Meridian Homes</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body text-center">

          <img src="assets/img/sm_logo.png" alt="Logo" class="mb-3">
          <h5 class="fw-bold mb-1 text-success">Member Login</h5>
          <p class="text-muted small mb-4">Homeowners, tenants, and administrators may log in here.</p>

          <form id="loginForm">

            <div class="form-floating mb-3 text-start">
              <input type="email" class="form-control" id="email" name="email" placeholder="Email" required>
              <label for="email">Email address</label>
            </div>

            <div class="form-floating mb-3 text-start">
              <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
              <label for="password">Password</label>
            </div>

            <div class="loading text-primary mb-2" style="display:none;">Checking credentials...</div>
            <div class="error-message text-danger mb-2" style="display:none;"></div>
            <div class="security-actions mb-2" style="display:none;"></div>

            <button type="submit" class="btn btn-success w-100 py-2 fw-semibold">
              Log in
            </button>
          </form>

          <div class="d-flex justify-content-start mt-3">
            <a href="forgot_password.php" class="text-success text-decoration-none small fw-semibold">
              Forgot password?
            </a>
          </div>

        </div>
      </div>
    </div>
  </div>


  <main class="main">

    <!-- HERO -->
    <section id="hero" class="hero section">
      <div class="container" data-aos="fade-up" data-aos-delay="100">

        <div class="row align-items-center gy-5 gx-lg-5">

          <div class="col-lg-7">
            <div class="landing-hero-copy" data-aos="fade-up" data-aos-delay="150">

              <div class="landing-eyebrow">
                <i class="bi bi-house-heart"></i>
                South Meridian Homeowners Association
              </div>

              <h1 class="landing-hero-title">
                Welcome to <span>South Meridian Homes</span>
              </h1>

              <p class="landing-hero-text">
                One secure digital platform for the South Meridian Homes community.
                Access HOA payments, parking permits, facility reservations, announcements,
                complaints, community voting, and other resident services in one place.
              </p>

              <div class="landing-hero-actions">
                <a href="#" class="landing-primary-btn" data-bs-toggle="modal" data-bs-target="#loginModal">
                  <i class="bi bi-person-circle"></i>
                  Member Login
                </a>

                <a href="#features" class="landing-secondary-btn">
                  Explore Services
                  <i class="bi bi-arrow-down"></i>
                </a>
              </div>

              <div class="landing-stats" data-aos="fade-up" data-aos-delay="250">

                <div class="landing-stat">
                  <strong>
                    <span data-purecounter-start="0" data-purecounter-end="3" data-purecounter-duration="1" class="purecounter"></span>
                  </strong>
                  <span>Community Phases</span>
                </div>

                <div class="landing-stat">
                  <strong>
                    <span data-purecounter-start="0" data-purecounter-end="8" data-purecounter-duration="1" class="purecounter"></span>+
                  </strong>
                  <span>Integrated Modules</span>
                </div>

                <div class="landing-stat">
                  <strong>
                    <span data-purecounter-start="0" data-purecounter-end="100" data-purecounter-duration="1" class="purecounter"></span>%
                  </strong>
                  <span>Online HOA Services</span>
                </div>

              </div>

            </div>
          </div>

          <div class="col-lg-5">
            <div class="landing-hero-image" data-aos="fade-left" data-aos-delay="250">

              <div class="landing-hero-image-main">
                <img src="assets/img/real-estate/property-exterior-8.webp" alt="South Meridian Homes Community">
              </div>

              <div class="landing-community-card">
                <img src="assets/img/sm_logo.png" alt="SMH">
                <div>
                  <span>Community Portal</span>
                  <strong>Salitran 4, Dasmariñas, Cavite</strong>
                </div>
              </div>

            </div>
          </div>

        </div>

      </div>
    </section>


    <!-- ABOUT -->
    <section id="about" class="section">
      <div class="container" data-aos="fade-up">

        <div class="row align-items-center gy-5 gx-lg-5">

          <div class="col-lg-5" data-aos="fade-right">

            <div class="landing-about-image">
              <img src="assets/img/real-estate/property-exterior-1.webp" alt="South Meridian Homes">

              <div class="landing-about-note">
                <i class="bi bi-shield-check"></i>
                <div>
                  <strong>Built for the South Meridian community</strong>
                  <span>Simple, secure, and organized HOA services.</span>
                </div>
              </div>
            </div>

          </div>

          <div class="col-lg-7" data-aos="fade-up" data-aos-delay="100">

            <div class="landing-about-content">

              <span class="landing-section-label">About South Meridian Homes</span>

              <h2 class="landing-section-title">
                Community management made simpler and more accessible.
              </h2>

              <p class="landing-section-text">
                South Meridian Homes is a residential community located in Salitran 4,
                Dasmariñas, Cavite. The HOA management platform centralizes essential
                community services so homeowners, tenants, and administrators can manage
                requests and records through one organized system.
              </p>

              <div class="landing-about-points">

                <div class="landing-about-point">
                  <i class="bi bi-megaphone"></i>
                  <div>
                    <strong>Communication</strong>
                    <span>Announcements, messaging, and complaint tracking.</span>
                  </div>
                </div>

                <div class="landing-about-point">
                  <i class="bi bi-credit-card"></i>
                  <div>
                    <strong>Payments</strong>
                    <span>Online dues and organized transaction records.</span>
                  </div>
                </div>

                <div class="landing-about-point">
                  <i class="bi bi-calendar-check"></i>
                  <div>
                    <strong>Reservations</strong>
                    <span>Request and manage community facility schedules.</span>
                  </div>
                </div>

                <div class="landing-about-point">
                  <i class="bi bi-check2-square"></i>
                  <div>
                    <strong>Community Participation</strong>
                    <span>Secure voting and community-wide updates.</span>
                  </div>
                </div>

              </div>

            </div>

          </div>

        </div>

      </div>
    </section>


    <!-- FEATURES -->
    <section id="features" class="section">
      <div class="container" data-aos="fade-up">

        <div class="row justify-content-center text-center mb-5">
          <div class="col-lg-8">
            <span class="landing-section-label">What the System Offers</span>
            <h2 class="landing-section-title">Complete HOA Management in One Platform</h2>
            <p class="landing-section-text">
              Essential community services are grouped into one accessible system for residents and administrators.
            </p>
          </div>
        </div>

        <div class="row g-4">

          <div class="col-lg-4 col-md-6">
            <div class="landing-feature-card">
              <div class="landing-feature-icon"><i class="bi bi-shield-lock"></i></div>
              <h5>User Authentication</h5>
              <p>Secure account access for homeowners, tenants, administrators, and other authorized users.</p>
            </div>
          </div>

          <div class="col-lg-4 col-md-6">
            <div class="landing-feature-card">
              <div class="landing-feature-icon"><i class="bi bi-chat-dots"></i></div>
              <h5>Communication & Complaints</h5>
              <p>Announcements, community chat, private messaging, and structured complaint tracking.</p>
            </div>
          </div>

          <div class="col-lg-4 col-md-6">
            <div class="landing-feature-card">
              <div class="landing-feature-icon"><i class="bi bi-wallet2"></i></div>
              <h5>Payment Management</h5>
              <p>Process HOA dues and other fees online with organized payment and transaction records.</p>
            </div>
          </div>

          <div class="col-lg-4 col-md-6">
            <div class="landing-feature-card">
              <div class="landing-feature-icon"><i class="bi bi-car-front"></i></div>
              <h5>Parking Management</h5>
              <p>Manage registered vehicles, parking permits, renewals, and related approvals.</p>
            </div>
          </div>

          <div class="col-lg-4 col-md-6">
            <div class="landing-feature-card">
              <div class="landing-feature-icon"><i class="bi bi-calendar-check"></i></div>
              <h5>Facility Rental</h5>
              <p>Reserve courts, tables, function areas, and other available community facilities.</p>
            </div>
          </div>

          <div class="col-lg-4 col-md-6">
            <div class="landing-feature-card">
              <div class="landing-feature-icon"><i class="bi bi-check2-square"></i></div>
              <h5>Voting Management</h5>
              <p>Participate in secure HOA elections with automated vote and result management.</p>
            </div>
          </div>

          <div class="col-lg-4 col-md-6">
            <div class="landing-feature-card">
              <div class="landing-feature-icon"><i class="bi bi-people"></i></div>
              <h5>Homeowner & User Management</h5>
              <p>Manage resident profiles, account information, properties, and community records.</p>
            </div>
          </div>

          <div class="col-lg-4 col-md-6">
            <div class="landing-feature-card">
              <div class="landing-feature-icon"><i class="bi bi-graph-up-arrow"></i></div>
              <h5>Financial & Reporting</h5>
              <p>Support transparent HOA operations through financial records and organized reports.</p>
            </div>
          </div>

          <div class="col-lg-4 col-md-6">
            <div class="landing-feature-card">
              <div class="landing-feature-icon"><i class="bi bi-phone"></i></div>
              <h5>Mobile Application</h5>
              <p>Access important South Meridian Homes services using a compatible mobile device.</p>
            </div>
          </div>

        </div>

      </div>
    </section>


    <!-- DOWNLOAD APP -->
    <section id="download-app" class="section">
      <div class="container" data-aos="fade-up">

        <div class="landing-app-wrap">

          <div class="row align-items-center gy-5">

            <div class="col-lg-7">

              <div class="landing-app-copy">
                <span class="landing-section-label" style="color:#b6d9a1;">South Meridian Mobile</span>

                <h2>Keep your community services within reach.</h2>

                <p>
                  Access HOA services using your mobile device and stay connected to
                  important South Meridian Homes updates wherever you are.
                </p>

                <div class="landing-app-benefits">
                  <span><i class="bi bi-check-circle-fill"></i> Resident access</span>
                  <span><i class="bi bi-check-circle-fill"></i> HOA services</span>
                  <span><i class="bi bi-check-circle-fill"></i> Community updates</span>
                </div>

                <div class="landing-download-buttons">

                  <a href="#"
                     class="landing-download-btn"
                     data-bs-toggle="modal"
                     data-bs-target="#androidNoticeModal">
                    <i class="bi bi-android2"></i>
                    <div>
                      <small>Download for</small>
                      <strong>Android</strong>
                    </div>
                  </a>

                  <a href="ios_source.tar" download class="landing-download-btn">
                    <i class="bi bi-apple"></i>
                    <div>
                      <small>Download for</small>
                      <strong>iOS</strong>
                    </div>
                  </a>

                </div>
              </div>

            </div>

            <div class="col-lg-5">
              <div class="landing-app-logo-box">
                <img src="assets/img/sm_logo.png" alt="South Meridian Homes App">
              </div>
            </div>

          </div>

        </div>

      </div>
    </section>

  </main>


  <footer id="footer" class="footer accent-background">

    <div class="container footer-top">
      <div class="row gy-4">

        <div class="col-lg-5 col-md-12 footer-about">
          <a href="index.php" class="logo d-flex align-items-center">
            <span class="sitename">South Meridian Homes</span>
          </a>

          <p>
            South Meridian Homes is a residential community in Salitran 4,
            Dasmariñas, Cavite, supported by an integrated HOA management system
            for organized and accessible community services.
          </p>

          <div class="social-links d-flex mt-4">
            <a href="#"><i class="bi bi-facebook"></i></a>
            <a href="#"><i class="bi bi-instagram"></i></a>
            <a href="#"><i class="bi bi-envelope"></i></a>
          </div>
        </div>

        <div class="col-lg-2 col-6 footer-links">
          <h4>Quick Links</h4>
          <ul>
            <li><a href="#hero">Home</a></li>
            <li><a href="#about">About Us</a></li>
            <li><a href="#features">Features</a></li>
            <li><a href="#download-app">Download App</a></li>
          </ul>
        </div>

        <div class="col-lg-2 col-6 footer-links">
          <h4>Services</h4>
          <ul>
            <li><a href="#features">Online Payments</a></li>
            <li><a href="#features">Parking Permits</a></li>
            <li><a href="#features">Facility Reservations</a></li>
            <li><a href="#features">Community Voting</a></li>
            <li><a href="#features">Complaint Tracking</a></li>
          </ul>
        </div>

        <div class="col-lg-3 col-md-12 footer-contact text-center text-md-start">
          <h4>Contact Us</h4>
          <p>South Meridian Homeowners Association</p>
          <p>Salitran 4, Dasmariñas</p>
          <p>Cavite, Philippines</p>
          <p class="mt-4">
            <strong>Email:</strong>
            <span>admin@southmeridianhomes.com</span>
          </p>
        </div>

      </div>
    </div>


    <!-- ANDROID NOTICE MODAL -->
    <div class="modal fade" id="androidNoticeModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 shadow border-0">

          <div class="modal-header bg-success text-white rounded-top-4">
            <h5 class="modal-title" style="color:white;">
              <i class="bi bi-android2 me-2"></i>Android Download Notice
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body text-center px-4 py-4">
            <div class="mb-3">
              <i class="bi bi-phone" style="font-size:48px;color:#3DDC84;"></i>
            </div>

            <h5 class="fw-bold mb-2">This app is for Android devices only</h5>

            <p class="text-muted mb-0">
              Please continue only if you are using an Android phone or tablet.
              This download is not supported for iOS devices.
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


    <div class="container copyright text-center mt-4">
      <p>
        © <span>Copyright</span>
        <strong class="px-1 sitename">South Meridian Homeowners Association</strong>
        <span>All Rights Reserved</span>
      </p>
    </div>

  </footer>


  <a href="#" id="scroll-top" class="scroll-top d-flex align-items-center justify-content-center">
    <i class="bi bi-arrow-up-short"></i>
  </a>

  <div id="preloader"></div>

  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="assets/vendor/php-email-form/validate.js"></script>
  <script src="assets/vendor/aos/aos.js"></script>
  <script src="assets/vendor/purecounter/purecounter_vanilla.js"></script>
  <script src="assets/vendor/swiper/swiper-bundle.min.js"></script>

  <script src="assets/js/main.js"></script>


  <script>
  (function () {
    const root = document.documentElement;
    const toggle = document.getElementById('landingThemeToggle');
    const icon = document.getElementById('landingThemeIcon');

    if (!toggle || !icon) return;

    function isDark() {
      return root.classList.contains('dark');
    }

    function syncThemeButton() {
      const dark = isDark();

      icon.className = dark
        ? 'bi bi-sun-fill'
        : 'bi bi-moon-stars-fill';

      toggle.setAttribute(
        'aria-label',
        dark
          ? 'Switch to light mode'
          : 'Switch to dark mode'
      );

      toggle.setAttribute(
        'title',
        dark
          ? 'Switch to light mode'
          : 'Switch to dark mode'
      );
    }

    toggle.addEventListener('click', function () {
      const nextDark = !isDark();

      root.classList.toggle('dark', nextDark);

      try {
        localStorage.setItem(
          'hoa-theme',
          nextDark ? 'dark' : 'light'
        );
      } catch (e) {}

      syncThemeButton();
    });

    syncThemeButton();
  })();
  </script>

  <script>
  (function () {
    const form = document.getElementById('loginForm');

    if (!form) return;

    const loading =
      form.querySelector('.loading');

    const errorBox =
      form.querySelector('.error-message');

    const securityActions =
      form.querySelector('.security-actions');

    const submitButton =
      form.querySelector('button[type="submit"]');

    let cooldownTimer = null;

    function clearCooldownTimer() {
      if (cooldownTimer) {
        clearInterval(cooldownTimer);
        cooldownTimer = null;
      }
    }

    function resetSecurityActions() {
      if (!securityActions) return;

      securityActions.innerHTML = '';
      securityActions.style.display = 'none';
    }

    function showError(message) {
      errorBox.textContent =
        message || 'Unable to sign in.';

      errorBox.style.display =
        'block';
    }

    function startCooldown(seconds) {
      clearCooldownTimer();

      let remaining =
        Math.max(
          1,
          Number(seconds || 10)
        );

      submitButton.disabled = true;

      function paint() {
        showError(
          `Too many incorrect passwords. Try again in ${remaining} second${remaining === 1 ? '' : 's'}.`
        );

        submitButton.textContent =
          `Locked (${remaining}s)`;
      }

      paint();

      cooldownTimer =
        setInterval(
          function () {
            remaining--;

            if (remaining <= 0) {
              clearCooldownTimer();
              submitButton.disabled = false;
              submitButton.textContent = 'Log in';

              showError(
                'You may try again. Three more incorrect passwords will lock the account until an administrator unlocks it.'
              );

              return;
            }

            paint();
          },
          1000
        );
    }

    function showAppeal(data) {
      if (!securityActions) return;

      const url =
        String(data.appeal_url || '');

      const emailMessage =
        data.mail_sent
          ? '<div class="small text-muted mb-2">A security notification and appeal link were sent to your account email.</div>'
          : '<div class="small text-warning mb-2">The security email could not be delivered. You can still submit the appeal using the button below.</div>';

      securityActions.innerHTML =
        emailMessage +
        (
          url
            ? `<a class="btn btn-outline-danger btn-sm w-100 fw-semibold" href="${url}">
                 <i class="bi bi-shield-exclamation me-1"></i>
                 Submit Unlock Appeal
               </a>`
            : `<div class="small text-muted">
                 Use the appeal link sent to your email. An administrator must unlock the account.
               </div>`
        );

      securityActions.style.display =
        'block';
    }

    form.addEventListener(
      'submit',
      async function (event) {
        event.preventDefault();

        if (submitButton.disabled) {
          return;
        }

        clearCooldownTimer();
        resetSecurityActions();

        loading.style.display = 'block';
        errorBox.style.display = 'none';

        const originalButtonText =
          submitButton.textContent;

        submitButton.disabled = true;
        submitButton.textContent = 'Checking...';

        try {
          const formData =
            new FormData(form);

          formData.append(
            'action',
            'login'
          );

          const response =
            await fetch(
              'index.php',
              {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
              }
            );

          const data =
            await response.json();

          loading.style.display =
            'none';

          if (
            data.success &&
            data.redirect
          ) {
            window.location.href =
              data.redirect;

            return;
          }

          showError(
            data.message ||
            'Unable to sign in.'
          );

          if (data.code === 'cooldown') {
            startCooldown(
              data.seconds || 10
            );
            return;
          }

          if (data.code === 'hard_locked') {
            showAppeal(data);
          }

        } catch (error) {
          loading.style.display =
            'none';

          showError(
            'An error occurred. Try again.'
          );

        } finally {
          if (!cooldownTimer) {
            submitButton.disabled =
              false;

            submitButton.textContent =
              'Log in';
          }
        }
      }
    );
  })();
  </script>

</body>
</html>