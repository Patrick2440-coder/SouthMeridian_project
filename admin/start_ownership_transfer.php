<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status() === PHP_SESSION_NONE) session_start();

function respond(array $data, int $status=200): void {
    while (ob_get_level()>0) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

require_once 'admin_access.php';
require_once '../config/database.php';
require_once 'ownership_transfer_common.php';
require_once 'homeowner_activity_logger.php';

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) || !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {
    respond(['success'=>false,'message'=>'Unauthorized.'],401);
}
requireAccess('homeowner_management');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['success'=>false,'message'=>'Invalid request method.'],405);

$csrf = trim((string)($_POST['csrf'] ?? ''));
$sessionCsrf = trim((string)($_SESSION['homeowner_import_csrf'] ?? ''));
if ($csrf==='' || $sessionCsrf==='' || !hash_equals($sessionCsrf,$csrf)) {
    respond(['success'=>false,'message'=>'Security token expired. Reload the page.'],403);
}
if (!otTransferSchemaReady($conn)) {
    respond(['success'=>false,'message'=>'Ownership Transfer database setup has not been installed yet. Run ownership_transfer_setup.sql first.'],500);
}

$sourceType = otNormalizeSourceType(trim((string)($_POST['source'] ?? 'import_queue')));
$sourceId = (int)($_POST['id'] ?? 0);
if ($sourceType==='' || $sourceId<=0) respond(['success'=>false,'message'=>'Invalid transfer candidate.'],400);

$adminId=(int)$_SESSION['admin_id'];
$stmt=$conn->prepare('SELECT phase,role FROM admins WHERE id=? LIMIT 1');
$stmt->bind_param('i',$adminId); $stmt->execute(); $admin=$stmt->get_result()->fetch_assoc(); $stmt->close();
if(!$admin) respond(['success'=>false,'message'=>'Admin account not found.'],403);
$adminRole=(string)$admin['role']; $adminPhase=(string)$admin['phase'];

$incoming=otLoadIncoming($conn,$sourceType,$sourceId,$adminRole,$adminPhase,false);
if(!$incoming) respond(['success'=>false,'message'=>'Transfer candidate not found, already processed, or outside your assigned phase.'],404);

$old=null;
if($sourceType==='import_queue'){
    $previousId=(int)($incoming['duplicate_homeowner_id']??0);
    if($previousId>0){
        $s=$conn->prepare('SELECT * FROM homeowners WHERE id=? LIMIT 1'); $s->bind_param('i',$previousId); $s->execute(); $old=$s->get_result()->fetch_assoc()?:null; $s->close();
    }
}else{
    $old=otFindApprovedPropertyOwner($conn,$incoming,$sourceId);
    $previousId=(int)($old['id']??0);
}
if(!$old || $previousId<=0) respond(['success'=>false,'message'=>'No active current homeowner could be found for this property.'],409);
if((string)$old['status']!=='approved') respond(['success'=>false,'message'=>'The linked homeowner is no longer active.'],409);
if(!otSameProperty($incoming,$old) || !otEmailsDifferent($incoming,$old)){
    respond(['success'=>false,'message'=>'This conflict is not a valid same-property ownership-transfer case.'],409);
}

$oldEmail=strtolower(trim((string)$old['email']));
$newEmail=strtolower(trim((string)$incoming['email']));
if(!filter_var($oldEmail,FILTER_VALIDATE_EMAIL) || !filter_var($newEmail,FILTER_VALIDATE_EMAIL)){
    respond(['success'=>false,'message'=>'Both parties need valid email addresses before verification can start.'],422);
}

[$block,$lot]=otBlockLot($incoming);
$phase=(string)$incoming['phase'];
$oldName=otFullName($old)?:'Current Homeowner';
$newName=otFullName($incoming)?:'Incoming Homeowner';
$mailConfig=otLoadMailConfig();
$expiresAt=date('Y-m-d H:i:s',time()+48*3600);
$oldRawToken=bin2hex(random_bytes(32)); $newRawToken=bin2hex(random_bytes(32));
$oldTokenHash=hash('sha256',$oldRawToken); $newTokenHash=hash('sha256',$newRawToken);

$existing=otFindTransferBySource($conn,$sourceType,$sourceId,false);
if($existing && (string)$existing['status']==='completed') respond(['success'=>false,'message'=>'This ownership transfer has already been completed.'],409);

$oldStatus='pending'; $newStatus='pending';
if($existing && !in_array((string)$existing['status'],['cancelled','denied'],true)){
    $oldStatus=(string)$existing['old_confirmation'];
    $newStatus=(string)$existing['new_confirmation'];
}
$sendOld=!in_array($oldStatus,['confirmed','manual_verified'],true);
$sendNew=$newStatus!=='confirmed';

try{
    $conn->begin_transaction();
    $status=(in_array($oldStatus,['confirmed','manual_verified'],true) && $newStatus==='confirmed')?'ready_for_admin':'awaiting_confirmation';

    if($existing){
        if(in_array((string)$existing['status'],['cancelled','denied'],true)){
            $oldStatus='pending'; $newStatus='pending'; $sendOld=true; $sendNew=true; $status='awaiting_confirmation';
        }
        $sourceQueueId=$sourceType==='import_queue'?$sourceId:null;
        $sourceHomeownerId=$sourceType==='pending_homeowner'?$sourceId:null;
        $u=$conn->prepare(
            "UPDATE homeowner_ownership_transfers
             SET source_type=?, source_queue_id=?, source_homeowner_id=?, previous_homeowner_id=?,
                 phase=?, block=?, lot=?, old_email=?, new_email=?, old_token_hash=?, new_token_hash=?, token_expires_at=?,
                 old_confirmation=?, new_confirmation=?, status=?,
                 old_confirmed_at=IF(?='pending',NULL,old_confirmed_at),
                 new_confirmed_at=IF(?='pending',NULL,new_confirmed_at),
                 initiated_by_admin_id=?, initiated_at=NOW(), documents_verified=0,
                 verification_method=NULL, admin_notes=NULL, completed_by_admin_id=NULL,
                 completed_at=NULL, new_homeowner_id=NULL
             WHERE id=?"
        );
        $blockS=(string)$block; $lotS=(string)$lot; $transferId=(int)$existing['id'];
        $u->bind_param('siiisssssssssssssii',
            $sourceType,$sourceQueueId,$sourceHomeownerId,$previousId,
            $phase,$blockS,$lotS,$oldEmail,$newEmail,$oldTokenHash,$newTokenHash,$expiresAt,
            $oldStatus,$newStatus,$status,$oldStatus,$newStatus,$adminId,$transferId
        );
        $u->execute(); $u->close();
    }else{
        $sourceQueueId=$sourceType==='import_queue'?$sourceId:null;
        $sourceHomeownerId=$sourceType==='pending_homeowner'?$sourceId:null;
        $i=$conn->prepare(
            "INSERT INTO homeowner_ownership_transfers
             (source_type,source_queue_id,source_homeowner_id,previous_homeowner_id,phase,block,lot,
              old_email,new_email,old_token_hash,new_token_hash,token_expires_at,
              old_confirmation,new_confirmation,status,initiated_by_admin_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'pending','pending','awaiting_confirmation',?)"
        );
        $blockS=(string)$block; $lotS=(string)$lot;
        $i->bind_param('siiissssssssi',$sourceType,$sourceQueueId,$sourceHomeownerId,$previousId,$phase,$blockS,$lotS,$oldEmail,$newEmail,$oldTokenHash,$newTokenHash,$expiresAt,$adminId);
        $i->execute(); $transferId=(int)$i->insert_id; $i->close();
    }

    $base=$mailConfig['app_base_url'].'/admin/ownership_transfer_confirm.php';
    $oldLink=$base.'?party=old&token='.urlencode($oldRawToken);
    $newLink=$base.'?party=new&token='.urlencode($newRawToken);
    $property="{$phase}, Block {$block}, Lot {$lot}";
    $safeProperty=htmlspecialchars($property,ENT_QUOTES,'UTF-8');
    $safeOld=htmlspecialchars($oldName,ENT_QUOTES,'UTF-8');
    $safeNew=htmlspecialchars($newName,ENT_QUOTES,'UTF-8');

    if($sendOld){
        $safeLink=htmlspecialchars($oldLink,ENT_QUOTES,'UTF-8');
        $body="<div style=\"font-family:Arial,sans-serif;color:#222;line-height:1.6\"><h2 style=\"color:#077f46\">South Meridian HOA</h2><p>Hello <strong>{$safeOld}</strong>,</p><p>A possible ownership transfer was reported for <strong>{$safeProperty}</strong>.</p><p>The incoming homeowner is <strong>{$safeNew}</strong>.</p><p>Please review whether you sold or transferred this property.</p><p style=\"margin:24px 0\"><a href=\"{$safeLink}\" style=\"background:#077f46;color:#fff;padding:12px 18px;text-decoration:none;border-radius:8px;font-weight:bold\">Review Ownership Transfer</a></p><p><strong>This link expires in 48 hours.</strong></p><p>If you did not authorize this, choose <strong>I Did Not Authorize This</strong> on the review page or contact the HOA office.</p></div>";
        otSendMail($mailConfig,$oldEmail,$oldName,'Please Confirm a South Meridian Property Ownership Transfer',$body,"Hello {$oldName},\n\nA possible ownership transfer was reported for {$property}. Incoming homeowner: {$newName}.\n\nReview: {$oldLink}\n\nThis link expires in 48 hours.");
    }

    if($sendNew){
        $safeLink=htmlspecialchars($newLink,ENT_QUOTES,'UTF-8');
        $body="<div style=\"font-family:Arial,sans-serif;color:#222;line-height:1.6\"><h2 style=\"color:#077f46\">South Meridian HOA</h2><p>Hello <strong>{$safeNew}</strong>,</p><p>Your homeowner record for <strong>{$safeProperty}</strong> matches a property that is currently registered to <strong>{$safeOld}</strong>.</p><p>Please confirm that you are requesting to become the new registered homeowner for this property.</p><p style=\"margin:24px 0\"><a href=\"{$safeLink}\" style=\"background:#077f46;color:#fff;padding:12px 18px;text-decoration:none;border-radius:8px;font-weight:bold\">Review Ownership Request</a></p><p><strong>This link expires in 48 hours.</strong></p><p>No ownership change is made until the HOA administrator verifies the supporting documents and completes the transfer.</p></div>";
        otSendMail($mailConfig,$newEmail,$newName,'Please Confirm Your South Meridian Ownership Transfer Request',$body,"Hello {$newName},\n\nYour request for {$property} matches a currently registered property. Current homeowner: {$oldName}.\n\nReview: {$newLink}\n\nThis link expires in 48 hours. No ownership change happens until HOA final review.");
    }

    $conn->commit();
    logHomeownerActivity($conn,'Ownership transfer verification started',"Transfer #{$transferId}: {$property}. Current homeowner: {$oldName}. Incoming homeowner: {$newName}. Verification email(s) sent.",$adminId,$phase);
    respond(['success'=>true,'transfer_id'=>$transferId,'message'=>'Ownership-transfer verification email(s) were sent. The transfer will remain pending until the required confirmations and admin document review are completed.']);
}catch(Throwable $e){
    try{$conn->rollback();}catch(Throwable $ignored){}
    error_log('start_ownership_transfer.php: '.$e->getMessage());
    respond(['success'=>false,'message'=>'The verification could not be started. No ownership change was made. '.$e->getMessage()],500);
}
