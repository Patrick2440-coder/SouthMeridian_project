<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    empty($_SESSION['admin_id']) ||
    strtolower((string)($_SESSION['admin_role'] ?? $_SESSION['role'] ?? '')) !== 'admin'
) {
    http_response_code(401);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Complaint Alert Monitor</title>
        <style>
            body{font-family:Arial,sans-serif;background:#f8fafc;color:#334155;padding:24px;text-align:center}
            .card{max-width:420px;margin:40px auto;background:#fff;padding:24px;border-radius:16px;box-shadow:0 15px 45px rgba(15,23,42,.12)}
        </style>
    </head>
    <body>
        <div class="card">
            <h2>Admin session required</h2>
            <p>Please sign in again before starting complaint alerts.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>South Meridian Complaint Alert Monitor</title>

    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;padding:18px;background:#f4f7f6;color:#1e293b;font-family:Arial,sans-serif}
        .monitor-card{width:100%;max-width:460px;margin:0 auto;background:#fff;border-radius:18px;padding:22px;box-shadow:0 18px 55px rgba(15,23,42,.15)}
        .monitor-icon{width:58px;height:58px;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;border-radius:50%;background:#ecfdf5;font-size:28px}
        h1{margin:0 0 7px;text-align:center;font-size:21px}
        .description{margin:0 0 18px;text-align:center;color:#64748b;font-size:12px;line-height:1.55}
        .status-box{padding:12px 14px;border-radius:11px;background:#f8fafc;border:1px solid #e2e8f0;font-size:12px;font-weight:800;text-align:center}
        .status-box.connected{background:#f0fdf4;border-color:#86efac;color:#166534}
        .status-box.warning{background:#fff7ed;border-color:#fdba74;color:#9a3412}
        .status-box.error{background:#fef2f2;border-color:#fecaca;color:#991b1b}
        .current-alert{display:none;margin-top:17px;padding:16px;border:2px solid #dc2626;border-radius:13px;background:#fef2f2}
        .current-alert.show{display:block}
        .current-alert.high{border-color:#d97706;background:#fffbeb}
        .current-alert.urgent{border-color:#dc2626;background:#fef2f2;animation:alarmPulse .8s infinite}
        @keyframes alarmPulse{50%{box-shadow:0 0 0 8px rgba(220,38,38,.12)}}
        .alert-level{margin-bottom:6px;font-size:12px;font-weight:900;text-transform:uppercase}
        .alert-subject{font-size:17px;font-weight:900;color:#0f172a}
        .alert-homeowner{margin-top:5px;color:#64748b;font-size:12px}
        .alert-actions{display:flex;gap:8px;margin-top:14px}
        .alert-actions button{flex:1;border:0;border-radius:9px;padding:10px;font-size:12px;font-weight:800;cursor:pointer}
        .dismiss-btn{background:#e2e8f0;color:#334155}
        .open-btn{background:#077f46;color:#fff}
        .queue-count{display:none;margin-top:10px;text-align:center;color:#92400e;font-size:11px;font-weight:700}
        .queue-count.show{display:block}
        .always-on{margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0;color:#64748b;font-size:11px;line-height:1.55;text-align:center}
    </style>
</head>
<body>

<div class="monitor-card">
    <div class="monitor-icon">🔔</div>

    <h1>Complaint Alert Monitor</h1>

    <p class="description">
        This monitor is started automatically by the admin system.
        Keep this small window open or minimized while using the system.
    </p>

    <div id="monitorStatus" class="status-box">
        ⏳ Starting complaint alarm...
    </div>

    <div id="currentAlert" class="current-alert">
        <div id="alertLevel" class="alert-level"></div>
        <div id="alertSubject" class="alert-subject"></div>
        <div id="alertHomeowner" class="alert-homeowner"></div>

        <div class="alert-actions">
            <button type="button" id="dismissAlarm" class="dismiss-btn">
                Dismiss
            </button>

            <button type="button" id="openComplaint" class="open-btn">
                Open Complaint
            </button>
        </div>
    </div>

    <div id="queueCount" class="queue-count"></div>

    <div class="always-on">
        <strong>Always-on mode:</strong>
        Low and Normal complaints play once. High and Urgent complaints continue
        ringing until Dismiss or Open Complaint is selected.
    </div>
</div>

<script>
(function () {
    'use strict';

    const API_URL = 'admin_complaints_realtime_api.php';
    const POLL_INTERVAL = 2000;
    const LAST_ID_PREFIX = 'smh-monitor-complaint-last-id-';
    const HEARTBEAT_KEY = 'smh-complaint-monitor-heartbeat';

    const channel = ('BroadcastChannel' in window)
        ? new BroadcastChannel('south-meridian-complaint-alerts')
        : null;

    let phase = '';
    let lastComplaintId = 0;
    let lastStorageKey = '';
    let initialized = false;
    let pollBusy = false;
    let alarmEnabled = false;
    let activeComplaint = null;
    let alarmQueue = [];

    const seenComplaints = new Set();

    let highAlarm = null;
    let urgentAlarm = null;
    let normalSound = null;
    let lowSound = null;

    const monitorStatus = document.getElementById('monitorStatus');
    const currentAlert = document.getElementById('currentAlert');
    const alertLevel = document.getElementById('alertLevel');
    const alertSubject = document.getElementById('alertSubject');
    const alertHomeowner = document.getElementById('alertHomeowner');
    const dismissAlarm = document.getElementById('dismissAlarm');
    const openComplaint = document.getElementById('openComplaint');
    const queueCount = document.getElementById('queueCount');

    function setStatus(type, message) {
        monitorStatus.classList.remove('connected', 'warning', 'error');

        if (type) {
            monitorStatus.classList.add(type);
        }

        monitorStatus.textContent = message;
    }

    /*
    |--------------------------------------------------------------------------
    | HEARTBEAT
    |--------------------------------------------------------------------------
    |
    | Normal admin pages use this to know that the monitor is already alive, so
    | they do not keep opening duplicate monitor windows during navigation.
    |
    */

    function writeHeartbeat() {
        localStorage.setItem(HEARTBEAT_KEY, String(Date.now()));
    }

    writeHeartbeat();
    window.setInterval(writeHeartbeat, 1500);

    window.addEventListener('beforeunload', function () {
        try {
            localStorage.removeItem(HEARTBEAT_KEY);
        } catch (error) {}
    });

    /*
    |--------------------------------------------------------------------------
    | GENERATE WAV AUDIO
    |--------------------------------------------------------------------------
    */

    function createWaveUrl(pattern, totalDuration) {
        const sampleRate = 44100;
        const sampleCount = Math.floor(sampleRate * totalDuration);
        const samples = new Int16Array(sampleCount);
        let cursor = 0;

        pattern.forEach(function (part) {
            const length = Math.floor(sampleRate * part.duration);

            for (
                let i = 0;
                i < length && cursor < sampleCount;
                i++, cursor++
            ) {
                if (!part.frequency) {
                    samples[cursor] = 0;
                    continue;
                }

                const time = i / sampleRate;
                const envelope =
                    Math.min(1, i / 300) *
                    Math.min(1, (length - i) / 300);

                const sample =
                    Math.sin(2 * Math.PI * part.frequency * time) *
                    envelope *
                    (part.volume || 0.35);

                samples[cursor] = Math.max(
                    -32767,
                    Math.min(32767, Math.floor(sample * 32767))
                );
            }
        });

        while (cursor < sampleCount) {
            samples[cursor++] = 0;
        }

        const buffer = new ArrayBuffer(44 + samples.length * 2);
        const view = new DataView(buffer);

        function writeString(offset, text) {
            for (let i = 0; i < text.length; i++) {
                view.setUint8(offset + i, text.charCodeAt(i));
            }
        }

        writeString(0, 'RIFF');
        view.setUint32(4, 36 + samples.length * 2, true);
        writeString(8, 'WAVE');
        writeString(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true);
        view.setUint16(22, 1, true);
        view.setUint32(24, sampleRate, true);
        view.setUint32(28, sampleRate * 2, true);
        view.setUint16(32, 2, true);
        view.setUint16(34, 16, true);
        writeString(36, 'data');
        view.setUint32(40, samples.length * 2, true);

        let offset = 44;

        for (let i = 0; i < samples.length; i++) {
            view.setInt16(offset, samples[i], true);
            offset += 2;
        }

        return URL.createObjectURL(
            new Blob([buffer], { type: 'audio/wav' })
        );
    }

    function buildSounds() {
        lowSound = new Audio(
            createWaveUrl([
                { frequency: 520, duration: 0.25, volume: 0.20 }
            ], 0.30)
        );

        normalSound = new Audio(
            createWaveUrl([
                { frequency: 660, duration: 0.18, volume: 0.28 },
                { frequency: 0, duration: 0.06 },
                { frequency: 880, duration: 0.22, volume: 0.28 }
            ], 0.50)
        );

        highAlarm = new Audio(
            createWaveUrl([
                { frequency: 820, duration: 0.25, volume: 0.40 },
                { frequency: 0, duration: 0.08 },
                { frequency: 1040, duration: 0.25, volume: 0.40 },
                { frequency: 0, duration: 0.08 },
                { frequency: 820, duration: 0.35, volume: 0.40 },
                { frequency: 0, duration: 0.45 }
            ], 1.50)
        );

        highAlarm.loop = true;

        urgentAlarm = new Audio(
            createWaveUrl([
                { frequency: 1120, duration: 0.22, volume: 0.48 },
                { frequency: 0, duration: 0.05 },
                { frequency: 780, duration: 0.22, volume: 0.48 },
                { frequency: 0, duration: 0.05 },
                { frequency: 1120, duration: 0.22, volume: 0.48 },
                { frequency: 0, duration: 0.05 },
                { frequency: 780, duration: 0.22, volume: 0.48 },
                { frequency: 0, duration: 0.18 }
            ], 1.25)
        );

        urgentAlarm.loop = true;

        [lowSound, normalSound, highAlarm, urgentAlarm].forEach(function (audio) {
            audio.preload = 'auto';
        });
    }

    async function primeAudio(audio) {
        if (!audio) {
            return false;
        }

        const oldVolume = audio.volume;
        audio.volume = 0;

        try {
            await audio.play();
            audio.pause();
            audio.currentTime = 0;
            audio.volume = oldVolume;
            return true;
        } catch (error) {
            audio.volume = oldVolume;
            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ALWAYS-ON ARMING
    |--------------------------------------------------------------------------
    |
    | The monitor is normally opened directly from a normal admin interaction,
    | so this automatic call usually succeeds. If a browser still blocks it,
    | ANY click/touch/key press inside this window arms it; there is no special
    | enable button.
    |
    */

    async function armAlarm() {
        if (!highAlarm) {
            buildSounds();
        }

        const results = await Promise.all([
            primeAudio(lowSound),
            primeAudio(normalSound),
            primeAudio(highAlarm),
            primeAudio(urgentAlarm)
        ]);

        alarmEnabled = results.some(Boolean);

        if (alarmEnabled) {
            setStatus(
                'connected',
                '🔔 Complaint Alarm: Always On'
            );
        } else {
            setStatus(
                'warning',
                '🔊 Click anywhere once to allow browser alarm sound'
            );
        }

        return alarmEnabled;
    }

    ['pointerdown', 'touchstart', 'keydown'].forEach(function (eventName) {
        document.addEventListener(
            eventName,
            function () {
                if (!alarmEnabled) {
                    armAlarm().catch(function () {});
                }
            },
            true
        );
    });

    /*
    |--------------------------------------------------------------------------
    | AUDIO PLAYBACK
    |--------------------------------------------------------------------------
    */

    function stopCurrentAudio() {
        [highAlarm, urgentAlarm].forEach(function (audio) {
            if (!audio) {
                return;
            }

            try {
                audio.pause();
                audio.currentTime = 0;
            } catch (error) {}
        });
    }

    function playOneTimeSound(priority) {
        if (!alarmEnabled) {
            return;
        }

        let audio = null;

        if (priority === 'low') {
            audio = lowSound;
        }

        if (priority === 'normal') {
            audio = normalSound;
        }

        if (!audio) {
            return;
        }

        try {
            audio.currentTime = 0;
            audio.play().catch(function () {
                alarmEnabled = false;
                setStatus(
                    'warning',
                    '🔊 Browser paused sound. Click anywhere once to restore it.'
                );
            });
        } catch (error) {}
    }

    function startPersistentAlarm(complaint) {
        const priority = String(
            complaint.priority || 'normal'
        ).toLowerCase();

        if (!alarmEnabled) {
            armAlarm().then(function (ready) {
                if (ready) {
                    startPersistentAlarm(complaint);
                }
            });
            return;
        }

        stopCurrentAudio();

        const audio = priority === 'urgent'
            ? urgentAlarm
            : highAlarm;

        if (!audio) {
            return;
        }

        try {
            audio.currentTime = 0;
            audio.loop = true;

            audio.play().catch(function () {
                alarmEnabled = false;
                setStatus(
                    'warning',
                    '🔊 Browser blocked alarm. Click anywhere once to restore it.'
                );
            });
        } catch (error) {}
    }

    /*
    |--------------------------------------------------------------------------
    | ALERT UI / QUEUE
    |--------------------------------------------------------------------------
    */

    function updateQueueCount() {
        if (alarmQueue.length > 0) {
            queueCount.classList.add('show');
            queueCount.textContent =
                alarmQueue.length +
                (alarmQueue.length === 1
                    ? ' additional high/urgent complaint waiting'
                    : ' additional high/urgent complaints waiting');
        } else {
            queueCount.classList.remove('show');
            queueCount.textContent = '';
        }
    }

    function renderActiveAlert() {
        if (!activeComplaint) {
            currentAlert.classList.remove('show', 'high', 'urgent');
            updateQueueCount();
            return;
        }

        const priority = String(
            activeComplaint.priority || 'normal'
        ).toLowerCase();

        currentAlert.classList.remove('high', 'urgent');
        currentAlert.classList.add('show', priority);

        alertLevel.textContent =
            priority === 'urgent'
                ? '🚨 URGENT COMPLAINT'
                : '⚠️ HIGH PRIORITY COMPLAINT';

        alertSubject.textContent = activeComplaint.subject || 'Complaint';

        alertHomeowner.textContent =
            (activeComplaint.homeowner_name || 'Homeowner') +
            (activeComplaint.house_lot_number
                ? ' • ' + activeComplaint.house_lot_number
                : '');

        updateQueueCount();
    }

    function startNextAlarm() {
        stopCurrentAudio();
        activeComplaint = null;

        if (alarmQueue.length > 0) {
            activeComplaint = alarmQueue.shift();
            renderActiveAlert();
            startPersistentAlarm(activeComplaint);
        } else {
            renderActiveAlert();
        }
    }

    function stopComplaintAlarm(complaintId) {
        complaintId = Number(complaintId || 0);

        alarmQueue = alarmQueue.filter(function (complaint) {
            return Number(complaint.id) !== complaintId;
        });

        if (
            activeComplaint &&
            Number(activeComplaint.id) === complaintId
        ) {
            startNextAlarm();
        } else {
            updateQueueCount();
        }
    }

    function processComplaint(complaint, broadcast) {
        if (!complaint || !complaint.id) {
            return;
        }

        const id = Number(complaint.id);

        if (seenComplaints.has(id)) {
            return;
        }

        seenComplaints.add(id);

        const priority = String(
            complaint.priority || 'normal'
        ).toLowerCase();

        if (broadcast && channel) {
            channel.postMessage({
                type: 'complaint',
                complaint: complaint,
                source: 'monitor'
            });
        }

        if (priority === 'low' || priority === 'normal') {
            playOneTimeSound(priority);
            return;
        }

        if (priority === 'high' || priority === 'urgent') {
            if (activeComplaint) {
                alarmQueue.push(complaint);
                updateQueueCount();
                return;
            }

            activeComplaint = complaint;
            renderActiveAlert();
            startPersistentAlarm(complaint);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DISMISS / OPEN
    |--------------------------------------------------------------------------
    */

    dismissAlarm.addEventListener('click', function () {
        if (!activeComplaint) {
            return;
        }

        const complaintId = activeComplaint.id;

        if (channel) {
            channel.postMessage({
                type: 'dismiss',
                complaintId: complaintId,
                source: 'monitor'
            });
        }

        stopComplaintAlarm(complaintId);
    });

    openComplaint.addEventListener('click', function () {
        if (!activeComplaint) {
            return;
        }

        const complaintId = activeComplaint.id;
        const url =
            'admin_complaints.php?filter=all&complaint_id=' +
            encodeURIComponent(complaintId);

        if (channel) {
            channel.postMessage({
                type: 'open',
                complaintId: complaintId,
                source: 'monitor'
            });
        }

        stopComplaintAlarm(complaintId);

        try {
            if (window.opener && !window.opener.closed) {
                window.opener.location.href = url;
                window.opener.focus();
            } else {
                window.open(url, '_blank');
            }
        } catch (error) {
            window.open(url, '_blank');
        }
    });

    /*
    |--------------------------------------------------------------------------
    | RECEIVE ADMIN PAGE EVENTS
    |--------------------------------------------------------------------------
    */

    if (channel) {
        channel.onmessage = function (event) {
            const message = event.data || {};

            if (
                message.type === 'complaint' &&
                message.complaint
            ) {
                processComplaint(message.complaint, false);
            }

            if (
                message.type === 'dismiss' ||
                message.type === 'open'
            ) {
                stopComplaintAlarm(message.complaintId);
            }
        };
    }

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    */

    async function requestJSON(url) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const raw = await response.text();
        let data;

        try {
            data = JSON.parse(raw);
        } catch (error) {
            throw new Error('Complaint API returned invalid JSON.');
        }

        if (!response.ok || !data.success) {
            throw new Error(
                data.message || ('HTTP ' + response.status)
            );
        }

        return data;
    }

    async function initialize() {
        try {
            const data = await requestJSON(
                API_URL + '?bootstrap=1&_=' + Date.now()
            );

            if (data.authorized === false) {
                setStatus(
                    'error',
                    '🔒 This admin does not have complaint access'
                );
                return false;
            }

            phase = String(data.phase || 'unknown');
            lastStorageKey = LAST_ID_PREFIX + phase;

            const serverLatest = Number(data.latest_id || 0);
            const stored = Number(
                localStorage.getItem(lastStorageKey) || 0
            );

            if (!stored || stored > serverLatest) {
                lastComplaintId = serverLatest;
                localStorage.setItem(
                    lastStorageKey,
                    String(lastComplaintId)
                );
            } else {
                lastComplaintId = stored;
            }

            initialized = true;

            if (!alarmEnabled) {
                setStatus(
                    'warning',
                    '🔔 Connected • Preparing always-on alarm...'
                );
            }

            return true;
        } catch (error) {
            setStatus('error', '⚠️ ' + error.message);
            return false;
        }
    }

    async function poll() {
        if (!initialized || pollBusy) {
            return;
        }

        pollBusy = true;

        try {
            const data = await requestJSON(
                API_URL +
                '?after_id=' +
                encodeURIComponent(lastComplaintId) +
                '&_=' +
                Date.now()
            );

            const complaints = Array.isArray(data.complaints)
                ? data.complaints
                : [];

            complaints.forEach(function (complaint) {
                processComplaint(complaint, true);
            });

            const newest = Number(data.latest_id || lastComplaintId);

            if (newest > lastComplaintId) {
                lastComplaintId = newest;
                localStorage.setItem(
                    lastStorageKey,
                    String(lastComplaintId)
                );
            }
        } catch (error) {
            setStatus('error', '⚠️ ' + error.message);
        } finally {
            pollBusy = false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | START
    |--------------------------------------------------------------------------
    */

    buildSounds();

    // Because this monitor is normally created directly from the admin's first
    // normal interaction, attempt to arm immediately without showing a button.
    armAlarm().catch(function () {});

    initialize().then(function (connected) {
        if (connected) {
            poll();
        }
    });

    window.setInterval(poll, POLL_INTERVAL);

    window.addEventListener('focus', function () {
        writeHeartbeat();

        if (!alarmEnabled) {
            armAlarm().catch(function () {});
        }

        poll();
    });
})();
</script>

</body>
</html>
