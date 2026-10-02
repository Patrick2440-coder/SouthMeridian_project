<?php







session_start();







require_once 'admin_access.php';







require_once '../config/database.php';







requireAccess('complaints');







/* =========================







   1) AUTH GUARD







   ========================= */







if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||







    !in_array($_SESSION['admin_role'], ['admin', 'superadmin'], true)) {







  echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";







  exit;







}















/* superadmin not allowed here */







if (($_SESSION['admin_role'] ?? '') === 'superadmin') {







  echo "<script>alert('Superadmin cannot access President Dashboard.'); window.location='index.php';</script>";







  exit;







}















/* =========================







   2) DB







   ========================= */







mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);























function esc($v){







  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');







}







function complaintStatusBadgeClass($s){







  $s = (string)$s;







  if ($s === 'open') return 'badge-soft-warning';







  if ($s === 'in_progress') return 'badge-soft-info';







  if ($s === 'resolved') return 'badge-soft-success';







  if ($s === 'closed') return 'badge-soft-secondary';







  return 'badge-soft-warning';







}







function complaintPriorityClass($p){







  $p = (string)$p;







  if ($p === 'urgent') return 'ann-badge urgent';







  if ($p === 'high') return 'ann-badge important';







  if ($p === 'normal') return 'ann-badge normal';







  return 'ann-badge';







}











function complaintAttachmentUrl($path){







  $path = str_replace('\\', '/', trim((string)$path));



  $path = ltrim($path, '/');







  if ($path === '' || !str_starts_with($path, 'uploads/complaints/')) {



    return '';



  }







  return '../' . $path;







}







function formatComplaintFileSize($bytes){







  $bytes = max(0, (int)$bytes);







  if ($bytes >= 1024 * 1024) {



    return number_format($bytes / (1024 * 1024), 1) . ' MB';



  }







  if ($bytes >= 1024) {



    return number_format($bytes / 1024, 1) . ' KB';



  }







  return $bytes . ' B';







}















/* =========================







   3) ADMIN INFO







   ========================= */







$adminId = (int)($_SESSION['admin_id'] ?? 0);















$stmt = $conn->prepare("SELECT id, email, full_name, phase, role FROM admins WHERE id=? LIMIT 1");







$stmt->bind_param("i", $adminId);







$stmt->execute();







$me = $stmt->get_result()->fetch_assoc();







$stmt->close();















if (!$me) {







  session_destroy();







  echo "<script>alert('Session error. Please login again.'); window.location='index.php';</script>";







  exit;







}















$adminEmail = (string)($me['email'] ?? '');







$adminName  = trim((string)($me['full_name'] ?? ''));







$phase      = (string)($me['phase'] ?? 'Phase 1');















$allowedPhases = ['Phase 1', 'Phase 2', 'Phase 3'];







if (!in_array($phase, $allowedPhases, true)) {







  $phase = 'Phase 1';







}















$filter = (string)($_GET['filter'] ?? 'all');







$allowedFilters = ['all','open','in_progress','resolved','closed'];







if (!in_array($filter, $allowedFilters, true)) $filter = 'all';















$selectedComplaintId = (int)($_GET['complaint_id'] ?? 0);















$ok = '';







$err = '';















/* =========================







   4) POST ACTIONS







   ========================= */







if ($_SERVER['REQUEST_METHOD'] === 'POST') {















  /* send reply */







  if (isset($_POST['send_reply_submit'])) {







    $complaintId = (int)($_POST['complaint_id'] ?? 0);







    $message = trim((string)($_POST['message'] ?? ''));















    if ($complaintId <= 0) {







      $err = "Invalid complaint.";







    } elseif ($message === '') {







      $err = "Message cannot be empty.";







    } else {







      $stmt = $conn->prepare("SELECT id, status FROM complaints WHERE id=? AND phase=? LIMIT 1");







      $stmt->bind_param("is", $complaintId, $phase);







      $stmt->execute();







      $chk = $stmt->get_result()->fetch_assoc();







      $stmt->close();















      if (!$chk) {







        $err = "Complaint not found.";







      } else {







        $stmt = $conn->prepare("







          INSERT INTO complaint_messages (complaint_id, sender_type, sender_admin_id, message)







          VALUES (?, 'admin', ?, ?)







        ");







        $stmt->bind_param("iis", $complaintId, $adminId, $message);







        $stmt->execute();







        $stmt->close();















        if (in_array((string)$chk['status'], ['open','resolved','closed'], true)) {







          $stmt = $conn->prepare("UPDATE complaints SET admin_id=?, status='in_progress', updated_at=NOW() WHERE id=?");







          $stmt->bind_param("ii", $adminId, $complaintId);







        } else {







          $stmt = $conn->prepare("UPDATE complaints SET admin_id=?, updated_at=NOW() WHERE id=?");







          $stmt->bind_param("ii", $adminId, $complaintId);







        }







        $stmt->execute();







        $stmt->close();















        header("Location: admin_complaints.php?filter=" . urlencode($filter) . "&complaint_id=" . $complaintId);







        exit;







      }







    }







  }















  /* update status */







  if (isset($_POST['update_status_submit'])) {







    $complaintId = (int)($_POST['complaint_id'] ?? 0);







    $newStatus   = trim((string)($_POST['status'] ?? ''));















    $allowedStatuses = ['open','in_progress','resolved','closed'];















    if ($complaintId <= 0) {







      $err = "Invalid complaint.";







    } elseif (!in_array($newStatus, $allowedStatuses, true)) {







      $err = "Invalid status.";







    } else {







      $stmt = $conn->prepare("SELECT id FROM complaints WHERE id=? AND phase=? LIMIT 1");







      $stmt->bind_param("is", $complaintId, $phase);







      $stmt->execute();







      $chk = $stmt->get_result()->fetch_assoc();







      $stmt->close();















      if (!$chk) {







        $err = "Complaint not found.";







      } else {







        $stmt = $conn->prepare("UPDATE complaints SET status=?, admin_id=?, updated_at=NOW() WHERE id=?");







        $stmt->bind_param("sii", $newStatus, $adminId, $complaintId);







        $stmt->execute();







        $stmt->close();















        $statusLabel = strtoupper(str_replace('_', ' ', $newStatus));







        $systemMsg = "Complaint status updated to " . $statusLabel . ".";















        $stmt = $conn->prepare("







          INSERT INTO complaint_messages (complaint_id, sender_type, sender_admin_id, message)







          VALUES (?, 'admin', ?, ?)







        ");







        $stmt->bind_param("iis", $complaintId, $adminId, $systemMsg);







        $stmt->execute();







        $stmt->close();















        header("Location: admin_complaints.php?filter=" . urlencode($filter) . "&complaint_id=" . $complaintId);







        exit;







      }







    }







  }







}















/* =========================







   5) COUNTS







   ========================= */







$counts = [







  'all' => 0,







  'open' => 0,







  'in_progress' => 0,







  'resolved' => 0,







  'closed' => 0







];















$stmt = $conn->prepare("







  SELECT status, COUNT(*) c







  FROM complaints







  WHERE phase=?







  GROUP BY status







");







$stmt->bind_param("s", $phase);







$stmt->execute();







$res = $stmt->get_result();







while ($r = $res->fetch_assoc()) {







  $st = (string)$r['status'];







  $counts[$st] = (int)$r['c'];







  $counts['all'] += (int)$r['c'];







}







$stmt->close();















/* =========================







   6) COMPLAINT LIST







   ========================= */







$attachmentTableReady = false;







try {







  $tableCheck = $conn->query("SHOW TABLES LIKE 'complaint_attachments'");



  $attachmentTableReady = ($tableCheck && $tableCheck->num_rows > 0);







  if ($tableCheck) {



    $tableCheck->free();



  }







} catch (mysqli_sql_exception $e) {







  error_log('Complaint attachment table check failed: ' . $e->getMessage());







}







$evidenceSelect = $attachmentTableReady



  ? ", (SELECT COUNT(*) FROM complaint_attachments ca WHERE ca.complaint_id = c.id) AS evidence_count"



  : ", 0 AS evidence_count";







$sql = "







  SELECT c.*,







         h.first_name, h.middle_name, h.last_name, h.email AS homeowner_email, h.contact_number, h.house_lot_number,







         a.full_name AS assigned_admin_name



         {$evidenceSelect}







  FROM complaints c







  LEFT JOIN homeowners h ON h.id = c.homeowner_id







  LEFT JOIN admins a ON a.id = c.admin_id







  WHERE c.phase=?







";







$types = "s";







$params = [$phase];















if ($filter !== 'all') {







  $sql .= " AND c.status=?";







  $types .= "s";







  $params[] = $filter;







}















$sql .= " ORDER BY c.updated_at DESC, c.id DESC LIMIT 200";















$stmt = $conn->prepare($sql);







$stmt->bind_param($types, ...$params);







$stmt->execute();







$complaints = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);







$stmt->close();



/* Latest complaint ID for real-time new complaint watcher. */

$latestComplaintId = 0;

$rtLatestStmt = $conn->prepare("SELECT COALESCE(MAX(id), 0) AS latest_id FROM complaints WHERE phase = ?");

$rtLatestStmt->bind_param("s", $phase);

$rtLatestStmt->execute();

$rtLatestRow = $rtLatestStmt->get_result()->fetch_assoc();

$latestComplaintId = (int)($rtLatestRow['latest_id'] ?? 0);

$rtLatestStmt->close();



if ($selectedComplaintId <= 0 && !empty($complaints)) {







  $selectedComplaintId = (int)$complaints[0]['id'];







}















/* =========================







   7) SELECTED COMPLAINT







   ========================= */







$selectedComplaint = null;







if ($selectedComplaintId > 0) {







  $stmt = $conn->prepare("







    SELECT c.*,







           h.first_name, h.middle_name, h.last_name, h.email AS homeowner_email, h.contact_number, h.house_lot_number,







           a.full_name AS assigned_admin_name







    FROM complaints c







    LEFT JOIN homeowners h ON h.id = c.homeowner_id







    LEFT JOIN admins a ON a.id = c.admin_id







    WHERE c.id=? AND c.phase=?







    LIMIT 1







  ");







  $stmt->bind_param("is", $selectedComplaintId, $phase);







  $stmt->execute();







  $selectedComplaint = $stmt->get_result()->fetch_assoc();







  $stmt->close();















  if (!$selectedComplaint) $selectedComplaintId = 0;







}











$complaintAttachments = [];







if ($selectedComplaintId > 0 && $attachmentTableReady) {







  try {







    $stmt = $conn->prepare("



      SELECT



        id,



        complaint_id,



        file_path,



        original_name,



        mime_type,



        file_kind,



        file_size,



        created_at



      FROM complaint_attachments



      WHERE complaint_id = ?



      ORDER BY id ASC



    " );







    $stmt->bind_param('i', $selectedComplaintId);



    $stmt->execute();



    $complaintAttachments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);



    $stmt->close();







  } catch (mysqli_sql_exception $e) {







    error_log('Unable to load complaint evidence: ' . $e->getMessage());



    $complaintAttachments = [];







  }







}











/* =========================







   8) MESSAGES







   ========================= */







$messages = [];







if ($selectedComplaintId > 0) {







  $stmt = $conn->prepare("







    SELECT cm.*,







           h.first_name, h.middle_name, h.last_name,







           a.full_name AS admin_name







    FROM complaint_messages cm







    LEFT JOIN homeowners h ON h.id = cm.sender_homeowner_id







    LEFT JOIN admins a ON a.id = cm.sender_admin_id







    WHERE cm.complaint_id=?







    ORDER BY cm.created_at ASC, cm.id ASC







  ");







  $stmt->bind_param("i", $selectedComplaintId);







  $stmt->execute();







  $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);







  $stmt->close();







}







?>







<!DOCTYPE html>







<html>







<head>







  <meta charset="utf-8">







  <title>HOA-ADMIN • Complaints</title>














<?php
require_once $_SERVER['DOCUMENT_ROOT'] .
    '/SouthMeridian_project/includes/favicon.php';
?>







  















  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">







  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">















  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">







  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">







  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">







  <!-- ADMIN DARK MODE -->







<link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">















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















  <style>







    .badge-soft { padding: .35rem .6rem; border-radius: 999px; font-weight: 800; font-size: 12px; }







    .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }







    .badge-soft-success { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }







    .badge-soft-info    { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; }







    .badge-soft-secondary { background:#f1f5f9; border:1px solid #cbd5e1; color:#475569; }















    .ann-badge {







      font-size: 11px;







      font-weight: 900;







      padding: 3px 8px;







      border-radius: 999px;







      border: 1px solid #e5e7eb;







      background: #f8fafc;







      color: #0f172a;







      display: inline-block;







    }







    .ann-badge.urgent { background:#fef2f2; border-color:#fecaca; color:#991b1b; }







    .ann-badge.important { background:#fffbeb; border-color:#fed7aa; color:#9a3412; }







    .ann-badge.normal { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }















    .complaint-layout{







      display:grid;







      grid-template-columns: 350px 1fr;







      gap:20px;







    }







    .complaint-list-card, .complaint-chat-card, .complaint-info-card{







      background:#fff;







      border-radius:14px;







      box-shadow:0 6px 18px rgba(0,0,0,.06);







      border:1px solid #eef2f7;







    }







    .complaint-item{







      border:1px solid #e5e7eb;







      border-radius:12px;







      padding:12px;







      margin-bottom:10px;







      color:#0f172a;







      text-decoration:none;







      display:block;







    }







    .complaint-item.active{







      border-color:#077f46;







      background:#f0fff7;







    }







    .complaint-item:hover{







      text-decoration:none;







      color:#0f172a;







      box-shadow:0 4px 12px rgba(0,0,0,.05);







    }







    .msg-area{







      height:430px;







      overflow:auto;







      background:#f8fafc;







      border:1px solid #e5e7eb;







      border-radius:12px;







      padding:15px;







    }







    .msg-row{







      display:flex;







      margin-bottom:12px;







    }







    .msg-row.mine{







      justify-content:flex-end;







    }







    .msg-bubble{







      max-width:76%;







      padding:12px 14px;







      border-radius:16px;







      box-shadow:0 4px 12px rgba(0,0,0,.05);







    }







    .msg-row.mine .msg-bubble{







      background:#077f46;







      color:#fff;







      border-bottom-right-radius:6px;







    }







    .msg-row.theirs .msg-bubble{







      background:#fff;







      border:1px solid #e5e7eb;







      color:#0f172a;







      border-bottom-left-radius:6px;







    }







    .msg-meta{







      font-size:12px;







      opacity:.8;







      margin-top:6px;







    }







    .filter-pills{







      display:flex;







      gap:8px;







      flex-wrap:wrap;







    }







    .filter-pill{







      padding:7px 12px;







      border-radius:999px;







      border:1px solid #e5e7eb;







      font-weight:800;







      font-size:12px;







      color:#0f172a;







      background:#fff;







      text-decoration:none;







    }







    .filter-pill.active{







      background:#077f46;







      border-color:#077f46;







      color:#fff;







    }







    .mini-label{







      font-size:12px;







      color:#64748b;







      font-weight:700;







    }











    .evidence-panel{



      margin-top:18px;



      overflow:hidden;



      border:1px solid #e2e8f0;



      border-radius:14px;



      background:#fff;



    }







    .evidence-panel-header{



      display:flex;



      align-items:center;



      justify-content:space-between;



      gap:12px;



      padding:13px 15px;



      border-bottom:1px solid #e2e8f0;



      background:#f8fafc;



    }







    .evidence-panel-title{



      display:flex;



      align-items:center;



      gap:8px;



      margin:0;



      color:#0f172a;



      font-size:14px;



      font-weight:800;



    }







    .evidence-count{



      display:inline-flex;



      align-items:center;



      justify-content:center;



      min-width:28px;



      height:26px;



      padding:0 9px;



      border-radius:999px;



      background:#dcfce7;



      color:#166534;



      font-size:11px;



      font-weight:900;



    }







    .evidence-grid{



      display:grid;



      grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));



      gap:14px;



      padding:15px;



    }







    .evidence-item{



      overflow:hidden;



      border:1px solid #e2e8f0;



      border-radius:12px;



      background:#fff;



    }







    .evidence-media{



      min-height:190px;



      display:flex;



      align-items:center;



      justify-content:center;



      background:#0f172a;



    }







    .evidence-media img,



    .evidence-media video{



      width:100%;



      max-height:360px;



      display:block;



      object-fit:contain;



      background:#0f172a;



    }







    .evidence-meta{



      padding:11px 12px;



      border-top:1px solid #e2e8f0;



    }







    .evidence-name{



      overflow:hidden;



      color:#0f172a;



      font-size:12px;



      font-weight:800;



      text-overflow:ellipsis;



      white-space:nowrap;



    }







    .evidence-submeta{



      margin-top:4px;



      color:#64748b;



      font-size:11px;



    }







    .evidence-open{



      display:inline-flex;



      align-items:center;



      gap:6px;



      margin-top:8px;



      color:#077f46;



      font-size:12px;



      font-weight:800;



      text-decoration:none;



    }







    .evidence-open:hover{



      color:#056437;



      text-decoration:none;



    }







    .evidence-empty{



      padding:18px;



      color:#64748b;



      font-size:13px;



      text-align:center;



    }







    .evidence-required-note{



      margin-top:6px;



      color:#64748b;



      font-size:11px;



    }







    html.dark .evidence-panel,



    html.dark .evidence-item{



      background:#172033;



      border-color:#334155;



    }







    html.dark .evidence-panel-header{



      background:#111827;



      border-color:#334155;



    }







    html.dark .evidence-panel-title,



    html.dark .evidence-name{



      color:#e5e7eb;



    }







    html.dark .evidence-meta{



      border-color:#334155;



    }







    html.dark .evidence-submeta,



    html.dark .evidence-empty,



    html.dark .evidence-required-note{



      color:#94a3b8;



    }







    html.dark .evidence-count{



      background:rgba(16,185,129,.14);



      color:#6ee7b7;



    }







    html.dark .evidence-open{



      color:#6ee7b7;



    }







    html.dark .evidence-open:hover{



      color:#a7f3d0;



    }







    /* Keep the footer at the real bottom of the admin content area. */



    .main-container > .pd-ltr-20{



      min-height:calc(100vh - 70px);



      display:flex;



      flex-direction:column;



    }







    .admin-page-footer{



      width:100%;



      margin-top:auto !important;



      margin-bottom:0 !important;



      text-align:center;



      flex-shrink:0;



    }







    @media (max-width: 991px){







      .complaint-layout{







        grid-template-columns:1fr;







      }







    }







/* ACCESS TOAST */







.access-toast {







    position: fixed;







    top: 20px;







    right: 20px;















    background: #ef4444;







    color: #fff;















    padding: 12px 18px;







    border-radius: 8px;















    font-weight: 600;















    box-shadow: 0 6px 18px rgba(0,0,0,0.2);















    z-index: 99999;















    opacity: 0;







    visibility: hidden;







    pointer-events: none;















    transform: translateY(-10px);















    transition:







        opacity .3s ease,







        transform .3s ease,







        visibility .3s ease;







}















.access-toast.show {







    opacity: 1;







    visibility: visible;







    transform: translateY(0);







}











    /* ==========================================================

       REAL-TIME COMPLAINT ALERTS

       ========================================================== */

    .complaint-sound-btn{display:inline-flex;align-items:center;gap:8px;border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:10px;padding:9px 13px;font-size:12px;font-weight:800;cursor:pointer;transition:.18s ease;}

    .complaint-sound-btn:hover{border-color:#077f46;color:#077f46;box-shadow:0 4px 12px rgba(0,0,0,.06);}

    .complaint-sound-btn.is-on{background:#ecfdf5;border-color:#86efac;color:#166534;}

    .complaint-sound-btn.is-blocked{background:#fff7ed;border-color:#fdba74;color:#9a3412;}

    .complaint-rt-toast{position:fixed;top:90px;right:22px;z-index:100000;width:min(390px,calc(100vw - 32px));border-radius:16px;border:1px solid #e2e8f0;background:#fff;box-shadow:0 20px 45px rgba(15,23,42,.22);overflow:hidden;opacity:0;visibility:hidden;transform:translateY(-12px) scale(.98);transition:.22s ease;pointer-events:none;}

    .complaint-rt-toast.show{opacity:1;visibility:visible;transform:translateY(0) scale(1);pointer-events:auto;}

    .complaint-rt-toast.priority-low{border-left:6px solid #64748b;}

    .complaint-rt-toast.priority-normal{border-left:6px solid #2563eb;}

    .complaint-rt-toast.priority-high{border-left:6px solid #d97706;}

    .complaint-rt-toast.priority-urgent{border-left:6px solid #dc2626;animation:urgentComplaintPulse 1s ease-in-out infinite;}

    @keyframes urgentComplaintPulse{0%,100%{box-shadow:0 20px 45px rgba(15,23,42,.22);}50%{box-shadow:0 20px 50px rgba(220,38,38,.38);}}

    .complaint-rt-toast-head{display:flex;align-items:flex-start;gap:12px;padding:15px 16px 10px;}

    .complaint-rt-icon{width:42px;height:42px;flex:0 0 42px;display:flex;align-items:center;justify-content:center;border-radius:12px;font-size:20px;background:#f1f5f9;}

    .priority-urgent .complaint-rt-icon{background:#fee2e2;color:#b91c1c;}

    .priority-high .complaint-rt-icon{background:#fef3c7;color:#b45309;}

    .priority-normal .complaint-rt-icon{background:#dbeafe;color:#1d4ed8;}

    .priority-low .complaint-rt-icon{background:#f1f5f9;color:#475569;}

    .complaint-rt-title{font-weight:900;color:#0f172a;font-size:14px;line-height:1.25;}

    .complaint-rt-meta{margin-top:4px;color:#64748b;font-size:12px;line-height:1.45;}

    .complaint-rt-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;padding:0 16px 14px;}

    .complaint-rt-actions a,.complaint-rt-actions button{border:0;border-radius:9px;padding:8px 11px;font-size:12px;font-weight:800;text-decoration:none;cursor:pointer;}

    .complaint-rt-open{background:#077f46;color:#fff !important;}

    .complaint-rt-dismiss{background:#f1f5f9;color:#475569;}

    .rt-new-badge{display:inline-flex;align-items:center;gap:5px;margin-left:6px;border-radius:999px;background:#dcfce7;color:#166534;padding:2px 7px;font-size:10px;font-weight:900;}

    html.dark .complaint-sound-btn{background:#172033;border-color:#334155;color:#cbd5e1;}

    html.dark .complaint-sound-btn.is-on{background:rgba(16,185,129,.12);border-color:#065f46;color:#6ee7b7;}

    html.dark .complaint-sound-btn.is-blocked{background:rgba(249,115,22,.12);border-color:#9a3412;color:#fdba74;}

    html.dark .complaint-rt-toast{background:#172033;border-color:#334155;}

    html.dark .complaint-rt-title{color:#f8fafc;}

    html.dark .complaint-rt-meta{color:#94a3b8;}

    html.dark .complaint-rt-dismiss{background:#334155;color:#e2e8f0;}



  

    /* ---------------------------------------------------------
       Header logout button
       --------------------------------------------------------- */
    .admin-page-logout-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-width: 98px;
      min-height: 40px;
      margin: 0 18px 0 10px;
      padding: 8px 14px;
      border: 1px solid #fecaca;
      border-radius: 11px;
      background: #ffffff;
      color: #b91c1c !important;
      box-shadow: 0 4px 14px rgba(15, 23, 42, .06);
      font-size: 12px;
      line-height: 1;
      font-weight: 800;
      text-decoration: none !important;
      white-space: nowrap;
      transition: background .18s ease, color .18s ease, border-color .18s ease, transform .18s ease, box-shadow .18s ease;
    }

    .admin-page-logout-btn i {
      font-size: 17px;
      line-height: 1;
    }

    .admin-page-logout-btn:hover,
    .admin-page-logout-btn:focus {
      border-color: #ef4444;
      background: #fef2f2;
      color: #991b1b !important;
      box-shadow: 0 7px 18px rgba(220, 38, 38, .12);
      transform: translateY(-1px);
      outline: none;
    }

    html.dark .admin-page-logout-btn {
      border-color: rgba(248, 113, 113, .30);
      background: rgba(127, 29, 29, .16);
      color: #fca5a5 !important;
      box-shadow: none;
    }

    html.dark .admin-page-logout-btn:hover,
    html.dark .admin-page-logout-btn:focus {
      border-color: rgba(248, 113, 113, .55);
      background: rgba(127, 29, 29, .28);
      color: #fecaca !important;
    }

    @media (max-width: 575.98px) {
      .admin-page-logout-btn {
        width: 40px;
        min-width: 40px;
        height: 40px;
        min-height: 40px;
        margin: 0 10px 0 6px;
        padding: 0;
        border-radius: 10px;
      }

      .admin-page-logout-btn span {
        display: none;
      }

      .admin-page-logout-btn i {
        font-size: 18px;
      }
    }
</style>







</head>







<body>















<div class="header">















    <div class="header-left">







        <div class="menu-icon dw dw-menu"></div>







        <div class="search-toggle-icon dw dw-search2" data-toggle="header_search"></div>







    </div>















    <div class="header-right">















        <!-- DARK MODE TOGGLE -->







        <div class="admin-theme-switch">







            <button type="button"







                    id="themeToggle"







                    class="admin-theme-toggle"







                    aria-label="Switch theme"







                    title="Switch theme">







                <span id="themeIcon">☾</span>







            </button>







        </div>

        <!-- DIRECT LOGOUT BUTTON -->
        <a href="logout.php"
           class="admin-page-logout-btn"
           title="Log out"
           aria-label="Log out">
            <i class="dw dw-logout" aria-hidden="true"></i>
            <span>Log Out</span>
        </a>















    </div>















</div>















<?php include 'sidebar.php'; ?>















  <div class="mobile-menu-overlay"></div>















  <div class="main-container">







    <div class="pd-ltr-20">















      <div class="page-header mb-20">







        <div class="row">







          <div class="col-md-12 col-sm-12">







            <div class="title"><h4>Complaints Management</h4></div>







            <div class="text-secondary">







              Phase: <b><?= esc($phase) ?></b> |







              Logged in as <b><?= esc($adminName !== '' ? $adminName : $adminEmail) ?></b>







            </div>









          </div>







        </div>







      </div>















      <?php if ($ok): ?>







        <div class="alert alert-success"><?= esc($ok) ?></div>







      <?php endif; ?>







      <?php if ($err): ?>







        <div class="alert alert-danger"><?= esc($err) ?></div>







      <?php endif; ?>















      <div class="row mb-20">







        <div class="col-xl-3 col-lg-6 col-md-6 mb-20">







          <div class="card-box pd-20">







            <div class="font-14 text-secondary">All Complaints</div>







            <div id="rtCountAll" class="font-30 weight-700"><?= (int)$counts['all'] ?></div>







          </div>







        </div>







        <div class="col-xl-3 col-lg-6 col-md-6 mb-20">







          <div class="card-box pd-20">







            <div class="font-14 text-secondary">Open</div>







            <div id="rtCountOpen" class="font-30 weight-700 text-warning"><?= (int)$counts['open'] ?></div>







          </div>







        </div>







        <div class="col-xl-3 col-lg-6 col-md-6 mb-20">







          <div class="card-box pd-20">







            <div class="font-14 text-secondary">In Progress</div>







            <div id="rtCountInProgress" class="font-30 weight-700 text-primary"><?= (int)$counts['in_progress'] ?></div>







          </div>







        </div>







        <div class="col-xl-3 col-lg-6 col-md-6 mb-20">







          <div class="card-box pd-20">







            <div class="font-14 text-secondary">Resolved / Closed</div>







            <div id="rtCountResolvedClosed" class="font-30 weight-700 text-success"><?= (int)($counts['resolved'] + $counts['closed']) ?></div>







          </div>







        </div>







      </div>















      <div class="card-box pd-20 mb-20">







        <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap:10px;">







          <h5 class="mb-0">Complaint Filters</h5>







          <div class="filter-pills">







            <a class="filter-pill <?= $filter==='all' ? 'active' : '' ?>" href="admin_complaints.php?filter=all">All (<span id="rtFilterAll"><?= (int)$counts['all'] ?></span>)</a>







            <a class="filter-pill <?= $filter==='open' ? 'active' : '' ?>" href="admin_complaints.php?filter=open">Open (<span id="rtFilterOpen"><?= (int)$counts['open'] ?></span>)</a>







            <a class="filter-pill <?= $filter==='in_progress' ? 'active' : '' ?>" href="admin_complaints.php?filter=in_progress">In Progress (<span id="rtFilterInProgress"><?= (int)$counts['in_progress'] ?></span>)</a>







            <a class="filter-pill <?= $filter==='resolved' ? 'active' : '' ?>" href="admin_complaints.php?filter=resolved">Resolved (<span id="rtFilterResolved"><?= (int)$counts['resolved'] ?></span>)</a>







            <a class="filter-pill <?= $filter==='closed' ? 'active' : '' ?>" href="admin_complaints.php?filter=closed">Closed (<span id="rtFilterClosed"><?= (int)$counts['closed'] ?></span>)</a>







          </div>







        </div>







      </div>















      <div class="complaint-layout">







        <!-- LEFT LIST -->







        <div class="complaint-list-card pd-20">







          <div class="d-flex justify-content-between align-items-center mb-15">







            <h5 class="mb-0">Complaints</h5>







            <span class="badge-soft badge-soft-info"><span id="rtResultCount"><?= count($complaints) ?></span> result(s)</span>







          </div>

          <div id="rtComplaintList">















          <?php if (empty($complaints)): ?>







            <div class="text-secondary">No complaints found for this filter.</div>







          <?php else: ?>







            <?php foreach ($complaints as $c): ?>







              <?php







                $name = trim((string)($c['first_name'] ?? '') . ' ' . (string)($c['middle_name'] ?? '') . ' ' . (string)($c['last_name'] ?? ''));







                $isActive = ((int)$c['id'] === (int)$selectedComplaintId);







              ?>







              <a class="complaint-item <?= $isActive ? 'active' : '' ?>"







                 href="admin_complaints.php?filter=<?= urlencode($filter) ?>&complaint_id=<?= (int)$c['id'] ?>">







                <div class="d-flex justify-content-between align-items-start" style="gap:8px;">







                  <div>







                    <div class="font-weight-bold"><?= esc($c['subject']) ?></div>







                    <div class="text-secondary font-12"><?= esc($name !== '' ? $name : 'Unknown Homeowner') ?></div>







                    <div class="text-secondary font-12"><?= esc((string)($c['house_lot_number'] ?? '')) ?></div>







                  </div>







                  <div class="text-right">







                    <span class="badge-soft <?= esc(complaintStatusBadgeClass((string)$c['status'])) ?>">







                      <?= esc(strtoupper(str_replace('_',' ', (string)$c['status']))) ?>







                    </span>







                  </div>







                </div>















                <div class="mt-10 d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">







                  <div class="d-flex align-items-center flex-wrap" style="gap:6px;">







                    <span class="<?= esc(complaintPriorityClass((string)$c['priority'])) ?>">







                      <?= esc(strtoupper((string)$c['priority'])) ?>







                    </span>







                    <?php if ((int)($c['evidence_count'] ?? 0) > 0): ?>







                      <span class="badge-soft badge-soft-success" title="Complaint has proof/evidence">



                        <i class="dw dw-attachment"></i>



                        Evidence



                      </span>







                    <?php endif; ?>







                  </div>







                  <span class="font-12 text-secondary"><?= esc(date('M d, Y h:i A', strtotime((string)$c['updated_at']))) ?></span>







                </div>







              </a>







            <?php endforeach; ?>







          <?php endif; ?>

          </div>







        </div>















        <!-- RIGHT -->







        <div>







          <?php if (!$selectedComplaint): ?>







            <div class="complaint-chat-card pd-30 text-center text-secondary">







              Select a complaint from the left panel.







            </div>







          <?php else: ?>







            <?php







              $selName = trim(







                (string)($selectedComplaint['first_name'] ?? '') . ' ' .







                (string)($selectedComplaint['middle_name'] ?? '') . ' ' .







                (string)($selectedComplaint['last_name'] ?? '')







              );







            ?>















            <!-- complaint info -->







            <div class="complaint-info-card pd-20 mb-20">







              <div class="d-flex justify-content-between align-items-start flex-wrap" style="gap:12px;">







                <div>







                  <h4 class="mb-5"><?= esc($selectedComplaint['subject']) ?></h4>







                  <div class="text-secondary">







                    Homeowner: <b><?= esc($selName !== '' ? $selName : 'Unknown') ?></b>







                  </div>







                  <div class="text-secondary">







                    Blk/Lot: <b><?= esc((string)($selectedComplaint['house_lot_number'] ?? '')) ?></b>







                  </div>







                  <div class="text-secondary">







                    Email: <b><?= esc((string)($selectedComplaint['homeowner_email'] ?? '')) ?></b>







                  </div>







                  <div class="text-secondary">







                    Contact: <b><?= esc((string)($selectedComplaint['contact_number'] ?? '')) ?></b>







                  </div>







                  <div class="text-secondary">







                    Assigned Admin: <b><?= esc((string)($selectedComplaint['assigned_admin_name'] ?: ($adminName ?: $adminEmail))) ?></b>







                  </div>







                </div>















                <div class="text-right">







                  <div class="mb-2">







                    <span class="badge-soft <?= esc(complaintStatusBadgeClass((string)$selectedComplaint['status'])) ?>">







                      <?= esc(strtoupper(str_replace('_',' ', (string)$selectedComplaint['status']))) ?>







                    </span>







                  </div>







                  <div>







                    <span class="<?= esc(complaintPriorityClass((string)$selectedComplaint['priority'])) ?>">







                      <?= esc(strtoupper((string)$selectedComplaint['priority'])) ?>







                    </span>







                  </div>







                </div>







              </div>















              <div class="row mt-15">







                <div class="col-md-3 mb-2">







                  <div class="mini-label">Category</div>







                  <div><b><?= esc(ucwords(str_replace('_',' ', (string)$selectedComplaint['category']))) ?></b></div>







                </div>







                <div class="col-md-3 mb-2">







                  <div class="mini-label">Created</div>







                  <div><b><?= esc(date('M d, Y h:i A', strtotime((string)$selectedComplaint['created_at']))) ?></b></div>







                </div>







                <div class="col-md-3 mb-2">







                  <div class="mini-label">Last Updated</div>







                  <div><b><?= esc(date('M d, Y h:i A', strtotime((string)$selectedComplaint['updated_at']))) ?></b></div>







                </div>







                <div class="col-md-3 mb-2">







                  <div class="mini-label">Complaint ID</div>







                  <div><b>#<?= (int)$selectedComplaint['id'] ?></b></div>







                </div>







              </div>















              <div class="mt-15">







                <div class="mini-label mb-1">Initial Complaint</div>







                <div style="white-space:pre-wrap; line-height:1.45;"><?= esc((string)$selectedComplaint['description']) ?></div>







              </div>











              <div class="evidence-panel">







                <div class="evidence-panel-header">







                  <div>



                    <div class="evidence-panel-title">



                      <i class="dw dw-attachment"></i>



                      Proof / Evidence



                    </div>



                    <div class="evidence-required-note">Evidence is required for newly submitted complaints.</div>



                  </div>







                  <span class="evidence-count"><?= (int)count($complaintAttachments) ?></span>







                </div>







                <?php if (!$attachmentTableReady): ?>







                  <div class="evidence-empty">



                    Evidence storage is not initialized. Import <b>complaint_attachments.sql</b> first.



                  </div>







                <?php elseif (empty($complaintAttachments)): ?>







                  <div class="evidence-empty">



                    No proof/evidence is attached to this complaint. This can happen with complaints filed before evidence became required.



                  </div>







                <?php else: ?>







                  <div class="evidence-grid">







                    <?php foreach ($complaintAttachments as $attachment): ?>







                      <?php



                        $evidenceUrl = complaintAttachmentUrl($attachment['file_path'] ?? '');



                        $evidenceKind = (string)($attachment['file_kind'] ?? '');



                        $evidenceMime = (string)($attachment['mime_type'] ?? '');



                      ?>







                      <?php if ($evidenceUrl !== ''): ?>







                        <div class="evidence-item">







                          <div class="evidence-media">







                            <?php if ($evidenceKind === 'image'): ?>







                              <a href="<?= esc($evidenceUrl) ?>" target="_blank" rel="noopener" style="display:block;width:100%;">



                                <img src="<?= esc($evidenceUrl) ?>" alt="Complaint evidence">



                              </a>







                            <?php elseif ($evidenceKind === 'video'): ?>







                              <video controls preload="metadata">



                                <source src="<?= esc($evidenceUrl) ?>" type="<?= esc($evidenceMime !== '' ? $evidenceMime : 'video/mp4') ?>">



                                Your browser does not support video playback.



                              </video>







                            <?php else: ?>







                              <div class="evidence-empty">Unsupported evidence type.</div>







                            <?php endif; ?>







                          </div>







                          <div class="evidence-meta">







                            <div class="evidence-name" title="<?= esc((string)($attachment['original_name'] ?? 'Evidence')) ?>">



                              <?= esc((string)($attachment['original_name'] ?? 'Evidence')) ?>



                            </div>







                            <div class="evidence-submeta">



                              <?= esc(strtoupper($evidenceKind !== '' ? $evidenceKind : 'FILE')) ?>



                              • <?= esc(formatComplaintFileSize((int)($attachment['file_size'] ?? 0))) ?>



                            </div>







                            <a class="evidence-open" href="<?= esc($evidenceUrl) ?>" target="_blank" rel="noopener">



                              <i class="dw dw-external-link"></i>



                              Open evidence



                            </a>







                          </div>







                        </div>







                      <?php endif; ?>







                    <?php endforeach; ?>







                  </div>







                <?php endif; ?>







              </div>















              <div class="mt-20">







                <form method="POST" class="form-inline">







                  <input type="hidden" name="update_status_submit" value="1">







                  <input type="hidden" name="complaint_id" value="<?= (int)$selectedComplaint['id'] ?>">















                  <label class="mr-2 font-weight-bold">Update Status:</label>







                  <select name="status" class="form-control mr-2">







                    <option value="open" <?= ((string)$selectedComplaint['status']==='open' ? 'selected' : '') ?>>Open</option>







                    <option value="in_progress" <?= ((string)$selectedComplaint['status']==='in_progress' ? 'selected' : '') ?>>In Progress</option>







                    <option value="resolved" <?= ((string)$selectedComplaint['status']==='resolved' ? 'selected' : '') ?>>Resolved</option>







                    <option value="closed" <?= ((string)$selectedComplaint['status']==='closed' ? 'selected' : '') ?>>Closed</option>







                  </select>







                  <button type="submit" class="btn btn-primary">Save Status</button>







                </form>







              </div>







            </div>















            <!-- chat -->







            <div class="complaint-chat-card pd-20">







              <div class="d-flex justify-content-between align-items-center mb-15">







                <h5 class="mb-0">Conversation</h5>







                <span class="text-secondary font-12"><?= count($messages) ?> message(s)</span>







              </div>















              <div class="msg-area" id="msgArea">







                <?php if (empty($messages)): ?>







                  <div class="text-secondary">No messages yet.</div>







                <?php else: ?>







                  <?php foreach ($messages as $m): ?>







                    <?php







                      $isMine = ((string)$m['sender_type'] === 'admin');







                      $senderName = $isMine







                        ? ((string)($m['admin_name'] ?: ($adminName ?: 'Admin')))







                        : trim((string)($m['first_name'] ?? '') . ' ' . (string)($m['middle_name'] ?? '') . ' ' . (string)($m['last_name'] ?? ''));







                      if ($senderName === '') $senderName = $isMine ? 'Admin' : 'Homeowner';







                    ?>







                    <div class="msg-row <?= $isMine ? 'mine' : 'theirs' ?>">







                      <div class="msg-bubble">







                        <div class="font-weight-bold font-12 mb-1"><?= esc($senderName) ?></div>







                        <div style="white-space:pre-wrap; line-height:1.45;"><?= esc((string)$m['message']) ?></div>







                        <div class="msg-meta"><?= esc(date('M d, Y h:i A', strtotime((string)$m['created_at']))) ?></div>







                      </div>







                    </div>







                  <?php endforeach; ?>







                <?php endif; ?>







              </div>















              <form method="POST" class="mt-15">







                <input type="hidden" name="send_reply_submit" value="1">







                <input type="hidden" name="complaint_id" value="<?= (int)$selectedComplaint['id'] ?>">







                <div class="form-group">







                  <label class="font-weight-bold">Reply to Homeowner</label>







                  <textarea name="message" class="form-control" rows="4" maxlength="3000" placeholder="Type your reply here..." required></textarea>







                </div>







                <button type="submit" class="btn btn-success">







                  <i class="dw dw-paper-plane1"></i> Send Reply







                </button>







              </form>







            </div>







          <?php endif; ?>







        </div>







      </div>















      <div class="footer-wrap pd-20 card-box admin-page-footer">







        © Copyright South Meridian Homes All Rights Reserved







      </div>







    </div>







  </div>















  <script src="vendors/scripts/core.js"></script>







  <script src="vendors/scripts/script.min.js"></script>







  <script src="vendors/scripts/process.js"></script>







  <script src="vendors/scripts/layout-settings.js"></script>















  <!-- ADMIN DARK MODE -->







<script src="vendors/scripts/admin_theme.js"></script>















<div id="complaintRealtimeToast" class="complaint-rt-toast" role="alert" aria-live="assertive">

  <div class="complaint-rt-toast-head">

    <div id="complaintRtIcon" class="complaint-rt-icon">🔔</div>

    <div style="min-width:0;flex:1;">

      <div id="complaintRtTitle" class="complaint-rt-title">New complaint received</div>

      <div id="complaintRtMeta" class="complaint-rt-meta"></div>

    </div>

  </div>

  <div class="complaint-rt-actions">

    <button type="button" id="complaintRtDismiss" class="complaint-rt-dismiss">Dismiss</button>

    <a id="complaintRtOpen" class="complaint-rt-open" href="#">Open Complaint</a>

  </div>

</div>



<script>

/*

|--------------------------------------------------------------------------

| Real-time complaint page

|--------------------------------------------------------------------------

| Keeps list/counts/popups real-time. Sound is owned only by the persistent

| complaint_alert_monitor.php so navigation between admin files does not

| create competing Web Audio engines.

*/

(function () {

  const apiUrl = 'admin_complaints_realtime_api.php';

  const currentFilter = <?= json_encode($filter, JSON_UNESCAPED_SLASHES) ?>;

  let lastComplaintId = <?= (int)$latestComplaintId ?>;

  let pollBusy = false;

  let toastTimer = null;

  let currentToastComplaintId = 0;

  const originalTitle = document.title;

  let titleResetTimer = null;



  const complaintChannel = ('BroadcastChannel' in window)

    ? new BroadcastChannel('south-meridian-complaint-alerts')

    : null;



  const toast = document.getElementById('complaintRealtimeToast');

  const toastIcon = document.getElementById('complaintRtIcon');

  const toastTitle = document.getElementById('complaintRtTitle');

  const toastMeta = document.getElementById('complaintRtMeta');

  const toastOpen = document.getElementById('complaintRtOpen');

  const toastDismiss = document.getElementById('complaintRtDismiss');

  const list = document.getElementById('rtComplaintList');



  function broadcast(type, payload) {

    if (!complaintChannel) return;

    complaintChannel.postMessage(Object.assign({

      type: type,

      source: 'admin-complaints-page'

    }, payload || {}));

  }



  function escapeHtml(value) {

    return String(value == null ? '' : value)

      .replace(/&/g, '&amp;')

      .replace(/</g, '&lt;')

      .replace(/>/g, '&gt;')

      .replace(/"/g, '&quot;')

      .replace(/'/g, '&#039;');

  }



  function priorityBadgeClass(p) {

    if (p === 'urgent') return 'ann-badge urgent';

    if (p === 'high') return 'ann-badge important';

    if (p === 'normal') return 'ann-badge normal';

    return 'ann-badge';

  }



  function statusBadgeClass(st) {

    if (st === 'open') return 'badge-soft badge-soft-warning';

    if (st === 'in_progress') return 'badge-soft badge-soft-info';

    if (st === 'resolved') return 'badge-soft badge-soft-success';

    if (st === 'closed') return 'badge-soft badge-soft-secondary';

    return 'badge-soft badge-soft-warning';

  }



  function showToast(c) {

    if (!toast) return;



    const p = String(c.priority || 'normal').toLowerCase();

    const iconMap = { low: '🔔', normal: '🔔', high: '⚠️', urgent: '🚨' };

    const labelMap = {

      low: 'LOW priority complaint',

      normal: 'New complaint received',

      high: 'HIGH priority complaint',

      urgent: 'URGENT complaint received'

    };



    currentToastComplaintId = Number(c.id || 0);

    toast.className = 'complaint-rt-toast priority-' + p + ' show';

    toastIcon.textContent = iconMap[p] || '🔔';

    toastTitle.textContent = labelMap[p] || 'New complaint received';

    toastMeta.textContent = c.subject + ' — ' + c.homeowner_name

      + (c.house_lot_number ? ' • ' + c.house_lot_number : '');

    toastOpen.href = 'admin_complaints.php?filter=' + encodeURIComponent(currentFilter)

      + '&complaint_id=' + encodeURIComponent(c.id);



    clearTimeout(toastTimer);

    // High and Urgent stay visible until Dismiss/Open.

    if (p !== 'high' && p !== 'urgent') {

      toastTimer = setTimeout(function () {

        toast.classList.remove('show');

      }, 8000);

    }

  }



  if (toastDismiss) {

    toastDismiss.addEventListener('click', function () {

      if (currentToastComplaintId > 0) {

        broadcast('dismiss', { complaintId: currentToastComplaintId });

      }

      toast.classList.remove('show');

      currentToastComplaintId = 0;

      document.title = originalTitle;

    });

  }



  if (toastOpen) {

    toastOpen.addEventListener('click', function () {

      if (currentToastComplaintId > 0) {

        broadcast('open', { complaintId: currentToastComplaintId });

      }

    });

  }



  if (complaintChannel) {

    complaintChannel.onmessage = function (event) {

      const message = event.data || {};

      if (

        (message.type === 'dismiss' || message.type === 'open') &&

        Number(message.complaintId || 0) === currentToastComplaintId

      ) {

        toast.classList.remove('show');

        currentToastComplaintId = 0;

        document.title = originalTitle;

      }

    };

  }



  function flashTitle(p) {

    clearTimeout(titleResetTimer);

    const labels = {

      urgent: '🚨 URGENT COMPLAINT',

      high: '⚠️ HIGH PRIORITY COMPLAINT',

      normal: '🔔 New Complaint',

      low: '🔔 Low Priority Complaint'

    };

    document.title = labels[p] || '🔔 New Complaint';



    if (p !== 'high' && p !== 'urgent') {

      titleResetTimer = setTimeout(function () {

        document.title = originalTitle;

      }, 7000);

    }

  }



  function setCount(id, value) {

    const el = document.getElementById(id);

    if (el) el.textContent = String(value || 0);

  }



  function updateCounts(c) {

    if (!c) return;

    setCount('rtCountAll', c.all);

    setCount('rtCountOpen', c.open);

    setCount('rtCountInProgress', c.in_progress);

    setCount('rtCountResolvedClosed', Number(c.resolved || 0) + Number(c.closed || 0));

    setCount('rtFilterAll', c.all);

    setCount('rtFilterOpen', c.open);

    setCount('rtFilterInProgress', c.in_progress);

    setCount('rtFilterResolved', c.resolved);

    setCount('rtFilterClosed', c.closed);

  }



  function shouldShow(c) {

    return currentFilter === 'all' || String(c.status) === currentFilter;

  }



  function prependComplaint(c) {

    if (!list || !shouldShow(c)) return;



    if (list.querySelector('[data-complaint-id="' + Number(c.id) + '"]')) return;



    const empty = Array.from(list.querySelectorAll('.text-secondary')).find(function (el) {

      return /No complaints found/.test(el.textContent || '');

    });

    if (empty) empty.remove();



    const a = document.createElement('a');

    a.className = 'complaint-item';

    a.dataset.complaintId = String(c.id);

    a.href = 'admin_complaints.php?filter=' + encodeURIComponent(currentFilter)

      + '&complaint_id=' + encodeURIComponent(c.id);



    const p = String(c.priority || 'normal').toLowerCase();

    const statusLabel = String(c.status || 'open').replace(/_/g, ' ').toUpperCase();

    const evidence = Number(c.evidence_count || 0) > 0

      ? '<span class="badge-soft badge-soft-success" title="Complaint has proof/evidence"><i class="dw dw-attachment"></i> Evidence</span>'

      : '';



    a.innerHTML =

      '<div class="d-flex justify-content-between align-items-start" style="gap:8px;">' +

        '<div>' +

          '<div class="font-weight-bold">' + escapeHtml(c.subject) + '<span class="rt-new-badge">NEW</span></div>' +

          '<div class="text-secondary font-12">' + escapeHtml(c.homeowner_name) + '</div>' +

          '<div class="text-secondary font-12">' + escapeHtml(c.house_lot_number || '') + '</div>' +

        '</div>' +

        '<div class="text-right">' +

          '<span class="' + statusBadgeClass(c.status) + '">' + escapeHtml(statusLabel) + '</span>' +

        '</div>' +

      '</div>' +

      '<div class="mt-10 d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">' +

        '<div class="d-flex align-items-center flex-wrap" style="gap:6px;">' +

          '<span class="' + priorityBadgeClass(p) + '">' + escapeHtml(p.toUpperCase()) + '</span>' +

          evidence +

        '</div>' +

        '<span class="font-12 text-secondary">' +

          escapeHtml(c.updated_at_display || c.created_at_display || '') +

        '</span>' +

      '</div>';



    list.prepend(a);



    const rc = document.getElementById('rtResultCount');

    if (rc) rc.textContent = String(Number(rc.textContent || 0) + 1);

  }



  function processNewComplaint(c, index) {

    prependComplaint(c);

    setTimeout(function () {

      const p = String(c.priority || 'normal').toLowerCase();

      showToast(c);

      flashTitle(p);

      broadcast('complaint', { complaint: c });

    }, index * 500);

  }



  async function pollComplaints() {

    if (pollBusy || document.hidden) return;

    pollBusy = true;



    try {

      const response = await fetch(

        apiUrl + '?after_id=' + encodeURIComponent(lastComplaintId),

        {

          credentials: 'same-origin',

          cache: 'no-store',

          headers: {

            'Accept': 'application/json',

            'X-Requested-With': 'XMLHttpRequest'

          }

        }

      );



      const data = await response.json();

      if (!response.ok || !data.success || data.authorized === false) return;



      lastComplaintId = Math.max(lastComplaintId, Number(data.latest_id || 0));

      updateCounts(data.counts || {});



      const items = Array.isArray(data.complaints) ? data.complaints : [];

      items.forEach(processNewComplaint);

    } catch (e) {

      // Silent retry. The persistent monitor also polls independently.

    } finally {

      pollBusy = false;

    }

  }



  setInterval(pollComplaints, 2000);

  document.addEventListener('visibilitychange', function () {

    if (!document.hidden) pollComplaints();

  });

})();

</script>



<div id="accessToast" class="access-toast">







  🚫 You do not have access to that part.







</div>







<script>







window.userPermissions = <?= json_encode($permissions) ?>;















document.addEventListener('DOMContentLoaded', function () {















  const toast = document.getElementById('accessToast');















  function showAccessToast() {







    toast.classList.add('show');















    setTimeout(() => {







      toast.classList.remove('show');







    }, 2500);







  }















  document.querySelectorAll('.menu-access-link').forEach(function(link){















    link.addEventListener('click', function(e){















      const moduleKey = this.dataset.module || '';







      const allowed = !!window.userPermissions[moduleKey];















      if(!allowed){







        e.preventDefault();







        showAccessToast();







      }















    });















  });















});







</script>







</body>







</html>