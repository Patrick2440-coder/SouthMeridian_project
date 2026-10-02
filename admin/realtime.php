<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SOUTH MERIDIAN HOMES - CENTRAL REALTIME ENDPOINT
|--------------------------------------------------------------------------
|
| One file serves both:
|   1) the shared sidebar realtime JavaScript (?client=2)
|   2) JSON snapshots / mark-seen actions / SSE stream
|
| Normal module PHP pages remain normal PHP pages.
|
*/

if (isset($_GET['client'])) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    echo <<<'JAVASCRIPT'
(() => {
  'use strict';

  const ENDPOINT = 'realtime.php';
  const counts = {};
  let stickyModules = new Set();
  let eventSource = null;
  let snapshotTimer = null;

  /*
   * One mapping for the whole admin sidebar.
   * No individual module page needs its own realtime API.
   */
  const routeMap = [
    { pattern: /ho_approval\.php$/i, module: 'homeowner_push' },

    { pattern: /login_security\.php$/i, module: 'login_security' },
    { pattern: /staff_management\.php$/i, module: 'staff_pending' },

    { pattern: /announcements\.php$/i, module: 'announcements' },

    { pattern: /admin_complaints\.php$/i, module: 'complaints' },

    { pattern: /finance_dues\.php$/i, module: 'finance_dues' },
    { pattern: /finance_reports\.php$/i, module: 'finance_reports' },

    { pattern: /parking_permits\.php$/i, module: 'parking' },
    { pattern: /parking\.php$/i, module: 'parking' },

    { pattern: /admin_facility_rentals\.php$/i, module: 'facility_rentals' },
    { pattern: /admin_facility_calendar\.php$/i, module: 'facility_rentals' },
    { pattern: /admin_public_chat\.php$/i, module: 'community_chat' }
  ];

  const badgeTargets = {
    homeowner_push: [
      'a[href="ho_approval.php"]'
    ],

    login_security: [
      'a[href="login_security.php"]'
    ],
    staff_pending: [
      'a[href="staff_management.php"]'
    ],

    announcements: [
      'a[href="announcements.php"]'
    ],

    complaints: [
      'a[href="admin_complaints.php"]'
    ],

    finance_dues: [
      'a[href="finance_dues.php"]'
    ],
    finance_reports: [
      'a[href="finance_reports.php"]'
    ],

    parking: [
      'a[href="parking_permits.php"]'
    ],

    facility_rentals: [
      'a[href="admin_facility_rentals.php"]'
    ],
    community_chat: [
      'a[href="admin_public_chat.php"]'
    ]
  };

  function currentPageName() {
    const path = String(window.location.pathname || '');
    return path.substring(path.lastIndexOf('/') + 1);
  }

  function currentModule() {
    const page = currentPageName();
    const route = routeMap.find(item => item.pattern.test(page));
    return route ? route.module : '';
  }

  function moduleForAnchor(anchor) {
    if (!anchor) return '';

    const href = String(anchor.getAttribute('href') || '');
    const clean = href.split('?')[0].split('#')[0];

    const route = routeMap.find(item => item.pattern.test(clean));
    return route ? route.module : '';
  }

  function injectStyles() {
    if (document.getElementById('hoaRealtimeBadgeStyles')) return;

    const style = document.createElement('style');
    style.id = 'hoaRealtimeBadgeStyles';

    style.textContent = `
      .hoa-rt-badge,
      .hoa-rt-parent-badge {
        display: none;
        align-items: center;
        justify-content: center;
        min-width: 18px;
        height: 18px;
        padding: 0 5px;
        border-radius: 999px;
        background: #dc2626;
        color: #fff !important;
        font-size: 10px;
        font-weight: 800;
        line-height: 1;
        box-shadow: 0 2px 8px rgba(127, 29, 29, .32);
        flex: 0 0 auto;
      }

      .hoa-rt-badge.is-visible,
      .hoa-rt-parent-badge.is-visible {
        display: inline-flex;
      }

      .sidebar-menu a.hoa-rt-target,
      .sidebar-menu a.hoa-rt-parent-target {
        position: relative;
      }

      .sidebar-menu a.hoa-rt-target {
        display: flex !important;
        align-items: center;
        gap: 8px;
      }

      .sidebar-menu a.hoa-rt-target > .hoa-rt-badge {
        margin-left: auto;
        margin-right: 4px;
      }

      /*
       * Keep EVERY dropdown parent consistent:
       * the red count sits on the upper-right corner of the module icon.
       */
      .sidebar-menu a.hoa-rt-parent-target > .hoa-rt-parent-badge {
        position: absolute;
        top: 6px;
        left: 31px;
        right: auto;
        z-index: 4;
        transform: none;
        min-width: 16px;
        height: 16px;
        padding: 0 4px;
        font-size: 9px;
        border: 2px solid #0f172a;
        box-shadow: 0 2px 6px rgba(127, 29, 29, .30);
      }

      /*
       * Reserve the right side of every dropdown parent for the DeskApp
       * arrow. This prevents labels such as "User Management" from
       * touching or appearing underneath the arrow.
       */
      .sidebar-menu li.dropdown > a.dropdown-toggle {
        position: relative !important;
        display: flex !important;
        align-items: center !important;
        min-width: 0;
        padding-right: 46px !important;
      }

      .sidebar-menu li.dropdown > a.dropdown-toggle > .micon {
        flex: 0 0 auto;
      }

      .sidebar-menu li.dropdown > a.dropdown-toggle > .mtext {
        flex: 1 1 auto;
        min-width: 0;
        padding-right: 10px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      /*
       * DeskApp draws the dropdown chevron with a pseudo element.
       * Force its horizontal position to the far-right reserved area.
       */
      .sidebar-menu li.dropdown > a.dropdown-toggle::after {
        right: 16px !important;
        left: auto !important;
      }

      html.dark .hoa-rt-badge,
      html.dark .hoa-rt-parent-badge {
        background: #ef4444;
        color: #fff !important;
        box-shadow: 0 2px 8px rgba(239, 68, 68, .24);
      }

      html.dark
        .sidebar-menu
        a.hoa-rt-parent-target
        > .hoa-rt-parent-badge {
        border-color: #0f172a;
      }

      @media (max-width: 1199.98px) {
        .sidebar-menu a.hoa-rt-parent-target > .hoa-rt-parent-badge {
          left: 30px;
          right: auto;
        }

        .sidebar-menu li.dropdown > a.dropdown-toggle {
          padding-right: 44px !important;
        }

        .sidebar-menu li.dropdown > a.dropdown-toggle::after {
          right: 14px !important;
        }
      }
    `;

    document.head.appendChild(style);
  }

  function findFirstTarget(moduleKey) {
    const selectors = badgeTargets[moduleKey] || [];

    for (const selector of selectors) {
      const node = document.querySelector(selector);
      if (node) return node;
    }

    return null;
  }

  function formatCount(value) {
    const n = Math.max(0, Number(value || 0));
    return n > 99 ? '99+' : String(n);
  }

  function ensureChildBadge(moduleKey) {
    const anchor = findFirstTarget(moduleKey);
    if (!anchor) return null;

    anchor.classList.add('hoa-rt-target');

    let badge = anchor.querySelector(
      `.hoa-rt-badge[data-realtime-module="${moduleKey}"]`
    );

    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'hoa-rt-badge';
      badge.dataset.realtimeModule = moduleKey;
      badge.setAttribute('aria-label', 'New or pending activity');
      anchor.appendChild(badge);
    }

    return badge;
  }

  function renderChildBadges() {
    Object.keys(badgeTargets).forEach(moduleKey => {
      const count = Math.max(0, Number(counts[moduleKey] || 0));
      const badge = ensureChildBadge(moduleKey);

      if (!badge) return;

      badge.textContent = formatCount(count);
      badge.classList.toggle('is-visible', count > 0);

      const target = findFirstTarget(moduleKey);
      if (target) {
        target.title = count > 0
          ? `${count} new or pending item${count === 1 ? '' : 's'}`
          : '';
      }
    });
  }

  function ensureParentBadge(parent) {
    if (!parent) return null;

    parent.classList.add('hoa-rt-parent-target');

    let badge = parent.querySelector(':scope > .hoa-rt-parent-badge');

    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'hoa-rt-parent-badge';
      badge.setAttribute('aria-label', 'New or pending activity');
      parent.appendChild(badge);
    }

    return badge;
  }

  function renderParentBadges() {
    document.querySelectorAll('.sidebar-menu li.dropdown').forEach(dropdown => {
      const parent = dropdown.querySelector(':scope > a.dropdown-toggle');
      if (!parent) return;

      const modules = new Set();

      dropdown.querySelectorAll('a[href]').forEach(anchor => {
        const key = moduleForAnchor(anchor);
        if (key) modules.add(key);
      });

      if (modules.size === 0) return;

      let total = 0;

      modules.forEach(key => {
        total += Math.max(0, Number(counts[key] || 0));
      });

      const badge = ensureParentBadge(parent);
      if (!badge) return;

      badge.textContent = formatCount(total);
      badge.classList.toggle('is-visible', total > 0);

      parent.title = total > 0
        ? `${total} new or pending item${total === 1 ? '' : 's'}`
        : '';

    });
  }

  function renderBadges() {
    injectStyles();
    renderChildBadges();
    renderParentBadges();
  }

  function applySnapshot(data) {
    Object.keys(counts).forEach(key => delete counts[key]);

    const incoming = data?.counts || {};

    Object.keys(incoming).forEach(key => {
      counts[key] = Math.max(0, Number(incoming[key] || 0));
    });

    stickyModules = new Set(
      Array.isArray(data?.sticky_modules)
        ? data.sticky_modules
        : []
    );

    renderBadges();

    document.dispatchEvent(new CustomEvent('hoa:realtime-snapshot', {
      detail: {
        counts: { ...counts },
        sticky_modules: [...stickyModules],
        last_event_id: Number(data?.last_event_id || 0)
      }
    }));
  }

  async function refreshSnapshot() {
    try {
      const response = await fetch(`${ENDPOINT}?action=snapshot`, {
        credentials: 'same-origin',
        cache: 'no-store'
      });

      if (!response.ok) return;

      const data = await response.json();
      if (!data || !data.success) return;

      applySnapshot(data);
    } catch (error) {
      console.debug('Realtime snapshot unavailable:', error);
    }
  }

  async function markSeen(moduleKey) {
    if (!moduleKey || stickyModules.has(moduleKey)) {
      return;
    }

    counts[moduleKey] = 0;
    renderBadges();

    try {
      const body = new URLSearchParams();
      body.set('action', 'mark_seen');
      body.set('module', moduleKey);

      await fetch(ENDPOINT, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body: body.toString(),
        keepalive: true
      });
    } catch (error) {
      console.debug('Realtime mark-seen unavailable:', error);
    }
  }

  function handleActivity(detail) {
    if (!detail || !detail.module_key) return;

    /*
     * Module pages can listen to this event if they want to update
     * their own visible UI immediately. The sidebar itself only
     * needs to refresh the central snapshot.
     */
    document.dispatchEvent(new CustomEvent('hoa:realtime', {
      detail
    }));

    const activeModule = currentModule();

    if (
      activeModule &&
      activeModule === detail.module_key &&
      !stickyModules.has(activeModule) &&
      document.visibilityState === 'visible'
    ) {
      markSeen(activeModule).then(refreshSnapshot);
      return;
    }

    refreshSnapshot();
  }

  function connectStream() {
    if (!window.EventSource) {
      return;
    }

    if (eventSource) {
      eventSource.close();
    }

    eventSource = new EventSource(`${ENDPOINT}?stream=1`);

    eventSource.addEventListener('snapshot', event => {
      try {
        applySnapshot(JSON.parse(event.data || '{}'));
      } catch (error) {
        console.debug('Realtime snapshot event parse failed:', error);
      }
    });

    eventSource.addEventListener('activity', event => {
      try {
        handleActivity(JSON.parse(event.data || '{}'));
      } catch (error) {
        console.debug('Realtime activity parse failed:', error);
      }
    });

    eventSource.addEventListener('setup_required', event => {
      console.warn(
        'South Meridian realtime setup is not installed yet.',
        event.data || ''
      );
    });

    eventSource.onerror = () => {
      /*
       * Browser EventSource reconnects automatically.
       * The periodic central snapshot below also keeps counts correct.
       */
    };
  }

  function bindSidebarClicks() {
    document.addEventListener('click', event => {
      const anchor = event.target.closest('.sidebar-menu a[href]');
      if (!anchor) return;

      const moduleKey = moduleForAnchor(anchor);
      if (!moduleKey) return;

      markSeen(moduleKey);
    });
  }

  function markCurrentPageSeen() {
    const moduleKey = currentModule();

    if (!moduleKey || stickyModules.has(moduleKey)) {
      return;
    }

    window.setTimeout(() => {
      markSeen(moduleKey).then(refreshSnapshot);
    }, 350);
  }

  function start() {
    injectStyles();
    renderBadges();
    bindSidebarClicks();

    refreshSnapshot().then(markCurrentPageSeen);
    connectStream();

    /*
     * One small central snapshot every 12 seconds keeps persistent
     * "needs action" badges exact even if a row changes without a trigger.
     * This is still only ONE central endpoint for the whole admin side.
     */
    snapshotTimer = window.setInterval(refreshSnapshot, 12000);

    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState !== 'visible') return;

      refreshSnapshot().then(markCurrentPageSeen);
    });

    window.addEventListener('resize', () => {
      renderBadges();
    });
  }

  window.HOARealtime = {
    refresh: refreshSnapshot,
    markSeen,
    getCounts: () => ({ ...counts }),
    getStickyModules: () => [...stickyModules]
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})();

JAVASCRIPT;
    exit;
}

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../config/database.php';

date_default_timezone_set('Asia/Manila');

/* --------------------------------------------------------------------------
   RESPONSE / DATABASE HELPERS
   -------------------------------------------------------------------------- */

function rt_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function rt_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['c'] ?? 0) > 0;
}

function rt_tables_ready(mysqli $conn): bool
{
    return
        rt_table_exists($conn, 'realtime_events')
        && rt_table_exists($conn, 'realtime_admin_state');
}

function rt_current_admin(mysqli $conn): array
{
    $adminId = (int)($_SESSION['admin_id'] ?? 0);

    if ($adminId <= 0) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT id, phase, role, position
        FROM admins
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    if (!$row) {
        return [];
    }

    $role = strtolower(trim((string)($row['role'] ?? '')));

    if ($role !== 'admin') {
        return [];
    }

    return $row;
}

function rt_scalar_count(
    mysqli $conn,
    string $sql,
    string $types = '',
    array $params = []
): int {
    $stmt = $conn->prepare($sql);

    if ($types !== '') {
        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }

        $stmt->bind_param($types, ...$refs);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return (int)($row['c'] ?? 0);
}

/* --------------------------------------------------------------------------
   EVENT-BASED COUNTS
   These are "new since I last opened it" badges.
   -------------------------------------------------------------------------- */

function rt_unseen_event_counts(
    mysqli $conn,
    int $adminId,
    string $phase
): array {
    $counts = [];

    $stmt = $conn->prepare("
        SELECT
            e.module_key,
            COUNT(*) AS unread_count
        FROM realtime_events e
        LEFT JOIN realtime_admin_state s
          ON s.admin_id = ?
         AND s.module_key = e.module_key
        WHERE e.id > COALESCE(s.last_seen_event_id, 0)
          AND e.module_key IN (
              'community_chat',
              'finance_dues',
              'announcements'
          )
          AND (
              e.phase IS NULL
              OR e.phase = ''
              OR e.phase = ?
          )
          AND (
              e.target_admin_id IS NULL
              OR e.target_admin_id = ?
          )
        GROUP BY e.module_key
    ");

    $stmt->bind_param('isi', $adminId, $phase, $adminId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $counts[(string)$row['module_key']] =
            (int)$row['unread_count'];
    }

    $stmt->close();

    return $counts;
}

/* --------------------------------------------------------------------------
   PERSISTENT / ACTION-NEEDED COUNTS
   These do NOT disappear just because the admin opened the page.
   -------------------------------------------------------------------------- */

function rt_action_counts(
    mysqli $conn,
    int $adminId,
    string $phase,
    string $position
): array {
    $counts = [];

    /* Homeowner Management: every pending homeowner not yet pushed/activated. */
    $counts['homeowner_push'] = 0;

    if (rt_table_exists($conn, 'homeowners')) {
        $counts['homeowner_push'] = rt_scalar_count(
            $conn,
            "
                SELECT COUNT(*) AS c
                FROM homeowners
                WHERE phase = ?
                  AND status = 'pending'
            ",
            's',
            [$phase]
        );
    }

    /* User Management -> Login Security: unresolved locks / pending appeals. */
    $counts['login_security'] = 0;

    if (rt_table_exists($conn, 'login_security_state')) {
        if (rt_table_exists($conn, 'login_security_appeals')) {
            $counts['login_security'] = rt_scalar_count(
                $conn,
                "
                    SELECT COUNT(DISTINCT s.id) AS c
                    FROM login_security_state s
                    LEFT JOIN login_security_appeals a
                      ON a.security_state_id = s.id
                     AND a.status = 'pending'
                    WHERE (
                        s.phase = ?
                        OR s.phase IS NULL
                        OR s.phase = ''
                    )
                      AND (
                        s.hard_locked = 1
                        OR a.id IS NOT NULL
                      )
                ",
                's',
                [$phase]
            );
        } else {
            $counts['login_security'] = rt_scalar_count(
                $conn,
                "
                    SELECT COUNT(*) AS c
                    FROM login_security_state
                    WHERE (
                        phase = ?
                        OR phase IS NULL
                        OR phase = ''
                    )
                      AND hard_locked = 1
                ",
                's',
                [$phase]
            );
        }
    }

    /* User Management -> Staff: pending applications for President review. */
    $counts['staff_pending'] = 0;

    if (
        strcasecmp(trim($position), 'President') === 0
        && rt_table_exists($conn, 'staff_applications')
    ) {
        $counts['staff_pending'] = rt_scalar_count(
            $conn,
            "
                SELECT COUNT(*) AS c
                FROM staff_applications
                WHERE phase = ?
                  AND status = 'pending'
                  AND (
                      president_admin_id IS NULL
                      OR president_admin_id = ?
                  )
            ",
            'si',
            [$phase, $adminId]
        );
    }

    /* Complaints: anything still unresolved. */
    $counts['complaints'] = 0;

    if (rt_table_exists($conn, 'complaints')) {
        $counts['complaints'] = rt_scalar_count(
            $conn,
            "
                SELECT COUNT(*) AS c
                FROM complaints
                WHERE phase = ?
                  AND status IN ('open', 'in_progress')
            ",
            's',
            [$phase]
        );
    }

    /* Parking: requests that are still pending. */
    $counts['parking'] = 0;

    if (rt_table_exists($conn, 'parking_permits')) {
        $counts['parking'] = rt_scalar_count(
            $conn,
            "
                SELECT COUNT(*) AS c
                FROM parking_permits
                WHERE phase = ?
                  AND status = 'pending'
            ",
            's',
            [$phase]
        );
    }

    /* Facility Rentals: requests still waiting for a decision. */
    $counts['facility_rentals'] = 0;

    if (rt_table_exists($conn, 'facility_rental_requests')) {
        $counts['facility_rentals'] = rt_scalar_count(
            $conn,
            "
                SELECT COUNT(*) AS c
                FROM facility_rental_requests
                WHERE phase = ?
                  AND status = 'pending'
            ",
            's',
            [$phase]
        );
    }

    /*
     * Finance Reports:
     * The President is the approval actor in the current workflow.
     */
    $counts['finance_reports'] = 0;

    if (
        strcasecmp(trim($position), 'President') === 0
        && rt_table_exists($conn, 'finance_report_requests')
    ) {
        $counts['finance_reports'] = rt_scalar_count(
            $conn,
            "
                SELECT COUNT(*) AS c
                FROM finance_report_requests
                WHERE phase = ?
                  AND status = 'pending'
            ",
            's',
            [$phase]
        );
    }

    return $counts;
}

function rt_sticky_modules(): array
{
    return [
        'homeowner_push',
        'login_security',
        'staff_pending',
        'complaints',
        'parking',
        'facility_rentals',
        'finance_reports'
    ];
}

function rt_snapshot_counts(
    mysqli $conn,
    int $adminId,
    string $phase,
    string $position
): array {
    $counts = rt_unseen_event_counts(
        $conn,
        $adminId,
        $phase
    );

    foreach (
        rt_action_counts(
            $conn,
            $adminId,
            $phase,
            $position
        )
        as $key => $value
    ) {
        $counts[$key] = $value;
    }

    /*
     * Keep expected event-based keys available even when zero.
     */
    foreach (
        [
            'community_chat',
            'finance_dues',
            'announcements'
        ]
        as $key
    ) {
        if (!array_key_exists($key, $counts)) {
            $counts[$key] = 0;
        }
    }

    return $counts;
}

/* --------------------------------------------------------------------------
   EVENT CURSOR / MARK SEEN
   -------------------------------------------------------------------------- */

function rt_max_visible_event_id(
    mysqli $conn,
    int $adminId,
    string $phase,
    ?string $moduleKey = null
): int {
    if ($moduleKey !== null && $moduleKey !== '') {
        $stmt = $conn->prepare("
            SELECT COALESCE(MAX(id), 0) AS max_id
            FROM realtime_events
            WHERE module_key = ?
              AND (
                  phase IS NULL
                  OR phase = ''
                  OR phase = ?
              )
              AND (
                  target_admin_id IS NULL
                  OR target_admin_id = ?
              )
        ");

        $stmt->bind_param(
            'ssi',
            $moduleKey,
            $phase,
            $adminId
        );
    } else {
        $stmt = $conn->prepare("
            SELECT COALESCE(MAX(id), 0) AS max_id
            FROM realtime_events
            WHERE (
                phase IS NULL
                OR phase = ''
                OR phase = ?
            )
              AND (
                  target_admin_id IS NULL
                  OR target_admin_id = ?
              )
        ");

        $stmt->bind_param(
            'si',
            $phase,
            $adminId
        );
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['max_id'] ?? 0);
}

function rt_mark_seen(
    mysqli $conn,
    int $adminId,
    string $phase,
    string $moduleKey
): int {
    /*
     * Only event-based badges can be marked seen.
     * Persistent counts disappear only when the work is actually resolved.
     */
    $allowed = [
        'community_chat',
        'finance_dues',
        'announcements'
    ];

    if (!in_array($moduleKey, $allowed, true)) {
        return 0;
    }

    $maxId = rt_max_visible_event_id(
        $conn,
        $adminId,
        $phase,
        $moduleKey
    );

    $stmt = $conn->prepare("
        INSERT INTO realtime_admin_state
            (
                admin_id,
                module_key,
                last_seen_event_id,
                updated_at
            )
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            last_seen_event_id = GREATEST(
                last_seen_event_id,
                VALUES(last_seen_event_id)
            ),
            updated_at = NOW()
    ");

    $stmt->bind_param(
        'isi',
        $adminId,
        $moduleKey,
        $maxId
    );

    $stmt->execute();
    $stmt->close();

    return $maxId;
}

/* --------------------------------------------------------------------------
   AUTH
   -------------------------------------------------------------------------- */

$admin = rt_current_admin($conn);

if (!$admin) {
    if (isset($_GET['stream'])) {
        http_response_code(401);
        exit;
    }

    rt_json([
        'success' => false,
        'message' => 'Admin session is not available.'
    ], 401);
}

$adminId = (int)$admin['id'];
$phase = (string)($admin['phase'] ?? '');
$position = trim((string)($admin['position'] ?? ''));

if (!rt_tables_ready($conn)) {
    if (isset($_GET['stream'])) {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');

        echo "event: setup_required\n";
        echo 'data: ' . json_encode([
            'message' => 'Run realtime_setup_v2.sql first.'
        ]) . "\n\n";

        @ob_flush();
        @flush();
        exit;
    }

    rt_json([
        'success' => false,
        'setup_required' => true,
        'message' => 'Run realtime_setup_v2.sql first.'
    ], 503);
}

/* --------------------------------------------------------------------------
   SNAPSHOT
   -------------------------------------------------------------------------- */

if (($_GET['action'] ?? '') === 'snapshot') {
    rt_json([
        'success' => true,
        'counts' => rt_snapshot_counts(
            $conn,
            $adminId,
            $phase,
            $position
        ),
        'sticky_modules' => rt_sticky_modules(),
        'last_event_id' => rt_max_visible_event_id(
            $conn,
            $adminId,
            $phase
        )
    ]);
}

/* --------------------------------------------------------------------------
   MARK EVENT-BASED MODULE SEEN
   -------------------------------------------------------------------------- */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'mark_seen'
) {
    $moduleKey = trim(
        (string)($_POST['module'] ?? '')
    );

    $seenThrough = rt_mark_seen(
        $conn,
        $adminId,
        $phase,
        $moduleKey
    );

    rt_json([
        'success' => true,
        'module' => $moduleKey,
        'seen_through' => $seenThrough
    ]);
}

/* --------------------------------------------------------------------------
   NORMAL HEALTH RESPONSE
   -------------------------------------------------------------------------- */

if (!isset($_GET['stream'])) {
    rt_json([
        'success' => true,
        'message' => 'South Meridian central realtime v2 is running.',
        'phase' => $phase
    ]);
}

/* --------------------------------------------------------------------------
   SERVER-SENT EVENTS STREAM
   -------------------------------------------------------------------------- */

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'off');

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

ignore_user_abort(true);
set_time_limit(0);

/*
 * Critical:
 * release the PHP session lock before this long-running SSE request.
 */
session_write_close();

while (ob_get_level() > 0) {
    @ob_end_flush();
}

echo "retry: 2000\n";

$snapshot = [
    'counts' => rt_snapshot_counts(
        $conn,
        $adminId,
        $phase,
        $position
    ),
    'sticky_modules' => rt_sticky_modules(),
    'last_event_id' => rt_max_visible_event_id(
        $conn,
        $adminId,
        $phase
    )
];

echo "event: snapshot\n";
echo 'data: ' . json_encode(
    $snapshot,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . "\n\n";

@flush();

$lastEventHeader =
    (int)($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0);

$cursor = $lastEventHeader > 0
    ? $lastEventHeader
    : (int)$snapshot['last_event_id'];

$startedAt = microtime(true);
$lastHeartbeat = microtime(true);

/*
 * Keep each connection short enough for normal PHP/XAMPP hosting.
 * EventSource reconnects automatically.
 */
while (
    !connection_aborted()
    && (microtime(true) - $startedAt) < 24
) {
    $stmt = $conn->prepare("
        SELECT
            id,
            module_key,
            event_type,
            phase,
            target_admin_id,
            entity_id,
            action,
            payload_json,
            created_at
        FROM realtime_events
        WHERE id > ?
          AND (
              phase IS NULL
              OR phase = ''
              OR phase = ?
          )
          AND (
              target_admin_id IS NULL
              OR target_admin_id = ?
          )
        ORDER BY id ASC
        LIMIT 100
    ");

    $stmt->bind_param(
        'isi',
        $cursor,
        $phase,
        $adminId
    );

    $stmt->execute();
    $res = $stmt->get_result();

    while ($event = $res->fetch_assoc()) {
        $cursor = max(
            $cursor,
            (int)$event['id']
        );

        $payload = [];
        $payloadJson = trim(
            (string)($event['payload_json'] ?? '')
        );

        if ($payloadJson !== '') {
            $decoded = json_decode(
                $payloadJson,
                true
            );

            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $message = [
            'id' => (int)$event['id'],
            'module_key' => (string)$event['module_key'],
            'event_type' => (string)$event['event_type'],
            'phase' => (string)($event['phase'] ?? ''),
            'entity_id' =>
                $event['entity_id'] !== null
                    ? (int)$event['entity_id']
                    : null,
            'action' => (string)$event['action'],
            'payload' => $payload,
            'created_at' => (string)$event['created_at']
        ];

        echo 'id: ' . (int)$event['id'] . "\n";
        echo "event: activity\n";
        echo 'data: ' . json_encode(
            $message,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) . "\n\n";
    }

    $stmt->close();

    if ((microtime(true) - $lastHeartbeat) >= 5) {
        echo ': heartbeat ' . time() . "\n\n";
        $lastHeartbeat = microtime(true);
    }

    @flush();
    usleep(800000);
}

exit;
