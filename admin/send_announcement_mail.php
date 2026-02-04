<?php


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . "/../vendor/autoload.php";

/**
 * Send announcement via PHPMailer.
 *
 * @return array { success:bool, sent:int, failed:int, errors:array }
 */
function send_announcement_mail(mysqli $conn, int $announcement_id, array $smtp): array
{
    $result = [
        'success' => false,
        'sent'    => 0,
        'failed'  => 0,
        'errors'  => []
    ];

    // 1) Load announcement
    $stmt = $conn->prepare("
        SELECT id, phase, title, category, message, start_date, end_date, priority
        FROM announcements
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $announcement_id);
    $stmt->execute();
    $ann = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$ann) {
        $result['errors'][] = "Announcement not found.";
        return $result;
    }

    // 2) Load recipients (avoid empty emails)
    $stmt = $conn->prepare("
        SELECT recipient_name, recipient_email
        FROM announcement_recipients
        WHERE announcement_id = ?
          AND recipient_email IS NOT NULL
          AND recipient_email <> ''
        ORDER BY recipient_email ASC
    ");
    $stmt->bind_param("i", $announcement_id);
    $stmt->execute();
    $recRes = $stmt->get_result();
    $stmt->close();

    $recipients = [];
    while ($r = $recRes->fetch_assoc()) {
        $email = trim($r['recipient_email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

        // Deduplicate by email
        $recipients[$email] = [
            'name'  => $r['recipient_name'] ?? '',
            'email' => $email
        ];
    }

    if (count($recipients) === 0) {
        $result['errors'][] = "No valid recipients found for this announcement.";
        return $result;
    }

    // 3) Load attachments
    $stmt = $conn->prepare("
        SELECT original_name, file_path
        FROM announcement_attachments
        WHERE announcement_id = ?
        ORDER BY id ASC
    ");
    $stmt->bind_param("i", $announcement_id);
    $stmt->execute();
    $attRes = $stmt->get_result();
    $stmt->close();

    $attachments = [];
    while ($a = $attRes->fetch_assoc()) {
        $rel = $a['file_path'] ?? '';
        if (!$rel) continue;

        // Convert relative path to absolute on server
        $abs = __DIR__ . "/" . ltrim($rel, "/");
        if (is_file($abs)) {
            $attachments[] = [
                'abs'  => $abs,
                'name' => $a['original_name'] ?: basename($abs)
            ];
        }
    }

    // 4) Build email subject/body
    $subject = "[{$ann['phase']}] " . ($ann['title'] ?? "Announcement");

    $priorityLabel = strtoupper($ann['priority'] ?? "normal");
    $start = $ann['start_date'] ?? '';
    $end   = $ann['end_date'] ?? '';

    // Basic HTML template
    $htmlBody = '
      <div style="font-family: Arial, sans-serif; line-height:1.5; color:#111;">
        <h2 style="margin:0 0 10px 0;">' . htmlspecialchars($ann['title']) . '</h2>
        <div style="margin:0 0 8px 0;">
          <b>Phase:</b> ' . htmlspecialchars($ann['phase']) . '<br>
          <b>Category:</b> ' . htmlspecialchars($ann['category']) . '<br>
          <b>Priority:</b> ' . htmlspecialchars($priorityLabel) . '<br>
          <b>Start:</b> ' . htmlspecialchars($start) . ($end ? ' &nbsp; <b>End:</b> ' . htmlspecialchars($end) : '') . '
        </div>
        <hr style="border:none;border-top:1px solid #e5e7eb;margin:12px 0;">
        <div style="white-space:pre-wrap;">' . nl2br(htmlspecialchars($ann['message'])) . '</div>
        <hr style="border:none;border-top:1px solid #e5e7eb;margin:12px 0;">
        <div style="font-size:12px;color:#6b7280;">
          This is an automated announcement from South Meridian Homes HOA System.
        </div>
      </div>
    ';

    $textBody = "Announcement: {$ann['title']}\n"
              . "Phase: {$ann['phase']}\n"
              . "Category: {$ann['category']}\n"
              . "Priority: {$priorityLabel}\n"
              . "Start: {$start}" . ($end ? "  End: {$end}" : "") . "\n\n"
              . ($ann['message'] ?? "");

    // 5) Send (INDIVIDUAL emails to avoid leaking addresses)
    foreach ($recipients as $rec) {
        $mail = new PHPMailer(true);

        try {
            // SMTP config
            $mail->isSMTP();
            $mail->Host       = $smtp['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtp['username'];
            $mail->Password   = $smtp['password'];
            $mail->SMTPSecure = $smtp['encryption']; // PHPMailer::ENCRYPTION_SMTPS or PHPMailer::ENCRYPTION_STARTTLS
            $mail->Port       = $smtp['port'];

            $mail->CharSet = "UTF-8";

            // Sender
            $mail->setFrom($smtp['from_email'], $smtp['from_name']);

            // Recipient
            $mail->addAddress($rec['email'], $rec['name']);

            // Subject & body
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody;

            // Attachments
            foreach ($attachments as $a) {
                $mail->addAttachment($a['abs'], $a['name']);
            }

            $mail->send();
            $result['sent']++;

        } catch (Exception $e) {
            $result['failed']++;
            $result['errors'][] = "Failed to send to {$rec['email']}: " . $mail->ErrorInfo;
        }
    }

    $result['success'] = ($result['sent'] > 0);
    return $result;
}
