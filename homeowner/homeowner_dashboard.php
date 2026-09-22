<?php
session_start();
/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_homeowner_dashboard'])) {
    $_SESSION['csrf_homeowner_dashboard'] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    (string)$_SESSION['csrf_homeowner_dashboard'];
require_once '../config/database.php';
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['homeowner', 'tenant'], true)) {
  header("Location: ../index.php");
  exit;
}




function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function tenant_can_access(string $module, ?array $tenant): bool {
  if (!$tenant) return false;

  $map = [
    'dashboard'     => true,
    'announcements' => !empty($tenant['can_announcements']),
    'pay_dues'      => !empty($tenant['can_pay_dues']),
    'rentals'       => !empty($tenant['can_rent']),
    'parking'       => !empty($tenant['can_parking']),
    'complaints'    => true,
    'public_chat'   => true,
    'voting'        => false,
    'tenant_mgmt'   => false
  ];

  return $map[$module] ?? false;
}

$isTenant = ($_SESSION['role'] === 'tenant');
$tenant = null;
$user = null;
$hid = 0;

if ($isTenant) {
  if (empty($_SESSION['tenant_id']) || empty($_SESSION['tenant_homeowner_id'])) {
    header("Location: ../index.php");
    exit;
  }

  $tenant_id = (int)$_SESSION['tenant_id'];
  $hid = (int)$_SESSION['tenant_homeowner_id'];

  $stmt = $conn->prepare("
    SELECT id, homeowner_id, first_name, last_name, email, status, phase,
           can_pay_dues, can_rent, can_parking, can_announcements, registered_at
    FROM tenants
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $tenant_id);
  $stmt->execute();
  $tenant = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$tenant || $tenant['status'] !== 'active') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }

  $stmt = $conn->prepare("
SELECT
    id,
    status,
    must_change_password,
    first_name,
    last_name,
    phase,
    house_lot_number,

    block,
    lot,
    street,
    map_x,
    map_y,

    latitude,
    longitude,

    created_at,
    profile_picture_path
FROM homeowners
WHERE id = ?
LIMIT 1
  ");
  $stmt->bind_param("i", $hid);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user || $user['status'] !== 'approved') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }
} else {
  if (empty($_SESSION['homeowner_id'])) {
    header("Location: ../index.php");
    exit;
  }

  $hid = (int)$_SESSION['homeowner_id'];

  $stmt = $conn->prepare("
SELECT
    id,
    status,
    must_change_password,
    first_name,
    last_name,
    phase,
    house_lot_number,

    block,
    lot,
    street,
    map_x,
    map_y,

    latitude,
    longitude,

    created_at,
    profile_picture_path
FROM homeowners
WHERE id = ?
LIMIT 1
  ");
  $stmt->bind_param("i", $hid);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user || $user['status'] !== 'approved') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }
}

$phase = (string)$user['phase'];

if ($isTenant) {

    $fullName =
        trim(
            ($tenant['first_name'] ?? '') .
            ' ' .
            ($tenant['last_name'] ?? '')
        );

    $initials =
        strtoupper(
            substr(
                $tenant['first_name'] ?? 'T',
                0,
                1
            ) .
            substr(
                $tenant['last_name'] ?? 'N',
                0,
                1
            )
        );

    $accountStartRaw =
        (string)(
            $tenant['registered_at']
            ?? ''
        );

} else {

    $fullName =
        trim(
            ($user['first_name'] ?? '') .
            ' ' .
            ($user['last_name'] ?? '')
        );

    $initials =
        strtoupper(
            substr(
                $user['first_name'] ?? 'H',
                0,
                1
            ) .
            substr(
                $user['last_name'] ?? 'O',
                0,
                1
            )
        );

    $accountStartRaw =
        (string)(
            $user['created_at']
            ?? ''
        );
}


/*
|--------------------------------------------------------------------------
| Homeowner Profile Picture
|--------------------------------------------------------------------------
*/

$profilePicturePath =
    trim(
        (string)(
            $user['profile_picture_path']
            ?? ''
        )
    );

$profilePictureUrl =
    $profilePicturePath !== ''
        ? '../' . ltrim(
            $profilePicturePath,
            '/'
        )
        : '';
$accountStartTs = strtotime($accountStartRaw);

if (!$accountStartTs) {
    $accountStartTs = time();
}

$accountStartYear  = (int)date('Y', $accountStartTs);
$accountStartMonth = (int)date('n', $accountStartTs);
$accountStartLabel = date('F Y', $accountStartTs);

$pageTitle  = "South Meridian Homes Salitran • " . $phase;

$activePage = basename($_SERVER['PHP_SELF'] ?? 'homeowner_dashboard.php');

$parkingPages = [
  'homeowner_parking.php',
  'homeowner_parking_permit.php',
  'homeowner_parking_violations.php'
];

$complaintPages = [
  'homeowner_complaints.php',
  'homeowner_complaint_chat.php'
];

$parkingOpen    = in_array($activePage, $parkingPages, true);
$complaintsOpen = in_array($activePage, $complaintPages, true);



$stmt = $conn->prepare("INSERT IGNORE INTO homeowner_feed_state (homeowner_id) VALUES (?)");
$stmt->bind_param("i", $hid);
$stmt->execute();
$stmt->close();

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'])
) {

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    /*
    |--------------------------------------------------------------------------
    | Verify CSRF token
    |--------------------------------------------------------------------------
    */

    $submittedCsrf =
        (string)(
            $_POST['csrf_token']
            ?? ''
        );

    if (
        $submittedCsrf === '' ||
        !hash_equals(
            $csrfToken,
            $submittedCsrf
        )
    ) {

        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' =>
                'Invalid or expired security token. Please refresh the page.'
        ]);

        exit;
    }

    $action =
        (string)$_POST['action'];

        /*
|--------------------------------------------------------------------------
| Update Homeowner Profile Picture
|--------------------------------------------------------------------------
*/

if ($action === 'update_profile_picture') {

if ($isTenant) {    
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Tenant accounts cannot change the homeowner profile picture.'
        ]);

        exit;
    }

    if (
        !isset($_FILES['profile_picture']) ||
        !is_array($_FILES['profile_picture'])
    ) {
        echo json_encode([
            'success' => false,
            'message' => 'Please select an image.'
        ]);

        exit;
    }

    $file = $_FILES['profile_picture'];

    if (
        !isset($file['error']) ||
        $file['error'] !== UPLOAD_ERR_OK
    ) {
        echo json_encode([
            'success' => false,
            'message' => 'Profile picture upload failed.'
        ]);

        exit;
    }

    /*
     * Maximum: 5 MB
     */
    $maxSize = 5 * 1024 * 1024;

    if (
        (int)($file['size'] ?? 0) <= 0 ||
        (int)$file['size'] > $maxSize
    ) {
        echo json_encode([
            'success' => false,
            'message' => 'Profile picture must be 5 MB or smaller.'
        ]);

        exit;
    }

    $tmpName =
        (string)($file['tmp_name'] ?? '');

    if (
        $tmpName === '' ||
        !is_uploaded_file($tmpName)
    ) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid uploaded image.'
        ]);

        exit;
    }

    /*
     * Detect actual MIME type.
     */
    $finfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );

    $mimeType =
        $finfo->file(
            $tmpName
        );

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (
        !isset(
            $allowedTypes[$mimeType]
        )
    ) {
        echo json_encode([
            'success' => false,
            'message' => 'Only JPG, PNG, and WEBP images are allowed.'
        ]);

        exit;
    }

    $extension =
        $allowedTypes[$mimeType];

    /*
     * /SouthMeridian_project/uploads/profile_pictures/
     */
    $uploadDir =
        dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        'uploads' .
        DIRECTORY_SEPARATOR .
        'profile_pictures' .
        DIRECTORY_SEPARATOR;

    if (
        !is_dir($uploadDir) &&
        !mkdir(
            $uploadDir,
            0755,
            true
        )
    ) {
        echo json_encode([
            'success' => false,
            'message' => 'Unable to create profile picture directory.'
        ]);

        exit;
    }

    $fileName =
        bin2hex(
            random_bytes(16)
        ) .
        '.' .
        $extension;

    $destination =
        $uploadDir .
        $fileName;

    if (
        !move_uploaded_file(
            $tmpName,
            $destination
        )
    ) {
        echo json_encode([
            'success' => false,
            'message' => 'Unable to save profile picture.'
        ]);

        exit;
    }

    $dbPath =
        'uploads/profile_pictures/' .
        $fileName;

    /*
     * Keep old picture so we can remove it after
     * database update succeeds.
     */
    $oldPicture =
        trim(
            (string)(
                $user['profile_picture_path']
                ?? ''
            )
        );

    try {

        $stmt =
            $conn->prepare("
                UPDATE homeowners
                SET profile_picture_path = ?
                WHERE id = ?
                LIMIT 1
            ");

        $stmt->bind_param(
            'si',
            $dbPath,
            $hid
        );

        $stmt->execute();
        $stmt->close();

        /*
         * Delete previous custom profile picture.
         */
        if (
            $oldPicture !== '' &&
            str_starts_with(
                $oldPicture,
                'uploads/profile_pictures/'
            )
        ) {

            $oldFullPath =
                dirname(__DIR__) .
                DIRECTORY_SEPARATOR .
                str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $oldPicture
                );

            if (is_file($oldFullPath)) {
                @unlink($oldFullPath);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Profile picture updated successfully.',
            'image_url' => '../' . $dbPath
        ]);

        exit;

    } catch (Throwable $e) {

        if (is_file($destination)) {
            @unlink($destination);
        }

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Unable to update profile picture.'
        ]);

        exit;
    }
}


  if ($isTenant && in_array($action, ['toggle_like_ann', 'add_comment_ann'], true)) {
    echo json_encode(['success'=>false,'message'=>'You do not have access to that action.']);
    exit;
  }

  if ($action === 'toggle_like_ann') {
    $ann_id = (int)($_POST['announcement_id'] ?? 0);
    if ($ann_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid announcement.']); exit; }

    $stmt = $conn->prepare("SELECT id FROM announcement_likes WHERE announcement_id=? AND homeowner_id=? LIMIT 1");
    $stmt->bind_param("ii", $ann_id, $hid);
    $stmt->execute();
    $liked = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($liked) {
      $stmt = $conn->prepare("DELETE FROM announcement_likes WHERE announcement_id=? AND homeowner_id=?");
      $stmt->bind_param("ii", $ann_id, $hid);
      $ok = $stmt->execute();
      $stmt->close();
      $state = false;
    } else {
      $stmt = $conn->prepare("INSERT IGNORE INTO announcement_likes (announcement_id, homeowner_id) VALUES (?,?)");
      $stmt->bind_param("ii", $ann_id, $hid);
      $ok = $stmt->execute();
      $stmt->close();
      $state = true;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM announcement_likes WHERE announcement_id=?");
    $stmt->bind_param("i", $ann_id);
    $stmt->execute();
    $cnt = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    echo json_encode(['success'=>$ok,'liked'=>$state,'like_count'=>$cnt]);
    exit;
  }

  if ($action === 'add_comment_ann') {
    $ann_id = (int)($_POST['announcement_id'] ?? 0);
    $comment = trim((string)($_POST['comment'] ?? ''));
    if ($ann_id <= 0 || $comment === '') {
      echo json_encode(['success'=>false,'message'=>'Comment cannot be empty.']);
      exit;
    }

$stmt = $conn->prepare("
    INSERT INTO announcement_comments
        (announcement_id, homeowner_id, comment)
    VALUES (?, ?, ?)
");

$stmt->bind_param(
    "iis",
    $ann_id,
    $hid,
    $comment
);

$ok = $stmt->execute();

$newCommentId =
    $ok
        ? (int)$conn->insert_id
        : 0;

$stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM announcement_comments WHERE announcement_id=?");
    $stmt->bind_param("i", $ann_id);
    $stmt->execute();
    $cc = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $avatarInitial = strtoupper(substr($user['first_name'] ?? 'H', 0, 1));

if ($profilePictureUrl !== '') {

    $newCommentAvatar =
        '<img
            src="' . esc($profilePictureUrl) . '"
            alt="' . esc($fullName) . '"
            class="h-full w-full object-cover"
        >';

} else {

    $newCommentAvatar =
        esc($avatarInitial);
}

    echo json_encode([
      'success'=>$ok,
      'message'=>$ok?'Comment added.':'Failed to comment.',
      'comment_count'=>$cc,
'comment_html'=>$ok ? '
<div
    class="comment-item mt-3 flex items-start gap-2.5"
    data-comment-id="'.$newCommentId.'"
>

<div class="
    flex h-9 w-9
    shrink-0
    items-center justify-center
    overflow-hidden
    rounded-full
    bg-emerald-100
    text-xs
    font-bold
    text-emerald-700
    dark:bg-emerald-950
    dark:text-emerald-300
">
    '.$newCommentAvatar.'
</div>
    <div class="min-w-0">

        <div class="flex items-center gap-2">

            <div class="
                text-sm
                font-semibold
                leading-5
                text-slate-800
                dark:text-slate-100
            ">
                '.esc($fullName).'
            </div>

            <div class="relative">

                <button
                    type="button"
                    class="
                        btn-comment-options
                        flex h-7 w-7
                        items-center justify-center
                        rounded-lg
                        text-sm
                        text-slate-400
                        hover:bg-slate-100
                        dark:hover:bg-slate-700
                    "
                >
                    <i class="bi bi-three-dots"></i>
                </button>

                <div class="
                    comment-options-menu
                    absolute left-0 top-8 z-30
                    hidden min-w-[120px]
                    rounded-xl
                    border border-slate-200
                    bg-white p-1
                    shadow-xl
                    dark:border-slate-700
                    dark:bg-slate-800
                ">

                    <button
                        type="button"
                        class="
                            btn-edit-comment
                            flex w-full items-center gap-2
                            rounded-lg px-3 py-2
                            text-left text-sm font-medium
                            text-slate-700
                            hover:bg-slate-100
                            dark:text-slate-200
                            dark:hover:bg-slate-700
                        "
                    >
                        <i class="bi bi-pencil"></i>
                        Edit
                    </button>

                    <button
                        type="button"
                        class="
                            btn-delete-comment
                            flex w-full items-center gap-2
                            rounded-lg px-3 py-2
                            text-left text-sm font-medium
                            text-red-600
                            hover:bg-red-50
                            dark:text-red-400
                            dark:hover:bg-red-950/40
                        "
                    >
                        <i class="bi bi-trash3"></i>
                        Delete
                    </button>

                </div>

            </div>

        </div>

        <div class="
            comment-message
            mt-1
            w-fit
            max-w-[min(80vw,520px)]
            rounded-xl
            rounded-tl-sm
            bg-slate-100
            px-3 py-1.5
            text-left
            text-[14px]
            leading-5
            text-slate-700
            dark:bg-slate-800
            dark:text-slate-200
        ">
            '.esc($comment).'
        </div>

        <div class="comment-edit-area mt-2 hidden">

            <input
                type="text"
                maxlength="500"
                value="'.esc($comment).'"
                class="
                    comment-edit-input
                    min-h-10
                    w-full
                    max-w-md
                    rounded-xl
                    border border-slate-300
                    bg-white
                    px-3
                    text-sm
                    text-slate-800
                    outline-none
                    focus:border-emerald-500
                    focus:ring-4
                    focus:ring-emerald-100
                    dark:border-slate-700
                    dark:bg-slate-800
                    dark:text-slate-100
                    dark:focus:ring-emerald-950
                "
            >

            <div class="mt-2 flex gap-2">

                <button
                    type="button"
                    class="
                        btn-save-comment
                        rounded-lg
                        bg-emerald-700
                        px-3 py-1.5
                        text-xs font-semibold
                        text-white
                    "
                >
                    Save
                </button>

                <button
                    type="button"
                    class="
                        btn-cancel-edit
                        rounded-lg
                        bg-slate-100
                        px-3 py-1.5
                        text-xs font-semibold
                        text-slate-700
                        dark:bg-slate-700
                        dark:text-slate-200
                    "
                >
                    Cancel
                </button>

            </div>

        </div>

        <div class="
            mt-1
            text-[10px]
            font-medium
            text-slate-400
            dark:text-slate-500
        ">
            Just now
        </div>

    </div>

</div>' : ''
    ]);
    exit;
  }
/*
|--------------------------------------------------------------------------
| Edit own comment
|--------------------------------------------------------------------------
*/

if ($action === 'edit_comment_ann') {

    if ($isTenant) {
        echo json_encode([
            'success' => false,
            'message' => 'You do not have access to this action.'
        ]);
        exit;
    }

    $commentId =
        (int)($_POST['comment_id'] ?? 0);

    $comment =
        trim((string)($_POST['comment'] ?? ''));

    if (
        $commentId <= 0 ||
        $comment === ''
    ) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid comment.'
        ]);
        exit;
    }

    if (mb_strlen($comment) > 500) {
        echo json_encode([
            'success' => false,
            'message' => 'Comment must not exceed 500 characters.'
        ]);
        exit;
    }


    $stmt = $conn->prepare("
        UPDATE announcement_comments
        SET comment = ?
        WHERE id = ?
          AND homeowner_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "sii",
        $comment,
        $commentId,
        $hid
    );

    $ok = $stmt->execute();

    $changed =
        $stmt->affected_rows > 0;

    $stmt->close();


    echo json_encode([
        'success' => $ok,
        'changed' => $changed,
        'comment' => $comment,
        'message' =>
            $ok
                ? 'Comment updated.'
                : 'Unable to update comment.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Delete own comment
|--------------------------------------------------------------------------
*/

if ($action === 'delete_comment_ann') {

    if ($isTenant) {
        echo json_encode([
            'success' => false,
            'message' => 'You do not have access to this action.'
        ]);
        exit;
    }


    $commentId =
        (int)($_POST['comment_id'] ?? 0);

    if ($commentId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid comment.'
        ]);
        exit;
    }


    /*
    | Get announcement first so we can return the new count
    */

    $stmt = $conn->prepare("
        SELECT announcement_id
        FROM announcement_comments
        WHERE id = ?
          AND homeowner_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "ii",
        $commentId,
        $hid
    );

    $stmt->execute();

    $row =
        $stmt->get_result()->fetch_assoc();

    $stmt->close();


    if (!$row) {
        echo json_encode([
            'success' => false,
            'message' => 'Comment not found or you cannot delete it.'
        ]);
        exit;
    }


    $announcementId =
        (int)$row['announcement_id'];


    $stmt = $conn->prepare("
        DELETE FROM announcement_comments
        WHERE id = ?
          AND homeowner_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "ii",
        $commentId,
        $hid
    );

    $ok = $stmt->execute();

    $stmt->close();


    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM announcement_comments
        WHERE announcement_id = ?
    ");

    $countStmt->bind_param(
        "i",
        $announcementId
    );

    $countStmt->execute();

    $commentCount =
        (int)(
            $countStmt
                ->get_result()
                ->fetch_assoc()['total']
            ?? 0
        );

    $countStmt->close();


    echo json_encode([
        'success' => $ok,
        'comment_count' => $commentCount,
        'message' =>
            $ok
                ? 'Comment deleted.'
                : 'Unable to delete comment.'
    ]);

    exit;
}
  if ($action === 'mark_seen') {
    $target = (string)($_POST['target'] ?? 'all');

    if ($target === 'ann') {
      $stmt = $conn->prepare("UPDATE homeowner_feed_state SET last_ann_seen = NOW() WHERE homeowner_id=?");
      $stmt->bind_param("i", $hid);
      $ok = $stmt->execute();
      $stmt->close();
      echo json_encode(['success'=>$ok]);
      exit;
    }

    if ($target === 'comments') {
      $stmt = $conn->prepare("UPDATE homeowner_feed_state SET last_comment_seen = NOW() WHERE homeowner_id=?");
      $stmt->bind_param("i", $hid);
      $ok = $stmt->execute();
      $stmt->close();
      echo json_encode(['success'=>$ok]);
      exit;
    }

    $stmt = $conn->prepare("UPDATE homeowner_feed_state SET last_ann_seen = NOW(), last_comment_seen = NOW() WHERE homeowner_id=?");
    $stmt->bind_param("i", $hid);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success'=>$ok]);
    exit;
  }

  echo json_encode(['success'=>false,'message'=>'Unknown action.']);
  exit;
}

$stmt = $conn->prepare("SELECT last_ann_seen, last_comment_seen FROM homeowner_feed_state WHERE homeowner_id=? LIMIT 1");
$stmt->bind_param("i", $hid);
$stmt->execute();
$state = $stmt->get_result()->fetch_assoc() ?: ['last_ann_seen'=>date('Y-m-d H:i:s'), 'last_comment_seen'=>date('Y-m-d H:i:s')];
$stmt->close();

$lastAnnSeen = (string)$state['last_ann_seen'];
$lastComSeen = (string)$state['last_comment_seen'];
$houseLot = (string)($user['house_lot_number'] ?? '');

date_default_timezone_set('Asia/Manila');
$now  = new DateTime('now');
$curYear  = (int)$now->format('Y');
$curMonth = (int)$now->format('n');

$monthlyDues = 0.00;
$stmt = $conn->prepare("SELECT monthly_dues FROM finance_dues_settings WHERE phase=? LIMIT 1");
$stmt->bind_param("s", $phase);
$stmt->execute();
$monthlyDues = (float)(($stmt->get_result()->fetch_assoc()['monthly_dues'] ?? 0) ?: 0);
$stmt->close();

$paidMonths = [];
$paidRowsByMonth = [];
$stmt = $conn->prepare("
  SELECT pay_month, status, amount, paid_at, reference_no
  FROM finance_payments
  WHERE homeowner_id=? AND phase=? AND pay_year=? AND pay_month BETWEEN 1 AND ?
");
$stmt->bind_param("isii", $hid, $phase, $curYear, $curMonth);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()){
  $m = (int)$r['pay_month'];
  $paidRowsByMonth[$m] = $r;
  if (($r['status'] ?? 'paid') === 'paid') $paidMonths[$m] = true;
}
$stmt->close();

$duesStartMonthThisYear = 1;
if ($accountStartYear === $curYear) {
  $duesStartMonthThisYear = $accountStartMonth;
}

$dueMonths = [];
if ($accountStartYear <= $curYear) {
  for ($m = $duesStartMonthThisYear; $m <= $curMonth; $m++) {
    $dueMonths[] = $m;
  }
}

$unpaidMonths = [];
foreach ($dueMonths as $m) {
  if (empty($paidMonths[$m])) $unpaidMonths[] = $m;
}

$curMonthIsApplicable = in_array($curMonth, $dueMonths, true);
$curMonthPaid = !$curMonthIsApplicable ? true : !in_array($curMonth, $unpaidMonths, true);
$nextDueMonth = !empty($unpaidMonths) ? (int)$unpaidMonths[0] : 0;

function month_name($m){
  return date('F', mktime(0,0,0,(int)$m,1));
}

$annFeed = [];
$stmt = $conn->prepare("
  SELECT
    a.id, a.title, a.message, a.category, a.priority, a.start_date, a.end_date, a.created_at,
    a.audience, a.audience_value,
    (SELECT COUNT(*) FROM announcement_likes al WHERE al.announcement_id=a.id) AS like_count,
    (SELECT COUNT(*) FROM announcement_comments ac WHERE ac.announcement_id=a.id) AS comment_count,
    (SELECT COUNT(*) FROM announcement_likes al2 WHERE al2.announcement_id=a.id AND al2.homeowner_id=?) AS i_liked
  FROM announcements a
  LEFT JOIN announcement_recipients ar
    ON ar.announcement_id = a.id
   AND ar.recipient_type = 'homeowner'
   AND ar.homeowner_id = ?
  WHERE
    (a.phase = ? OR a.phase = 'Superadmin')
    AND a.start_date <= CURDATE()
    AND (a.end_date IS NULL OR a.end_date >= CURDATE())
    AND (
      a.audience = 'all'
      OR (a.audience = 'selected' AND ar.id IS NOT NULL)
      OR (a.audience = 'block' AND a.audience_value IS NOT NULL AND a.audience_value <> '' AND LOWER(?) LIKE CONCAT('%', LOWER(a.audience_value), '%'))
    )
  GROUP BY a.id
  ORDER BY FIELD(a.priority,'urgent','important','normal'), a.start_date DESC, a.created_at DESC
  LIMIT 25
");
$stmt->bind_param("iiss", $hid, $hid, $phase, $houseLot);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $annFeed[] = $r;
$stmt->close();

$stmt = $conn->prepare("
  SELECT COUNT(*) c
  FROM announcements a
  LEFT JOIN announcement_recipients ar
    ON ar.announcement_id = a.id
   AND ar.recipient_type='homeowner'
   AND ar.homeowner_id=?
  WHERE
    (a.phase = ? OR a.phase='Superadmin')
    AND a.created_at > ?
    AND a.start_date <= CURDATE()
    AND (a.end_date IS NULL OR a.end_date >= CURDATE())
    AND (
      a.audience='all'
      OR (a.audience='selected' AND ar.id IS NOT NULL)
      OR (a.audience='block' AND a.audience_value IS NOT NULL AND a.audience_value <> '' AND LOWER(?) LIKE CONCAT('%', LOWER(a.audience_value), '%'))
    )
");
$stmt->bind_param("isss", $hid, $phase, $lastAnnSeen, $houseLot);
$stmt->execute();
$newAnnCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("
  SELECT COUNT(*) c
  FROM announcement_comments ac
  JOIN announcements a ON a.id = ac.announcement_id
  LEFT JOIN announcement_recipients ar
    ON ar.announcement_id=a.id
   AND ar.recipient_type='homeowner'
   AND ar.homeowner_id=?
  WHERE
    (a.phase = ? OR a.phase='Superadmin')
    AND ac.created_at > ?
    AND (
      a.audience='all'
      OR (a.audience='selected' AND ar.id IS NOT NULL)
      OR (a.audience='block' AND a.audience_value IS NOT NULL AND a.audience_value <> '' AND LOWER(?) LIKE CONCAT('%', LOWER(a.audience_value), '%'))
    )
    AND ac.homeowner_id <> ?
");
$stmt->bind_param("isssi", $hid, $phase, $lastComSeen, $houseLot, $hid);
$stmt->execute();
$newComCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$notifCount = $newAnnCount + $newComCount;
$notifItems = [];

$stmt = $conn->prepare("
  SELECT a.id, a.title, a.created_at, 'announcement' AS kind
  FROM announcements a
  LEFT JOIN announcement_recipients ar
    ON ar.announcement_id=a.id
   AND ar.recipient_type='homeowner'
   AND ar.homeowner_id=?
  WHERE
    (a.phase = ? OR a.phase='Superadmin')
    AND a.created_at > ?
    AND a.start_date <= CURDATE()
    AND (a.end_date IS NULL OR a.end_date >= CURDATE())
    AND (
      a.audience='all'
      OR (a.audience='selected' AND ar.id IS NOT NULL)
      OR (a.audience='block' AND a.audience_value IS NOT NULL AND a.audience_value <> '' AND LOWER(?) LIKE CONCAT('%', LOWER(a.audience_value), '%'))
    )
  GROUP BY a.id
  ORDER BY a.created_at DESC
  LIMIT 6
");
$stmt->bind_param("isss", $hid, $phase, $lastAnnSeen, $houseLot);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $notifItems[] = $r;
$stmt->close();

$stmt = $conn->prepare("
  SELECT ac.id, ac.created_at, 'comment' AS kind,
         CONCAT(h.first_name,' ',h.last_name) AS actor_name,
         LEFT(ac.comment, 90) AS snippet
  FROM announcement_comments ac
  JOIN announcements a ON a.id=ac.announcement_id
  JOIN homeowners h ON h.id=ac.homeowner_id
  LEFT JOIN announcement_recipients ar
    ON ar.announcement_id=a.id
   AND ar.recipient_type='homeowner'
   AND ar.homeowner_id=?
  WHERE
    (a.phase = ? OR a.phase='Superadmin')
    AND ac.created_at > ?
    AND ac.homeowner_id <> ?
    AND (
      a.audience='all'
      OR (a.audience='selected' AND ar.id IS NOT NULL)
      OR (a.audience='block' AND a.audience_value IS NOT NULL AND a.audience_value <> ''
          AND LOWER(?) LIKE CONCAT('%', LOWER(a.audience_value), '%'))
    )
  ORDER BY ac.created_at DESC
  LIMIT 6
");
$stmt->bind_param("issis", $hid, $phase, $lastComSeen, $hid, $houseLot);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $notifItems[] = $r;
$stmt->close();

usort($notifItems, function($a,$b){
  return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});
$notifItems = array_slice($notifItems, 0, 8);

$commentsByAnn = [];
$attachmentsByAnn = [];

if (!empty($annFeed)) {
  $ids = array_map(
    fn($a) => (int)$a['id'],
    $annFeed
  );

  $in = implode(
    ',',
    array_fill(
      0,
      count($ids),
      '?'
    )
  );

  $types = str_repeat(
    'i',
    count($ids)
  );

  /*
  |--------------------------------------------------------------------------
  | Load comments for visible announcements
  |--------------------------------------------------------------------------
  */
  $sql = "
    SELECT
      ac.id,
      ac.announcement_id,
      ac.homeowner_id,
      ac.comment,
      ac.created_at,
      h.first_name,
      h.last_name,
      h.profile_picture_path
    FROM announcement_comments ac
    JOIN homeowners h
      ON h.id = ac.homeowner_id
    WHERE ac.announcement_id IN ($in)
    ORDER BY ac.created_at ASC
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$ids);
  $stmt->execute();
  $res = $stmt->get_result();

  while ($r = $res->fetch_assoc()) {
    $aid =
      (int)$r['announcement_id'];

    if (!isset($commentsByAnn[$aid])) {
      $commentsByAnn[$aid] = [];
    }

    $commentsByAnn[$aid][] = $r;
  }

  $stmt->close();

  /*
  |--------------------------------------------------------------------------
  | Load attachments for visible announcements
  |--------------------------------------------------------------------------
  */
  $attachmentSql = "
    SELECT
      id,
      announcement_id,
      original_name,
      stored_name,
      file_path,
      mime_type,
      file_size
    FROM announcement_attachments
    WHERE announcement_id IN ($in)
    ORDER BY id ASC
  ";

  $stmt =
    $conn->prepare(
      $attachmentSql
    );

  $stmt->bind_param(
    $types,
    ...$ids
  );

  $stmt->execute();

  $attachmentResult =
    $stmt->get_result();

  while (
    $attachment =
      $attachmentResult->fetch_assoc()
  ) {
    $announcementId =
      (int)$attachment['announcement_id'];

    if (
      !isset(
        $attachmentsByAnn[
          $announcementId
        ]
      )
    ) {
      $attachmentsByAnn[
        $announcementId
      ] = [];
    }

    $attachmentsByAnn[
      $announcementId
    ][] = $attachment;
  }

  $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Home Location
|--------------------------------------------------------------------------
*/

/*
 * Start with the newer dedicated database columns.
 */
$homeBlock =
    (int)(
        $user['block']
        ?? 0
    );

$homeLot =
    (int)(
        $user['lot']
        ?? 0
    );

$homeStreet =
    trim(
        (string)(
            $user['street']
            ?? ''
        )
    );

$homeMapX =
    (float)(
        $user['map_x']
        ?? 0
    );

$homeMapY =
    (float)(
        $user['map_y']
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| Legacy Block / Lot fallback
|--------------------------------------------------------------------------
|
| Older homeowner records may only have:
|
|   house_lot_number = "Block 2 Lot 3"
|
| and may not yet have values in the newer
| block, lot, map_x and map_y columns.
|
*/

if (
    $homeBlock <= 0 ||
    $homeLot <= 0
) {

    $legacyHouseLot =
        trim(
            (string)(
                $user['house_lot_number']
                ?? ''
            )
        );


    if (
        preg_match(
            '/(?:block|b)\s*[-:]?\s*(\d+)\D+(?:lot|l)\s*[-:]?\s*(\d+)/i',
            $legacyHouseLot,
            $matches
        )
    ) {

        $homeBlock =
            (int)$matches[1];

        $homeLot =
            (int)$matches[2];
    }
}


/*
|--------------------------------------------------------------------------
| Resolve location from official South Meridian map
|--------------------------------------------------------------------------
|
| If map_x/map_y are missing, locate the property
| using the official Block/Lot mapping JSON.
|
*/

if (
    $homeBlock > 0 &&
    $homeLot > 0 &&
    (
        $homeMapX <= 0 ||
        $homeMapY <= 0
    )
) {

    /*
     * homeowner_dashboard.php is inside /homeowner/
     *
     * Mapping JSON is inside /admin/
     */
    $mappingPath =
        dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        'admin' .
        DIRECTORY_SEPARATOR .
        'southmeri_block_lot_mapping.json';


    if (is_readable($mappingPath)) {

        $mappingData =
            json_decode(
                (string)file_get_contents(
                    $mappingPath
                ),
                true
            );


        if (is_array($mappingData)) {

            /*
             * Same conversion used by your
             * official admin Block/Lot map.
             */
            $pageWidthEmu =
                8.5 * 914400;

            $pageHeightEmu =
                11 * 914400;

            $pageMarginEmu =
                914400;

            $markerCenterOffsetEmu =
                90000;


            foreach ($mappingData as $location) {

                $locationBlock =
                    (int)(
                        $location['block']
                        ?? 0
                    );

                $locationLot =
                    (int)(
                        $location['lot']
                        ?? 0
                    );


                if (
                    $locationBlock !== $homeBlock ||
                    $locationLot !== $homeLot
                ) {
                    continue;
                }


                $xEmu =
                    (float)(
                        $location['x_emu']
                        ?? 0
                    );

                $yEmu =
                    (float)(
                        $location['y_emu']
                        ?? 0
                    );


                $homeMapX =
                    (float)round(
                        (
                            (
                                $pageMarginEmu +
                                $xEmu +
                                $markerCenterOffsetEmu
                            )
                            /
                            $pageWidthEmu
                        )
                        * 2550,
                        2
                    );


                $homeMapY =
                    (float)round(
                        (
                            (
                                $pageMarginEmu +
                                $yEmu +
                                $markerCenterOffsetEmu
                            )
                            /
                            $pageHeightEmu
                        )
                        * 3300,
                        2
                    );


                /*
                 * Get official street name too.
                 */
                if ($homeStreet === '') {

                    $homeStreet =
                        trim(
                            (string)(
                                $location['street']
                                ?? ''
                            )
                        );
                }


                break;
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Determine available map
|--------------------------------------------------------------------------
*/

$hasSubdivisionMap =
    $homeBlock > 0 &&
    $homeLot > 0 &&
    $homeMapX > 0 &&
    $homeMapY > 0;


/*
 * Older GPS coordinates remain available
 * as the last fallback.
 */
$lat =
    $user['latitude']
    ?? null;

$lng =
    $user['longitude']
    ?? null;


$hasLegacyGps =
    !$hasSubdivisionMap &&
    is_numeric($lat) &&
    is_numeric($lng) &&
    (float)$lat != 0 &&
    (float)$lng != 0;
$chatPages = ['homeowner_public_chat.php'];
$chatOpen = in_array($activePage, $chatPages, true);

$accessDeniedMsg = $_SESSION['access_denied'] ?? '';
unset($_SESSION['access_denied']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= esc($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- Dark mode initialization -->
<script>
(function () {
    const savedTheme =
        localStorage.getItem('hoa-theme');

    const systemDark =
        window.matchMedia(
            '(prefers-color-scheme: dark)'
        ).matches;

    const useDark =
        savedTheme === 'dark' ||
        (!savedTheme && systemDark);

    document.documentElement.classList.toggle(
        'dark',
        useDark
    );
})();
</script>

<!-- Tailwind manual dark mode -->
<style type="text/tailwindcss">
    @custom-variant dark (&:where(.dark, .dark *));
</style>

<!-- Tailwind CSS -->
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

<!-- Bootstrap Icons only -->
<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css"
>

<!-- Leaflet -->
<link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>

<body
    class="
        bg-slate-50
        text-slate-900
        antialiased
        transition-colors
        duration-200

        dark:bg-slate-950
        dark:text-slate-100
    "
>


<?php if ($accessDeniedMsg !== ''): ?>

<div
    id="accessDeniedToast"
    class="
        fixed right-4 top-4 z-[100]
        w-[calc(100%-2rem)]
        max-w-md
        rounded-2xl
        border border-red-200
        bg-white
        shadow-xl
        
        dark:bg-slate-900 dark:border-red-900
"
>

    <div class="flex items-start gap-3 p-4">

        <div class="
            flex h-11 w-11 shrink-0
            items-center justify-center
            rounded-xl
            bg-red-100
            text-xl
            text-red-700
            
            dark:bg-red-950/60 dark:text-red-300
">
            <i class="bi bi-shield-exclamation"></i>
        </div>

        <div class="min-w-0 flex-1">

            <p class="font-bold text-slate-900 dark:text-slate-100">
                Access Denied
            </p>

            <p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-400">
                <?= esc($accessDeniedMsg) ?>
            </p>

        </div>

        <button
            type="button"
            id="accessDeniedClose"
            class="
                flex h-10 w-10 shrink-0
                items-center justify-center
                rounded-xl
                text-slate-500
                transition
                hover:bg-slate-100
                hover:text-slate-800
                
                dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-slate-100
"
            aria-label="Close notification"
        >
            <i class="bi bi-x-lg"></i>
        </button>

    </div>

</div>

<?php endif; ?>


<!-- Mobile sidebar overlay -->

<div
    id="sidebarOverlay"
    class="
        fixed inset-0 z-50
        hidden
        bg-slate-950/50
        backdrop-blur-[1px]
        lg:hidden
    "
></div>


<?php include 'homeowner_sidebar.php'; ?>


<!-- =========================================================
     MAIN AREA
     ========================================================= -->

<div class="min-h-screen lg:ml-[280px]">


    <!-- =====================================================
         TOP BAR
         ===================================================== -->

<header
    class="
        sticky top-0 z-40
        border-b border-slate-200
        bg-white/95
        backdrop-blur
        transition-colors

        dark:border-slate-800
        dark:bg-slate-900/95
    "
>

        <div
            class="
                mx-auto
                flex min-h-[72px]
                max-w-7xl
                items-center
                gap-3
                px-4
                sm:px-6
            "
        >

            <!-- Mobile menu -->

            <button
                type="button"
                id="sidebarToggle"
                class="
                    flex h-12 w-12
                    shrink-0
                    items-center justify-center
                    rounded-xl
                    border border-slate-200
                    bg-white
                    text-2xl
                    text-slate-700
                    shadow-sm
                    transition
                    hover:bg-slate-50
dark:bg-slate-800/70
                    focus:outline-none
                    focus:ring-4
                    focus:ring-emerald-100

                    lg:hidden
                    
                    dark:text-slate-300 dark:border-slate-800 dark:hover:bg-slate-800 dark:focus:ring-emerald-950
"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>


            <!-- Brand -->

            <a
                href="homeowner_dashboard.php"
                class="min-w-0"
            >

                <div
                    class="
                        truncate
                        text-base
                        font-bold
                        text-emerald-800

                        sm:text-lg
                        
                        dark:text-emerald-300
"
                >
                    HOA Community
                </div>

                <div
                    class="
                        hidden
                        text-xs
                        font-medium
                        text-slate-500

                        sm:block
                        
                        dark:text-slate-400
"
                >
                    South Meridian Homes Salitran
                </div>

            </a>


            <div
                class="
                    ml-auto
                    flex items-center
                    gap-2

                    sm:gap-3
                "
            >

<!-- Theme Toggle -->
<button
    type="button"
    id="themeToggle"
    class="
        flex h-12 w-12
        shrink-0
        items-center justify-center
        rounded-xl
        border border-slate-200
        bg-white
        text-xl
        text-slate-700
        shadow-sm
        transition

        hover:border-emerald-200
        hover:bg-emerald-50
        hover:text-emerald-800

        focus:outline-none
        focus:ring-4
        focus:ring-emerald-100

        dark:border-slate-700
        dark:bg-slate-900
        dark:text-slate-200
        dark:hover:border-slate-600
        dark:hover:bg-slate-800
        dark:hover:text-emerald-300
        dark:focus:ring-emerald-950
    "
    aria-label="Switch to dark mode"
    title="Switch to dark mode"
>
    <i
        id="themeIcon"
        class="bi bi-moon-stars-fill"
    ></i>
</button>
                <!-- =================================================
                     NOTIFICATIONS
                     ================================================= -->

                <div class="relative">

                    <button
                        type="button"
                        id="notificationToggle"
                        class="
                            relative
                            flex h-12 w-12
                            items-center justify-center
                            rounded-xl
                            border border-slate-200
                            bg-white
                            text-xl
                            text-slate-700
                            shadow-sm
                            transition
                            hover:border-emerald-200
                            hover:bg-emerald-50
                            hover:text-emerald-800
                            focus:outline-none
                            focus:ring-4
                            focus:ring-emerald-100
                            
                            dark:bg-slate-900 dark:text-slate-300 dark:border-slate-800 dark:hover:bg-emerald-950/50 dark:hover:text-emerald-300 dark:hover:border-emerald-800 dark:focus:ring-emerald-950
"
                        aria-label="Open notifications"
                        aria-expanded="false"
                    >

                        <i class="bi bi-bell-fill"></i>


                        <?php if ($notifCount > 0): ?>

                            <span
                                class="
                                    absolute
                                    -right-1.5
                                    -top-1.5
                                    flex min-h-5
                                    min-w-5
                                    items-center justify-center
                                    rounded-full
                                    border-2 border-white
                                    bg-red-600
                                    px-1.5
                                    text-[10px]
                                    font-bold
                                    text-white
                                "
                            >
                                <?= (int)$notifCount ?>
                            </span>

                        <?php endif; ?>

                    </button>


                    <!-- Notification panel -->

                    <div
                        id="notificationMenu"
class="
    absolute right-0
    mt-3
    hidden
    w-[min(92vw,390px)]
    overflow-hidden
    rounded-2xl
    border border-slate-200
    bg-white
    shadow-2xl

    dark:border-slate-700
    dark:bg-slate-900
"
                    >

                        <div
                            class="
                                flex items-center
                                justify-between
                                gap-3
                                border-b
                                border-slate-200
                                p-4
                                
                                dark:border-slate-800
"
                        >

                            <div>

                                <h2
                                    class="
                                        text-base
                                        font-bold
                                        text-slate-900
                                        
                                        dark:text-slate-100
"
                                >
                                    Notifications
                                </h2>

                                <p
                                    class="
                                        mt-0.5
                                        text-xs
                                        text-slate-500
                                        
                                        dark:text-slate-400
"
                                >
                                    Recent community updates
                                </p>

                            </div>


                            <button
                                type="button"
                                id="btnMarkAllSeen"
                                class="
                                    min-h-10
                                    rounded-xl
                                    bg-emerald-50
                                    px-3
                                    text-xs
                                    font-bold
                                    text-emerald-800
                                    transition
                                    hover:bg-emerald-100
                                    
                                    dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/50
"
                            >
                                Mark all seen
                            </button>

                        </div>


                        <div
                            class="
                                max-h-[380px]
                                overflow-y-auto
                                p-2
                            "
                        >

                            <?php if (empty($notifItems)): ?>

                                <div
                                    class="
                                        flex flex-col
                                        items-center
                                        justify-center
                                        px-5 py-10
                                        text-center
                                    "
                                >

                                    <div
                                        class="
                                            flex h-14 w-14
                                            items-center justify-center
                                            rounded-2xl
                                            bg-slate-100
                                            text-2xl
                                            text-slate-500
                                            
                                            dark:bg-slate-800 dark:text-slate-400
"
                                    >
                                        <i class="bi bi-bell-slash"></i>
                                    </div>

                                    <p
                                        class="
                                            mt-3
                                            font-semibold
                                            text-slate-700
                                            
                                            dark:text-slate-300
"
                                    >
                                        You're all caught up
                                    </p>

                                    <p
                                        class="
                                            mt-1
                                            text-sm
                                            text-slate-500
                                            
                                            dark:text-slate-400
"
                                    >
                                        No new notifications.
                                    </p>

                                </div>

                            <?php else: ?>

                                <?php foreach ($notifItems as $n): ?>

                                    <div
                                        class="
                                            m-1
                                            rounded-xl
                                            border border-slate-100
                                            bg-slate-50
dark:bg-slate-800/70
                                            p-3
                                            
                                            dark:border-slate-800
"
                                    >

                                        <?php if ($n['kind'] === 'announcement'): ?>

                                            <div
                                                class="
                                                    flex items-start
                                                    gap-3
                                                "
                                            >

                                                <div
                                                    class="
                                                        flex h-10 w-10
                                                        shrink-0
                                                        items-center justify-center
                                                        rounded-xl
                                                        bg-emerald-100
                                                        text-emerald-700
                                                        
                                                        dark:bg-emerald-950/60 dark:text-emerald-400
"
                                                >
                                                    <i class="bi bi-megaphone-fill"></i>
                                                </div>

                                                <div class="min-w-0">

                                                    <p
                                                        class="
                                                            text-sm
                                                            font-bold
                                                            text-slate-900
                                                            
                                                            dark:text-slate-100
"
                                                    >
                                                        New announcement
                                                    </p>

                                                    <p
                                                        class="
                                                            mt-0.5
                                                            break-words
                                                            text-sm
                                                            font-medium
                                                            text-slate-700
                                                            
                                                            dark:text-slate-300
"
                                                    >
                                                        <?= esc($n['title']) ?>
                                                    </p>

                                                    <p
                                                        class="
                                                            mt-1
                                                            text-xs
                                                            text-slate-500
                                                            
                                                            dark:text-slate-400
"
                                                    >
                                                        <?= esc(
                                                            date(
                                                                'M d, Y h:i A',
                                                                strtotime($n['created_at'])
                                                            )
                                                        ) ?>
                                                    </p>

                                                </div>

                                            </div>

                                        <?php else: ?>

                                            <div
                                                class="
                                                    flex items-start
                                                    gap-3
                                                "
                                            >

                                                <div
                                                    class="
                                                        flex h-10 w-10
                                                        shrink-0
                                                        items-center justify-center
                                                        rounded-xl
                                                        bg-blue-100
                                                        text-blue-700
                                                        
                                                        dark:bg-blue-950/60 dark:text-blue-300
"
                                                >
                                                    <i class="bi bi-chat-left-dots-fill"></i>
                                                </div>

                                                <div class="min-w-0">

                                                    <p
                                                        class="
                                                            text-sm
                                                            font-bold
                                                            text-slate-900
                                                            
                                                            dark:text-slate-100
"
                                                    >
                                                        New comment
                                                    </p>

                                                    <p
                                                        class="
                                                            mt-0.5
                                                            text-sm
                                                            font-semibold
                                                            text-slate-700
                                                            
                                                            dark:text-slate-300
"
                                                    >
                                                        <?= esc($n['actor_name'] ?? 'Someone') ?>
                                                    </p>

                                                    <p
                                                        class="
                                                            mt-0.5
                                                            break-words
                                                            text-sm
                                                            text-slate-600
                                                            
                                                            dark:text-slate-400
"
                                                    >
                                                        <?= esc($n['snippet'] ?? '') ?>

                                                        <?= strlen($n['snippet'] ?? '') >= 90
                                                            ? '…'
                                                            : ''
                                                        ?>
                                                    </p>

                                                    <p
                                                        class="
                                                            mt-1
                                                            text-xs
                                                            text-slate-500
                                                            
                                                            dark:text-slate-400
"
                                                    >
                                                        <?= esc(
                                                            date(
                                                                'M d, Y h:i A',
                                                                strtotime($n['created_at'])
                                                            )
                                                        ) ?>
                                                    </p>

                                                </div>

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </div>


                        <div
                            class="
                                grid grid-cols-2
                                gap-2
                                border-t
                                border-slate-200
                                p-3
                                
                                dark:border-slate-800
"
                        >

                            <button
                                id="btnSeenAnn"
                                type="button"
                                class="
                                    min-h-11
                                    rounded-xl
                                    border border-slate-200
                                    px-3
                                    text-xs
                                    font-semibold
                                    text-slate-700
                                    transition
                                    hover:bg-slate-50
dark:bg-slate-800/70
                                    
                                    dark:text-slate-300 dark:border-slate-800 dark:hover:bg-slate-800
"
                            >
                                Seen announcements
                            </button>

                            <button
                                id="btnSeenCom"
                                type="button"
                                class="
                                    min-h-11
                                    rounded-xl
                                    border border-slate-200
                                    px-3
                                    text-xs
                                    font-semibold
                                    text-slate-700
                                    transition
                                    hover:bg-slate-50
dark:bg-slate-800/70
                                    
                                    dark:text-slate-300 dark:border-slate-800 dark:hover:bg-slate-800
"
                            >
                                Seen comments
                            </button>

                        </div>

                    </div>

                </div>


                <!-- Desktop account -->

                <div
                    class="
                        hidden
                        text-right

                        md:block
                    "
                >

                    <div
                        class="
                            max-w-[220px]
                            truncate
                            text-sm
                            font-bold
                            text-slate-800
                            
                            dark:text-slate-200
"
                    >
                        <?= esc($fullName) ?>
                    </div>

                    <div
                        class="
                            text-xs
                            font-medium
                            text-slate-500
                            
                            dark:text-slate-400
"
                    >
                        <?= esc($phase) ?>

                        <?= $isTenant
                            ? ' • Tenant'
                            : ''
                        ?>
                    </div>

                </div>


                <!-- Logout -->

                <a
                    href="logout.php"
                    class="
                        flex min-h-12
                        items-center
                        justify-center
                        gap-2
                        rounded-xl
                        border border-slate-200
                        bg-white
                        px-3
                        text-sm
                        font-semibold
                        text-slate-700
                        transition
                        hover:border-red-200
                        hover:bg-red-50
                        hover:text-red-700

                        sm:px-4
                        
                        dark:bg-slate-900 dark:text-slate-300 dark:border-slate-800 dark:hover:bg-red-950/40 dark:hover:text-red-300 dark:hover:border-red-800
"
                >
                    <i class="bi bi-box-arrow-right text-lg"></i>

                    <span class="hidden sm:inline">
                        Logout
                    </span>
                </a>

            </div>

        </div>

    </header>


    <!-- =====================================================
         PAGE CONTENT
         ===================================================== -->

    <main
        class="
            mx-auto
            max-w-7xl
            px-4
            py-6

            sm:px-6
            sm:py-8
        "
    >


        <!-- =================================================
             WELCOME
             ================================================= -->

        <section class="mb-6">

            <p
                class="
                    text-sm
                    font-semibold
                    text-emerald-700
                    
                    dark:text-emerald-400
"
            >
                South Meridian Homes Salitran
            </p>

            <h1
                class="
                    mt-1
                    text-2xl
                    font-bold
                    tracking-tight
                    text-slate-900

                    sm:text-3xl
                    
                    dark:text-slate-100
"
            >
                Welcome,
                <?= esc(
                    $isTenant
                        ? ($tenant['first_name'] ?? $fullName)
                        : ($user['first_name'] ?? $fullName)
                ) ?>
                👋
            </h1>

            <p
                class="
                    mt-2
                    max-w-2xl
                    text-[15px]
                    leading-6
                    text-slate-600

                    sm:text-base
                    
                    dark:text-slate-400
"
            >
                View important community updates, manage your dues,
                permits, rentals and other HOA services in one place.
            </p>

        </section>


        <!-- =================================================
             HOME LOCATION
             ================================================= -->

<section
    class="
        relative
        isolate
        z-0

        overflow-hidden
        rounded-3xl
        border border-slate-200
        bg-white
        shadow-sm

        dark:bg-slate-900
        dark:border-slate-800
    "
>

<div
    class="
        relative
        isolate
        h-[220px]
        overflow-hidden
        bg-slate-200

        sm:h-[260px]
        lg:h-[300px]

        dark:bg-slate-800
    "
>

<?php if ($hasSubdivisionMap): ?>

    <!-- Official South Meridian subdivision map -->
    <div
        id="coverMap"
        class="absolute inset-0 h-full w-full"

        data-map-type="subdivision"

        data-map-x="<?= esc($homeMapX) ?>"
        data-map-y="<?= esc($homeMapY) ?>"

        data-block="<?= (int)$homeBlock ?>"
        data-lot="<?= (int)$homeLot ?>"

        data-street="<?= esc($homeStreet) ?>"

        data-map-image="../assets/img/south_meridian_block_lot_map.png"
    ></div>


<?php elseif ($hasLegacyGps): ?>

    <!-- Older homeowner record -->
    <div
        id="coverMap"
        class="absolute inset-0 h-full w-full"

        data-map-type="gps"

        data-lat="<?= esc($lat) ?>"
        data-lng="<?= esc($lng) ?>"
    ></div>


<?php else: ?>

    <div
        class="
            flex h-full
            items-center justify-center
            p-6
            text-center
        "
    >

        <div>

            <i
                class="
                    bi bi-geo-alt
                    text-4xl
                    text-slate-400
                    dark:text-slate-500
                "
            ></i>

            <p
                class="
                    mt-3
                    font-semibold
                    text-slate-600
                    dark:text-slate-400
                "
            >
                No home location saved yet.
            </p>

        </div>

    </div>

                <?php endif; ?>


                <div
                    class="
                        absolute left-4 top-4
                        z-[500]
                        rounded-xl
                        border border-white/70
                        bg-white/95
                        px-4 py-2.5
                        shadow-md
                        backdrop-blur
                        
                        dark:bg-slate-900/95 dark:border-slate-700/70
"
                >

                    <div
                        class="
                            text-sm
                            font-bold
                            text-slate-900
                            
                            dark:text-slate-100
"
                    >
                        Your Home Location
                    </div>

                    <div
                        class="
                            mt-0.5
                            text-xs
                            font-medium
                            text-slate-600
                            
                            dark:text-slate-400
"
                    >
                        <?= esc($phase) ?>

<?php if ($homeBlock > 0 && $homeLot > 0): ?>
    • Block <?= (int)$homeBlock ?>,
    Lot <?= (int)$homeLot ?>
<?php endif; ?>
                    </div>

                </div>

            </div>


            <!-- Profile -->

            <div
                class="
                    flex flex-col
                    gap-4
                    p-5

                    sm:flex-row
                    sm:items-center
                    sm:p-6
                "
            >

<div class="relative shrink-0">

    <div
        id="profilePicturePreview"
        class="
            flex h-20 w-20
            items-center justify-center
            overflow-hidden
            rounded-2xl
            bg-emerald-700
            text-2xl
            font-bold
            text-white
            shadow-sm
            ring-2 ring-white
            dark:ring-slate-800
        "
    >

        <?php if ($profilePictureUrl !== ''): ?>

            <img
                src="<?= esc($profilePictureUrl) ?>"
                alt="<?= esc($fullName) ?> profile picture"
                class="h-full w-full object-cover"
            >

        <?php else: ?>

            <span id="profileInitials">
                <?= esc($initials) ?>
            </span>

        <?php endif; ?>

    </div>


    <?php if (!$isTenant): ?>

        <button
            type="button"
            id="changeProfilePictureBtn"
            class="
                absolute -bottom-2 -right-2
                flex h-9 w-9
                items-center justify-center
                rounded-full
                border-2 border-white
                bg-emerald-700
                text-sm
                text-white
                shadow-md
                transition
                hover:bg-emerald-800

                dark:border-slate-900
            "
            title="Change profile picture"
            aria-label="Change profile picture"
        >
            <i class="bi bi-camera-fill"></i>
        </button>

        <input
            type="file"
            id="profilePictureInput"
            accept=".jpg,.jpeg,.png,.webp"
            class="hidden"
        >

    <?php endif; ?>

</div>


                <div class="min-w-0 flex-1">

                    <h2
                        class="
                            break-words
                            text-xl
                            font-bold
                            text-slate-900

                            sm:text-2xl
                            
                            dark:text-slate-100
"
                    >
                        <?= esc($fullName) ?>
                    </h2>

                    <p
                        class="
                            mt-1
                            text-[15px]
                            font-medium
                            text-slate-600
                            
                            dark:text-slate-400
"
                    >
                        <?= esc($phase) ?>
                        •
                        <?= esc($user['house_lot_number'] ?? '') ?>

                        <?php if ($isTenant): ?>
                            • Tenant Account
                        <?php endif; ?>
                    </p>


                    <div
                        class="
                            mt-3
                            flex flex-wrap
                            gap-2
                        "
                    >

                        <span
                            class="
                                inline-flex
                                min-h-9
                                items-center
                                gap-2
                                rounded-xl
                                bg-slate-100
                                px-3
                                text-sm
                                font-medium
                                text-slate-700
                                
                                dark:bg-slate-800 dark:text-slate-300
"
                        >
                            <i class="bi bi-geo-alt-fill text-emerald-700 dark:text-emerald-400"></i>

                            South Meridian Homes
                        </span>

                        <span
                            class="
                                inline-flex
                                min-h-9
                                items-center
                                gap-2
                                rounded-xl
                                bg-slate-100
                                px-3
                                text-sm
                                font-medium
                                text-slate-700
                                
                                dark:bg-slate-800 dark:text-slate-300
"
                        >
                            <i class="bi bi-house-door-fill text-emerald-700 dark:text-emerald-400"></i>

                            <?= esc($user['house_lot_number'] ?? '') ?>
                        </span>

                    </div>

                </div>


                <?php if (
                    !$isTenant ||
                    tenant_can_access(
                        'announcements',
                        $tenant
                    )
                ): ?>

                    <a
                        href="#feed"
                        class="
                            inline-flex
                            min-h-12
                            items-center
                            justify-center
                            gap-2
                            rounded-xl
                            bg-emerald-700
                            px-5
                            text-base
                            font-semibold
                            text-white
                            shadow-sm
                            transition
                            hover:bg-emerald-800
                            focus:outline-none
                            focus:ring-4
                            focus:ring-emerald-100
                            
                            dark:focus:ring-emerald-950
"
                    >
                        <i class="bi bi-megaphone-fill"></i>

                        View Announcements
                    </a>

                <?php endif; ?>

            </div>

        </section>


        <!-- =================================================
             QUICK ACTIONS
             ================================================= -->

        <section class="mt-8">

            <div class="mb-4">

                <h2
                    class="
                        text-xl
                        font-bold
                        text-slate-900
                        
                        dark:text-slate-100
"
                >
                    Quick Actions
                </h2>

                <p
                    class="
                        mt-1
                        text-sm
                        text-slate-500
                        
                        dark:text-slate-400
"
                >
                    Choose what you would like to do.
                </p>

            </div>


            <div
                class="
                    grid
                    gap-4

                    sm:grid-cols-2
                    xl:grid-cols-4
                "
            >


                <?php if (
                    !$isTenant ||
                    tenant_can_access(
                        'announcements',
                        $tenant
                    )
                ): ?>

                    <a
                        href="#feed"
                        class="
                            group
                            rounded-2xl
                            border border-slate-200
                            bg-white
                            p-5
                            shadow-sm
                            transition
                            hover:-translate-y-0.5
                            hover:border-emerald-200
                            hover:shadow-md
                            
                            dark:bg-slate-900 dark:border-slate-800 dark:hover:border-emerald-800
"
                    >

                        <div
                            class="
                                flex h-12 w-12
                                items-center justify-center
                                rounded-xl
                                bg-emerald-100
                                text-xl
                                text-emerald-700
                                
                                dark:bg-emerald-950/60 dark:text-emerald-400
"
                        >
                            <i class="bi bi-megaphone-fill"></i>
                        </div>

                        <h3
                            class="
                                mt-4
                                text-base
                                font-bold
                                text-slate-900
                                
                                dark:text-slate-100
"
                        >
                            Announcements
                        </h3>

                        <p
                            class="
                                mt-1
                                text-sm
                                leading-6
                                text-slate-500
                                
                                dark:text-slate-400
"
                        >
                            <?= (int)$newAnnCount ?>
                            new community update<?= $newAnnCount === 1 ? '' : 's' ?>.
                        </p>

                    </a>

                <?php endif; ?>


                <?php if (
                    !$isTenant ||
                    tenant_can_access(
                        'pay_dues',
                        $tenant
                    )
                ): ?>

                    <a
                        href="homeowner_pay_dues.php"
                        class="
                            group
                            rounded-2xl
                            border border-slate-200
                            bg-white
                            p-5
                            shadow-sm
                            transition
                            hover:-translate-y-0.5
                            hover:border-blue-200
                            hover:shadow-md
                            
                            dark:bg-slate-900 dark:border-slate-800 dark:hover:border-blue-800
"
                    >

                        <div
                            class="
                                flex h-12 w-12
                                items-center justify-center
                                rounded-xl
                                bg-blue-100
                                text-xl
                                text-blue-700
                                
                                dark:bg-blue-950/60 dark:text-blue-300
"
                        >
                            <i class="bi bi-wallet2"></i>
                        </div>

                        <h3
                            class="
                                mt-4
                                text-base
                                font-bold
                                text-slate-900
                                
                                dark:text-slate-100
"
                        >
                            Monthly Dues
                        </h3>

                        <p
                            class="
                                mt-1
                                text-sm
                                leading-6
                                text-slate-500
                                
                                dark:text-slate-400
"
                        >
                            <?= count($unpaidMonths) ?>

                            unpaid
                            month<?= count($unpaidMonths) === 1 ? '' : 's' ?>.
                        </p>

                    </a>

                <?php endif; ?>


                <?php if (
                    !$isTenant ||
                    tenant_can_access(
                        'parking',
                        $tenant
                    )
                ): ?>

                    <a
                        href="homeowner_parking.php"
                        class="
                            group
                            rounded-2xl
                            border border-slate-200
                            bg-white
                            p-5
                            shadow-sm
                            transition
                            hover:-translate-y-0.5
                            hover:border-violet-200
                            hover:shadow-md
                            
                            dark:bg-slate-900 dark:border-slate-800 dark:hover:border-violet-800
"
                    >

                        <div
                            class="
                                flex h-12 w-12
                                items-center justify-center
                                rounded-xl
                                bg-violet-100
                                text-xl
                                text-violet-700
                                
                                dark:bg-violet-950/60 dark:text-violet-300
"
                        >
                            <i class="bi bi-car-front-fill"></i>
                        </div>

                        <h3
                            class="
                                mt-4
                                text-base
                                font-bold
                                text-slate-900
                                
                                dark:text-slate-100
"
                        >
                            Parking
                        </h3>

                        <p
                            class="
                                mt-1
                                text-sm
                                leading-6
                                text-slate-500
                                
                                dark:text-slate-400
"
                        >
                            Manage permits and view violations.
                        </p>

                    </a>

                <?php endif; ?>


                <?php if (
                    !$isTenant ||
                    tenant_can_access(
                        'rentals',
                        $tenant
                    )
                ): ?>

                    <a
                        href="homeowner_rentals.php"
                        class="
                            group
                            rounded-2xl
                            border border-slate-200
                            bg-white
                            p-5
                            shadow-sm
                            transition
                            hover:-translate-y-0.5
                            hover:border-amber-200
                            hover:shadow-md
                            
                            dark:bg-slate-900 dark:border-slate-800 dark:hover:border-amber-800
"
                    >

                        <div
                            class="
                                flex h-12 w-12
                                items-center justify-center
                                rounded-xl
                                bg-amber-100
                                text-xl
                                text-amber-700
                                
                                dark:bg-amber-950/60 dark:text-amber-300
"
                        >
                            <i class="bi bi-calendar2-week-fill"></i>
                        </div>

                        <h3
                            class="
                                mt-4
                                text-base
                                font-bold
                                text-slate-900
                                
                                dark:text-slate-100
"
                        >
                            Facility Rentals
                        </h3>

                        <p
                            class="
                                mt-1
                                text-sm
                                leading-6
                                text-slate-500
                                
                                dark:text-slate-400
"
                        >
                            Check availability and request a booking.
                        </p>

                    </a>

                <?php endif; ?>

            </div>

        </section>


        <!-- =================================================
             MAIN GRID
             ================================================= -->

        <div
            class="
                mt-8
                grid
                gap-6

                xl:grid-cols-[360px_minmax(0,1fr)]
            "
        >


            <!-- =================================================
                 LEFT COLUMN
                 ================================================= -->

            <div class="space-y-6">


                <!-- Your Home -->

                <section
                    class="
                        rounded-2xl
                        border border-slate-200
                        bg-white
                        shadow-sm
                        
                        dark:bg-slate-900 dark:border-slate-800
"
                >

                    <div
                        class="
                            border-b
                            border-slate-200
                            px-5 py-4
                            
                            dark:border-slate-800
"
                    >

                        <h2
                            class="
                                flex items-center
                                gap-2
                                font-bold
                                text-slate-900
                                
                                dark:text-slate-100
"
                        >
                            <i class="bi bi-house-heart-fill text-emerald-700 dark:text-emerald-400"></i>

                            Your Home
                        </h2>

                    </div>


                    <div class="space-y-4 p-5">

                        <div>

                            <p
                                class="
                                    text-xs
                                    font-semibold
                                    uppercase
                                    tracking-wide
                                    text-slate-400
                                    
                                    dark:text-slate-500
"
                            >
                                Phase
                            </p>

                            <p
                                class="
                                    mt-1
                                    text-base
                                    font-semibold
                                    text-slate-800
                                    
                                    dark:text-slate-200
"
                            >
                                <?= esc($phase) ?>
                            </p>

                        </div>


                        <div>

                            <p
                                class="
                                    text-xs
                                    font-semibold
                                    uppercase
                                    tracking-wide
                                    text-slate-400
                                    
                                    dark:text-slate-500
"
                            >
                                Home Address
                            </p>

                            <p
                                class="
                                    mt-1
                                    break-words
                                    text-base
                                    font-semibold
                                    text-slate-800
                                    
                                    dark:text-slate-200
"
                            >
                                <?= esc($houseLot) ?>
                            </p>

                        </div>


                        <div>

                            <p
                                class="
                                    text-xs
                                    font-semibold
                                    uppercase
                                    tracking-wide
                                    text-slate-400
                                    
                                    dark:text-slate-500
"
                            >
                                Community
                            </p>

                            <p
                                class="
                                    mt-1
                                    text-base
                                    font-semibold
                                    text-slate-800
                                    
                                    dark:text-slate-200
"
                            >
                                South Meridian Homes Salitran
                            </p>

                        </div>


                        <?php if ($isTenant): ?>

                            <div>

                                <p
                                    class="
                                        text-xs
                                        font-semibold
                                        uppercase
                                        tracking-wide
                                        text-slate-400
                                        
                                        dark:text-slate-500
"
                                >
                                    Account Type
                                </p>

                                <p
                                    class="
                                        mt-1
                                        inline-flex
                                        rounded-lg
                                        bg-blue-50
                                        px-3 py-1.5
                                        text-sm
                                        font-bold
                                        text-blue-700
                                        
                                        dark:bg-blue-950/40 dark:text-blue-300
"
                                >
                                    Tenant
                                </p>

                            </div>

                        <?php endif; ?>

                    </div>

                </section>


                <!-- =================================================
                     MONTHLY DUES
                     ================================================= -->

                <?php if (
                    !$isTenant ||
                    !empty($tenant['can_pay_dues'])
                ): ?>

                    <section
                        class="
                            overflow-hidden
                            rounded-2xl
                            border border-slate-200
                            bg-white
                            shadow-sm
                            
                            dark:bg-slate-900 dark:border-slate-800
"
                    >

                        <div
                            class="
                                flex items-center
                                justify-between
                                gap-3
                                border-b
                                border-slate-200
                                px-5 py-4
                                
                                dark:border-slate-800
"
                        >

                            <div>

                                <h2
                                    class="
                                        flex items-center
                                        gap-2
                                        font-bold
                                        text-slate-900
                                        
                                        dark:text-slate-100
"
                                >
                                    <i class="bi bi-wallet2 text-blue-700 dark:text-blue-300"></i>

                                    Monthly Dues
                                </h2>

                                <p
                                    class="
                                        mt-1
                                        text-xs
                                        text-slate-500
                                        
                                        dark:text-slate-400
"
                                >
                                    Your current payment status
                                </p>

                            </div>

                        </div>


                        <div class="p-5">

                            <div
                                class="
                                    text-3xl
                                    font-bold
                                    tracking-tight
                                    text-slate-900
                                    
                                    dark:text-slate-100
"
                            >
                                ₱<?= number_format((float)$monthlyDues, 2) ?>
                            </div>

                            <div
                                class="
                                    mt-1
                                    text-sm
                                    font-medium
                                    text-slate-500
                                    
                                    dark:text-slate-400
"
                            >
                                per month
                            </div>


                            <?php if ($accountStartYear > $curYear): ?>

                                <div
                                    class="
                                        mt-5
                                        rounded-xl
                                        border border-blue-200
                                        bg-blue-50
                                        p-4
                                        
                                        dark:bg-blue-950/40 dark:border-blue-900
"
                                >

                                    <div
                                        class="
                                            flex items-start
                                            gap-3
                                        "
                                    >

                                        <i
                                            class="
                                                bi bi-info-circle-fill
                                                mt-0.5
                                                text-lg
                                                text-blue-700
                                                
                                                dark:text-blue-300
"
                                        ></i>

                                        <p
                                            class="
                                                text-sm
                                                leading-6
                                                text-blue-900
                                                
                                                dark:text-blue-200
"
                                        >
                                            Your monthly dues will start in
                                            <strong>
                                                <?= esc($accountStartLabel) ?>
                                            </strong>.
                                        </p>

                                    </div>

                                </div>


                            <?php elseif (empty($unpaidMonths)): ?>

                                <div
                                    class="
                                        mt-5
                                        rounded-xl
                                        border border-emerald-200
                                        bg-emerald-50
                                        p-4
                                        
                                        dark:bg-emerald-950/40 dark:border-emerald-900
"
                                >

                                    <div
                                        class="
                                            flex items-start
                                            gap-3
                                        "
                                    >

                                        <i
                                            class="
                                                bi bi-check-circle-fill
                                                mt-0.5
                                                text-xl
                                                text-emerald-700
                                                
                                                dark:text-emerald-400
"
                                        ></i>

                                        <div>

                                            <p
                                                class="
                                                    font-bold
                                                    text-emerald-900
                                                    
                                                    dark:text-emerald-200
"
                                            >
                                                You're fully paid
                                            </p>

                                            <p
                                                class="
                                                    mt-1
                                                    text-sm
                                                    leading-6
                                                    text-emerald-800
                                                    
                                                    dark:text-emerald-300
"
                                            >
                                                Your dues for
                                                <?= esc($curYear) ?>
                                                are up to date.
                                            </p>

                                        </div>

                                    </div>

                                </div>


                            <?php else: ?>

                                <div
                                    class="
                                        mt-5
                                        rounded-xl
                                        border border-red-200
                                        bg-red-50
                                        p-4
                                        
                                        dark:bg-red-950/40 dark:border-red-900
"
                                >

                                    <div
                                        class="
                                            flex items-start
                                            gap-3
                                        "
                                    >

                                        <i
                                            class="
                                                bi bi-exclamation-triangle-fill
                                                mt-0.5
                                                text-xl
                                                text-red-700
                                                
                                                dark:text-red-300
"
                                        ></i>

                                        <div class="min-w-0">

                                            <p
                                                class="
                                                    font-bold
                                                    text-red-900
                                                    
                                                    dark:text-red-200
"
                                            >
                                                Payment required
                                            </p>

                                            <p
                                                class="
                                                    mt-1
                                                    text-sm
                                                    leading-6
                                                    text-red-800
                                                    
                                                    dark:text-red-300
"
                                            >
                                                You have
                                                <strong>
                                                    <?= count($unpaidMonths) ?>
                                                </strong>

                                                unpaid
                                                month<?= count($unpaidMonths) === 1 ? '' : 's' ?>.
                                            </p>

                                        </div>

                                    </div>


                                    <div
                                        class="
                                            mt-4
                                            flex flex-wrap
                                            gap-2
                                        "
                                    >

                                        <?php foreach ($unpaidMonths as $m): ?>

                                            <span
                                                class="
                                                    rounded-lg
                                                    bg-white
                                                    px-2.5 py-1.5
                                                    text-xs
                                                    font-bold
                                                    text-red-700
                                                    ring-1
                                                    ring-red-200
                                                    
                                                    dark:bg-slate-900 dark:text-red-300 dark:ring-red-900
"
                                            >
                                                <?= esc(month_name($m)) ?>
                                            </span>

                                        <?php endforeach; ?>

                                    </div>


                                    <p
                                        class="
                                            mt-4
                                            text-sm
                                            text-red-800
                                            
                                            dark:text-red-300
"
                                    >
                                        Next payment:
                                        <strong>
                                            <?= esc(month_name($nextDueMonth)) ?>
                                            <?= esc($curYear) ?>
                                        </strong>
                                    </p>

                                </div>


                                <a
                                    href="homeowner_pay_dues.php"
                                    class="
                                        mt-4
                                        flex min-h-12
                                        w-full
                                        items-center
                                        justify-center
                                        gap-2
                                        rounded-xl
                                        bg-emerald-700
                                        px-4
                                        text-base
                                        font-semibold
                                        text-white
                                        shadow-sm
                                        transition
                                        hover:bg-emerald-800
                                        focus:outline-none
                                        focus:ring-4
                                        focus:ring-emerald-100
                                        
                                        dark:focus:ring-emerald-950
"
                                >
                                    <i class="bi bi-cash-coin text-lg"></i>

                                    Pay Monthly Dues
                                </a>

                            <?php endif; ?>


                            <div
                                class="
                                    mt-5
                                    border-t
                                    border-slate-100
                                    pt-4
                                    
                                    dark:border-slate-800
"
                            >

                                <div
                                    class="
                                        flex
                                        items-center
                                        justify-between
                                        gap-3
                                    "
                                >

                                    <span
                                        class="
                                            text-sm
                                            font-medium
                                            text-slate-600
                                            
                                            dark:text-slate-400
"
                                    >
                                        <?= esc(month_name($curMonth)) ?>
                                    </span>


                                    <?php if (!$curMonthIsApplicable): ?>

                                        <span
                                            class="
                                                rounded-lg
                                                bg-slate-100
                                                px-2.5 py-1
                                                text-xs
                                                font-bold
                                                text-slate-600
                                                
                                                dark:bg-slate-800 dark:text-slate-400
"
                                        >
                                            Not applicable
                                        </span>

                                    <?php elseif ($curMonthPaid): ?>

                                        <span
                                            class="
                                                rounded-lg
                                                bg-emerald-100
                                                px-2.5 py-1
                                                text-xs
                                                font-bold
                                                text-emerald-800
                                                
                                                dark:bg-emerald-950/60 dark:text-emerald-300
"
                                        >
                                            Paid
                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="
                                                rounded-lg
                                                bg-red-100
                                                px-2.5 py-1
                                                text-xs
                                                font-bold
                                                text-red-800
                                                
                                                dark:bg-red-950/60 dark:text-red-300
"
                                        >
                                            Not paid
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    </section>

                <?php endif; ?>

            </div>


            <!-- =================================================
                 ANNOUNCEMENT FEED
                 ================================================= -->

            <section
                id="feed"
                class="min-w-0"
            >

                <div
                    class="
                        mb-4
                        flex flex-col
                        gap-2

                        sm:flex-row
                        sm:items-end
                        sm:justify-between
                    "
                >

                    <div>

                        <h2
                            class="
                                text-xl
                                font-bold
                                text-slate-900

                                sm:text-2xl
                                
                                dark:text-slate-100
"
                        >
                            Community Announcements
                        </h2>

                        <p
                            class="
                                mt-1
                                text-sm
                                text-slate-500
                                
                                dark:text-slate-400
"
                        >
                            Official updates visible to your account.
                        </p>

                    </div>


                    <div
                        class="
                            inline-flex
                            w-fit
                            items-center
                            gap-2
                            rounded-xl
                            bg-white
                            px-3 py-2
                            text-sm
                            font-semibold
                            text-slate-600
                            ring-1
                            ring-slate-200
                            
                            dark:bg-slate-900 dark:text-slate-400 dark:ring-slate-700
"
                    >
                        <i class="bi bi-megaphone text-emerald-700 dark:text-emerald-400"></i>

                        <?= count($annFeed) ?>

                        post<?= count($annFeed) === 1 ? '' : 's' ?>
                    </div>

                </div>


                <?php if (
                    $isTenant &&
                    !tenant_can_access(
                        'announcements',
                        $tenant
                    )
                ): ?>

                    <div
                        class="
                            rounded-2xl
                            border border-slate-200
                            bg-white
                            p-8
                            text-center
                            shadow-sm
                            
                            dark:bg-slate-900 dark:border-slate-800
"
                    >

                        <i
                            class="
                                bi bi-lock-fill
                                text-3xl
                                text-slate-400
                                
                                dark:text-slate-500
"
                        ></i>

                        <h3
                            class="
                                mt-3
                                font-bold
                                text-slate-800
                                
                                dark:text-slate-200
"
                        >
                            Announcements unavailable
                        </h3>

                        <p
                            class="
                                mt-1
                                text-sm
                                text-slate-500
                                
                                dark:text-slate-400
"
                        >
                            Your account does not have access to this section.
                        </p>

                    </div>


                <?php elseif (empty($annFeed)): ?>

                    <div
                        class="
                            rounded-2xl
                            border border-slate-200
                            bg-white
                            p-10
                            text-center
                            shadow-sm
                            
                            dark:bg-slate-900 dark:border-slate-800
"
                    >

                        <div
                            class="
                                mx-auto
                                flex h-16 w-16
                                items-center justify-center
                                rounded-2xl
                                bg-emerald-50
                                text-3xl
                                text-emerald-700
                                
                                dark:bg-emerald-950/40 dark:text-emerald-400
"
                        >
                            <i class="bi bi-megaphone"></i>
                        </div>

                        <h3
                            class="
                                mt-4
                                text-lg
                                font-bold
                                text-slate-900
                                
                                dark:text-slate-100
"
                        >
                            No announcements right now
                        </h3>

                        <p
                            class="
                                mx-auto
                                mt-2
                                max-w-md
                                text-sm
                                leading-6
                                text-slate-500
                                
                                dark:text-slate-400
"
                        >
                            New official HOA updates will appear here.
                        </p>

                    </div>


                <?php else: ?>

                    <div class="space-y-5">

                        <?php foreach ($annFeed as $a): ?>

                            <?php
                                $aid =
                                    (int)$a['id'];

                                $iLiked =
                                    ((int)$a['i_liked'] > 0);

                                $announcementAttachments =
                                    $attachmentsByAnn[$aid]
                                    ?? [];

                                $prio =
                                    (string)$a['priority'];

                                $prioIcon =
                                    $prio === 'urgent'
                                        ? 'bi-exclamation-octagon-fill'
                                        : (
                                            $prio === 'important'
                                                ? 'bi-exclamation-triangle-fill'
                                                : 'bi-info-circle-fill'
                                        );

                                $prioColor =
                                    $prio === 'urgent'
                                        ? 'text-red-600'
                                        : (
                                            $prio === 'important'
                                                ? 'text-amber-600'
                                                : 'text-emerald-600'
                                        );

                                $prioBadge =
                                    $prio === 'urgent'
                                        ? 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900'
                                        : (
                                            $prio === 'important'
                                                ? 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900'
                                                : 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900'
                                        );
                            ?>


<article
    class="
        post
        overflow-hidden
        rounded-2xl
        border border-slate-200
        bg-white
        shadow-sm

        dark:border-slate-800
        dark:bg-slate-900
    "
    data-ann-id="<?= $aid ?>"
>


                                <!-- Post header -->

                                <div
                                    class="
                                        flex items-start
                                        gap-3
                                        p-5
                                    "
                                >

                                    <div
                                        class="
                                            flex h-12 w-12
                                            shrink-0
                                            items-center justify-center
                                            rounded-xl
                                            bg-emerald-100
                                            text-lg
                                            font-bold
                                            text-emerald-800
                                            
                                            dark:bg-emerald-950/60 dark:text-emerald-300
"
                                    >
                                        <i class="bi bi-megaphone-fill"></i>
                                    </div>


                                    <div class="min-w-0 flex-1">

                                        <div
                                            class="
                                                flex flex-wrap
                                                items-center
                                                gap-2
                                            "
                                        >

                                            <h3
                                                class="
                                                    break-words
                                                    text-base
                                                    font-bold
                                                    text-slate-900

                                                    sm:text-lg
                                                    
                                                    dark:text-slate-100
"
                                            >
                                                <?= esc($a['title']) ?>
                                            </h3>

                                            <i
                                                class="
                                                    bi
                                                    <?= esc($prioIcon) ?>
                                                    <?= esc($prioColor) ?>
                                                "
                                            ></i>

                                        </div>


                                        <div
                                            class="
                                                mt-1
                                                flex flex-wrap
                                                items-center
                                                gap-x-2
                                                gap-y-1
                                                text-xs
                                                font-medium
                                                text-slate-500
                                                
                                                dark:text-slate-400
"
                                        >

                                            <span>
                                                <?= esc(ucfirst($a['category'])) ?>
                                            </span>

                                            <span>•</span>

                                            <span>
                                                <?= esc($phase) ?>
                                            </span>

                                            <span>•</span>

                                            <span>
                                                <?= esc(
                                                    date(
                                                        'M d, Y h:i A',
                                                        strtotime($a['created_at'])
                                                    )
                                                ) ?>
                                            </span>

                                        </div>

                                    </div>


                                    <span
                                        class="
                                            hidden
                                            shrink-0
                                            rounded-lg
                                            px-2.5 py-1
                                            text-[11px]
                                            font-bold
                                            uppercase
                                            tracking-wide
                                            ring-1
                                            <?= $prioBadge ?>

                                            sm:inline-flex
                                        "
                                    >
                                        <?= esc($prio) ?>
                                    </span>

                                </div>


                                <!-- Announcement -->

                                <div
                                    class="
                                        px-5
                                        pb-5
                                    "
                                >

                                    <div
class="
    post-content
    whitespace-pre-wrap
    break-words
    text-[15px]
    leading-7
    text-slate-700

    sm:text-base

    dark:text-slate-300
"
                                    >
                                        <?= esc($a['message']) ?>
                                    </div>


                                    <?php if (!empty($announcementAttachments)): ?>

                                        <div class="mt-4 space-y-3">

                                            <?php foreach ($announcementAttachments as $attachment): ?>

                                                <?php
                                                    $storedName =
                                                        basename(
                                                            (string)(
                                                                $attachment['stored_name']
                                                                ?? ''
                                                            )
                                                        );

                                                    $originalName =
                                                        trim(
                                                            (string)(
                                                                $attachment['original_name']
                                                                ?? 'Attachment'
                                                            )
                                                        );

                                                    $mimeType =
                                                        strtolower(
                                                            trim(
                                                                (string)(
                                                                    $attachment['mime_type']
                                                                    ?? ''
                                                                )
                                                            )
                                                        );

                                                    $fileSize =
                                                        (int)(
                                                            $attachment['file_size']
                                                            ?? 0
                                                        );

                                                    $extension =
                                                        strtolower(
                                                            pathinfo(
                                                                $storedName !== ''
                                                                    ? $storedName
                                                                    : $originalName,
                                                                PATHINFO_EXTENSION
                                                            )
                                                        );

                                                    $imageExtensions = [
                                                        'jpg',
                                                        'jpeg',
                                                        'png',
                                                        'gif',
                                                        'webp'
                                                    ];

                                                    $videoExtensions = [
                                                        'mp4',
                                                        'webm',
                                                        'mov',
                                                        'm4v'
                                                    ];

                                                    $isImageAttachment =
                                                        str_starts_with(
                                                            $mimeType,
                                                            'image/'
                                                        ) ||
                                                        in_array(
                                                            $extension,
                                                            $imageExtensions,
                                                            true
                                                        );

                                                    $isVideoAttachment =
                                                        str_starts_with(
                                                            $mimeType,
                                                            'video/'
                                                        ) ||
                                                        in_array(
                                                            $extension,
                                                            $videoExtensions,
                                                            true
                                                        );

                                                    /*
                                                     * Always construct the public URL from the
                                                     * stored filename instead of trusting an
                                                     * arbitrary database path.
                                                     */
                                                    $attachmentUrl =
                                                        $storedName !== ''
                                                            ? '../admin/uploads/announcements/' .
                                                              rawurlencode($storedName)
                                                            : '';

                                                    if ($fileSize >= 1048576) {
                                                        $fileSizeLabel =
                                                            number_format(
                                                                $fileSize / 1048576,
                                                                1
                                                            ) .
                                                            ' MB';

                                                    } elseif ($fileSize >= 1024) {
                                                        $fileSizeLabel =
                                                            number_format(
                                                                $fileSize / 1024,
                                                                1
                                                            ) .
                                                            ' KB';

                                                    } else {
                                                        $fileSizeLabel =
                                                            $fileSize .
                                                            ' B';
                                                    }

                                                    $videoMime =
                                                        $mimeType;

                                                    if (
                                                        !str_starts_with(
                                                            $videoMime,
                                                            'video/'
                                                        )
                                                    ) {
                                                        $videoMime =
                                                            match ($extension) {
                                                                'webm' =>
                                                                    'video/webm',

                                                                'mov' =>
                                                                    'video/quicktime',

                                                                default =>
                                                                    'video/mp4'
                                                            };
                                                    }
                                                ?>


                                                <?php if (
                                                    $attachmentUrl !== '' &&
                                                    $isImageAttachment
                                                ): ?>

                                                    <a
                                                        href="<?= esc($attachmentUrl) ?>"
                                                        target="_blank"
                                                        rel="noopener"
                                                        class="
                                                            block
                                                            overflow-hidden
                                                            rounded-2xl
                                                            border border-slate-200
                                                            bg-slate-100

                                                            dark:border-slate-700
                                                            dark:bg-slate-800
                                                        "
                                                        title="Open image"
                                                    >
                                                        <img
                                                            src="<?= esc($attachmentUrl) ?>"
                                                            alt="<?= esc($originalName) ?>"
                                                            class="
                                                                max-h-[560px]
                                                                w-full
                                                                object-contain
                                                            "
                                                            loading="lazy"
                                                        >
                                                    </a>


                                                <?php elseif (
                                                    $attachmentUrl !== '' &&
                                                    $isVideoAttachment
                                                ): ?>

                                                    <div
                                                        class="
                                                            overflow-hidden
                                                            rounded-2xl
                                                            border border-slate-200
                                                            bg-black

                                                            dark:border-slate-700
                                                        "
                                                    >
                                                        <video
                                                            controls
                                                            playsinline
                                                            preload="metadata"
                                                            class="
                                                                max-h-[560px]
                                                                w-full
                                                                bg-black
                                                                object-contain
                                                            "
                                                        >
                                                            <source
                                                                src="<?= esc($attachmentUrl) ?>"
                                                                type="<?= esc($videoMime) ?>"
                                                            >

                                                            Your browser does not support video playback.
                                                        </video>
                                                    </div>


                                                <?php elseif ($attachmentUrl !== ''): ?>

                                                    <a
                                                        href="<?= esc($attachmentUrl) ?>"
                                                        target="_blank"
                                                        rel="noopener"
                                                        class="
                                                            flex
                                                            items-center
                                                            gap-3
                                                            rounded-xl
                                                            border border-slate-200
                                                            bg-slate-50
                                                            p-3
                                                            transition

                                                            hover:border-emerald-300
                                                            hover:bg-emerald-50

                                                            dark:border-slate-700
                                                            dark:bg-slate-800
                                                            dark:hover:border-emerald-800
                                                            dark:hover:bg-emerald-950/30
                                                        "
                                                    >
                                                        <div
                                                            class="
                                                                flex h-11 w-11
                                                                shrink-0
                                                                items-center
                                                                justify-center
                                                                rounded-xl
                                                                bg-white
                                                                text-lg
                                                                text-emerald-700
                                                                shadow-sm

                                                                dark:bg-slate-900
                                                                dark:text-emerald-400
                                                            "
                                                        >
                                                            <i class="bi bi-paperclip"></i>
                                                        </div>

                                                        <div class="min-w-0 flex-1">

                                                            <div
                                                                class="
                                                                    truncate
                                                                    text-sm
                                                                    font-semibold
                                                                    text-slate-800

                                                                    dark:text-slate-200
                                                                "
                                                            >
                                                                <?= esc($originalName) ?>
                                                            </div>

                                                            <div
                                                                class="
                                                                    mt-0.5
                                                                    text-xs
                                                                    text-slate-500

                                                                    dark:text-slate-400
                                                                "
                                                            >
                                                                <?= esc($fileSizeLabel) ?>
                                                                •
                                                                Open attachment
                                                            </div>

                                                        </div>

                                                        <i
                                                            class="
                                                                bi
                                                                bi-box-arrow-up-right
                                                                text-slate-400
                                                            "
                                                        ></i>
                                                    </a>

                                                <?php endif; ?>

                                            <?php endforeach; ?>

                                        </div>

                                    <?php endif; ?>

                                </div>


                                <!-- Statistics -->

                                <div
                                    class="
                                        flex
                                        items-center
                                        justify-between
                                        gap-4
                                        border-y
                                        border-slate-100
                                        px-5 py-3
                                        text-sm
                                        text-slate-500
                                        
                                        dark:text-slate-400 dark:border-slate-800
"
                                >

                                    <div
                                        class="
                                            flex
                                            items-center
                                            gap-5
                                        "
                                    >

                                        <span
                                            class="
                                                flex
                                                items-center
                                                gap-2
                                            "
                                        >
                                            <i class="bi bi-hand-thumbs-up-fill text-emerald-700 dark:text-emerald-400"></i>

                                            <span class="like-count">
                                                <?= (int)$a['like_count'] ?>
                                            </span>
                                        </span>


                                        <span
                                            class="
                                                flex
                                                items-center
                                                gap-2
                                            "
                                        >
                                            <i class="bi bi-chat-left-text-fill"></i>

                                            <span class="comment-count">
                                                <?= (int)$a['comment_count'] ?>
                                            </span>
                                        </span>

                                    </div>


                                    <span
                                        class="
                                            text-xs
                                            font-semibold
                                            text-slate-400
                                            
                                            dark:text-slate-500
"
                                    >
                                        Official HOA Post
                                    </span>

                                </div>


                                <!-- Like / comment buttons -->

                                <?php if (!$isTenant): ?>

                                    <div
                                        class="
                                            grid grid-cols-2
                                            gap-2
                                            p-3
                                        "
                                    >

                                        <button
                                            type="button"
                                            class="
                                                btn-like
                                                flex min-h-12
                                                items-center
                                                justify-center
                                                gap-2
                                                rounded-xl
                                                px-4
                                                text-sm
                                                font-semibold
                                                transition

                                                <?= $iLiked
                                                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'
                                                    : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800'
                                                ?>
                                                
"
                                        >
                                            <i
                                                class="
                                                    bi
                                                    <?= $iLiked
                                                        ? 'bi-hand-thumbs-up-fill'
                                                        : 'bi-hand-thumbs-up'
                                                    ?>
                                                    text-lg
                                                "
                                            ></i>

                                            Like
                                        </button>


                                        <button
                                            type="button"
                                            class="
                                                btn-focus-comment
                                                flex min-h-12
                                                items-center
                                                justify-center
                                                gap-2
                                                rounded-xl
                                                px-4
                                                text-sm
                                                font-semibold
                                                text-slate-600
                                                transition
                                                hover:bg-slate-50
                                                dark:text-slate-300
                                                dark:hover:bg-slate-800
"
                                        >
                                            <i class="bi bi-chat-left-text text-lg"></i>

                                            Comment
                                        </button>

                                    </div>

                                <?php endif; ?>


                                <!-- Comments -->

                                <div
class="
    border-t
    border-slate-100
    bg-slate-50/70
    p-5

    dark:border-slate-800
    dark:bg-slate-950/40
"
                                >

                                    <?php

                                        $clist =
                                            $commentsByAnn[$aid] ?? [];

                                        foreach ($clist as $c):

                                            $cName =
                                                trim(
                                                    ($c['first_name'] ?? '') .
                                                    ' ' .
                                                    ($c['last_name'] ?? '')
                                                );

                                            $cInit =
                                                strtoupper(
                                                    substr(
                                                        (string)(
                                                            $c['first_name']
                                                            ?? 'H'
                                                        ),
                                                        0,
                                                        1
                                                    )
                                                );
                                                $cProfilePicturePath =
    trim(
        (string)(
            $c['profile_picture_path']
            ?? ''
        )
    );

$cProfilePictureUrl =
    $cProfilePicturePath !== ''
        ? '../' . ltrim(
            $cProfilePicturePath,
            '/'
        )
        : '';

                                    ?>

<?php
$isMyComment =
    !$isTenant &&
    (int)$c['homeowner_id'] === $hid;
?>

<div
    class="comment-item mt-3 flex items-start gap-2.5"
    data-comment-id="<?= (int)$c['id'] ?>"
>

<!-- Comment Avatar -->
<div class="
    flex h-9 w-9
    shrink-0
    items-center justify-center
    overflow-hidden
    rounded-full
        bg-emerald-100
        text-xs
        font-bold
        text-emerald-700

        dark:bg-emerald-950
        dark:text-emerald-300
    "
>

    <?php if ($cProfilePictureUrl !== ''): ?>

        <img
            src="<?= esc($cProfilePictureUrl) ?>"
            alt="<?= esc($cName) ?>"
            class="h-full w-full object-cover"
            loading="lazy"
        >

    <?php else: ?>

        <?= esc($cInit) ?>

    <?php endif; ?>

</div>


    <!-- Comment content -->
    <div class="min-w-0">

        <!-- Name + options -->
        <div class="flex items-center gap-1">

            <div
                class="
                    text-sm
                    font-semibold
                    leading-5
                    text-slate-800

                    dark:text-slate-100
                "
            >
                <?= esc($cName) ?>
            </div>


            <?php if ($isMyComment): ?>

                <div class="relative">

                    <button
                        type="button"
                        class="
                            btn-comment-options
                            flex h-7 w-7
                            items-center justify-center
                            rounded-full
                            text-slate-400
                            transition

                            hover:bg-slate-200
                            hover:text-slate-700

                            dark:hover:bg-slate-700
                            dark:hover:text-slate-200
                        "
                        aria-label="Comment options"
                    >
                        <i class="bi bi-three-dots"></i>
                    </button>


                    <div
                        class="
                            comment-options-menu

                            absolute
                            left-0
                            top-8
                            z-30

                            hidden
                            min-w-[130px]

                            overflow-hidden
                            rounded-xl
                            border border-slate-200
                            bg-white
                            p-1
                            shadow-xl

                            dark:border-slate-700
                            dark:bg-slate-800
                        "
                    >

                        <button
                            type="button"
                            class="
                                btn-edit-comment
                                flex w-full
                                items-center
                                gap-2

                                rounded-lg
                                px-3 py-2

                                text-left
                                text-sm
                                font-medium
                                text-slate-700

                                hover:bg-slate-100

                                dark:text-slate-200
                                dark:hover:bg-slate-700
                            "
                        >
                            <i class="bi bi-pencil"></i>

                            Edit
                        </button>


                        <button
                            type="button"
                            class="
                                btn-delete-comment
                                flex w-full
                                items-center
                                gap-2

                                rounded-lg
                                px-3 py-2

                                text-left
                                text-sm
                                font-medium
                                text-red-600

                                hover:bg-red-50

                                dark:text-red-400
                                dark:hover:bg-red-950/40
                            "
                        >
                            <i class="bi bi-trash3"></i>

                            Delete
                        </button>

                    </div>

                </div>

            <?php endif; ?>

        </div>


        <!-- Normal comment -->
        <div
            class="
                comment-message

                mt-1
                w-fit
                max-w-[min(80vw,520px)]

                rounded-xl
                rounded-tl-sm

                bg-slate-100

                px-3
                py-1.5

                text-left
                text-[14px]
                leading-5
                text-slate-700

                dark:bg-slate-800
                dark:text-slate-200
            "
        >
            <?= esc($c['comment']) ?>
        </div>


        <!-- Edit form -->
        <div
            class="
                comment-edit-area
                mt-2
                hidden
            "
        >

            <input
                type="text"
                maxlength="500"
                value="<?= esc($c['comment']) ?>"
                class="
                    comment-edit-input

                    min-h-10
                    w-full
                    max-w-md

                    rounded-xl
                    border border-slate-300
                    bg-white

                    px-3

                    text-sm
                    text-slate-800

                    outline-none
                    transition

                    focus:border-emerald-500
                    focus:ring-4
                    focus:ring-emerald-100

                    dark:border-slate-700
                    dark:bg-slate-800
                    dark:text-slate-100
                    dark:focus:ring-emerald-950
                "
            >


            <div class="mt-2 flex gap-2">

                <button
                    type="button"
                    class="
                        btn-save-comment

                        rounded-lg
                        bg-emerald-700
                        px-3 py-1.5

                        text-xs
                        font-semibold
                        text-white

                        hover:bg-emerald-800
                    "
                >
                    Save
                </button>


                <button
                    type="button"
                    class="
                        btn-cancel-edit

                        rounded-lg
                        bg-slate-100
                        px-3 py-1.5

                        text-xs
                        font-semibold
                        text-slate-700

                        hover:bg-slate-200

                        dark:bg-slate-700
                        dark:text-slate-200
                        dark:hover:bg-slate-600
                    "
                >
                    Cancel
                </button>

            </div>

        </div>


        <!-- Date -->
        <div
            class="
                mt-1
                text-[10px]
                font-medium
                leading-4
                text-slate-400

                dark:text-slate-500
            "
        >
            <?= esc(
                date(
                    'M d, Y • h:i A',
                    strtotime($c['created_at'])
                )
            ) ?>
        </div>

    </div>

</div>

                                    <?php endforeach; ?>


                                    <?php if (!$isTenant): ?>

                                        <div
                                            class="
                                                comment-form
                                                mt-4
                                                flex
                                                items-center
                                                gap-2
                                            "
                                        >

                                            <input
class="
    comment-input
    min-h-12
    min-w-0
    flex-1
    rounded-xl
    border border-slate-300
    bg-white
    px-4
    text-base
    text-slate-800
    outline-none
    transition
    placeholder:text-slate-400
    focus:border-emerald-500
    focus:ring-4
    focus:ring-emerald-100

    dark:border-slate-700
    dark:bg-slate-800
    dark:text-slate-100
    dark:placeholder:text-slate-500
    dark:focus:border-emerald-500
    dark:focus:ring-emerald-950
"
                                                type="text"
                                                placeholder="Write a comment..."
                                                maxlength="500"
                                                aria-label="Write a comment"
                                            >


                                            <button
                                                class="
                                                    btn-comment-send
                                                    flex h-12 w-12
                                                    shrink-0
                                                    items-center justify-center
                                                    rounded-xl
                                                    bg-emerald-700
                                                    text-lg
                                                    text-white
                                                    shadow-sm
                                                    transition
                                                    hover:bg-emerald-800
                                                    focus:outline-none
                                                    focus:ring-4
                                                    focus:ring-emerald-100
                                                    
                                                    dark:focus:ring-emerald-950
"
                                                type="button"
                                                aria-label="Send comment"
                                            >
                                                <i class="bi bi-send-fill"></i>
                                            </button>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>

        </div>

    </main>

</div>

<!-- Delete Comment Modal -->
<div
    id="deleteCommentModal"
    class="
        fixed inset-0 z-[200]
        hidden
        items-center justify-center
        bg-slate-950/60
        p-4
        backdrop-blur-sm
    "
    aria-hidden="true"
>
    <div
        class="
            w-full max-w-sm
            rounded-2xl
            border border-slate-200
            bg-white
            p-5
            shadow-2xl

            dark:border-slate-700
            dark:bg-slate-900
        "
    >

        <div class="flex items-start gap-3">

            <div
                class="
                    flex h-11 w-11
                    shrink-0
                    items-center justify-center
                    rounded-xl
                    bg-red-100
                    text-xl
                    text-red-600

                    dark:bg-red-950/60
                    dark:text-red-300
                "
            >
                <i class="bi bi-trash3-fill"></i>
            </div>

            <div class="min-w-0 flex-1">

                <h3
                    class="
                        text-base
                        font-bold
                        text-slate-900

                        dark:text-slate-100
                    "
                >
                    Delete comment?
                </h3>

                <p
                    class="
                        mt-1
                        text-sm
                        leading-6
                        text-slate-600

                        dark:text-slate-400
                    "
                >
                    This comment will be permanently removed.
                </p>

            </div>

        </div>


        <div
            class="
                mt-6
                flex
                justify-end
                gap-2
            "
        >

            <button
                type="button"
                id="cancelDeleteComment"
                class="
                    min-h-11
                    rounded-xl
                    bg-slate-100
                    px-4
                    text-sm
                    font-semibold
                    text-slate-700
                    transition

                    hover:bg-slate-200

                    dark:bg-slate-800
                    dark:text-slate-200
                    dark:hover:bg-slate-700
                "
            >
                Cancel
            </button>


            <button
                type="button"
                id="confirmDeleteComment"
                class="
                    min-h-11
                    rounded-xl
                    bg-red-600
                    px-4
                    text-sm
                    font-semibold
                    text-white
                    transition

                    hover:bg-red-700

                    focus:outline-none
                    focus:ring-4
                    focus:ring-red-100

                    dark:focus:ring-red-950
                "
            >
                Delete
            </button>

        </div>

    </div>
</div>
<script>
/*
|--------------------------------------------------------------------------
| Cover Map
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Home Location Map
|--------------------------------------------------------------------------
*/

(function initCoverMap() {

    const mapEl =
        document.getElementById(
            'coverMap'
        );

    if (
        !mapEl ||
        typeof L === 'undefined'
    ) {
        return;
    }


    const mapType =
        mapEl.dataset.mapType || '';


    /*
    |--------------------------------------------------------------------------
    | Official South Meridian subdivision map
    |--------------------------------------------------------------------------
    */

    if (mapType === 'subdivision') {

        const x =
            parseFloat(
                mapEl.dataset.mapX || ''
            );

        const y =
            parseFloat(
                mapEl.dataset.mapY || ''
            );

        const block =
            mapEl.dataset.block || '';

        const lot =
            mapEl.dataset.lot || '';

        const street =
            mapEl.dataset.street || '';

        const imageUrl =
            mapEl.dataset.mapImage || '';


        if (
            !Number.isFinite(x) ||
            !Number.isFinite(y) ||
            !imageUrl
        ) {
            return;
        }


        /*
         * South Meridian map image:
         * width  = 2550
         * height = 3300
         *
         * Leaflet CRS.Simple uses bottom-up Y,
         * while image coordinates use top-down Y.
         */
        const leafletY =
            3300 - y;


 const map =
    L.map(
        mapEl,
        {
            crs: L.CRS.Simple,

            center: [
                leafletY,
                x
            ],

            zoom: -1,

            /*
             * Static homeowner map.
             * The resident should only see the
             * exact property location.
             */
            zoomControl: false,
            attributionControl: false,

            dragging: false,
            scrollWheelZoom: false,
            doubleClickZoom: false,
            touchZoom: false,
            boxZoom: false,
            keyboard: false,

            /*
             * Prevent mobile tap/drag behaviour.
             */
            tap: false
        }
    );

        const mapBounds = [
            [0, 0],
            [3300, 2550]
        ];


        L.imageOverlay(
            imageUrl,
            mapBounds
        ).addTo(
            map
        );


        /*
         * Exact homeowner property marker
         */
 const marker =
    L.marker(
        [
            leafletY,
            x
        ],
        {
            /*
             * The pin is only a location indicator.
             * It cannot be moved.
             */
            draggable: false,
            keyboard: false
        }
    )
    .addTo(
        map
    );

        /*
         * Safe popup content
         */
        const popup =
            document.createElement(
                'div'
            );

        const title =
            document.createElement(
                'strong'
            );

        title.textContent =
            'Block ' +
            block +
            ', Lot ' +
            lot;

        popup.appendChild(
            title
        );


        if (street) {

            popup.appendChild(
                document.createElement(
                    'br'
                )
            );

            popup.appendChild(
                document.createTextNode(
                    street
                )
            );
        }


        marker
            .bindPopup(
                popup
            )
            .openPopup();


        /*
         * Center directly on homeowner property
         */
        map.setView(
            [
                leafletY,
                x
            ],
            -1
        );


        setTimeout(
            function () {

                map.invalidateSize(
                    true
                );

                map.setView(
                    [
                        leafletY,
                        x
                    ],
                    -1
                );

            },
            250
        );


        window.addEventListener(
            'resize',
            function () {

                setTimeout(
                    function () {

                        map.invalidateSize(
                            true
                        );

                    },
                    200
                );
            }
        );


        return;
    }


    /*
    |--------------------------------------------------------------------------
    | Legacy GPS fallback
    |--------------------------------------------------------------------------
    */

    if (mapType === 'gps') {

        const lat =
            parseFloat(
                mapEl.dataset.lat || ''
            );

        const lng =
            parseFloat(
                mapEl.dataset.lng || ''
            );


        if (
            !Number.isFinite(lat) ||
            !Number.isFinite(lng)
        ) {
            return;
        }


const map =
    L.map(
        mapEl,
        {
            zoomControl: false,
            attributionControl: false,

            dragging: false,
            scrollWheelZoom: false,
            doubleClickZoom: false,
            touchZoom: false,
            boxZoom: false,
            keyboard: false,
            tap: false
        }
    )
    .setView(
        [
            lat,
            lng
        ],
        18
    );


        L.tileLayer(
            'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            {
                maxZoom: 20
            }
        ).addTo(
            map
        );


        L.marker(
            [
                lat,
                lng
            ]
        ).addTo(
            map
        );


        setTimeout(
            function () {

                map.invalidateSize();

            },
            250
        );
    }

})();


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

const CSRF_TOKEN =
    <?= json_encode(
        $csrfToken,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;


async function postJSON(
    action,
    payload
) {

    const fd =
        new FormData();

    fd.append(
        'action',
        action
    );

    fd.append(
        'csrf_token',
        CSRF_TOKEN
    );

    for (
        const [key, value]
        of Object.entries(payload || {})
    ) {

        fd.append(
            key,
            value
        );
    }


    const response =
        await fetch(
            'homeowner_dashboard.php',
            {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }
        );


    return await response.json();
}

/*
|--------------------------------------------------------------------------
| Delete Comment Modal
|--------------------------------------------------------------------------
*/

const deleteCommentModal =
    document.getElementById(
        'deleteCommentModal'
    );

const cancelDeleteComment =
    document.getElementById(
        'cancelDeleteComment'
    );

const confirmDeleteComment =
    document.getElementById(
        'confirmDeleteComment'
    );

let pendingDeleteComment = null;
let pendingDeletePost = null;


function openDeleteCommentModal(
    commentItem,
    postEl
) {

    pendingDeleteComment =
        commentItem;

    pendingDeletePost =
        postEl;

    if (!deleteCommentModal) {
        return;
    }

    deleteCommentModal.classList.remove(
        'hidden'
    );

    deleteCommentModal.classList.add(
        'flex'
    );

    deleteCommentModal.setAttribute(
        'aria-hidden',
        'false'
    );

    document.body.classList.add(
        'overflow-hidden'
    );
}


function closeDeleteCommentModal() {

    if (!deleteCommentModal) {
        return;
    }

    deleteCommentModal.classList.add(
        'hidden'
    );

    deleteCommentModal.classList.remove(
        'flex'
    );

    deleteCommentModal.setAttribute(
        'aria-hidden',
        'true'
    );

    document.body.classList.remove(
        'overflow-hidden'
    );

    pendingDeleteComment = null;
    pendingDeletePost = null;
}
/*
|--------------------------------------------------------------------------
| Notification menu
|--------------------------------------------------------------------------
*/

(function () {

    const button =
        document.getElementById(
            'notificationToggle'
        );

    const menu =
        document.getElementById(
            'notificationMenu'
        );

    if (!button || !menu) {
        return;
    }


    function closeMenu() {

        menu.classList.add(
            'hidden'
        );

        button.setAttribute(
            'aria-expanded',
            'false'
        );
    }


    button.addEventListener(
        'click',
        function (event) {

            event.stopPropagation();

            const opening =
                menu.classList.contains(
                    'hidden'
                );

            menu.classList.toggle(
                'hidden'
            );

            button.setAttribute(
                'aria-expanded',
                opening
                    ? 'true'
                    : 'false'
            );
        }
    );


    menu.addEventListener(
        'click',
        function (event) {

            event.stopPropagation();
        }
    );


    document.addEventListener(
        'click',
        closeMenu
    );


    document.addEventListener(
        'keydown',
        function (event) {

            if (
                event.key === 'Escape'
            ) {
                closeMenu();
            }
        }
    );

})();


/*
|--------------------------------------------------------------------------
| Mark notifications as seen
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'btnMarkAllSeen'
    )
    ?.addEventListener(
        'click',
        async function () {

            const result =
                await postJSON(
                    'mark_seen',
                    {
                        target: 'all'
                    }
                );

            if (result.success) {
                location.reload();
            }
        }
    );


document
    .getElementById(
        'btnSeenAnn'
    )
    ?.addEventListener(
        'click',
        async function () {

            const result =
                await postJSON(
                    'mark_seen',
                    {
                        target: 'ann'
                    }
                );

            if (result.success) {
                location.reload();
            }
        }
    );


document
    .getElementById(
        'btnSeenCom'
    )
    ?.addEventListener(
        'click',
        async function () {

            const result =
                await postJSON(
                    'mark_seen',
                    {
                        target: 'comments'
                    }
                );

            if (result.success) {
                location.reload();
            }
        }
    );


/*
|--------------------------------------------------------------------------
| Announcement interactions
|--------------------------------------------------------------------------
*/

document
    .getElementById('feed')
    ?.addEventListener(
        'click',
        async function (event) {

            const postEl =
                event.target.closest(
                    '.post'
                );

            if (!postEl) {
                return;
            }


            const announcementId =
                postEl.getAttribute(
                    'data-ann-id'
                );
                /*
|--------------------------------------------------------------------------
| Comment Options
|--------------------------------------------------------------------------
*/

const optionsButton =
    event.target.closest(
        '.btn-comment-options'
    );

if (optionsButton) {

    const item =
        optionsButton.closest(
            '.comment-item'
        );

    const menu =
        item?.querySelector(
            '.comment-options-menu'
        );

    document
        .querySelectorAll(
            '.comment-options-menu'
        )
        .forEach(function (otherMenu) {

            if (otherMenu !== menu) {
                otherMenu.classList.add(
                    'hidden'
                );
            }

        });

    menu?.classList.toggle(
        'hidden'
    );

    return;
}


/*
|--------------------------------------------------------------------------
| Edit Comment
|--------------------------------------------------------------------------
*/

const editButton =
    event.target.closest(
        '.btn-edit-comment'
    );

if (editButton) {

    const item =
        editButton.closest(
            '.comment-item'
        );

    item
        ?.querySelector(
            '.comment-options-menu'
        )
        ?.classList.add('hidden');

    item
        ?.querySelector(
            '.comment-message'
        )
        ?.classList.add('hidden');

    item
        ?.querySelector(
            '.comment-edit-area'
        )
        ?.classList.remove('hidden');

    const input =
        item?.querySelector(
            '.comment-edit-input'
        );

    input?.focus();

    return;
}


/*
|--------------------------------------------------------------------------
| Cancel Edit
|--------------------------------------------------------------------------
*/

const cancelButton =
    event.target.closest(
        '.btn-cancel-edit'
    );

if (cancelButton) {

    const item =
        cancelButton.closest(
            '.comment-item'
        );

    item
        ?.querySelector(
            '.comment-edit-area'
        )
        ?.classList.add('hidden');

    item
        ?.querySelector(
            '.comment-message'
        )
        ?.classList.remove('hidden');

    return;
}


/*
|--------------------------------------------------------------------------
| Save Comment
|--------------------------------------------------------------------------
*/

const saveButton =
    event.target.closest(
        '.btn-save-comment'
    );

if (saveButton) {

    const item =
        saveButton.closest(
            '.comment-item'
        );

    const commentId =
        item?.dataset.commentId;

    const input =
        item?.querySelector(
            '.comment-edit-input'
        );

    const text =
        (input?.value || '').trim();

    if (!commentId || !text) {
        return;
    }

    saveButton.disabled = true;

    try {

        const result =
            await postJSON(
                'edit_comment_ann',
                {
                    comment_id: commentId,
                    comment: text
                }
            );

        if (!result.success) {
            return;
        }

        const message =
            item.querySelector(
                '.comment-message'
            );

        if (message) {
            message.textContent =
                result.comment;
        }

        item
            .querySelector(
                '.comment-edit-area'
            )
            ?.classList.add('hidden');

        message?.classList.remove(
            'hidden'
        );

    } finally {

        saveButton.disabled =
            false;
    }

    return;
}


/*
|--------------------------------------------------------------------------
| Delete Comment
|--------------------------------------------------------------------------
*/

const deleteButton =
    event.target.closest(
        '.btn-delete-comment'
    );

if (deleteButton) {

    const item =
        deleteButton.closest(
            '.comment-item'
        );

    item
        ?.querySelector(
            '.comment-options-menu'
        )
        ?.classList.add('hidden');

    if (item) {

        openDeleteCommentModal(
            item,
            postEl
        );
    }

    return;
}


            /*
            |------------------------------------------------------------------
            | Like
            |------------------------------------------------------------------
            */

            const likeButton =
                event.target.closest(
                    '.btn-like'
                );

            if (likeButton) {

                const result =
                    await postJSON(
                        'toggle_like_ann',
                        {
                            announcement_id:
                                announcementId
                        }
                    );


                if (!result.success) {

                    alert(
                        result.message ||
                        'Unable to update like.'
                    );

                    return;
                }


                const icon =
                    likeButton.querySelector(
                        'i'
                    );


                if (result.liked) {

                    likeButton.classList.add(
                        'bg-emerald-50',
                        'text-emerald-700',
                        'dark:bg-emerald-950/50',
                        'dark:text-emerald-300'
                    );

                    likeButton.classList.remove(
                        'text-slate-600',
                        'dark:text-slate-300',
                        'dark:hover:bg-slate-800'
                    );

                    if (icon) {
                        icon.className =
                            'bi bi-hand-thumbs-up-fill text-lg';
                    }

                } else {

                    likeButton.classList.remove(
                        'bg-emerald-50',
                        'text-emerald-700',
                        'dark:bg-emerald-950/50',
                        'dark:text-emerald-300'
                    );

                    likeButton.classList.add(
                        'text-slate-600',
                        'dark:text-slate-300',
                        'dark:hover:bg-slate-800'
                    );

                    if (icon) {
                        icon.className =
                            'bi bi-hand-thumbs-up text-lg';
                    }
                }


                const counter =
                    postEl.querySelector(
                        '.like-count'
                    );

                if (counter) {

                    counter.textContent =
                        result.like_count ?? 0;
                }

                return;
            }


            /*
            |------------------------------------------------------------------
            | Focus comment
            |------------------------------------------------------------------
            */

            if (
                event.target.closest(
                    '.btn-focus-comment'
                )
            ) {

                postEl
                    .querySelector(
                        '.comment-input'
                    )
                    ?.focus();

                return;
            }


            /*
            |------------------------------------------------------------------
            | Send comment
            |------------------------------------------------------------------
            */

            if (
                event.target.closest(
                    '.btn-comment-send'
                )
            ) {

                const input =
                    postEl.querySelector(
                        '.comment-input'
                    );

                const text =
                    (
                        input?.value || ''
                    ).trim();


                if (!text) {
                    return;
                }


                const result =
                    await postJSON(
                        'add_comment_ann',
                        {
                            announcement_id:
                                announcementId,

                            comment:
                                text
                        }
                    );


                if (!result.success) {

                    alert(
                        result.message ||
                        'Unable to add comment.'
                    );

                    return;
                }


                const form =
                    postEl.querySelector(
                        '.comment-form'
                    );


                if (form) {

                    form.insertAdjacentHTML(
                        'beforebegin',
                        result.comment_html || ''
                    );
                }


                input.value = '';


                const counter =
                    postEl.querySelector(
                        '.comment-count'
                    );


                if (counter) {

                    counter.textContent =
                        result.comment_count ?? 0;
                }

                return;
            }

        }
    );


/*
|--------------------------------------------------------------------------
| Press Enter to send comment
|--------------------------------------------------------------------------
*/

document
    .getElementById('feed')
    ?.addEventListener(
        'keydown',
        function (event) {

            if (
                event.key !== 'Enter' ||
                !event.target.classList.contains(
                    'comment-input'
                )
            ) {
                return;
            }


            event.preventDefault();


            const post =
                event.target.closest(
                    '.post'
                );


            post
                ?.querySelector(
                    '.btn-comment-send'
                )
                ?.click();
        }
    );


/*
|--------------------------------------------------------------------------
| Sidebar dropdowns
|--------------------------------------------------------------------------
*/

function initSidebarDropdown(
    buttonId,
    menuId,
    caretId
) {

    const button =
        document.getElementById(
            buttonId
        );

    const menu =
        document.getElementById(
            menuId
        );

    const caret =
        document.getElementById(
            caretId
        );


    if (!button || !menu) {
        return;
    }


    button.addEventListener(
        'click',
        function () {

            const willOpen =
                menu.classList.contains(
                    'hidden'
                );


            menu.classList.toggle(
                'hidden'
            );


            button.setAttribute(
                'aria-expanded',
                willOpen
                    ? 'true'
                    : 'false'
            );


            if (caret) {

                caret.classList.toggle(
                    'rotate-180',
                    willOpen
                );
            }

        }
    );

}


initSidebarDropdown(
    'sbParkingToggle',
    'sbParkingMenu',
    'sbParkingCaret'
);


initSidebarDropdown(
    'sbTenantToggle',
    'sbTenantMenu',
    'sbTenantCaret'
);

/*
|--------------------------------------------------------------------------
| Confirm Delete Comment
|--------------------------------------------------------------------------
*/

confirmDeleteComment
    ?.addEventListener(
        'click',
        async function () {

            if (
                !pendingDeleteComment ||
                !pendingDeletePost
            ) {
                return;
            }

            const commentId =
                pendingDeleteComment
                    .dataset.commentId;

            if (!commentId) {
                return;
            }

            confirmDeleteComment.disabled =
                true;

            confirmDeleteComment.textContent =
                'Deleting...';

            try {

                const result =
                    await postJSON(
                        'delete_comment_ann',
                        {
                            comment_id:
                                commentId
                        }
                    );

                if (!result.success) {
                    return;
                }

                pendingDeleteComment.remove();

                const counter =
                    pendingDeletePost
                        .querySelector(
                            '.comment-count'
                        );

                if (counter) {

                    counter.textContent =
                        result.comment_count ?? 0;
                }

                closeDeleteCommentModal();

            } finally {

                confirmDeleteComment.disabled =
                    false;

                confirmDeleteComment.textContent =
                    'Delete';
            }
        }
    );


cancelDeleteComment
    ?.addEventListener(
        'click',
        closeDeleteCommentModal
    );


deleteCommentModal
    ?.addEventListener(
        'click',
        function (event) {

            if (
                event.target ===
                deleteCommentModal
            ) {

                closeDeleteCommentModal();
            }
        }
    );
/*
|--------------------------------------------------------------------------
| Mobile sidebar
|--------------------------------------------------------------------------
*/

(function () {

    const sidebar =
        document.getElementById(
            'sidebar'
        );

    const overlay =
        document.getElementById(
            'sidebarOverlay'
        );

    const openButton =
        document.getElementById(
            'sidebarToggle'
        );

    const closeButton =
        document.getElementById(
            'sidebarClose'
        );


    if (
        !sidebar ||
        !overlay ||
        !openButton
    ) {
        return;
    }


    function openSidebar() {

        sidebar.classList.remove(
            '-translate-x-full'
        );

        overlay.classList.remove(
            'hidden'
        );

        document.body.classList.add(
            'overflow-hidden'
        );
    }


    function closeSidebar() {

        sidebar.classList.add(
            '-translate-x-full'
        );

        overlay.classList.add(
            'hidden'
        );

        document.body.classList.remove(
            'overflow-hidden'
        );
    }


    openButton.addEventListener(
        'click',
        openSidebar
    );


    closeButton?.addEventListener(
        'click',
        closeSidebar
    );


    overlay.addEventListener(
        'click',
        closeSidebar
    );


    sidebar
        .querySelectorAll('a')
        .forEach(
            function (link) {

                link.addEventListener(
                    'click',
                    function () {

                        if (
                            window.innerWidth <
                            1024
                        ) {
                            closeSidebar();
                        }

                    }
                );
            }
        );


    window.addEventListener(
        'resize',
        function () {

            if (
                window.innerWidth >=
                1024
            ) {

                overlay.classList.add(
                    'hidden'
                );

                document.body.classList.remove(
                    'overflow-hidden'
                );
            }
        }
    );

})();


/*
|--------------------------------------------------------------------------
| Access denied notification
|--------------------------------------------------------------------------
*/

(function () {

    const toast =
        document.getElementById(
            'accessDeniedToast'
        );

    const closeButton =
        document.getElementById(
            'accessDeniedClose'
        );


    if (!toast) {
        return;
    }


    function closeToast() {

        toast.remove();
    }


    closeButton?.addEventListener(
        'click',
        closeToast
    );


    setTimeout(
        closeToast,
        5000
    );

})();

/*
|--------------------------------------------------------------------------
| Light / Dark Theme
|--------------------------------------------------------------------------
*/

(function () {

    const toggle =
        document.getElementById(
            'themeToggle'
        );

    const icon =
        document.getElementById(
            'themeIcon'
        );


    if (!toggle || !icon) {
        return;
    }


    function updateThemeIcon() {

        const dark =
            document.documentElement
                .classList
                .contains('dark');


        icon.className =
            dark
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


    toggle.addEventListener(
        'click',
        function () {

            const dark =
                document.documentElement
                    .classList
                    .toggle('dark');


            localStorage.setItem(
                'hoa-theme',
                dark
                    ? 'dark'
                    : 'light'
            );


            updateThemeIcon();
        }
    );


    updateThemeIcon();

})();

/*
|--------------------------------------------------------------------------
| Homeowner Profile Picture
|--------------------------------------------------------------------------
*/

(function () {

    const changeButton =
        document.getElementById(
            'changeProfilePictureBtn'
        );

    const fileInput =
        document.getElementById(
            'profilePictureInput'
        );

    const preview =
        document.getElementById(
            'profilePicturePreview'
        );


    if (
        !changeButton ||
        !fileInput ||
        !preview
    ) {
        return;
    }


    changeButton.addEventListener(
        'click',
        function () {

            fileInput.click();

        }
    );


    fileInput.addEventListener(
        'change',
        async function () {

            const file =
                this.files &&
                this.files[0];

            if (!file) {
                return;
            }


            /*
             * Client-side size check.
             */
            if (
                file.size >
                5 * 1024 * 1024
            ) {

                alert(
                    'Profile picture must be 5 MB or smaller.'
                );

                fileInput.value = '';

                return;
            }


            const allowedTypes = [
                'image/jpeg',
                'image/png',
                'image/webp'
            ];


            if (
                !allowedTypes.includes(
                    file.type
                )
            ) {

                alert(
                    'Please select a JPG, PNG, or WEBP image.'
                );

                fileInput.value = '';

                return;
            }


            changeButton.disabled = true;


            const oldHtml =
                changeButton.innerHTML;

            changeButton.innerHTML =
                '<i class="bi bi-hourglass-split"></i>';


            try {

                const formData =
                    new FormData();

                formData.append(
                    'action',
                    'update_profile_picture'
                );

                formData.append(
                    'csrf_token',
                    CSRF_TOKEN
                );

                formData.append(
                    'profile_picture',
                    file
                );


                const response =
                    await fetch(
                        'homeowner_dashboard.php',
                        {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        }
                    );


                const result =
                    await response.json();


                if (
                    !response.ok ||
                    !result.success
                ) {

                    throw new Error(
                        result.message ||
                        'Unable to update profile picture.'
                    );
                }


                /*
                 * Update dashboard preview immediately.
                 */
                preview.innerHTML = '';

                const image =
                    document.createElement(
                        'img'
                    );

                image.src =
                    result.image_url +
                    '?v=' +
                    Date.now();

                image.alt =
                    'Profile picture';

                image.className =
                    'h-full w-full object-cover';

                preview.appendChild(
                    image
                );


                /*
                 * Reload so sidebar also gets the new image.
                 */
                setTimeout(
                    function () {

                        location.reload();

                    },
                    500
                );


            } catch (error) {

                console.error(error);

                alert(
                    error.message ||
                    'Unable to upload profile picture.'
                );


            } finally {

                changeButton.disabled =
                    false;

                changeButton.innerHTML =
                    oldHtml;

                fileInput.value =
                    '';

            }

        }
    );

})();
</script>
</body>
</html>