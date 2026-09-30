<?php

require_once __DIR__ . '/finance_helpers.php';
require_once __DIR__ . '/admin_access.php';
requireAccess('finance');
require_admin();

$file = __DIR__ . '/South_Meridian_Historical_Dues_Migration_CLEAN_Template.xlsx';
if (!is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Historical dues migration template file was not found.';
    exit;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="South_Meridian_Historical_Dues_Migration_Template.xlsx"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($file);
exit;
