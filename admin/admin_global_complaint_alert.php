<?php
/*
 * Global complaint watcher for all normal admin pages.
 * Include once from admin/sidebar.php.
 */
if (empty($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
    return;
}

$currentAdminPage = basename($_SERVER['PHP_SELF'] ?? '');
if ($currentAdminPage === 'admin_complaints.php') {
    return; // that page already has its own detailed watcher
}

$globalComplaintAdminId = (int)$_SESSION['admin_id'];
?>
<style>
#hoaComplaintAlertControl{position:fixed;right:18px;bottom:18px;z-index:100050;display:flex;align-items:center;gap:8px;padding:10px 13px;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#334155;box-shadow:0 10px 28px rgba(15,23,42,.18);font:800 12px/1.2 Inter,Arial,sans-serif;cursor:pointer}
#hoaComplaintAlertControl.connected{border-color:#86efac;background:#f0fdf4;color:#166534}
#hoaComplaintAlertControl.offline{border-color:#fca5a5;background:#fef2f2;color:#991b1b}
#hoaComplaintAlertControl.unauthorized{border-color:#cbd5e1;background:#f8fafc;color:#64748b;cursor:default}
#hoaComplaintAlertToast{position:fixed;right:20px;top:84px;z-index:100060;width:min(420px,calc(100vw - 32px));background:#fff;border:1px solid #e2e8f0;border-left:6px solid #2563eb;border-radius:16px;box-shadow:0 24px 55px rgba(15,23,42,.27);opacity:0;visibility:hidden;transform:translateY(-12px) scale(.98);transition:.22s ease;pointer-events:none;overflow:hidden}
#hoaComplaintAlertToast.show{opacity:1;visibility:visible;transform:translateY(0) scale(1);pointer-events:auto}
#hoaComplaintAlertToast.priority-low{border-left-color:#64748b}#hoaComplaintAlertToast.priority-normal{border-left-color:#2563eb}#hoaComplaintAlertToast.priority-high{border-left-color:#d97706}#hoaComplaintAlertToast.priority-urgent{border-left-color:#dc2626;animation:hoaComplaintPulse .9s ease-in-out infinite}
@keyframes hoaComplaintPulse{50%{box-shadow:0 24px 60px rgba(220,38,38,.42)}}
.hoa-alert-body{display:flex;gap:12px;padding:16px}.hoa-alert-icon{width:46px;height:46px;flex:0 0 46px;border-radius:12px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:23px}.hoa-alert-title{font-size:15px;font-weight:900;color:#0f172a}.hoa-alert-meta{margin-top:5px;color:#64748b;font-size:12px;line-height:1.5}.hoa-alert-actions{display:flex;justify-content:flex-end;gap:8px;padding:0 16px 15px}.hoa-alert-actions a,.hoa-alert-actions button{border:0;border-radius:9px;padding:8px 12px;font-size:12px;font-weight:800;cursor:pointer;text-decoration:none}.hoa-alert-open{background:#077f46;color:#fff!important}.hoa-alert-close{background:#f1f5f9;color:#475569}
html.dark #hoaComplaintAlertControl{background:#172033;border-color:#334155;color:#cbd5e1}html.dark #hoaComplaintAlertControl.connected{background:rgba(16,185,129,.13);border-color:#065f46;color:#6ee7b7}html.dark #hoaComplaintAlertControl.offline{background:rgba(239,68,68,.12);border-color:#991b1b;color:#fca5a5}html.dark #hoaComplaintAlertToast{background:#172033;border-color:#334155}html.dark .hoa-alert-title{color:#f8fafc}html.dark .hoa-alert-meta{color:#94a3b8}html.dark .hoa-alert-close{background:#334155;color:#e2e8f0}
@media(max-width:575px){#hoaComplaintAlertToast{top:72px;right:10px;width:calc(100vw - 20px)}#hoaComplaintAlertControl{right:10px;bottom:10px}}
</style>

<button type="button" id="hoaComplaintAlertControl" title="Click to enable/disable complaint sounds">
    <span id="hoaComplaintAlertStatusIcon">⏳</span>
    <span id="hoaComplaintAlertStatusText">Complaint Alerts: Connecting…</span>
</button>

<div id="hoaComplaintAlertToast" role="alert" aria-live="assertive">
    <div class="hoa-alert-body">
        <div id="hoaComplaintAlertIcon" class="hoa-alert-icon">🔔</div>
        <div style="min-width:0;flex:1">
            <div id="hoaComplaintAlertTitle" class="hoa-alert-title">New complaint received</div>
            <div id="hoaComplaintAlertMeta" class="hoa-alert-meta"></div>
        </div>
    </div>
    <div class="hoa-alert-actions">
        <button type="button" id="hoaComplaintAlertClose" class="hoa-alert-close">Dismiss</button>
        <a id="hoaComplaintAlertOpen" class="hoa-alert-open" href="admin_complaints.php">Open Complaint</a>
    </div>
</div>

<script>
(function(){
    if (window.HOA_GLOBAL_COMPLAINT_WATCHER_V2) return;
    window.HOA_GLOBAL_COMPLAINT_WATCHER_V2 = true;

    const adminId = <?= $globalComplaintAdminId ?>;
    const apiUrl = 'admin_complaints_realtime_api.php';
    const lastIdKey = 'hoa-global-complaint-last-id-' + adminId;
    const soundKey = 'hoa-global-complaint-sound-enabled';
    const control = document.getElementById('hoaComplaintAlertControl');
    const statusIcon = document.getElementById('hoaComplaintAlertStatusIcon');
    const statusText = document.getElementById('hoaComplaintAlertStatusText');
    const toast = document.getElementById('hoaComplaintAlertToast');
    const toastIcon = document.getElementById('hoaComplaintAlertIcon');
    const toastTitle = document.getElementById('hoaComplaintAlertTitle');
    const toastMeta = document.getElementById('hoaComplaintAlertMeta');
    const toastOpen = document.getElementById('hoaComplaintAlertOpen');
    const toastClose = document.getElementById('hoaComplaintAlertClose');

    let lastId = Number(localStorage.getItem(lastIdKey) || 0);
    let initialized = lastId > 0;
    let soundEnabled = localStorage.getItem(soundKey) === '1';
    let pollBusy = false;
    let audioCtx = null;
    let authorized = true;
    let toastTimer = null;
    let titleTimer = null;
    const originalTitle = document.title;

    function setState(state, extra){
        control.classList.remove('connected','offline','unauthorized');
        if (state === 'connected') {
            control.classList.add('connected');
            statusIcon.textContent = soundEnabled ? '🔔' : '🔕';
            statusText.textContent = soundEnabled ? 'Complaint Alerts: Connected + Sound On' : 'Complaint Alerts: Connected • Sound Off';
        } else if (state === 'unauthorized') {
            control.classList.add('unauthorized');
            statusIcon.textContent = '🔒';
            statusText.textContent = 'Complaint Alerts: No Access';
        } else if (state === 'offline') {
            control.classList.add('offline');
            statusIcon.textContent = '⚠️';
            statusText.textContent = 'Complaint Alerts: Offline' + (extra ? ' • ' + extra : '');
        } else {
            statusIcon.textContent = '⏳';
            statusText.textContent = 'Complaint Alerts: Connecting…';
        }
    }

    function getAudio(){
        if (!audioCtx) {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return null;
            audioCtx = new Ctx();
        }
        return audioCtx;
    }

    async function armAudio(){
        const ctx = getAudio();
        if (!ctx) return false;
        if (ctx.state === 'suspended') {
            try { await ctx.resume(); } catch(e) {}
        }
        return ctx.state === 'running';
    }

    function tone(freq, startDelay, duration, volume, type){
        return new Promise(resolve => {
            const ctx = getAudio();
            if (!ctx || ctx.state !== 'running') { resolve(); return; }
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = type || 'sine';
            osc.frequency.value = freq;
            const start = ctx.currentTime + startDelay;
            const end = start + duration;
            gain.gain.setValueAtTime(.0001,start);
            gain.gain.exponentialRampToValueAtTime(Math.max(.0002,volume),start+.02);
            gain.gain.exponentialRampToValueAtTime(.0001,end);
            osc.connect(gain); gain.connect(ctx.destination);
            osc.start(start); osc.stop(end+.03);
            setTimeout(resolve, Math.ceil((startDelay+duration+.05)*1000));
        });
    }

    async function playSound(priority, preview){
        if (!soundEnabled && !preview) return;
        if (!(await armAudio())) return;
        const p = String(priority || 'normal').toLowerCase();
        if (p === 'low') {
            await tone(520,0,.22,.05,'sine');
        } else if (p === 'normal') {
            await Promise.all([tone(660,0,.18,.07,'sine'),tone(880,.22,.22,.07,'sine')]);
        } else if (p === 'high') {
            await Promise.all([tone(820,0,.17,.12,'triangle'),tone(1040,.20,.17,.12,'triangle'),tone(820,.40,.22,.12,'triangle')]);
        } else {
            await Promise.all([
                tone(1120,0,.20,.16,'square'),tone(780,.23,.20,.16,'square'),
                tone(1120,.48,.20,.16,'square'),tone(780,.71,.20,.16,'square'),
                tone(1120,.96,.20,.16,'square'),tone(780,1.19,.28,.16,'square')
            ]);
        }
    }

    control.addEventListener('click', async function(){
        if (!authorized) return;
        soundEnabled = !soundEnabled;
        localStorage.setItem(soundKey, soundEnabled ? '1' : '0');
        if (soundEnabled) {
            const ok = await armAudio();
            if (ok) await playSound('normal', true);
            if ('Notification' in window && Notification.permission === 'default') {
                try { await Notification.requestPermission(); } catch(e) {}
            }
        }
        setState('connected');
    });

    ['pointerdown','keydown','touchstart'].forEach(evt => {
        document.addEventListener(evt, function once(){
            if (soundEnabled) armAudio();
            document.removeEventListener(evt, once, true);
        }, true);
    });

    function info(priority){
        const p = String(priority || 'normal').toLowerCase();
        return ({
            low:{icon:'🔔',title:'LOW priority complaint'},
            normal:{icon:'🔔',title:'New complaint received'},
            high:{icon:'⚠️',title:'HIGH priority complaint'},
            urgent:{icon:'🚨',title:'URGENT complaint received'}
        })[p] || {icon:'🔔',title:'New complaint received'};
    }

    function notifyComplaint(c){
        const p = String(c.priority || 'normal').toLowerCase();
        const d = info(p);
        toast.className = 'priority-' + p + ' show';
        toastIcon.textContent = d.icon;
        toastTitle.textContent = d.title;
        toastMeta.textContent = c.subject + ' — ' + c.homeowner_name + (c.house_lot_number ? ' • ' + c.house_lot_number : '');
        toastOpen.href = 'admin_complaints.php?filter=all&complaint_id=' + encodeURIComponent(c.id);
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), p === 'urgent' ? 15000 : 9000);

        clearTimeout(titleTimer);
        const labels={urgent:'🚨 URGENT COMPLAINT',high:'⚠️ HIGH PRIORITY COMPLAINT',normal:'🔔 New Complaint',low:'🔔 Low Priority Complaint'};
        document.title = labels[p] || labels.normal;
        titleTimer = setTimeout(() => document.title = originalTitle, p === 'urgent' ? 15000 : 8000);

        playSound(p,false).catch(()=>{});

        if ('Notification' in window && Notification.permission === 'granted') {
            try {
                const n = new Notification(d.title,{body:toastMeta.textContent,tag:'hoa-complaint-'+c.id,requireInteraction:p==='urgent'});
                n.onclick=function(){window.focus();window.location.href=toastOpen.href;};
            } catch(e) {}
        }
    }

    toastClose.addEventListener('click',()=>toast.classList.remove('show'));

    async function requestJson(url){
        const r = await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
        const text = await r.text();
        let data;
        try { data = JSON.parse(text); }
        catch(e) { throw new Error('API returned non-JSON'); }
        if (!r.ok || !data.success) throw new Error(data.message || ('HTTP '+r.status));
        return data;
    }

    async function bootstrap(){
        setState('connecting');
        try {
            const data = await requestJson(apiUrl+'?bootstrap=1&_='+Date.now());
            if (data.authorized === false) {
                authorized=false; setState('unauthorized'); return false;
            }
            authorized=true;
            if (!initialized) {
                lastId = Number(data.latest_id || 0);
                localStorage.setItem(lastIdKey,String(lastId));
                initialized=true;
            }
            setState('connected');
            return true;
        } catch(e) {
            setState('offline', e.message);
            return false;
        }
    }

    async function poll(){
        if (pollBusy || !authorized) return;
        if (!initialized) { await bootstrap(); return; }
        pollBusy=true;
        try {
            const data = await requestJson(apiUrl+'?after_id='+encodeURIComponent(lastId)+'&_='+Date.now());
            if (data.authorized === false) {
                authorized=false; setState('unauthorized'); return;
            }
            setState('connected');
            const items = Array.isArray(data.complaints) ? data.complaints : [];
            const serverLatest = Number(data.server_latest_id || 0);
            if (data.database_reset_detected && serverLatest < lastId) {
                lastId = Math.max(0, serverLatest - (items.length ? 0 : 0));
            }
            items.forEach((c,i)=>setTimeout(()=>notifyComplaint(c),i*650));
            const newLast = Number(data.latest_id || serverLatest || lastId);
            if (newLast !== lastId) {
                lastId = newLast;
                localStorage.setItem(lastIdKey,String(lastId));
            }
        } catch(e) {
            setState('offline', e.message);
        } finally {
            pollBusy=false;
        }
    }

    bootstrap().then(ok=>{ if(ok) poll(); });
    setInterval(poll,2000);
    window.addEventListener('focus',poll);
})();
</script>
