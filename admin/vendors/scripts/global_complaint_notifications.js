/*
|--------------------------------------------------------------------------
| SOUTH MERIDIAN HOMES
| GLOBAL COMPLAINT NOTIFICATIONS + AUTOMATIC ALERT MONITOR LAUNCHER
|--------------------------------------------------------------------------
|
| Purpose:
| - Runs from the shared admin sidebar on every normal admin page.
| - Automatically starts/reuses the persistent complaint alert monitor.
| - No separate "Open Complaint Alert Monitor" button is required.
| - Shows realtime complaint popups on normal admin pages.
| - Sends complaint events to complaint_alert_monitor.php using BroadcastChannel.
| - The monitor is the ONLY source of persistent complaint alarm sound.
|
| Browser rule:
| A browser may block a new popup/audio until a user gesture occurs.
| Therefore the monitor is started from the admin's FIRST NORMAL interaction
| (click/touch/key press) on any admin page. No special alert button is needed.
|
*/

(function () {
    'use strict';

    if (window.SMH_GLOBAL_COMPLAINT_NOTIFICATION_LOADED) {
        return;
    }

    window.SMH_GLOBAL_COMPLAINT_NOTIFICATION_LOADED = true;

    var currentScript = document.currentScript;

    var API_URL =
        currentScript &&
        currentScript.dataset &&
        currentScript.dataset.complaintApi
            ? currentScript.dataset.complaintApi
            : 'admin_complaints_realtime_api.php';

    var MONITOR_URL =
        currentScript &&
        currentScript.dataset &&
        currentScript.dataset.complaintMonitor
            ? currentScript.dataset.complaintMonitor
            : 'complaint_alert_monitor.php?auto=1';

    var MONITOR_WINDOW_NAME = 'SouthMeridianComplaintMonitor';
    var MONITOR_HEARTBEAT_KEY = 'smh-complaint-monitor-heartbeat';
    var MONITOR_ACTIVE_MS = 6500;
    var POLL_INTERVAL = 2000;
    var LAST_ID_PREFIX = 'smh-global-popup-last-id-';

    var currentPage = String(
        window.location.pathname.split('/').pop() || ''
    ).toLowerCase();

    var complaintChannel =
        'BroadcastChannel' in window
            ? new BroadcastChannel('south-meridian-complaint-alerts')
            : null;

    var phase = '';
    var lastComplaintId = 0;
    var lastStorageKey = '';
    var initialized = false;
    var polling = false;
    var toastTimer = null;
    var currentPopupComplaint = null;
    var displayedComplaints = new Set();
    var monitorLaunchAttempted = false;

    /*
    |--------------------------------------------------------------------------
    | MONITOR HEARTBEAT
    |--------------------------------------------------------------------------
    */

    function monitorIsAlive() {
        var heartbeat = Number(
            localStorage.getItem(MONITOR_HEARTBEAT_KEY) || 0
        );

        return heartbeat > 0 &&
            (Date.now() - heartbeat) < MONITOR_ACTIVE_MS;
    }

    /*
    |--------------------------------------------------------------------------
    | AUTOMATIC MONITOR START
    |--------------------------------------------------------------------------
    |
    | This function MUST be called directly from a user interaction to satisfy
    | browser popup/audio policies. It opens one named popup only. If the monitor
    | is already alive, nothing is opened.
    |
    */

    function startComplaintMonitorFromGesture() {
        if (monitorIsAlive()) {
            updateStatus('connected', '🔔 Complaint Alerts: Monitor Active');
            return true;
        }

        var monitorWindow = null;

        try {
            monitorWindow = window.open(
                MONITOR_URL,
                MONITOR_WINDOW_NAME,
                'width=470,height=620,resizable=yes,scrollbars=yes'
            );
        } catch (error) {
            monitorWindow = null;
        }

        monitorLaunchAttempted = true;

        if (!monitorWindow) {
            updateStatus(
                'warning',
                '⚠️ Complaint Alerts: Allow popups once to enable alarm'
            );
            return false;
        }

        try {
            // Keep the admin page as the active working window when possible.
            window.setTimeout(function () {
                try {
                    window.focus();
                } catch (error) {}
            }, 150);
        } catch (error) {}

        updateStatus('connected', '🔔 Complaint Alerts: Starting Monitor…');
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | FIRST NORMAL ADMIN INTERACTION
    |--------------------------------------------------------------------------
    |
    | No special button is required. The first click/touch/key press starts the
    | persistent monitor automatically. These listeners remain installed so that
    | if the admin manually closes the monitor, a later interaction can reopen it.
    |
    */

    function installAutomaticMonitorLauncher() {
        var events = ['pointerdown', 'touchstart', 'keydown'];

        events.forEach(function (eventName) {
            document.addEventListener(
                eventName,
                function () {
                    if (!monitorIsAlive()) {
                        startComplaintMonitorFromGesture();
                    }

                    if (
                        'Notification' in window &&
                        Notification.permission === 'default'
                    ) {
                        try {
                            Notification.requestPermission().catch(function () {});
                        } catch (error) {}
                    }
                },
                true
            );
        });

        // If monitor is already alive from another admin page, reflect it now.
        window.setInterval(function () {
            if (monitorIsAlive()) {
                updateStatus('connected', '🔔 Complaint Alerts: Monitor Active');
            } else if (monitorLaunchAttempted) {
                updateStatus(
                    'warning',
                    '🔔 Complaint Alerts: Click anywhere to restart monitor'
                );
            }
        }, 3000);
    }

    /*
    |--------------------------------------------------------------------------
    | GLOBAL UI
    |--------------------------------------------------------------------------
    */

    function createUI() {
        if (document.getElementById('globalComplaintToast')) {
            return;
        }

        var style = document.createElement('style');

        style.textContent = `
#globalComplaintStatus{position:fixed;right:16px;bottom:16px;z-index:2147483000;display:flex;align-items:center;gap:7px;border:1px solid #cbd5e1;border-radius:999px;padding:9px 12px;background:#fff;color:#475569;font:700 12px/1.25 Arial,sans-serif;box-shadow:0 10px 30px rgba(15,23,42,.18);pointer-events:none}
#globalComplaintStatus.connected{background:#f0fdf4;border-color:#86efac;color:#166534}
#globalComplaintStatus.warning{background:#fff7ed;border-color:#fdba74;color:#9a3412}
#globalComplaintStatus.error{background:#fef2f2;border-color:#fca5a5;color:#991b1b}
#globalComplaintStatus.no-access{background:#f8fafc;border-color:#cbd5e1;color:#64748b}
#globalComplaintToast{position:fixed;top:84px;right:20px;width:min(420px,calc(100vw - 40px));z-index:2147483001;background:#fff;border:1px solid #e2e8f0;border-left:6px solid #2563eb;border-radius:15px;padding:17px;box-shadow:0 25px 65px rgba(15,23,42,.30);opacity:0;visibility:hidden;transform:translateY(-15px);transition:.25s ease}
#globalComplaintToast.show{opacity:1;visibility:visible;transform:translateY(0)}
#globalComplaintToast.low{border-left-color:#64748b}
#globalComplaintToast.normal{border-left-color:#2563eb}
#globalComplaintToast.high{border-left-color:#d97706}
#globalComplaintToast.urgent{border-left-color:#dc2626;animation:globalComplaintPulse .9s ease-in-out infinite}
@keyframes globalComplaintPulse{50%{box-shadow:0 25px 70px rgba(220,38,38,.45)}}
.global-complaint-title{font:800 16px/1.3 Arial,sans-serif;color:#0f172a;margin-bottom:6px}
.global-complaint-info{font:500 13px/1.5 Arial,sans-serif;color:#64748b}
.global-complaint-priority{display:inline-block;margin-top:9px;padding:4px 8px;border-radius:999px;background:#f1f5f9;color:#475569;font:800 11px Arial,sans-serif;text-transform:uppercase}
.global-complaint-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}
.global-complaint-actions button,.global-complaint-actions a{border:0;border-radius:8px;padding:8px 12px;font:700 12px Arial,sans-serif;text-decoration:none;cursor:pointer}
#globalComplaintDismiss{background:#f1f5f9;color:#475569}
#globalComplaintOpen{background:#077f46;color:#fff!important}
html.dark #globalComplaintStatus{background:#172033;border-color:#334155;color:#cbd5e1}
html.dark #globalComplaintStatus.connected{background:rgba(22,101,52,.25);color:#86efac}
html.dark #globalComplaintStatus.warning{background:rgba(154,52,18,.20);color:#fdba74}
html.dark #globalComplaintToast{background:#172033;border-color:#334155}
html.dark .global-complaint-title{color:#f8fafc}
html.dark .global-complaint-info{color:#94a3b8}
html.dark .global-complaint-priority{background:#334155;color:#cbd5e1}
html.dark #globalComplaintDismiss{background:#334155;color:#e2e8f0}
@media(max-width:575px){#globalComplaintStatus{right:10px;bottom:10px}#globalComplaintToast{right:10px;top:75px;width:calc(100vw - 20px)}}
        `;

        document.head.appendChild(style);

        var status = document.createElement('div');
        status.id = 'globalComplaintStatus';
        status.textContent = '⏳ Complaint Alerts: Connecting…';
        document.body.appendChild(status);

        var toast = document.createElement('div');
        toast.id = 'globalComplaintToast';
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.innerHTML = `
            <div id="globalComplaintTitle" class="global-complaint-title">New Complaint</div>
            <div id="globalComplaintInfo" class="global-complaint-info"></div>
            <div id="globalComplaintPriority" class="global-complaint-priority">Normal</div>
            <div class="global-complaint-actions">
                <button type="button" id="globalComplaintDismiss">Dismiss</button>
                <a href="admin_complaints.php" id="globalComplaintOpen">Open Complaint</a>
            </div>
        `;

        document.body.appendChild(toast);

        document
            .getElementById('globalComplaintDismiss')
            .addEventListener('click', dismissCurrentPopup);

        document
            .getElementById('globalComplaintOpen')
            .addEventListener('click', openCurrentComplaint);
    }

    function updateStatus(type, message) {
        var element = document.getElementById('globalComplaintStatus');

        if (!element) {
            return;
        }

        element.classList.remove(
            'connected',
            'warning',
            'error',
            'no-access'
        );

        if (type) {
            element.classList.add(type);
        }

        element.textContent = message;
    }

    /*
    |--------------------------------------------------------------------------
    | MONITOR BRIDGE
    |--------------------------------------------------------------------------
    */

    function sendToMonitor(type, payload) {
        if (!complaintChannel) {
            return;
        }

        complaintChannel.postMessage(
            Object.assign(
                {
                    type: type,
                    source: 'admin-page'
                },
                payload || {}
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | POPUP
    |--------------------------------------------------------------------------
    */

    function showComplaint(complaint) {
        if (!complaint || !complaint.id) {
            return;
        }

        var complaintId = Number(complaint.id);

        if (displayedComplaints.has(complaintId)) {
            return;
        }

        displayedComplaints.add(complaintId);
        currentPopupComplaint = complaint;

        var priority = String(complaint.priority || 'normal').toLowerCase();

        if (['low', 'normal', 'high', 'urgent'].indexOf(priority) === -1) {
            priority = 'normal';
        }

        var labels = {
            low: '🔔 LOW Priority Complaint',
            normal: '🔔 New Complaint Received',
            high: '⚠️ HIGH Priority Complaint',
            urgent: '🚨 URGENT COMPLAINT RECEIVED'
        };

        var toast = document.getElementById('globalComplaintToast');
        var title = document.getElementById('globalComplaintTitle');
        var info = document.getElementById('globalComplaintInfo');
        var priorityBadge = document.getElementById('globalComplaintPriority');
        var open = document.getElementById('globalComplaintOpen');

        if (!toast || !title || !info || !priorityBadge || !open) {
            return;
        }

        title.textContent = labels[priority];
        info.textContent =
            (complaint.subject || 'Complaint') +
            ' — ' +
            (complaint.homeowner_name || 'Homeowner') +
            (complaint.house_lot_number
                ? ' • ' + complaint.house_lot_number
                : '');

        priorityBadge.textContent = priority;
        open.href =
            'admin_complaints.php?filter=all&complaint_id=' +
            encodeURIComponent(complaint.id);

        toast.className = priority + ' show';

        clearTimeout(toastTimer);

        // High/Urgent stay visible until the admin dismisses or opens them.
        if (priority === 'low' || priority === 'normal') {
            toastTimer = window.setTimeout(function () {
                toast.classList.remove('show');
            }, 9000);
        }

        sendToMonitor('complaint', {
            complaint: complaint
        });

        if (
            'Notification' in window &&
            Notification.permission === 'granted'
        ) {
            try {
                var notification = new Notification(labels[priority], {
                    body: info.textContent,
                    tag: 'south-meridian-complaint-' + complaint.id,
                    requireInteraction:
                        priority === 'high' || priority === 'urgent'
                });

                notification.onclick = function () {
                    sendToMonitor('open', {
                        complaintId: complaint.id
                    });

                    window.focus();
                    window.location.href = open.href;
                };
            } catch (error) {}
        }
    }

    function dismissCurrentPopup() {
        if (currentPopupComplaint) {
            sendToMonitor('dismiss', {
                complaintId: currentPopupComplaint.id
            });
        }

        var toast = document.getElementById('globalComplaintToast');

        if (toast) {
            toast.classList.remove('show');
        }

        currentPopupComplaint = null;
    }

    function openCurrentComplaint() {
        if (currentPopupComplaint) {
            sendToMonitor('open', {
                complaintId: currentPopupComplaint.id
            });
        }
    }

    if (complaintChannel) {
        complaintChannel.onmessage = function (event) {
            var message = event.data || {};

            if (
                (message.type === 'dismiss' || message.type === 'open') &&
                currentPopupComplaint &&
                Number(currentPopupComplaint.id) === Number(message.complaintId)
            ) {
                var toast = document.getElementById('globalComplaintToast');

                if (toast) {
                    toast.classList.remove('show');
                }

                currentPopupComplaint = null;
            }
        };
    }

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    */

    async function requestJSON(url) {
        var response = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        var raw = await response.text();
        var data;

        try {
            data = JSON.parse(raw);
        } catch (error) {
            throw new Error(
                'Complaint API returned invalid JSON. HTTP ' + response.status
            );
        }

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Complaint API failed.');
        }

        return data;
    }

    async function initialize() {
        try {
            var data = await requestJSON(
                API_URL + '?bootstrap=1&_=' + Date.now()
            );

            if (data.authorized === false) {
                updateStatus('no-access', '🔒 Complaint Alerts: No Access');
                return false;
            }

            phase = String(data.phase || 'unknown');
            lastStorageKey = LAST_ID_PREFIX + phase;

            var serverLatest = Number(data.latest_id || 0);
            var stored = Number(localStorage.getItem(lastStorageKey) || 0);

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

            updateStatus(
                monitorIsAlive() ? 'connected' : 'warning',
                monitorIsAlive()
                    ? '🔔 Complaint Alerts: Monitor Active'
                    : '🔔 Complaint Alerts: Click anywhere once to start alarm'
            );

            return true;
        } catch (error) {
            updateStatus(
                'error',
                '⚠️ Complaint Alerts: ' + error.message
            );
            return false;
        }
    }

    async function poll() {
        if (!initialized || polling) {
            return;
        }

        polling = true;

        try {
            var data = await requestJSON(
                API_URL +
                    '?after_id=' +
                    encodeURIComponent(lastComplaintId) +
                    '&_=' +
                    Date.now()
            );

            if (data.authorized === false) {
                updateStatus('no-access', '🔒 Complaint Alerts: No Access');
                initialized = false;
                return;
            }

            var complaints = Array.isArray(data.complaints)
                ? data.complaints
                : [];

            complaints.forEach(function (complaint, index) {
                window.setTimeout(function () {
                    showComplaint(complaint);
                }, index * 500);
            });

            var newest = Number(data.latest_id || lastComplaintId);

            if (newest > lastComplaintId) {
                lastComplaintId = newest;
                localStorage.setItem(
                    lastStorageKey,
                    String(lastComplaintId)
                );
            }

            if (monitorIsAlive()) {
                updateStatus('connected', '🔔 Complaint Alerts: Monitor Active');
            }
        } catch (error) {
            updateStatus(
                'error',
                '⚠️ Complaint Alerts: ' + error.message
            );
        } finally {
            polling = false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | START
    |--------------------------------------------------------------------------
    */

    async function start() {
        // The launcher must exist on EVERY admin page, including
        // admin_complaints.php. That page has its own detailed realtime watcher,
        // so only polling/UI are skipped there.
        installAutomaticMonitorLauncher();

        if (currentPage === 'complaint_alert_monitor.php') {
            return;
        }

        if (currentPage === 'admin_complaints.php') {
            return;
        }

        createUI();

        var connected = await initialize();

        if (connected) {
            await poll();
        }

        window.setInterval(poll, POLL_INTERVAL);
        window.addEventListener('focus', poll);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
