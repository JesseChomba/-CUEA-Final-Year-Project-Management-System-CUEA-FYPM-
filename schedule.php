<?php
require_once __DIR__ . '/includes/page_guard.php';
$currentUser = requirePageRole(['student']);
requireStudentSupervisorAssigned($currentUser);
require_once __DIR__ . '/includes/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Schedule – CUEA FYPM</title>
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/dashboard.css">
  <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js'></script>
  <style>
    .layout-toggle { display: flex; gap: 10px; margin-bottom: 20px; }
    .layout-toggle button { padding: 8px 16px; border: 1px solid var(--color-border); background: #fff; cursor: pointer; border-radius: 4px; }
    .layout-toggle button.active { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }
    #calendarView { display: none; background: #fff; padding: 20px; border-radius: 8px; box-shadow: var(--shadow-sm); }
    #listView { display: block; }
  </style>
  </head>
<body class="dashboard-layout">
  <?php renderSidebar($currentUser, 'schedule'); ?>
<div class="main-wrapper">
    <header class="top-bar"><div class="top-bar-title">Project Schedule</div></header>
    <main class="content-body">
      <div class="layout-toggle">
        <button id="btnListView" class="active">List View</button>
        <button id="btnCalendarView">Calendar View</button>
      </div>
      
      <div id="listView">
        <div class="card">
          <h3>Upcoming Deadlines & Meetings</h3>
          <p class="text-muted">A list of upcoming project milestones and supervisor meetings will appear here.</p>
        </div>
      </div>

      <div id="calendarView">
        <div id="calendar"></div>
      </div>
    </main>
  </div>
  <script src="js/student-common.js"></script>
  <script src="js/schedule.js"></script>
  <script src="js/session-guard.js"></script>
  <script src="js/sidebar.js"></script>
</body>
</html>







