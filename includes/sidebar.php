<?php
/**
 * Role-aware sidebar renderer.
 */

function sidebarSvg(string $name): string {
    $icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect>',
        'folder' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>',
        'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line>',
        'message' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'profile' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
    ];

    $path = $icons[$name] ?? $icons['dashboard'];
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' . $path . '</svg>';
}

function unreadNotificationCount(int $userId): int {
    try {
        $db = DB::connect();
        $stmt = $db->prepare('SELECT COUNT(*) AS unread_count FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int)($stmt->fetch()['unread_count'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function renderNavItem(array $item, string $activeKey): void {
    $isActive = ($item['key'] ?? '') === $activeKey;
    $href = htmlspecialchars($item['href'] ?? '#', ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars($item['label'] ?? '', ENT_QUOTES, 'UTF-8');
    $extra = '';
    if (!empty($item['tab'])) {
        $extra .= ' data-tab="' . htmlspecialchars($item['tab'], ENT_QUOTES, 'UTF-8') . '"';
    }

    echo '<a href="' . $href . '" class="nav-item' . ($isActive ? ' active' : '') . '"' . $extra . '>';
    echo sidebarSvg($item['icon'] ?? 'dashboard');
    echo '<span class="nav-label">' . $label . '</span>';
    if (!empty($item['badge'])) {
        echo '<span class="nav-badge nav-badge-glow">' . (int)$item['badge'] . '</span>';
    }
    echo '</a>';
}

function rolePortalPath(string $role): string {
    $paths = [
        'student' => 'dashboard.php',
        'supervisor' => 'supervisor-dashboard.php',
        'coordinator' => 'coordinator.php',
    ];

    return $paths[$role] ?? 'dashboard.php';
}

function renderSidebar(array $user, string $activeKey = ''): void {
    $role = $user['role'] ?? 'student';
    $fullName = $user['full_name'] ?? 'User';
    $identifier = $role === 'student'
        ? 'Student ID: ' . ($user['university_id_number'] ?? '')
        : ucfirst($role);
    $unread = unreadNotificationCount((int)$user['user_id']);
    $avatarName = rawurlencode($fullName);

    $menus = [
        'student' => [
            ['key' => 'dashboard', 'href' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard'],
            ['key' => 'deliverables', 'href' => 'deliverables.php', 'label' => 'Deliverables', 'icon' => 'file'],
            ['key' => 'communications', 'href' => 'communications.php', 'label' => 'Communications', 'icon' => 'message', 'badge' => $unread],
            ['key' => 'schedule', 'href' => 'schedule.php', 'label' => 'Schedule', 'icon' => 'calendar'],
            ['key' => 'profile', 'href' => 'edit-profile.php', 'label' => 'Profile', 'icon' => 'profile'],
        ],
        'supervisor' => [
            ['key' => 'supervisees', 'href' => '#', 'label' => 'My Supervisees', 'icon' => 'users', 'tab' => 'supervisees'],
            ['key' => 'submissions', 'href' => '#', 'label' => 'Submission Review', 'icon' => 'file', 'tab' => 'submissions', 'badge' => $unread],
            ['key' => 'milestones', 'href' => '#', 'label' => 'Manage Milestones', 'icon' => 'folder', 'tab' => 'milestones'],
            ['key' => 'profile', 'href' => 'edit-profile.php', 'label' => 'Profile', 'icon' => 'profile'],
        ],
        'coordinator' => [
            ['key' => 'overview', 'href' => '#', 'label' => 'System Overview', 'icon' => 'dashboard', 'tab' => 'overview'],
            ['key' => 'users', 'href' => '#', 'label' => 'User Management', 'icon' => 'users', 'tab' => 'users'],
            ['key' => 'cohorts', 'href' => '#', 'label' => 'Cohort Management', 'icon' => 'calendar', 'tab' => 'cohorts'],
            ['key' => 'milestones', 'href' => '#', 'label' => 'Milestone Engine', 'icon' => 'folder', 'tab' => 'milestones'],
            ['key' => 'allocations', 'href' => '#', 'label' => 'Allocation Dashboard', 'icon' => 'file', 'tab' => 'allocations'],
            ['key' => 'profile', 'href' => 'edit-profile.php', 'label' => 'Profile', 'icon' => 'profile'],
        ],
    ];

    $items = $menus[$role] ?? $menus['student'];
    if ($activeKey === 'profile') {
        $portalPath = rolePortalPath($role);
        foreach ($items as &$item) {
            if (!empty($item['tab'])) {
                $item['href'] = $portalPath . '?tab=' . rawurlencode($item['tab']);
                unset($item['tab']);
            }
        }
        unset($item);
    }
    ?>
    <aside class="sidebar">
      <div class="sidebar-header">
        <div class="sidebar-logo">
          <img src="cuea_logo.png/screen.png" alt="CUEA Logo" onerror="this.style.display='none'">
        </div>
        <div class="sidebar-title"><?php echo htmlspecialchars(strtoupper($role), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>

      <nav class="sidebar-nav">
        <?php foreach ($items as $item) renderNavItem($item, $activeKey); ?>
      </nav>

      <!-- Help Button -->
      <a href="mailto:itsupport@cuea.edu?subject=Inquiry%20on%20FYPMS"
         class="sidebar-help-btn"
         title="Contact IT Support"
         aria-label="Get Help" target="_blank">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/>
          <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
          <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        <span class="nav-label">Help &amp; Support</span>
      </a>

      <div class="sidebar-footer">
        <img src="https://ui-avatars.com/api/?name=<?php echo $avatarName; ?>&background=D4A017&color=1A1A1A" alt="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>" class="avatar">
        <div class="user-info">
          <span class="name"><?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?></span>
          <span class="id"><?php echo htmlspecialchars($identifier, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <a href="index.html" class="logout-btn" title="Sign Out" aria-label="Sign out">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <polyline points="16 17 21 12 16 7"></polyline>
            <line x1="21" y1="12" x2="9" y2="12"></line>
          </svg>
        </a>
      </div>
    </aside>
    <?php
}
