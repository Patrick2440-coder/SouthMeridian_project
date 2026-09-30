<?php
declare(strict_types=1);

/**
 * South Meridian Homes - Shared Complaint Service
 *
 * Used by the website now and intentionally structured so the Android
 * application can call the same rules through an API later.
 */

function smh_complaint_allowed_categories(): array
{
    return [
        'general',
        'security',
        'maintenance',
        'noise',
        'parking',
        'neighbor',
        'billing',
        'other',
    ];
}

function smh_complaint_priority_for_category(string $category): string
{
    $category = strtolower(trim($category));

    return match ($category) {
        'security', 'noise', 'neighbor' => 'urgent',
        'maintenance', 'parking', 'billing' => 'high',
        default => 'normal',
    };
}

function smh_complaint_allowed_proof_types(): array
{
    return [
        'image/jpeg'      => ['kind' => 'image', 'ext' => 'jpg'],
        'image/png'       => ['kind' => 'image', 'ext' => 'png'],
        'image/webp'      => ['kind' => 'image', 'ext' => 'webp'],
        'image/gif'       => ['kind' => 'image', 'ext' => 'gif'],
        'video/mp4'       => ['kind' => 'video', 'ext' => 'mp4'],
        'video/webm'      => ['kind' => 'video', 'ext' => 'webm'],
        'video/quicktime' => ['kind' => 'video', 'ext' => 'mov'],
    ];
}

function smh_complaint_validate_proof(?array $file, bool $required = true): ?array
{
    if (
        !is_array($file) ||
        (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        if ($required) {
            throw new InvalidArgumentException(
                'Proof / Evidence is required. Please attach a picture or video.'
            );
        }
        return null;
    }

    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $size = (int)($file['size'] ?? 0);
    $tmpName = (string)($file['tmp_name'] ?? '');
    $originalName = trim((string)($file['name'] ?? 'proof'));

    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(
            'The proof file could not be uploaded. Please choose the file again.'
        );
    }

    if ($size <= 0 || $size > (50 * 1024 * 1024)) {
        throw new InvalidArgumentException(
            'Proof must be an image or video no larger than 50 MB.'
        );
    }

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new InvalidArgumentException(
            'The selected proof file is invalid. Please choose it again.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpName);
    $allowed = smh_complaint_allowed_proof_types();

    if (!isset($allowed[$mime])) {
        throw new InvalidArgumentException(
            'Unsupported proof format. Use JPG, PNG, WEBP, GIF, MP4, WEBM, or MOV.'
        );
    }

    return [
        'tmp_name'      => $tmpName,
        'original_name' => $originalName !== '' ? $originalName : 'proof.' . $allowed[$mime]['ext'],
        'mime_type'     => $mime,
        'file_kind'     => $allowed[$mime]['kind'],
        'extension'     => $allowed[$mime]['ext'],
        'file_size'     => $size,
    ];
}

function smh_complaint_find_phase_admin_id(mysqli $conn, string $phase): int
{
    $stmt = $conn->prepare("\n        SELECT id\n        FROM admins\n        WHERE phase = ? AND role = 'admin'\n        ORDER BY id ASC\n        LIMIT 1\n    ");
    $stmt->bind_param('s', $phase);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $adminId = (int)($row['id'] ?? 0);
    if ($adminId <= 0) {
        throw new RuntimeException('No HOA administrator is assigned to this phase.');
    }

    return $adminId;
}

/**
 * Create one complaint using the same rules for web/mobile callers.
 *
 * Expected payload keys:
 * homeowner_id, phase, admin_id(optional), subject, category, description,
 * submitted_by_role(optional), proof_required(optional)
 */
function smh_create_complaint(mysqli $conn, array $payload, ?array $proofFile = null): array
{
    $homeownerId = (int)($payload['homeowner_id'] ?? 0);
    $phase = trim((string)($payload['phase'] ?? ''));
    $subject = trim((string)($payload['subject'] ?? ''));
    $category = strtolower(trim((string)($payload['category'] ?? 'general')));
    $description = trim((string)($payload['description'] ?? ''));
    $submittedByRole = strtolower(trim((string)($payload['submitted_by_role'] ?? 'homeowner')));
    $proofRequired = !array_key_exists('proof_required', $payload) || (bool)$payload['proof_required'];

    if ($homeownerId <= 0) {
        throw new InvalidArgumentException('Invalid homeowner account.');
    }
    if ($phase === '') {
        throw new InvalidArgumentException('Homeowner phase is missing.');
    }
    if ($subject === '' || mb_strlen($subject) < 3) {
        throw new InvalidArgumentException('Subject must be at least 3 characters.');
    }
    if (!in_array($category, smh_complaint_allowed_categories(), true)) {
        throw new InvalidArgumentException('Invalid category selected.');
    }
    if ($description === '') {
        throw new InvalidArgumentException('Complaint details are required.');
    }

    if (!in_array($submittedByRole, ['homeowner', 'tenant'], true)) {
        $submittedByRole = 'homeowner';
    }

    // The current complaint_messages DB enum supports homeowner/admin only.
    $messageSenderType = 'homeowner';
    $priority = smh_complaint_priority_for_category($category);
    $proofMeta = smh_complaint_validate_proof($proofFile, $proofRequired);

    $adminId = (int)($payload['admin_id'] ?? 0);
    if ($adminId <= 0) {
        $adminId = smh_complaint_find_phase_admin_id($conn, $phase);
    }

    $savedProofAbsolutePath = null;

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare("\n            INSERT INTO complaints\n            (homeowner_id, phase, admin_id, subject, category, description, status, priority)\n            VALUES (?, ?, ?, ?, ?, ?, 'open', ?)\n        ");
        $stmt->bind_param(
            'isissss',
            $homeownerId,
            $phase,
            $adminId,
            $subject,
            $category,
            $description,
            $priority
        );
        $stmt->execute();
        $complaintId = (int)$stmt->insert_id;
        $stmt->close();

        $attachment = null;

        if ($proofMeta !== null) {
            $projectRoot = dirname(__DIR__);
            $uploadDirectory = $projectRoot
                . DIRECTORY_SEPARATOR . 'uploads'
                . DIRECTORY_SEPARATOR . 'complaints';

            if (
                !is_dir($uploadDirectory) &&
                !mkdir($uploadDirectory, 0775, true) &&
                !is_dir($uploadDirectory)
            ) {
                throw new RuntimeException('Unable to create the complaint upload directory.');
            }

            $storedFileName = 'complaint_' . $complaintId . '_'
                . bin2hex(random_bytes(12)) . '.' . $proofMeta['extension'];

            $savedProofAbsolutePath = $uploadDirectory . DIRECTORY_SEPARATOR . $storedFileName;

            if (!move_uploaded_file($proofMeta['tmp_name'], $savedProofAbsolutePath)) {
                throw new RuntimeException('Unable to save the uploaded complaint proof.');
            }

            $relativePath = 'uploads/complaints/' . $storedFileName;

            $stmt = $conn->prepare("\n                INSERT INTO complaint_attachments\n                (complaint_id, file_path, original_name, mime_type, file_kind, file_size)\n                VALUES (?, ?, ?, ?, ?, ?)\n            ");
            $stmt->bind_param(
                'issssi',
                $complaintId,
                $relativePath,
                $proofMeta['original_name'],
                $proofMeta['mime_type'],
                $proofMeta['file_kind'],
                $proofMeta['file_size']
            );
            $stmt->execute();
            $stmt->close();

            $attachment = [
                'file_path'     => $relativePath,
                'original_name' => $proofMeta['original_name'],
                'mime_type'     => $proofMeta['mime_type'],
                'file_kind'     => $proofMeta['file_kind'],
                'file_size'     => $proofMeta['file_size'],
            ];
        }

        $stmt = $conn->prepare("\n            INSERT INTO complaint_messages\n            (complaint_id, sender_type, sender_homeowner_id, message)\n            VALUES (?, ?, ?, ?)\n        ");
        $stmt->bind_param('isis', $complaintId, $messageSenderType, $homeownerId, $description);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $savedProofAbsolutePath = null;

        return [
            'id'                => $complaintId,
            'homeowner_id'      => $homeownerId,
            'phase'             => $phase,
            'admin_id'          => $adminId,
            'subject'           => $subject,
            'category'          => $category,
            'description'       => $description,
            'status'            => 'open',
            'priority'          => $priority,
            'attachment'        => $attachment,
            'submitted_by_role' => $submittedByRole,
        ];
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            // Preserve original exception.
        }

        if ($savedProofAbsolutePath !== null && is_file($savedProofAbsolutePath)) {
            @unlink($savedProofAbsolutePath);
        }

        throw $e;
    }
}
