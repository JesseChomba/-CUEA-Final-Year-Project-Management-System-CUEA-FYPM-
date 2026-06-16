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
  <title>Student Dashboard – CUEA FYPM</title>
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/dashboard.css">
</head>
<body class="dashboard-layout">

  <!--  SIDEBAR NAVIGATION  -->
  <?php renderSidebar($currentUser, 'dashboard'); ?>
<!--  MAIN CONTENT  -->
  <div class="main-wrapper">
    <header class="top-bar">
      <div class="top-bar-title">Final Year Project Management System</div>
      <div class="top-bar-actions">
        <div style="position: relative;">
          <button class="nav-icon-btn" title="Notifications" id="notifToggleBtn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
              <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
            </svg>
            <span class="badge-dot" id="notifBadge" style="display:none;"></span>
          </button>
          <div id="notifDropdown" style="display:none; position:absolute; top:40px; right:0; background:#fff; border:1px solid var(--color-border); border-radius:var(--radius); width:300px; box-shadow:var(--shadow-md); z-index:100; padding:12px;">
             <h4 style="margin:0 0 10px 0; padding-bottom:8px; border-bottom:1px solid var(--color-border); font-size: 0.9rem;">Notifications</h4>
             <div id="notifList" style="max-height: 300px; overflow-y: auto;"></div>
          </div>
        
    </header>

    <main class="content-body">
      
      <!-- Welcome Banner -->
      <section class="welcome-banner" id="welcomeBanner">
        <h2>Welcome back!</h2>
        <p>Loading your project dashboard...</p>
      </section>

      <div id="supervisorAssignmentBanner" class="alert alert-info supervisor-alert"></div>

      <!-- Project Details Panel -->
      <section class="project-details-card">
        <div class="project-details-grid">
          <form id="projectDetailsForm" class="project-edit-form">
            <div class="section-heading-row">
              <h3>Project Details</h3>
              <span id="projectStatusBadge" class="badge badge-draft">DRAFT</span>
            </div>
            <div id="projectUpdateAlert" class="alert"></div>
            <div class="form-group">
              <label class="form-label" for="projectTitleInput">Project Title</label>
              <input id="projectTitleInput" class="form-input" type="text" required>
            </div>
            <div class="form-group">
              <label class="form-label" for="projectAbstractInput">Abstract Summary</label>
              <textarea id="projectAbstractInput" class="form-input" rows="5" placeholder="Add a concise project abstract."></textarea>
            </div>
            <button class="btn btn-primary" id="saveProjectBtn" type="submit">Save Project Details</button>
          </form>

          <div class="project-side-panel">
            <div class="supervisor-card">
              <h3>Supervisor Details</h3>
              <p class="detail-label">Name</p>
              <p id="supervisorNameDisplay" class="detail-value">Loading...</p>
              <p class="detail-label">Email</p>
              <a id="supervisorEmailDisplay" class="detail-value detail-link" href="#">Loading...</a>
            </div>
            <div class="progress-card">
              <div class="section-heading-row">
                <h3>Progress</h3>
                <strong id="overallProgressText">0%</strong>
              </div>
              <div class="progress-bar-container">
                <div id="overallProgressBar" class="progress-bar-fill"></div>
              </div>
              <p id="progressMeta" class="text-sm text-muted">Loading milestones...</p>
            </div>
            <div class="supervisor-request-card">
              <div class="section-heading-row" style="margin-bottom: 0; cursor: pointer;" id="toggleSupervisorRequestHeader">
                <h3 style="margin-bottom: 0;">Request New Supervisor</h3>
                <span class="toggle-icon" id="toggleRequestIcon" style="transition: transform 0.2s; font-size: 0.8rem; font-weight: bold; color: var(--color-primary);">[+] Expand</span>
              </div>
              <div id="supervisorRequestFormContent" style="display: none; margin-top: 16px;">
                <p class="text-sm text-muted" style="margin-bottom: 12px;">Submit a change request for Coordinator review.</p>
                <div id="supervisorRequestAlert" class="alert"></div>
                <form id="supervisorRequestForm">
                  <div class="form-group">
                    <label class="form-label" for="requestedSupervisorSelect">Preferred Supervisor</label>
                    <select id="requestedSupervisorSelect" class="form-input">
                      <option value="">No specific preference</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="supervisorRequestReason">Reason</label>
                    <textarea id="supervisorRequestReason" class="form-input" rows="4" required></textarea>
                  </div>
                  <button type="submit" class="btn btn-outline btn-full" id="submitSupervisorRequestBtn">Submit Request</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Real-time Milestone Timeline -->
      <section class="tracker-card" style="position: relative; margin-bottom: 24px; padding: 24px; background: #fff; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
        <div class="carousel-nav">
          <button id="milestonePrevBtn" class="carousel-btn" aria-label="Previous Milestones" disabled>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="15 18 9 12 15 6"></polyline>
            </svg>
          </button>
          <button id="milestoneNextBtn" class="carousel-btn" aria-label="Next Milestones">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="9 18 15 12 9 6"></polyline>
            </svg>
          </button>
        </div>
        <h3 class="tracker-title" style="margin-top: 0; margin-bottom: 16px; font-size: 1.1rem; color: var(--color-text); border-bottom: 1px solid var(--color-border); padding-bottom: 12px; padding-right: 80px;">Cohort Milestone Timeline</h3>
        <div id="milestoneTimelineContainer" class="milestone-timeline">
          <!-- Populated by JS -->
          <p class="text-muted" style="width: 100%; text-align: center; padding: 20px;">Loading milestones...</p>
        </div>
      </section>

      <div class="dashboard-grid">
        
        <!-- Left Column -->
        <div class="grid-main">
          
          <!-- Milestone Progress -->
          <div class="milestones-section">
            <div class="milestones-header">
              <h3>Milestone Progress</h3>
              <a href="#" class="text-sm font-bold" style="color: var(--color-primary)">View All</a>
            </div>
            
            <div class="milestone-cards">
              <p class="text-sm text-muted">Loading milestone progress...</p>
            </div>

            <!-- Recent Feedback -->
            <div class="feedback-box">
              <div style="color: var(--color-info)">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <circle cx="12" cy="12" r="10"></circle>
                  <line x1="12" y1="16" x2="12" y2="12"></line>
                  <line x1="12" y1="8" x2="12.01" y2="8"></line>
                </svg>
              </div>
              <div class="feedback-content">
                <strong>Supervisor Comments</strong>
                <p>"Please expand on the sampling technique in Section 2.4. The current description is too vague."</p>
              </div>
            </div>
          </div>

          <!-- Recent Files -->
          <div class="recent-files-card">
            <div class="milestones-header" style="margin-bottom: 16px;">
              <h3>Recent Files</h3>
              <button class="nav-icon-btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <line x1="4" y1="21" x2="4" y2="14"></line>
                  <line x1="4" y1="10" x2="4" y2="3"></line>
                  <line x1="12" y1="21" x2="12" y2="12"></line>
                  <line x1="12" y1="8" x2="12" y2="3"></line>
                  <line x1="20" y1="21" x2="20" y2="16"></line>
                  <line x1="20" y1="12" x2="20" y2="3"></line>
                  <line x1="1" y1="14" x2="7" y2="14"></line>
                  <line x1="9" y1="8" x2="15" y2="8"></line>
                  <line x1="17" y1="16" x2="23" y2="16"></line>
                </svg>
              </button>
            </div>
            
            <div class="file-list">
              <p class="text-sm text-muted">Loading files...</p>
            </div>
          </div>
        </div>

        <!-- Right Column (Pending Actions) -->
        <aside class="grid-sidebar">
          <div class="action-card">
            <h3>Pending Actions</h3>
            <p class="text-sm text-muted">Loading actions...</p>

            <button class="btn btn-outline btn-full" style="border: none; color: var(--color-muted); font-size: 0.8rem; margin-top: 10px;">
              View System Logs &rarr;
            </button>
          </div>
        </aside>

      </div>
    </main>
  </div>

  <script src="js/dashboard.js"></script>
  <script src="js/dashboard-interactive.js"></script>
  <script src="js/session-guard.js"></script>
  <script src="js/sidebar.js"></script>
</body>
</html>







