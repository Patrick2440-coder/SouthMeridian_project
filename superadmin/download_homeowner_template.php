<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    $_SESSION['admin_role'] !== 'superadmin'
) {
    http_response_code(403);
    exit('Superadmin access is required.');
}

$file = __DIR__ . '/templates/South_Meridian_Resident_Import_Template.xlsx';

if (!is_file($file)) {
    http_response_code(404);
    exit('Homeowner import template was not found.');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="South_Meridian_Resident_Import_Template.xlsx"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($file);
exit;
