(() => {
    'use strict';

    if (window.HOAGlobalRealtimeNotifications) {
        return;
    }

    const STORAGE_PREFIX = 'south-meridian-global-notifications';
    const SOUND_KEY = `${STORAGE_PREFIX}:sound-enabled`;
    const LEADER_KEY = `${STORAGE_PREFIX}:leader`;
    const LAST_EVENT_KEY = `${STORAGE_PREFIX}:last-event-id`;
    const CHANNEL_NAME = 'south-meridian-global-realtime-notifications';

    const tabId = (
        window.crypto?.randomUUID?.()
        || `${Date.now()}-${Math.random().toString(16).slice(2)}`
    );

    const moduleLabels = {
        homeowner_push: 'Homeowner Management',
        login_security: 'Login Security',
        staff_pending: 'Staff Management',
        announcements: 'Announcements',
        complaints: 'Complaints',
        finance_dues: 'Monthly Dues',
        finance_reports: 'Financial Reports',
        parking: 'Parking',
        facility_rentals: 'Facility Rentals',
        community_chat: 'Community Chat'
    };

    const moduleUrls = {
        homeowner_push: 'ho_approval.php',
        login_security: 'login_security.php',
        staff_pending: 'staff_management.php',
        announcements: 'announcements.php',
        complaints: 'admin_complaints.php',
        finance_dues: 'finance_dues.php',
        finance_reports: 'finance_reports.php',
        parking: 'parking_permits.php',
        facility_rentals: 'admin_facility_rentals.php',
        community_chat: 'admin_public_chat.php'
    };

    const eventLabels = {
        announcement_comment: 'New announcement comment',
        complaint_created: 'New complaint received',
        complaint_message: 'New complaint message',
        facility_rental_request: 'New facility rental request',
        finance_report_requested: 'New financial report request',
        import_waiting_for_push: 'Imported homeowner needs review',
        registered_household_waiting_for_review: 'New household needs review',
        homeowner_documents_updated: 'Homeowner documents updated',
        monthly_due_paid: 'Monthly dues payment received',
        private_chat_message: 'New private chat message',
        public_chat_message: 'New public chat message',
        security_appeal_created: 'New login security appeal',
        account_hard_locked: 'Account temporarily locked',
        parking_permit_request: 'New parking permit request',
        staff_application_created: 'New staff application'
    };

    let soundEnabled = localStorage.getItem(SOUND_KEY) !== '0';
    let audioContext = null;
    let initializedSnapshot = false;
    let previousCounts = {};
    let lastActivityAt = 0;
    let leaderTimer = null;
    let control = null;
    let statusText = null;
    let channel = null;
    let toastTimer = null;

    try {
        channel = 'BroadcastChannel' in window
            ? new BroadcastChannel(CHANNEL_NAME)
            : null;
    } catch (error) {
        channel = null;
    }

    function now() {
        return Date.now();
    }

    function readLeader() {
        try {
            return JSON.parse(
                localStorage.getItem(LEADER_KEY) || 'null'
            );
        } catch (error) {
            return null;
        }
    }

    function writeLeader() {
        localStorage.setItem(
            LEADER_KEY,
            JSON.stringify({
                tabId,
                heartbeat: now()
            })
        );
    }

    function isLeader() {
        const leader = readLeader();

        if (
            !leader
            || !leader.tabId
            || (now() - Number(leader.heartbeat || 0)) > 7000
        ) {
            writeLeader();
            return true;
        }

        return leader.tabId === tabId;
    }

    function maintainLeader() {
        if (isLeader()) {
            writeLeader();
        }
    }

    function getAudioContext() {
        if (audioContext) {
            return audioContext;
        }

        const AudioContextClass =
            window.AudioContext
            || window.webkitAudioContext;

        if (!AudioContextClass) {
            return null;
        }

        audioContext = new AudioContextClass();

        return audioContext;
    }

    async function unlockAudio() {
        if (!soundEnabled) {
            return;
        }

        const ctx = getAudioContext();

        if (!ctx) {
            return;
        }

        if (ctx.state === 'suspended') {
            try {
                await ctx.resume();
            } catch (error) {
                // Browser will allow audio after another user interaction.
            }
        }
    }

    function tone(
        frequency,
        startTime,
        duration,
        volume = 0.055
    ) {
        const ctx = getAudioContext();

        if (!ctx || ctx.state !== 'running') {
            return;
        }

        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();

        oscillator.type = 'sine';

        oscillator.frequency.setValueAtTime(
            frequency,
            startTime
        );

        gain.gain.setValueAtTime(
            0.0001,
            startTime
        );

        gain.gain.exponentialRampToValueAtTime(
            volume,
            startTime + 0.015
        );

        gain.gain.exponentialRampToValueAtTime(
            0.0001,
            startTime + duration
        );

        oscillator.connect(gain);
        gain.connect(ctx.destination);

        oscillator.start(startTime);
        oscillator.stop(
            startTime + duration + 0.03
        );
    }

    async function playSound(kind = 'default') {
        if (!soundEnabled || !isLeader()) {
            return;
        }

        await unlockAudio();

        const ctx = getAudioContext();

        if (!ctx || ctx.state !== 'running') {
            updateControl();
            return;
        }

        const t = ctx.currentTime + 0.01;

        if (kind === 'chat') {
            tone(720, t, 0.10, 0.05);
            tone(900, t + 0.13, 0.11, 0.05);
            return;
        }

        if (kind === 'payment') {
            tone(640, t, 0.10, 0.045);
            tone(800, t + 0.11, 0.10, 0.045);
            tone(980, t + 0.22, 0.12, 0.05);
            return;
        }

        if (kind === 'attention') {
            tone(520, t, 0.12, 0.055);
            tone(720, t + 0.15, 0.14, 0.06);
            return;
        }

        tone(620, t, 0.11, 0.05);
        tone(820, t + 0.14, 0.12, 0.055);
    }

    function soundKind(detail) {
        const eventType = String(
            detail?.event_type || ''
        );

        const moduleKey = String(
            detail?.module_key || ''
        );

        if (
            eventType.includes('chat')
            || eventType.includes('message')
            || moduleKey === 'community_chat'
        ) {
            return 'chat';
        }

        if (
            eventType.includes('paid')
            || moduleKey === 'finance_dues'
        ) {
            return 'payment';
        }

        if (
            moduleKey === 'login_security'
            || moduleKey === 'complaints'
        ) {
            return 'attention';
        }

        return 'default';
    }

    function shouldSoundActivity(detail) {
        if (!detail || !detail.module_key) {
            return false;
        }

        const eventType = String(
            detail.event_type || ''
        );

        const action = String(
            detail.action || ''
        ).toLowerCase();

        /*
         * Brand-new complaints already have their own dedicated
         * complaint alert sound. Skip them here to avoid duplicates.
         */
        if (eventType === 'complaint_created') {
            return false;
        }

        /*
         * Generic updates are commonly caused by the admin's own action.
         * Do not beep for those, except homeowner document uploads.
         */
        if (
            action === 'updated'
            && eventType !== 'homeowner_documents_updated'
        ) {
            return false;
        }

        return true;
    }

    function markEventHandled(eventId) {
        const id = Number(eventId || 0);

        if (id <= 0) {
            return true;
        }

        const lastId = Number(
            localStorage.getItem(LAST_EVENT_KEY) || 0
        );

        if (id <= lastId) {
            return false;
        }

        localStorage.setItem(
            LAST_EVENT_KEY,
            String(id)
        );

        channel?.postMessage({
            type: 'event-handled',
            eventId: id,
            tabId
        });

        return true;
    }

    function eventText(detail) {
        const eventType = String(
            detail?.event_type || ''
        );

        const moduleKey = String(
            detail?.module_key || ''
        );

        return (
            eventLabels[eventType]
            || `New ${moduleLabels[moduleKey] || 'system'} activity`
        );
    }

    function moduleText(moduleKey) {
        return moduleLabels[moduleKey]
            || 'System';
    }

    function injectStyles() {
        if (
            document.getElementById(
                'hoaGlobalNotificationStyles'
            )
        ) {
            return;
        }

        const style = document.createElement('style');

        style.id = 'hoaGlobalNotificationStyles';

        style.textContent = `
            #hoaGlobalSoundControl {
                position: fixed;
                right: 14px;
                bottom: 58px;
                z-index: 99990;
                display: inline-flex;
                align-items: center;
                gap: 7px;
                min-height: 32px;
                padding: 6px 10px;
                border: 1px solid #dbe4ea;
                border-radius: 999px;
                background: rgba(255, 255, 255, .96);
                color: #475569;
                box-shadow: 0 6px 18px rgba(15, 23, 42, .10);
                font-size: 11px;
                font-weight: 800;
                cursor: pointer;
                backdrop-filter: blur(8px);
            }

            #hoaGlobalSoundControl:hover {
                border-color: #86efac;
                color: #047857;
            }

            #hoaGlobalSoundControl.is-muted {
                color: #94a3b8;
            }

            #hoaGlobalRealtimeToast {
                position: fixed;
                top: 84px;
                right: 16px;
                z-index: 99989;
                display: none;
                width: min(330px, calc(100vw - 32px));
                padding: 13px 14px;
                border: 1px solid #dbe4ea;
                border-radius: 14px;
                background: rgba(255, 255, 255, .98);
                box-shadow: 0 14px 38px rgba(15, 23, 42, .16);
                color: #0f172a;
                cursor: pointer;
            }

            #hoaGlobalRealtimeToast.is-visible {
                display: block;
            }

            .hoa-global-toast-title {
                font-size: 13px;
                font-weight: 800;
                line-height: 1.35;
            }

            .hoa-global-toast-meta {
                margin-top: 3px;
                color: #64748b;
                font-size: 11px;
                font-weight: 600;
            }

            html.dark #hoaGlobalSoundControl,
            html.dark #hoaGlobalRealtimeToast {
                border-color: #334155;
                background: rgba(23, 32, 51, .97);
                color: #e2e8f0;
            }

            html.dark #hoaGlobalSoundControl:hover {
                border-color: #047857;
                color: #6ee7b7;
            }

            html.dark .hoa-global-toast-meta {
                color: #94a3b8;
            }

            @media (max-width: 575px) {
                #hoaGlobalSoundControl {
                    right: 10px;
                    bottom: 54px;
                }

                #hoaGlobalRealtimeToast {
                    top: 76px;
                    right: 10px;
                    width: calc(100vw - 20px);
                }
            }
        `;

        document.head.appendChild(style);
    }

    function ensureControl() {
        if (control) {
            return;
        }

        injectStyles();

        control = document.createElement(
            'button'
        );

        control.type = 'button';
        control.id = 'hoaGlobalSoundControl';
        control.title =
            'Turn admin notification sounds on or off';

        control.innerHTML = `
            <span class="hoa-sound-icon">
                🔔
            </span>

            <span class="hoa-sound-status">
                Notifications: Sound On
            </span>
        `;

        document.body.appendChild(control);

        statusText = control.querySelector(
            '.hoa-sound-status'
        );

        control.addEventListener(
            'click',
            async () => {
                soundEnabled = !soundEnabled;

                localStorage.setItem(
                    SOUND_KEY,
                    soundEnabled
                        ? '1'
                        : '0'
                );

                updateControl();

                if (soundEnabled) {
                    await unlockAudio();
                    playSound('default');
                }
            }
        );

        updateControl();
    }

    function updateControl() {
        if (!control) {
            return;
        }

        const icon = control.querySelector(
            '.hoa-sound-icon'
        );

        if (icon) {
            icon.textContent =
                soundEnabled
                    ? '🔔'
                    : '🔕';
        }

        if (statusText) {
            statusText.textContent =
                soundEnabled
                    ? 'Notifications: Sound On'
                    : 'Notifications: Sound Off';
        }

        control.classList.toggle(
            'is-muted',
            !soundEnabled
        );
    }

    function showToast(
        title,
        moduleKey
    ) {
        if (!isLeader()) {
            return;
        }

        let toast = document.getElementById(
            'hoaGlobalRealtimeToast'
        );

        if (!toast) {
            toast = document.createElement(
                'div'
            );

            toast.id =
                'hoaGlobalRealtimeToast';

            toast.setAttribute(
                'role',
                'status'
            );

            toast.setAttribute(
                'aria-live',
                'polite'
            );

            toast.innerHTML = `
                <div class="hoa-global-toast-title"></div>
                <div class="hoa-global-toast-meta"></div>
            `;

            document.body.appendChild(toast);
        }

        toast
            .querySelector(
                '.hoa-global-toast-title'
            )
            .textContent = title;

        toast
            .querySelector(
                '.hoa-global-toast-meta'
            )
            .textContent = moduleText(
                moduleKey
            );

        toast.onclick = () => {
            const url = moduleUrls[moduleKey];

            if (url) {
                window.location.href = url;
            }

            toast.classList.remove(
                'is-visible'
            );
        };

        toast.classList.add(
            'is-visible'
        );

        clearTimeout(toastTimer);

        toastTimer = window.setTimeout(
            () => {
                toast.classList.remove(
                    'is-visible'
                );
            },
            5000
        );
    }

    function notifyActivity(detail) {
        if (!shouldSoundActivity(detail)) {
            return;
        }

        if (!markEventHandled(detail.id)) {
            return;
        }

        lastActivityAt = now();

        if (!isLeader()) {
            return;
        }

        playSound(
            soundKind(detail)
        );

        showToast(
            eventText(detail),
            String(
                detail.module_key || ''
            )
        );
    }

    function notifyCountIncrease(
        moduleKey,
        newCount,
        oldCount
    ) {
        if (
            !initializedSnapshot
            || newCount <= oldCount
            || (now() - lastActivityAt) < 2200
            || !isLeader()
        ) {
            return;
        }

        playSound(
            moduleKey === 'complaints'
                ? 'attention'
                : 'default'
        );

        showToast(
            `${moduleText(moduleKey)} has new activity`,
            moduleKey
        );
    }

    function handleSnapshot(detail) {
        const incoming =
            detail?.counts
            && typeof detail.counts === 'object'
                ? detail.counts
                : {};

        Object.keys(incoming).forEach(
            moduleKey => {
                const nextCount = Math.max(
                    0,
                    Number(
                        incoming[moduleKey] || 0
                    )
                );

                const previousCount = Math.max(
                    0,
                    Number(
                        previousCounts[moduleKey] || 0
                    )
                );

                notifyCountIncrease(
                    moduleKey,
                    nextCount,
                    previousCount
                );
            }
        );

        previousCounts = {
            ...incoming
        };

        initializedSnapshot = true;
    }

    function armAudioOnInteraction() {
        const arm = () => {
            unlockAudio();
        };

        window.addEventListener(
            'pointerdown',
            arm,
            {
                passive: true,
                once: true
            }
        );

        window.addEventListener(
            'keydown',
            arm,
            {
                once: true
            }
        );

        window.addEventListener(
            'touchstart',
            arm,
            {
                passive: true,
                once: true
            }
        );
    }

    function start() {
        ensureControl();
        maintainLeader();
        armAudioOnInteraction();

        leaderTimer = window.setInterval(
            maintainLeader,
            2500
        );

        document.addEventListener(
            'hoa:realtime',
            event => {
                notifyActivity(
                    event.detail || {}
                );
            }
        );

        document.addEventListener(
            'hoa:realtime-snapshot',
            event => {
                handleSnapshot(
                    event.detail || {}
                );
            }
        );

        window.addEventListener(
            'storage',
            event => {
                if (event.key === SOUND_KEY) {
                    soundEnabled =
                        localStorage.getItem(
                            SOUND_KEY
                        ) !== '0';

                    updateControl();
                }
            }
        );

        channel?.addEventListener(
            'message',
            event => {
                const data =
                    event.data || {};

                if (
                    data.type === 'event-handled'
                    && Number(
                        data.eventId || 0
                    ) > 0
                ) {
                    const existing = Number(
                        localStorage.getItem(
                            LAST_EVENT_KEY
                        ) || 0
                    );

                    if (
                        Number(data.eventId)
                        > existing
                    ) {
                        localStorage.setItem(
                            LAST_EVENT_KEY,
                            String(
                                data.eventId
                            )
                        );
                    }
                }
            }
        );

        window.addEventListener(
            'beforeunload',
            () => {
                if (leaderTimer) {
                    clearInterval(
                        leaderTimer
                    );
                }

                const leader =
                    readLeader();

                if (
                    leader?.tabId === tabId
                ) {
                    localStorage.removeItem(
                        LEADER_KEY
                    );
                }

                channel?.close();
            }
        );
    }

    window.HOAGlobalRealtimeNotifications = {
        playTest: () => {
            playSound('default');
        },

        isSoundEnabled: () => {
            return soundEnabled;
        },

        setSoundEnabled: enabled => {
            soundEnabled =
                Boolean(enabled);

            localStorage.setItem(
                SOUND_KEY,
                soundEnabled
                    ? '1'
                    : '0'
            );

            updateControl();
        }
    };

    if (
        document.readyState === 'loading'
    ) {
        document.addEventListener(
            'DOMContentLoaded',
            start,
            {
                once: true
            }
        );
    } else {
        start();
    }
})();