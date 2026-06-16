<?php
require_once __DIR__ . '/includes/page_guard.php';
$currentUser = requirePageRole(['coordinator']);
require_once __DIR__ . '/includes/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Coordinator Portal – CUEA FYPM</title>
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/dashboard.css">
  <style>
    /* Tab System */
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    
    /* Coordinator specific utility styles */
    .admin-card {
      background: #fff;
      border-radius: var(--radius-lg);
      padding: 24px;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
      margin-bottom: 24px;
    }
    
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.9rem; }
    .form-group input, .form-group select {
      width: 100%; padding: 10px; border: 1px solid var(--color-border); border-radius: var(--radius-md);
    }
    
    table.data-table {
      width: 100%; border-collapse: collapse; margin-top: 16px;
    }
    table.data-table th, table.data-table td {
      padding: 12px; text-align: left; border-bottom: 1px solid var(--color-border);
    }
    table.data-table th { background: #F9FAFB; font-weight: 600; color: var(--color-text); }
  </style>
  </head>
<body class="dashboard-layout">

  <!--  SIDEBAR NAVIGATION  -->
  <?php renderSidebar($currentUser, 'overview'); ?>
<!--MAIN CONTENT  -->
  <div class="main-wrapper">
    <header class="top-bar">
      <div class="top-bar-title">Admin Hub</div>
    </header>

    <main class="content-body">
      
      <!-- System Overview Tab -->
      <section id="tab-overview" class="tab-content active">
        <div id="eagleMetrics" class="admin-card">
          <h2>Coordinator Metrics</h2>
          <div id="metricsGrid" style="display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap:16px; margin-top:16px;">
            <p class="text-muted">Loading metrics...</p>
          </div>
        </div>
        <div class="admin-card">
          <h2>View Logs</h2>
          <div id="coordinatorLogsContainer">Loading activity logs...</div>
        </div>
        <div class="admin-card">
          <h2>System Overview</h2>
          <p class="text-muted">Select a cohort and supervisor to inspect student milestone progress.</p>
          
          <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-top: 16px;">
            <div class="form-group">
              <label>Filter by Cohort</label>
              <select id="overviewCohortFilter">
                <option value="">Loading...</option>
              </select>
            </div>
            <div class="form-group">
              <label>Filter by Supervisor</label>
              <select id="overviewSupervisorFilter">
                <option value="">All Supervisors</option>
              </select>
            </div>
            <div class="form-group">
              <label>Filter by Milestone Status</label>
              <select id="overviewMilestoneStatusFilter">
                <option value="">All Statuses</option>
                <option value="no_submission">No Submission Yet</option>
                <option value="submitted">Submitted (Pending)</option>
                <option value="revision_required">Revision Required</option>
                <option value="supervisor_approved">Supervisor Approved</option>
                <option value="coordinator_approved">Coordinator Approved</option>
                <option value="satisfactory">Satisfactory</option>
                <option value="satisfactory_with_corrections">Satisfactory w/ Corrections</option>
                <option value="rejected">Rejected</option>
                <option value="cleared_for_defense">Cleared for Defense</option>
              </select>
            </div>
          </div>
          
          <div id="overviewResults" style="margin-top: 24px;">
            <!-- Rendered by JS -->
          </div>
        </div>
      </section>

      <!-- User Management Tab -->
      <section id="tab-users" class="tab-content">
        <div class="admin-card">
          <h2>Create New User</h2>
          <form id="createUserForm">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
              <div class="form-group">
                <label>Full Name</label>
                <input type="text" id="cu_name" required>
              </div>
              <div class="form-group">
                <label>Email</label>
                <input type="email" id="cu_email" required>
              </div>
              <div class="form-group">
                <label>Role</label>
                <select id="cu_role">
                  <option value="student">Student</option>
                  <option value="supervisor">Supervisor</option>
                </select>
              </div>
              <div class="form-group">
                <label>University ID Number </label>
                <input type="text" id="cu_uid" placeholder="Student ID for Students, Staff ID for Supervisors" required >
              </div>
              <div class="form-group">
                <label>Department</label>
                <select id="cu_department">
                  <option value="Computer Science">Computer Science</option>
                  <option value="Information Tech.">Information Tech.</option>
                  <option value="Software Eng.">Software Eng.</option>
                  <option value="Library Science">Library Science</option>
                </select>
              </div>
              <div class="form-group">
                <label>Password (Temporary)</label>
                <div style="position: relative; display: flex; align-items: center;">
                  <input type="password" id="cu_password" required value="Changeme123!" style="padding-right: 40px;">
                  <button type="button" id="toggleCuPwd" style="position: absolute; right: 10px; background: none; border: none; cursor: pointer; color: var(--color-muted);">
                    <svg id="cuEyeIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                      <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                    <svg id="cuEyeOffIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;">
                      <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                      <line x1="1" y1="1" x2="23" y2="23"></line>
                    </svg>
                  </button>
                </div>
              </div>
              <div class="form-group">
                <label>Assign to Cohort</label>
                <select id="cu_cohort" required>
                  <!-- Populated via JS -->
                </select>
              </div>
            </div>
            <button type="submit" class="btn btn-primary" style="background: var(--color-primary); color: #fff;">Create User</button>
            <div id="cu_alert" style="margin-top: 10px; color: var(--color-primary); font-weight: bold;"></div>
          </form>
        </div>
      </section>

      <!-- Cohort Management Tab -->
      <section id="tab-cohorts" class="tab-content">
        <div class="admin-card">
          <h2>Existing Cohorts</h2>
          <div id="cohortCrudContainer">Loading cohorts...</div>
        </div>
        <div class="admin-card">
          <h2>Create New Cohort</h2>
          <form id="createCohortForm">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
              <div class="form-group">
                <label>Cohort Name</label>
                <input type="text" id="cc_name" placeholder="e.g. Class of 2025" required>
              </div>
              <div class="form-group">
                <label>Max Students Per Supervisor</label>
                <input type="number" id="cc_max" value="12" required>
              </div>
              <div class="form-group">
                <label>Start Date</label>
                <input type="date" id="cc_start" required>
              </div>
              <div class="form-group">
                <label>End Date</label>
                <input type="date" id="cc_end" required>
              </div>
            </div>
            <button type="submit" class="btn btn-primary" style="background: var(--color-primary); color: #fff;">Create Cohort</button>
            <div id="cc_alert" style="margin-top: 10px; color: var(--color-primary); font-weight: bold;"></div>
          </form>
        </div>
      </section>

      <!-- Milestone Engine Tab -->
      <section id="tab-milestones" class="tab-content">
        <div class="admin-card">
          <h2>Existing Coordinator Milestones</h2>
          <div style="margin: 12px 0;">
            <select id="milestoneListCohortFilter"></select>
          </div>
          <div id="milestoneCrudContainer">Loading milestones...</div>
        </div>
        <div class="admin-card">
          <h2>Define Milestone</h2>
          <form id="createMilestoneForm">
            <div class="form-group">
              <label>Select Cohort</label>
              <select id="cm_cohort" required></select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
              <div class="form-group">
                <label>Milestone Name</label>
                <input type="text" id="cm_name" required>
              </div>
              <div class="form-group">
                <label>Type</label>
                <select id="cm_type">
                  <option value="proposal">Proposal</option>
                  <option value="standard_milestone" selected>Standard Milestone</option>
                  <option value="sub_milestone">Sub-Milestone</option>
                  <option value="defense">Project Defense</option>
                </select>
              </div>
              <div class="form-group">
                <label>Due Date</label>
                <input type="date" id="cm_date" required>
              </div>
              <div class="form-group">
                <label>Parent Milestone (Optional)</label>
                <select id="cm_parent"><option value="">None</option></select>
              </div>
            </div>
            <div class="form-group">
              <label>Description</label>
              <textarea id="cm_desc" style="width: 100%; border: 1px solid var(--color-border); padding: 10px; border-radius: var(--radius-md);" rows="3"></textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="background: var(--color-primary); color: #fff;">Create Milestone</button>
            <div id="cm_alert" style="margin-top: 10px; color: var(--color-primary); font-weight: bold;"></div>
          </form>
        </div>
        <div class="admin-card">
          <h2>Milestone Templates & Defense</h2>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
            <form id="templateCreateForm">
              <h3>Create Template</h3>
              <div class="form-group">
                <label>Template Name</label>
                <input type="text" id="templateName" required>
              </div>
              <div class="form-group">
                <label>Items, one per line</label>
                <textarea id="templateItems" rows="5" placeholder="Proposal&#10;Chapter Draft&#10;Project Defense" required></textarea>
              </div>
              <button class="btn btn-primary" type="submit" style="background: var(--color-primary); color:#fff;">Save Template</button>
            </form>
            <form id="templateApplyForm">
              <h3>Apply Template / Defense</h3>
              <div class="form-group">
                <label>Template</label>
                <select id="templateSelect"></select>
              </div>
              <div class="form-group">
                <label>Cohort</label>
                <select id="templateCohort"></select>
              </div>
              <div class="form-group">
                <label>Default Due Date For Template Items</label>
                <input type="date" id="templateDefaultDate">
              </div>
              <button class="btn btn-outline" type="submit">Apply Template</button>
              <div style="height:1px;background:var(--color-border);margin:14px 0;"></div>
              <div class="form-group">
                <label>Defense Deadline</label>
                <input type="date" id="defenseDeadline">
              </div>
              <button class="btn btn-primary" id="saveDefenseDeadlineBtn" type="button" style="background: var(--color-primary); color:#fff;">Save Defense Deadline</button>
            </form>
          </div>
          <div id="templateAlert" style="margin-top:10px;font-weight:700;"></div>
        </div>
      </section>

      <!-- Allocation Dashboard Tab -->
      <section id="tab-allocations" class="tab-content">
        <div class="admin-card">
          <h2>Global Workload Setting</h2>
          <form id="settingsForm" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <div class="form-group" style="min-width:240px;">
              <label>Max Total Students Per Supervisor</label>
              <input type="number" min="1" id="settingMaxTotalStudents" required>
            </div>
            <button class="btn btn-primary" type="submit" style="background: var(--color-primary); color:#fff;">Save Setting</button>
          </form>
          <div id="settingsAlert" style="margin-top:10px; font-weight:700;"></div>
        </div>

        <div class="admin-card">
          <h2>Supervisor Cohort Access</h2>
          <form id="supervisorCohortForm" style="display:grid; grid-template-columns:1fr 1fr auto; gap:12px; align-items:end;">
            <div class="form-group">
              <label>Supervisor</label>
              <select id="scSupervisor"></select>
            </div>
            <div class="form-group">
              <label>Cohort</label>
              <select id="scCohort"></select>
            </div>
            <button class="btn btn-primary" type="submit" style="background: var(--color-primary); color:#fff;">Allow Cohort</button>
          </form>
          <div id="supervisorCohortAlert" style="margin-top:10px; font-weight:700;"></div>
        </div>

        <div class="admin-card">
          <h2>Supervisor Change Requests</h2>
          <div id="transferRequestsContainer">Loading transfer requests...</div>
        </div>

        <div class="admin-card">
          <h2>Supervisor Allocations</h2>
          <p class="text-muted">Review supervisor loads and manual allocations.</p>
          <div id="allocationContainer">
            <!-- Populated via JS -->
          </div>
        </div>

        <div class="admin-card">
          <h2>Pending Allocations</h2>
          <p class="text-muted">Students who have submitted their preferences but are not yet assigned a supervisor.</p>
          <div id="pendingAllocationsContainer">
            Loading...
          </div>
        </div>

        <div class="admin-card">
          <h2>Manual Student Transfer</h2>
          <p class="text-muted">Search by admission number and move an already assigned student from one supervisor to another.</p>
          <form id="manualTransferSearchForm" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end; margin:12px 0;">
            <div class="form-group" style="min-width:260px; flex:1;">
              <label>Admission Number</label>
              <input type="search" id="manualTransferSearchInput" placeholder="Search admission number">
            </div>
            <button class="btn btn-primary" type="submit" style="background: var(--color-primary); color:#fff;">Search</button>
          </form>
          <div id="manualTransferContainer">Loading projects...</div>
        </div>
      </section>

    </main>
  </div>

  <script src="js/coordinator.js"></script>
  <script src="js/session-guard.js"></script>
  <script src="js/sidebar.js"></script>
</body>
</html>



