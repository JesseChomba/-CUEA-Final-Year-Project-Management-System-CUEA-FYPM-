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
  <title>Deliverables – CUEA FYPM</title>
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/dashboard.css">
  </head>
<body class="dashboard-layout">
  <?php renderSidebar($currentUser, 'deliverables'); ?>
<div class="main-wrapper">
    <header class="top-bar"><div class="top-bar-title">Deliverables & Milestones</div></header>
    <main class="content-body">
      <div class="card">
        <h3>Milestones</h3>
        <p class="text-muted">A list of all project deliverables and their status will appear here.</p>
      </div>
    </main>
  </div>
  <script src="js/student-common.js"></script>
  <script src="js/deliverables.js"></script>
  <script src="js/session-guard.js"></script>
  <script src="js/sidebar.js"></script>
</body>
</html>







