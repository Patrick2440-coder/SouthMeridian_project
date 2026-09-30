<?php
ob_start();
ini_set('display_errors','0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
if(session_status()===PHP_SESSION_NONE) session_start();

function respond(array $data,int $status=200):void{
    while(ob_get_level()>0) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

require_once 'admin_access.php';
require_once '../config/database.php';
require_once 'ownership_transfer_common.php';
require_once 'homeowner_activity_logger.php';

if(empty($_SESSION['admin_id'])||empty($_SESSION['admin_role'])||!in_array($_SESSION['admin_role'],['admin','superadmin'],true)) respond(['success'=>false,'message'=>'Unauthorized.'],401);
requireAccess('homeowner_management');
if($_SERVER['REQUEST_METHOD']!=='POST') respond(['success'=>false,'message'=>'Invalid request method.'],405);
$csrf=trim((string)($_POST['csrf']??'')); $sessionCsrf=trim((string)($_SESSION['homeowner_import_csrf']??''));
if($csrf===''||$sessionCsrf===''||!hash_equals($sessionCsrf,$csrf)) respond(['success'=>false,'message'=>'Security token expired. Reload the page.'],403);
if(!otTransferSchemaReady($conn)) respond(['success'=>false,'message'=>'Run ownership_transfer_setup.sql before using ownership transfer.'],500);

$action=strtolower(trim((string)($_POST['action']??''))); $transferId=(int)($_POST['transfer_id']??0);
if($transferId<=0||!in_array($action,['manual_verify_old','cancel','finalize'],true)) respond(['success'=>false,'message'=>'Invalid ownership-transfer action.'],400);

$adminId=(int)$_SESSION['admin_id'];
$s=$conn->prepare('SELECT phase,role,full_name FROM admins WHERE id=? LIMIT 1'); $s->bind_param('i',$adminId); $s->execute(); $admin=$s->get_result()->fetch_assoc(); $s->close();
if(!$admin) respond(['success'=>false,'message'=>'Admin account not found.'],403);
$adminRole=(string)$admin['role']; $adminPhase=(string)$admin['phase'];

function loadTransfer(mysqli $conn,int $id,string $role,string $phase,bool $lock=false):?array{
    $tail=$lock?' FOR UPDATE':'';
    if($role==='superadmin'){$s=$conn->prepare("SELECT * FROM homeowner_ownership_transfers WHERE id=? LIMIT 1{$tail}");$s->bind_param('i',$id);}
    else{$s=$conn->prepare("SELECT * FROM homeowner_ownership_transfers WHERE id=? AND phase=? LIMIT 1{$tail}");$s->bind_param('is',$id,$phase);}
    $s->execute();$r=$s->get_result()->fetch_assoc()?:null;$s->close();return $r;
}

$transfer=loadTransfer($conn,$transferId,$adminRole,$adminPhase,false);
if(!$transfer) respond(['success'=>false,'message'=>'Ownership-transfer record was not found or is outside your assigned phase.'],404);
$phase=(string)$transfer['phase']; $propertyText="{$phase}, Block {$transfer['block']}, Lot {$transfer['lot']}";

if($action==='cancel'){
    if((string)$transfer['status']==='completed') respond(['success'=>false,'message'=>'A completed ownership transfer cannot be cancelled.'],409);
    $notes=trim((string)($_POST['notes']??''));
    $s=$conn->prepare("UPDATE homeowner_ownership_transfers SET status='cancelled',admin_notes=?,old_token_hash=NULL,new_token_hash=NULL,token_expires_at=NULL WHERE id=?");
    $s->bind_param('si',$notes,$transferId);$s->execute();$s->close();
    logHomeownerActivity($conn,'Ownership transfer cancelled',"Transfer #{$transferId} for {$propertyText} was cancelled.".($notes!==''?" Notes: {$notes}":''),$adminId,$phase);
    respond(['success'=>true,'message'=>'Ownership-transfer verification was cancelled.']);
}

if($action==='manual_verify_old'){
    if(in_array((string)$transfer['status'],['completed','cancelled','denied'],true)) respond(['success'=>false,'message'=>'This ownership-transfer request is already closed.'],409);
    $method=strtolower(trim((string)($_POST['verification_method']??''))); $notes=trim((string)($_POST['notes']??''));
    if(!in_array($method,['office','documents','mixed'],true)) respond(['success'=>false,'message'=>'Select how the current homeowner was verified.'],422);
    if($notes==='') respond(['success'=>false,'message'=>'Enter a note explaining how the current homeowner was verified.'],422);
    $next=(string)$transfer['new_confirmation']==='confirmed'?'ready_for_admin':'awaiting_confirmation';
    $s=$conn->prepare("UPDATE homeowner_ownership_transfers SET old_confirmation='manual_verified',old_manual_verified_by_admin_id=?,old_manual_verified_at=NOW(),verification_method=?,admin_notes=?,status=? WHERE id=?");
    $s->bind_param('isssi',$adminId,$method,$notes,$next,$transferId);$s->execute();$s->close();
    logHomeownerActivity($conn,'Current homeowner manually verified',"Transfer #{$transferId} for {$propertyText}. Method: {$method}. Notes: {$notes}",$adminId,$phase);
    respond(['success'=>true,'message'=>$next==='ready_for_admin'?'Current homeowner was verified. Both parties are ready for final admin review.':'Current homeowner was verified. Waiting for the incoming homeowner email confirmation.']);
}

$documentsVerified=(int)($_POST['documents_verified']??0)===1;
$verificationMethod=strtolower(trim((string)($_POST['verification_method']??'')));
$adminNotes=trim((string)($_POST['notes']??''));
if(!$documentsVerified) respond(['success'=>false,'message'=>'Confirm that the ownership-transfer documents were verified.'],422);
if(!in_array($verificationMethod,['email','office','documents','mixed'],true)) respond(['success'=>false,'message'=>'Select a valid verification method.'],422);
if($adminNotes==='') respond(['success'=>false,'message'=>'Add a short admin note for the ownership-transfer history.'],422);
$statusCol=$conn->query("SHOW COLUMNS FROM homeowners LIKE 'status'")->fetch_assoc();
if(!$statusCol||stripos((string)$statusCol['Type'],"'former'")===false) respond(['success'=>false,'message'=>'Run ownership_transfer_setup.sql first so Former Owner status is available.'],500);

$mailConfig=otLoadMailConfig(); $transactionStarted=false;
try{
    $conn->begin_transaction(); $transactionStarted=true;
    $transfer=loadTransfer($conn,$transferId,$adminRole,$adminPhase,true);
    if(!$transfer) throw new RuntimeException('Ownership-transfer record is no longer available.');
    if(in_array((string)$transfer['status'],['completed','cancelled','denied'],true)) throw new RuntimeException('This ownership-transfer request is already closed.');
    if(!in_array((string)$transfer['old_confirmation'],['confirmed','manual_verified'],true)||(string)$transfer['new_confirmation']!=='confirmed') throw new RuntimeException('Both required homeowner verifications must be completed before final transfer.');

    $sourceType=otNormalizeSourceType((string)($transfer['source_type']??'import_queue'))?:'import_queue';
    $sourceId=$sourceType==='pending_homeowner'?(int)($transfer['source_homeowner_id']??0):(int)($transfer['source_queue_id']??0);
    $incoming=otLoadIncoming($conn,$sourceType,$sourceId,$adminRole,$adminPhase,true);
    if(!$incoming) throw new RuntimeException('The incoming homeowner record is no longer pending or available.');

    $previousId=(int)$transfer['previous_homeowner_id'];
    $s=$conn->prepare('SELECT * FROM homeowners WHERE id=? LIMIT 1 FOR UPDATE');$s->bind_param('i',$previousId);$s->execute();$old=$s->get_result()->fetch_assoc()?:null;$s->close();
    if(!$old||(string)$old['status']!=='approved') throw new RuntimeException('The previous homeowner is no longer the active approved homeowner.');
    if(!otSameProperty($incoming,$old)||!otEmailsDifferent($incoming,$old)) throw new RuntimeException('The property conflict no longer matches a valid ownership-transfer case.');

    $newEmail=strtolower(trim((string)$incoming['email']));
    if(!filter_var($newEmail,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The incoming homeowner has an invalid email address.');
    $excludeIncoming=$sourceType==='pending_homeowner'?$sourceId:0;
    $emailCheck=$conn->prepare('SELECT id FROM homeowners WHERE LOWER(TRIM(email))=? AND id<>? LIMIT 1');
    $emailCheck->bind_param('si',$newEmail,$excludeIncoming);$emailCheck->execute();$used=$emailCheck->get_result()->fetch_assoc();$emailCheck->close();
    if($used) throw new RuntimeException('The incoming homeowner email is already used by another homeowner account.');

    [$block,$lot]=otBlockLot($incoming); $newPhase=(string)$incoming['phase'];
    $regex=otPropertyRegex($block,$lot);
    $active=$conn->prepare("SELECT id FROM homeowners WHERE phase=? AND status='approved' AND id<>? AND id<>? AND ((CAST(block AS UNSIGNED)=? AND CAST(lot AS UNSIGNED)=?) OR house_lot_number REGEXP ?) LIMIT 1");
    $active->bind_param('siiiis',$newPhase,$previousId,$excludeIncoming,$block,$lot,$regex);$active->execute();$other=$active->get_result()->fetch_assoc();$active->close();
    if($other) throw new RuntimeException('Another active homeowner is already assigned to this Block and Lot.');

    $resetToken=bin2hex(random_bytes(32)); $resetHash=hash('sha256',$resetToken); $resetExpiry=date('Y-m-d H:i:s',time()+3600); $tempPassword=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);

    if($sourceType==='pending_homeowner'){
        $newHomeownerId=$sourceId;
        $publicId=trim((string)($incoming['public_id']??''));
        if($publicId===''){
            $pn=(int)filter_var($newPhase,FILTER_SANITIZE_NUMBER_INT); if($pn<1||$pn>3) throw new RuntimeException('Unable to generate homeowner public ID.');
            $publicId='P'.$pn.$newHomeownerId;
        }
        $u=$conn->prepare("UPDATE homeowners SET public_id=?,status='approved',password=?,must_change_password=1,reset_token=?,reset_expires=? WHERE id=? AND status='pending' LIMIT 1");
        $u->bind_param('ssssi',$publicId,$tempPassword,$resetHash,$resetExpiry,$newHomeownerId);$u->execute();
        if($u->affected_rows!==1){$u->close();throw new RuntimeException('The pending homeowner could not be activated because the record changed.');}$u->close();
        $newName=otFullName($incoming)?:'Homeowner';
    }else{
        $first=trim((string)($incoming['first_name']??''));$middle=trim((string)($incoming['middle_name']??''));$last=trim((string)($incoming['last_name']??''));
        $contact=trim((string)($incoming['contact_number']??''));$houseLot=trim((string)($incoming['house_lot_number']??''))?:"Block {$block} Lot {$lot}";
        $street=trim((string)($incoming['street']??''));$mapX=(int)($incoming['map_x']??0);$mapY=(int)($incoming['map_y']??0);
        $barangay=trim((string)($incoming['barangay']??''))?:'Salitran IV';$city=trim((string)($incoming['city_municipality']??''))?:'Dasmarinas City';$province=trim((string)($incoming['province']??''))?:'Cavite';$region=trim((string)($incoming['region']??''))?:'CALABARZON';$zip=trim((string)($incoming['zip_code']??''))?:'4114';$country=trim((string)($incoming['country']??''))?:'Philippines';
        $otherLocation=trim((string)($incoming['other_location_info']??''));$exact=trim((string)($incoming['exact_location']??''))?:"Block {$block}, Lot {$lot}".($street!==''?", {$street}":'');
        $length=trim((string)($incoming['length_of_residency']??''));$resType=trim((string)($incoming['residential_type']??'Owner'))?:'Owner';$emPerson=trim((string)($incoming['emergency_contact_person']??''));$emNumber=trim((string)($incoming['emergency_contact_number']??''));
        $validId='imports/not_provided';$proof='imports/not_provided';$blockS=(string)$block;$lotS=(string)$lot;
        $i=$conn->prepare("INSERT INTO homeowners (public_id,first_name,middle_name,last_name,contact_number,email,password,must_change_password,phase,house_lot_number,block,lot,street,map_x,map_y,barangay,city_municipality,province,region,zip_code,country,other_location_info,exact_location,valid_id_path,proof_of_billing_path,status,admin_id,length_of_residency,residential_type,emergency_contact_person,emergency_contact_number,reset_token,reset_expires) VALUES (NULL,?,?,?,?,?,?,1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'approved',?,?,?,?,?,?,?)");
        $i->bind_param('sssssssssssiissssssssssissssss',$first,$middle,$last,$contact,$newEmail,$tempPassword,$newPhase,$houseLot,$blockS,$lotS,$street,$mapX,$mapY,$barangay,$city,$province,$region,$zip,$country,$otherLocation,$exact,$validId,$proof,$adminId,$length,$resType,$emPerson,$emNumber,$resetHash,$resetExpiry);
        $i->execute();$newHomeownerId=(int)$i->insert_id;$i->close();
        $pn=(int)filter_var($newPhase,FILTER_SANITIZE_NUMBER_INT);if($pn<1||$pn>3)throw new RuntimeException('Unable to generate homeowner public ID.');$publicId='P'.$pn.$newHomeownerId;
        $u=$conn->prepare('UPDATE homeowners SET public_id=? WHERE id=? LIMIT 1');$u->bind_param('si',$publicId,$newHomeownerId);$u->execute();$u->close();
        $q=$conn->prepare("UPDATE homeowner_import_queue SET status='approved',approved_homeowner_id=?,approved_at=NOW() WHERE id=? AND status='duplicate' LIMIT 1");$q->bind_param('ii',$newHomeownerId,$sourceId);$q->execute();$q->close();
        $newName=otFullName($incoming)?:'Homeowner';
    }

    $former=$conn->prepare("UPDATE homeowners SET status='former',reset_token=NULL,reset_expires=NULL WHERE id=? AND status='approved' LIMIT 1");$former->bind_param('i',$previousId);$former->execute();if($former->affected_rows!==1){$former->close();throw new RuntimeException('The previous homeowner could not be changed to Former Owner.');}$former->close();

    $inactiveTenantCount=0;$tt=$conn->query("SHOW TABLES LIKE 'tenants'");
    if($tt&&$tt->num_rows>0){$t=$conn->prepare("UPDATE tenants SET status='inactive' WHERE homeowner_id=? AND status='active'");$t->bind_param('i',$previousId);$t->execute();$inactiveTenantCount=max(0,(int)$t->affected_rows);$t->close();}

    $u=$conn->prepare("UPDATE homeowner_ownership_transfers SET new_homeowner_id=?,documents_verified=1,verification_method=?,admin_notes=?,status='completed',completed_by_admin_id=?,completed_at=NOW(),old_token_hash=NULL,new_token_hash=NULL,token_expires_at=NULL WHERE id=?");
    $u->bind_param('issii',$newHomeownerId,$verificationMethod,$adminNotes,$adminId,$transferId);$u->execute();$u->close();

    $oldName=otFullName($old)?:'Previous Homeowner';$propertyText="{$newPhase}, Block {$block}, Lot {$lot}";
    $resetLink=$mailConfig['app_base_url'].'/admin/reset-password.php?token='.urlencode($resetToken);
    $safeName=htmlspecialchars($newName,ENT_QUOTES,'UTF-8');$safeLink=htmlspecialchars($resetLink,ENT_QUOTES,'UTF-8');$safeProperty=htmlspecialchars($propertyText,ENT_QUOTES,'UTF-8');$safePublic=htmlspecialchars($publicId,ENT_QUOTES,'UTF-8');
    $newBody="<div style=\"font-family:Arial,sans-serif;color:#222;line-height:1.6\"><h2 style=\"color:#077f46\">South Meridian HOA</h2><p>Hello <strong>{$safeName}</strong>,</p><p>The ownership transfer for <strong>{$safeProperty}</strong> has been verified and completed by the HOA administrator.</p><p>Your homeowner account is now active. Please set your password using the secure link below.</p><p style=\"margin:24px 0\"><a href=\"{$safeLink}\" style=\"background:#077f46;color:#fff;padding:12px 18px;text-decoration:none;border-radius:8px;font-weight:bold\">Set My Password</a></p><p><strong>This password setup link expires in 1 hour.</strong></p><p>Your homeowner ID is <strong>{$safePublic}</strong>.</p></div>";
    otSendMail($mailConfig,$newEmail,$newName,'Your South Meridian HOA Ownership Transfer Is Complete',$newBody,"Hello {$newName},\n\nYour ownership transfer for {$propertyText} is complete. Set your password: {$resetLink}\n\nLink expires in 1 hour. Homeowner ID: {$publicId}");

    $conn->commit();$transactionStarted=false;
    logHomeownerActivity($conn,'Ownership transfer completed',"Transfer #{$transferId}: {$propertyText}. Previous homeowner: {$oldName} (#{$previousId}) -> Former Owner. New homeowner: {$newName} ({$publicId}, #{$newHomeownerId}) -> Active. {$inactiveTenantCount} active tenant account(s) were made inactive. Source: {$sourceType}. Method: {$verificationMethod}. Notes: {$adminNotes}",$adminId,$newPhase);

    $oldEmail=strtolower(trim((string)($old['email']??'')));
    if(filter_var($oldEmail,FILTER_VALIDATE_EMAIL)){
        try{
            $safeOld=htmlspecialchars($oldName,ENT_QUOTES,'UTF-8');
            $oldBody="<div style=\"font-family:Arial,sans-serif;color:#222;line-height:1.6\"><h2 style=\"color:#077f46\">South Meridian HOA</h2><p>Hello <strong>{$safeOld}</strong>,</p><p>The ownership transfer for <strong>{$safeProperty}</strong> has been completed by the HOA administrator.</p><p>Your homeowner record is preserved as a Former Owner for historical and audit purposes. Your previous HOA transactions remain attached to your record.</p><p>If this is unexpected, contact the HOA office immediately.</p></div>";
            otSendMail($mailConfig,$oldEmail,$oldName,'South Meridian HOA Ownership Transfer Completed',$oldBody,"Hello {$oldName},\n\nThe ownership transfer for {$propertyText} has been completed. Your historical HOA record is preserved as Former Owner.");
        }catch(Throwable $mailError){error_log('Old owner completion email failed for transfer #'.$transferId.': '.$mailError->getMessage());}
    }

    respond(['success'=>true,'new_homeowner_id'=>$newHomeownerId,'public_id'=>$publicId,'message'=>'Ownership transfer completed. The previous homeowner is now a Former Owner and the new homeowner account is active.']);
}catch(Throwable $e){
    if($transactionStarted){try{$conn->rollback();}catch(Throwable $ignored){}}
    error_log('Finalize ownership transfer failed: '.$e->getMessage());
    respond(['success'=>false,'message'=>$e->getMessage()],500);
}
