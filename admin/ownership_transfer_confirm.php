<?php
ini_set('display_errors','0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once '../config/database.php';
require_once 'ownership_transfer_common.php';
require_once 'homeowner_activity_logger.php';

function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }

$party=trim((string)($_POST['party']??$_GET['party']??''));
$token=strtolower(trim((string)($_POST['token']??$_GET['token']??'')));
$message=''; $messageType='info'; $canRespond=false; $transfer=null; $incoming=null; $old=null;

try{
    if(!in_array($party,['old','new'],true) || !preg_match('/^[a-f0-9]{64}$/',$token)){
        throw new RuntimeException('This ownership-transfer verification link is invalid.');
    }
    if(!otTransferSchemaReady($conn)) throw new RuntimeException('Ownership-transfer verification is not available yet.');

    $tokenHash=hash('sha256',$token);
    $column=$party==='old'?'old_token_hash':'new_token_hash';
    $stmt=$conn->prepare("SELECT * FROM homeowner_ownership_transfers WHERE {$column}=? LIMIT 1");
    $stmt->bind_param('s',$tokenHash); $stmt->execute(); $transfer=$stmt->get_result()->fetch_assoc()?:null; $stmt->close();
    if(!$transfer) throw new RuntimeException('This ownership-transfer verification link is invalid or has already been closed.');

    $sourceType=otNormalizeSourceType((string)($transfer['source_type']??'import_queue')) ?: 'import_queue';
    $sourceId=$sourceType==='pending_homeowner'?(int)($transfer['source_homeowner_id']??0):(int)($transfer['source_queue_id']??0);

    /* Public confirmation page may load the incoming row directly without admin phase filtering. */
    if($sourceType==='import_queue'){
        $s=$conn->prepare('SELECT * FROM homeowner_import_queue WHERE id=? LIMIT 1');
    }else{
        $s=$conn->prepare('SELECT * FROM homeowners WHERE id=? LIMIT 1');
    }
    $s->bind_param('i',$sourceId); $s->execute(); $incoming=$s->get_result()->fetch_assoc()?:null; $s->close();

    $oldId=(int)$transfer['previous_homeowner_id'];
    $s=$conn->prepare('SELECT * FROM homeowners WHERE id=? LIMIT 1'); $s->bind_param('i',$oldId); $s->execute(); $old=$s->get_result()->fetch_assoc()?:null; $s->close();

    $currentPartyStatus=$party==='old'?(string)$transfer['old_confirmation']:(string)$transfer['new_confirmation'];
    $terminal=in_array((string)$transfer['status'],['completed','cancelled','denied'],true);
    $expired=!empty($transfer['token_expires_at']) && strtotime((string)$transfer['token_expires_at'])<time();

    if($_SERVER['REQUEST_METHOD']==='POST'){
        $action=(string)($_POST['action']??'');
        if(!in_array($action,['confirm','deny'],true)) throw new RuntimeException('Select a valid response.');
        if($terminal) throw new RuntimeException('This ownership-transfer request is already closed.');
        if($expired) throw new RuntimeException('This verification link has expired. Please ask the HOA administrator to resend it.');
        if($currentPartyStatus==='confirmed' || ($party==='old' && $currentPartyStatus==='manual_verified')){
            throw new RuntimeException('Your response has already been recorded.');
        }

        $conn->begin_transaction();
        $lockedStmt=$conn->prepare('SELECT * FROM homeowner_ownership_transfers WHERE id=? LIMIT 1 FOR UPDATE');
        $transferId=(int)$transfer['id']; $lockedStmt->bind_param('i',$transferId); $lockedStmt->execute(); $locked=$lockedStmt->get_result()->fetch_assoc(); $lockedStmt->close();
        if(!$locked || in_array((string)$locked['status'],['completed','cancelled','denied'],true)) throw new RuntimeException('This ownership-transfer request is already closed.');
        if(!empty($locked['token_expires_at']) && strtotime((string)$locked['token_expires_at'])<time()) throw new RuntimeException('This verification link has expired. Please ask the HOA administrator to resend it.');

        $decision=$action==='confirm'?'confirmed':'denied';
        if($party==='old') $u=$conn->prepare("UPDATE homeowner_ownership_transfers SET old_confirmation=?, old_confirmed_at=NOW() WHERE id=?");
        else $u=$conn->prepare("UPDATE homeowner_ownership_transfers SET new_confirmation=?, new_confirmed_at=NOW() WHERE id=?");
        $u->bind_param('si',$decision,$transferId); $u->execute(); $u->close();

        $r=$conn->prepare('SELECT old_confirmation,new_confirmation FROM homeowner_ownership_transfers WHERE id=? LIMIT 1');
        $r->bind_param('i',$transferId); $r->execute(); $c=$r->get_result()->fetch_assoc(); $r->close();
        if((string)$c['old_confirmation']==='denied' || (string)$c['new_confirmation']==='denied') $next='denied';
        elseif(in_array((string)$c['old_confirmation'],['confirmed','manual_verified'],true) && (string)$c['new_confirmation']==='confirmed') $next='ready_for_admin';
        else $next='awaiting_confirmation';
        $u=$conn->prepare('UPDATE homeowner_ownership_transfers SET status=? WHERE id=?'); $u->bind_param('si',$next,$transferId); $u->execute(); $u->close();
        $conn->commit();

        $actor=$party==='old'?'Current homeowner':'Incoming homeowner';
        logHomeownerActivity($conn,$action==='confirm'?'Ownership transfer confirmation received':'Ownership transfer authorization denied',"Transfer #{$transferId}: {$actor} ".($action==='confirm'?'confirmed':'denied')." the request for {$transfer['phase']}, Block {$transfer['block']}, Lot {$transfer['lot']}.",null,(string)$transfer['phase']);
        $message=$action==='confirm'
            ?'Your confirmation was recorded. The HOA administrator must still verify the ownership documents and complete the final transfer.'
            :'Your response was recorded. The ownership-transfer request has been stopped for HOA review.';
        $messageType=$action==='confirm'?'success':'warning';

        $stmt=$conn->prepare('SELECT * FROM homeowner_ownership_transfers WHERE id=? LIMIT 1'); $stmt->bind_param('i',$transferId); $stmt->execute(); $transfer=$stmt->get_result()->fetch_assoc(); $stmt->close();
        $currentPartyStatus=$party==='old'?(string)$transfer['old_confirmation']:(string)$transfer['new_confirmation'];
        $terminal=in_array((string)$transfer['status'],['completed','cancelled','denied'],true);
        $expired=false;
    }

    if($message===''){
        if((string)$transfer['status']==='completed'){ $message='This ownership transfer has already been completed by the HOA administrator.'; $messageType='success'; }
        elseif((string)$transfer['status']==='cancelled'){ $message='This ownership-transfer request was cancelled by the HOA administrator.'; $messageType='warning'; }
        elseif((string)$transfer['status']==='denied'){ $message='This ownership-transfer request was stopped because one of the parties did not authorize it.'; $messageType='warning'; }
        elseif($expired){ $message='This verification link has expired. Please contact the HOA office and request a new verification email.'; $messageType='warning'; }
        elseif($currentPartyStatus==='confirmed'){ $message='You already confirmed this ownership-transfer request. No further action is needed from you right now.'; $messageType='success'; }
        elseif($party==='old' && $currentPartyStatus==='manual_verified'){ $message='The HOA office has already verified the current homeowner manually.'; $messageType='success'; }
        else $canRespond=true;
    }
}catch(Throwable $e){
    try{$conn->rollback();}catch(Throwable $ignored){}
    $message=$e->getMessage(); $messageType='danger'; $canRespond=false;
}

$oldName=$old?(otFullName($old)?:'Current Homeowner'):'Current Homeowner';
$newName=$incoming?(otFullName($incoming)?:'Incoming Homeowner'):'Incoming Homeowner';
$phase=(string)($transfer['phase']??($incoming['phase']??''));
$block=(string)($transfer['block']??''); $lot=(string)($transfer['lot']??'');
$partyName=$party==='old'?$oldName:$newName;
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ownership Transfer Verification - South Meridian HOA</title>
<style>*{box-sizing:border-box}body{margin:0;font-family:Inter,Arial,sans-serif;background:#f3f6f5;color:#17211c;padding:28px 14px}.wrap{max-width:760px;margin:0 auto}.card{background:#fff;border:1px solid #dfe8e3;border-radius:18px;box-shadow:0 12px 32px rgba(0,0,0,.08);overflow:hidden}.head{background:#077f46;color:#fff;padding:24px 28px}.head h1{font-size:24px;margin:0 0 6px}.head p{margin:0;opacity:.9}.body{padding:28px}.property{background:#f1f8f4;border:1px solid #cce6d7;border-radius:12px;padding:16px;margin:18px 0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:18px 0}.person{border:1px solid #e1e6e3;border-radius:12px;padding:14px}.label{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#66756d;margin-bottom:5px}.value{font-weight:700;word-break:break-word}.alert{padding:14px 16px;border-radius:10px;margin:16px 0}.alert-info{background:#eef6ff;color:#19486f}.alert-success{background:#eaf8f0;color:#17683d}.alert-warning{background:#fff7df;color:#765815}.alert-danger{background:#fdecec;color:#8b2424}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:24px}.btn{border:0;border-radius:9px;padding:12px 18px;font-weight:700;cursor:pointer;font-size:15px}.btn-confirm{background:#077f46;color:#fff}.btn-deny{background:#fff;color:#b42318;border:1px solid #efb1ac}.note{font-size:13px;color:#66756d;margin-top:18px;line-height:1.6}@media(max-width:640px){.grid{grid-template-columns:1fr}.body{padding:20px}.head{padding:20px}}</style></head>
<body><div class="wrap"><div class="card"><div class="head"><h1>South Meridian HOA</h1><p>Property Ownership Transfer Verification</p></div><div class="body">
<p>Hello <strong><?=h($partyName)?></strong>,</p>
<?php if($phase!==''&&$block!==''&&$lot!==''):?><div class="property"><div class="label">Property</div><div class="value"><?=h($phase)?> — Block <?=h($block)?>, Lot <?=h($lot)?></div></div><?php endif;?>
<?php if($old||$incoming):?><div class="grid"><div class="person"><div class="label">Current Registered Homeowner</div><div class="value"><?=h($oldName)?></div></div><div class="person"><div class="label">Incoming Homeowner</div><div class="value"><?=h($newName)?></div></div></div><?php endif;?>
<?php if($message!==''):?><div class="alert alert-<?=h($messageType)?>"><?=h($message)?></div><?php endif;?>
<?php if($canRespond):?>
<p><?= $party==='old'?'Please confirm whether you sold or transferred this property to the incoming homeowner shown above.':'Please confirm that you are requesting to become the new registered homeowner for this property.' ?></p>
<form method="post" class="actions" onsubmit="return confirm(this.dataset.question);"><input type="hidden" name="party" value="<?=h($party)?>"><input type="hidden" name="token" value="<?=h($token)?>"><button class="btn btn-confirm" type="submit" name="action" value="confirm" onclick="this.form.dataset.question='Confirm this ownership-transfer request?'">Confirm Transfer</button><button class="btn btn-deny" type="submit" name="action" value="deny" onclick="this.form.dataset.question='Are you sure you want to report that you did NOT authorize this ownership transfer?'">I Did Not Authorize This</button></form>
<?php endif;?>
<div class="note">Opening this page does not change ownership. A transfer is completed only after the required confirmations, document verification, and final approval by an authorized South Meridian HOA administrator.</div>
</div></div></div></body></html>
