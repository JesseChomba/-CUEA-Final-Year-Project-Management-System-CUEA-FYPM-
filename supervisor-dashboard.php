<?php
require_once __DIR__ . '/includes/page_guard.php';
$currentUser = requirePageRole(['supervisor']);
require_once __DIR__ . '/includes/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Supervisor Portal – CUEA FYPM</title>
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/dashboard.css">
  <style>
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    
    .sup-card {
      background: #fff;
      border-radius: var(--radius-lg);
      padding: 24px;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
      margin-bottom: 24px;
    }
    
    table.data-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    table.data-table th, table.data-table td { padding: 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
    table.data-table th { background: #F9FAFB; font-weight: 600; color: var(--color-text); }
    
    .submission-item {
        border: 1px solid var(--color-border);
        border-radius: var(--radius-md);
        padding: 16px;
        margin-bottom: 16px;
    }
    .form-group { margin-bottom: 12px; }
    .form-group label { display: block; margin-bottom: 4px; font-weight: 600; font-size: 0.9rem; }
    .form-group select, .form-group textarea {
        width: 100%; padding: 8px; border: 1px solid var(--color-border); border-radius: var(--radius-sm);
    }
    .metric-card {
      border: 1px solid var(--color-border);
      border-radius: var(--radius-md);
      padding: 14px;
      background: #F9FAFB;
    }
    .metric-card strong { display:block; font-size:1.4rem; color:var(--color-primary); }
    .metric-card span { color:var(--color-muted); font-size:.85rem; }
  </style>
  </head>
<body class="dashboard-layout">

  <!-- ── SIDEBAR NAVIGATION ────────────────────────────────── -->
  <?php renderSidebar($currentUser, 'supervisees'); ?>
<!-- ── MAIN CONTENT ──────────────────────────────────────── -->
  <div class="main-wrapper">
    <header class="top-bar">
      <div class="top-bar-title">Supervisor Hub</div>
    </header>

    <main class="content-body">
      
      <!-- My Supervisees Tab -->
      <section id="tab-supervisees" class="tab-content active">
        <div class="sup-card">
          <h2>Supervisor Dashboard</h2>
          <div id="supervisorSummaryContainer">Loading dashboard summary...</div>
        </div>
        <div class="sup-card">
          <h2>View Logs</h2>
          <div id="supervisorLogsContainer">Loading activity logs...</div>
        </div>
        <div class="sup-card">
          <h2>My Supervisees</h2>
          <div style="margin-bottom: 14px;">
            <label style="display:block; font-weight:600; font-size:0.9rem; margin-bottom:6px;">Filter by Cohort</label>
            <select id="superviseeCohortFilter" class="responsive-filter-control">
              <option value="">All Cohorts</option>
              <!-- Populated by JS -->
            </select>
          </div>
          <div id="superviseesContainer">Loading...</div>
        </div>
      </section>

      <!-- Submissions Tab -->
      <section id="tab-submissions" class="tab-content">
        <div class="sup-card" style="margin-bottom: 20px;">
          <h2>Select Student Project</h2>
          <div class="form-group">
            <label>Cohort Filter</label>
            <select id="submissionCohortFilter">
              <option value="">All assigned cohorts</option>
            </select>
          </div>
          <div class="form-group">
            <select id="projectSelect">
              <option value="">Select a student project...</option>
              <!-- Populated via JS -->
            </select>
          </div>
        </div>

        <div class="sup-card">
          <h2>Milestone Submissions</h2>
          <div id="submissionsContainer">
            <p class="text-muted">Select a project above to view submissions.</p>
          </div>
        </div>
      </section>

      <!-- Manage Milestones Tab -->
      <section id="tab-milestones" class="tab-content">
        <div class="sup-card">
          <h2>Create Sub-Milestone</h2>
          <p class="text-muted">Create specific sub-milestones for your assigned cohorts. These will only appear to students you are supervising.</p>
          <form id="createSubMilestoneForm">
            <div class="form-group">
              <label>Select Cohort</label>
              <select id="sm_cohort" required>
                <option value="">Loading cohorts...</option>
              </select>
            </div>
            <div class="responsive-form-grid">
              <div class="form-group">
                <label>Sub-Milestone Name</label>
                <input type="text" id="sm_name" required>
              </div>
              <div class="form-group">
                <label>Parent Milestone (Optional)</label>
                <select id="sm_parent"><option value="">None</option></select>
              </div>
            </div>
            <div class="form-group">
              <label>Due Date</label>
              <input type="date" id="sm_date" required>
            </div>
            <div class="form-group">
              <label>Description</label>
              <textarea id="sm_desc" rows="3"></textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="background: var(--color-primary); color: #fff;">Create Sub-Milestone</button>
            <div id="sm_alert" style="margin-top: 10px; color: var(--color-primary); font-weight: bold;"></div>
          </form>
        </div>
        <div class="sup-card" style="margin-top: 20px;">
          <h2>Existing Milestones</h2>
          <div id="existingMilestonesContainer">
            <p class="text-muted">Select a cohort above to view milestones.</p>
          </div>
        </div>
      </section>

    </main>
  </div>

  <script src="js/supervisor.js"></script>
  <script src="js/session-guard.js"></script>
  <script src="js/sidebar.js"></script>
</body>
</html>






