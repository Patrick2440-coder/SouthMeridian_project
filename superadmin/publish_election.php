<?php
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ================= SECURITY =================
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

// ================= DB =================
$conn = new mysqli("localhost", "root", "", "your_database_name");
if ($conn->connect_error) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed'
    ]);
    exit;
}
$conn->set_charset("utf8mb4");

// ================= INPUT =================
$sessionId = (int)($_POST['session_id'] ?? 0);

if ($sessionId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid election session.'
    ]);
    exit;
}

// ================= HELPER: SEND WINNER EMAIL =================
function sendWinnerEmail($recipientEmail, $fullName, $positionName, $phaseTitle)
{
    $reportDate = date('F j, Y', strtotime('+1 day'));

    $safeName     = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safePosition = htmlspecialchars($positionName, ENT_QUOTES, 'UTF-8');
    $safePhase    = htmlspecialchars($phaseTitle, ENT_QUOTES, 'UTF-8');
    $safeDate     = htmlspecialchars($reportDate, ENT_QUOTES, 'UTF-8');

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'yourgmail@gmail.com';       // change this
    $mail->Password   = 'your app password here';    // change this
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('yourgmail@gmail.com', 'South Meridian HOA');
    $mail->addAddress($recipientEmail);

    $mail->isHTML(true);
    $mail->Subject = 'Election Result Notice - You Have Won';

    $mail->Body = "
        <div style='font-family: Arial, sans-serif; color: #222; line-height: 1.6;'>
            <h2 style='margin-bottom: 10px;'>Congratulations, {$safeName}!</h2>

            <p>We are pleased to inform you that you have <strong>won the election</strong> for the position of
            <strong>{$safePosition}</strong> for <strong>{$safePhase}</strong>.</p>

            <p>Please be informed that you are required to go to the HOA office on
            <strong>{$safeDate}</strong>, which is the day after this email was sent, for further instructions and confirmation of your appointment.</p>

            <p><strong>Important Information:</strong></p>
            <ul>
                <li>Name: {$safeName}</li>
                <li>Position Won: {$safePosition}</li>
                <li>Election Phase: {$safePhase}</li>
                <li>Report Date: {$safeDate}</li>
            </ul>

            <p>Please bring any required identification or documents if instructed by the office.</p>

            <br>
            <p>Congratulations once again.</p>
            <p>— South Meridian HOA</p>
        </div>
    ";

    $mail->send();
}

// ================= START TRANSACTION =================
$conn->begin_transaction();

try {
    // =========================================================
    // 1) GET SESSION INFO
    // Adjust column/table names based on your actual database
    // =========================================================
    $stmt = $conn->prepare("
        SELECT id, phase, title, status
        FROM election_sessions
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $sessionId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) {
        throw new Exception('Election session not found.');
    }

    $phaseTitle = $session['title'] ?: $session['phase'];

    // =========================================================
    // 2) GET WINNERS
    // This assumes:
    // - election_candidates.id
    // - election_candidates.homeowner_id
    // - election_candidates.position_id
    // - election_positions.id
    // - election_positions.position_name
    // - homeowners.id, first_name, last_name, email
    // - votes table stores candidate_id
    //
    // Adjust names if your tables differ.
    // =========================================================
    $winnerSql = "
        SELECT winners.position_id,
               winners.candidate_id,
               p.position_name,
               h.id AS homeowner_id,
               h.first_name,
               h.last_name,
               h.email
        FROM (
            SELECT c.position_id,
                   c.id AS candidate_id,
                   c.homeowner_id,
                   COUNT(v.id) AS total_votes
            FROM election_candidates c
            LEFT JOIN votes v ON v.candidate_id = c.id
            WHERE c.session_id = ?
            GROUP BY c.position_id, c.id, c.homeowner_id
        ) winners
        INNER JOIN (
            SELECT c2.position_id, MAX(vote_count.total_votes) AS max_votes
            FROM (
                SELECT c3.position_id,
                       c3.id AS candidate_id,
                       COUNT(v3.id) AS total_votes
                FROM election_candidates c3
                LEFT JOIN votes v3 ON v3.candidate_id = c3.id
                WHERE c3.session_id = ?
                GROUP BY c3.position_id, c3.id
            ) vote_count
            INNER JOIN election_candidates c2
                ON c2.id = vote_count.candidate_id
            GROUP BY c2.position_id
        ) topw
            ON winners.position_id = topw.position_id
           AND winners.total_votes = topw.max_votes
        INNER JOIN election_positions p
            ON p.id = winners.position_id
        INNER JOIN homeowners h
            ON h.id = winners.homeowner_id
        ORDER BY p.position_name ASC, h.last_name ASC, h.first_name ASC
    ";

    $stmt = $conn->prepare($winnerSql);
    $stmt->bind_param("ii", $sessionId, $sessionId);
    $stmt->execute();
    $winnerResult = $stmt->get_result();

    $winners = [];
    while ($row = $winnerResult->fetch_assoc()) {
        $winners[] = $row;
    }
    $stmt->close();

    if (empty($winners)) {
        throw new Exception('No winners found for this election session.');
    }

    // =========================================================
    // 3) OPTIONAL: CLEAR CURRENT OFFICERS FOR THIS PHASE
    // Adjust if you only replace matching positions instead of all
    // =========================================================
    $stmt = $conn->prepare("DELETE FROM officers WHERE election_session_id = ?");
    $stmt->bind_param("i", $sessionId);
    $stmt->execute();
    $stmt->close();

    // =========================================================
    // 4) INSERT NEW OFFICERS
    // Adjust table/columns based on your schema
    // =========================================================
    $insertOfficer = $conn->prepare("
        INSERT INTO officers (
            homeowner_id,
            position_id,
            election_session_id,
            phase,
            started_at,
            created_at
        ) VALUES (?, ?, ?, ?, NOW(), NOW())
    ");

    foreach ($winners as $winner) {
        $homeownerId = (int)$winner['homeowner_id'];
        $positionId  = (int)$winner['position_id'];
        $phase       = $session['phase'];

        $insertOfficer->bind_param("iiis", $homeownerId, $positionId, $sessionId, $phase);
        if (!$insertOfficer->execute()) {
            throw new Exception('Failed to insert officer record.');
        }
    }
    $insertOfficer->close();

    // =========================================================
    // 5) OPTIONAL: SAVE TO HISTORY
    // =========================================================
    $insertHistory = $conn->prepare("
        INSERT INTO election_history (
            election_session_id,
            phase,
            title,
            published_at,
            created_at
        ) VALUES (?, ?, ?, NOW(), NOW())
    ");
    $insertHistory->bind_param("iss", $sessionId, $session['phase'], $session['title']);
    $insertHistory->execute();
    $insertHistory->close();

    // =========================================================
    // 6) MARK SESSION AS PUBLISHED / FINISHED
    // If you do not have is_published column, remove it.
    // =========================================================
    $stmt = $conn->prepare("
        UPDATE election_sessions
        SET status = 'published'
        WHERE id = ?
    ");
    $stmt->bind_param("i", $sessionId);
    $stmt->execute();
    $stmt->close();

    // =========================================================
    // 7) SEND EMAILS TO WINNERS
    // =========================================================
    foreach ($winners as $winner) {
        if (!empty($winner['email'])) {
            $fullName = trim($winner['first_name'] . ' ' . $winner['last_name']);
            sendWinnerEmail(
                $winner['email'],
                $fullName,
                $winner['position_name'],
                $phaseTitle
            );
        }
    }

    // =========================================================
    // 8) OPTIONAL: DELETE ACTIVE SESSION / RESULTS AFTER PUBLISH
    // Only do this if this is really your intended flow.
    // =========================================================
    // Example:
    // $stmt = $conn->prepare("DELETE FROM election_sessions WHERE id = ?");
    // $stmt->bind_param("i", $sessionId);
    // $stmt->execute();
    // $stmt->close();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Election published successfully and winner emails have been sent.'
    ]);
    exit;

} catch (Exception $e) {
    $conn->rollback();

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}