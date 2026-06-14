/**
 * CUEA FYPM - Student Dashboard JS
 */

'use strict';

const originalFetch = window.fetch;
window.fetch = async function () {
  const res = await originalFetch.apply(this, arguments);
  if (res.status === 401 || res.status === 403) {
    const cloned = res.clone();
    try {
      const data = await cloned.json();
      const message = (data.message || '').toLowerCase();
      if (data.status === 401 || message.includes('unauthorized') || message.includes('session')) {
        const reason = data?.data?.reason || (res.status === 403 ? 'role_changed' : 'session_expired');
        window.location.href = `index.html?reason=${encodeURIComponent(reason)}`;
      }
    } catch (e) { }
  }
  return res;
};

document.addEventListener('DOMContentLoaded', async () => {
  const mainContent = document.querySelector('.content-body');
  const notifToggleBtn = document.getElementById('notifToggleBtn');
  const notifDropdown = document.getElementById('notifDropdown');
  const notifList = document.getElementById('notifList');
  const notifBadge = document.getElementById('notifBadge');

  let dashboardData = null;

  try {
    const [dashboardRes, sessionRes] = await Promise.all([
      fetch('php/api/dashboard.php'),
      fetch('php/api/auth.php?action=session')
    ]);
    const result = await dashboardRes.json();
    const sessionData = await sessionRes.json();

    if (!dashboardRes.ok || result.status !== 200) {
      throw new Error(result.message || 'Failed to load dashboard data');
    }
    if (!sessionRes.ok || sessionData.status !== 200) {
      throw new Error(sessionData.message || 'Failed to load session data');
    }

    dashboardData = result.data;
    renderDashboard(dashboardData, sessionData.data);
  } catch (err) {
    console.error(err);
    mainContent.innerHTML = `
      <div class="alert alert-error show">
        <strong>Error:</strong> ${escapeHtml(err.message)}. Please try refreshing the page or contact IT Support.
      </div>
    `;
  }

  function renderDashboard(data, sessionUser) {
    const studentName = sessionUser.full_name || 'Student';
    const firstName = studentName.split(' ')[0];

    document.querySelector('.sidebar-footer img.avatar').src = `https://ui-avatars.com/api/?name=${encodeURIComponent(studentName)}&background=D4A017&color=1A1A1A`;
    document.querySelector('.sidebar-footer .user-info .name').textContent = studentName;
    document.querySelector('.sidebar-footer .user-info .id').textContent = `Student ID: ${sessionUser.university_id_number || ''}`;

    const banner = document.getElementById('welcomeBanner');
    if (banner) {
      banner.innerHTML = `
        <h2>Welcome back, ${escapeHtml(firstName)}!</h2>
        <p>Manage your submissions and track your milestone progress below.</p>
      `;
    }

    renderDefenseFlag(data.defense_flag);
    renderSupervisor(data.project);
    renderProjectDetails(data.project, data.progress);
    renderTimeline(data.milestones || [], data.progress);
    renderProgressCards(data.milestones || []);
    renderFeedback(data.feedback || []);
    renderActions(data.notifications || []);
    renderFiles(data.files || []);
    renderNotificationDropdown(data.notifications || []);
    initSupervisorRequest(data.project);
  }

  function renderDefenseFlag(flag) {
    if (!flag) return;
    const banner = document.getElementById('welcomeBanner');
    if (!banner) return;
    const notice = document.createElement('div');
    notice.className = 'alert alert-warning show';
    notice.style.marginTop = '12px';
    notice.textContent = flag.message || 'Defense deadline missed. Await the next configured Defense cycle.';
    banner.appendChild(notice);
  }

  function renderSupervisor(project) {
    const sName = document.getElementById('supervisorNameDisplay');
    const sEmail = document.getElementById('supervisorEmailDisplay');
    const supervisorBanner = document.getElementById('supervisorAssignmentBanner');

    const name = project.supervisor_name || 'Not Assigned';
    const email = project.supervisor_email || '';

    if (sName) sName.textContent = name;
    if (sEmail) {
      sEmail.textContent = email || 'Not available';
      sEmail.href = email ? `mailto:${email}` : '#';
    }

    if (supervisorBanner && project.supervisor_name) {
      supervisorBanner.className = 'alert alert-info show supervisor-alert';
      supervisorBanner.innerHTML = `<strong>Supervisor assigned:</strong> ${escapeHtml(name)}${email ? `, ${escapeHtml(email)}` : ''}`;
    }
  }

  function renderProjectDetails(project, progress) {
    const titleInput = document.getElementById('projectTitleInput');
    const abstractInput = document.getElementById('projectAbstractInput');
    const statusBadge = document.getElementById('projectStatusBadge');
    const saveBtn = document.getElementById('saveProjectBtn');
    const progressBar = document.getElementById('overallProgressBar');
    const progressText = document.getElementById('overallProgressText');
    const progressMeta = document.getElementById('progressMeta');

    titleInput.value = project.title || 'Untitled Project';
    abstractInput.value = project.abstract || project.description || '';

    const status = progress?.project_status || project.status || 'draft';
    statusBadge.textContent = status.replace(/_/g, ' ').toUpperCase();
    statusBadge.className = `badge ${badgeClassForStatus(status)}`;

    const canEdit = Boolean(progress?.can_edit_project);
    titleInput.disabled = !canEdit;
    abstractInput.disabled = !canEdit;
    saveBtn.disabled = !canEdit;
    saveBtn.textContent = canEdit ? 'Save Project Details' : 'Locked at Current Status';

    const pct = Number(progress?.percent || 0);
    progressBar.style.width = `${pct}%`;
    progressText.textContent = `${pct}%`;
    progressMeta.textContent = `${progress?.completed_milestones || 0} of ${progress?.total_milestones || 0} milestones approved`;
  }

  function renderTimeline(milestones) {
    const timelineContainer = document.getElementById('milestoneTimelineContainer');
    if (!timelineContainer) return;

    if (milestones.length === 0) {
      timelineContainer.innerHTML = '<div class="empty-state">No current milestones</div>';
      return;
    }

    timelineContainer.innerHTML = milestones.map((m) => {
      const state = milestoneState(m);
      return `
        <article class="timeline-card ${state.className}">
          <div class="timeline-card-header">
            <span class="timeline-type">${labelForType(m.type)}</span>
            <span class="timeline-status">${state.label}</span>
          </div>
          <h4>${escapeHtml(m.name || 'Milestone')}</h4>
          <p>${escapeHtml(m.description || 'No description provided.')}</p>
          ${m.instruction_file_path ? `
            <a href="${escapeAttribute(m.instruction_file_path)}" target="_blank" class="btn btn-outline" style="width:max-content;padding:6px 10px;margin-top:8px;">
              Instruction File
            </a>
          ` : ''}
          <div class="timeline-meta">
            <span>Due ${formatDate(m.due_date)}</span>
            ${m.submitted_at ? `<span>Updated ${formatDate(m.submitted_at)}</span>` : ''}
          </div>
          ${m.is_locked ? `<div class="locked-note">${escapeHtml(m.locked_reason || 'Locked: Complete previous milestone first.')}</div>` : ''}
        </article>
      `;
    }).join('');
  }

  function renderProgressCards(milestones) {
    const milestoneContainer = document.querySelector('.milestone-cards');
    if (!milestoneContainer) return;

    if (milestones.length === 0) {
      milestoneContainer.innerHTML = '<p class="text-sm text-muted">No current milestones</p>';
      return;
    }

    // Initialize with collapsed state if there are more than 4 items
    if (milestones.length > 4) {
      milestoneContainer.classList.add('collapsed');
      milestoneContainer.classList.remove('expanded');
    } else {
      milestoneContainer.classList.remove('collapsed', 'expanded');
    }

    const viewAllLink = document.querySelector('.milestones-header a');
    if (viewAllLink) {
      viewAllLink.style.display = milestones.length > 4 ? 'inline-block' : 'none';
      viewAllLink.textContent = 'View All';
    }

    milestoneContainer.innerHTML = milestones.map((m) => {
      const pct = percentForMilestone(m.submission_status);
      const color = colorForMilestone(m);
      const locked = m.is_locked ? '<span class="text-xs text-muted">Locked</span>' : '';
      return `
        <div class="milestone-mini-card">
          <div class="circular-progress" style="background: conic-gradient(${color} ${pct * 3.6}deg, #E5E7EB 0deg);">
            <span class="progress-value">${pct}%</span>
          </div>
          <div class="milestone-info">
            <span class="title">${escapeHtml(m.name || 'Milestone')}</span>
            <span class="status" style="color: ${color}">${escapeHtml(statusLabel(m.submission_status))}</span>
            <span class="text-xs text-muted">Due: ${formatDate(m.due_date)}</span>
            ${locked}
          </div>
        </div>
      `;
    }).join('');
  }

  function renderFeedback(feedback) {
    const feedbackBox = document.querySelector('.feedback-box');
    if (!feedbackBox) return;

    if (feedback.length === 0) {
      feedbackBox.style.display = 'none';
      return;
    }

    const latest = feedback[0];
    const comment = latest.supervisor_feedback || latest.coordinator_feedback || '';
    feedbackBox.querySelector('.feedback-content').innerHTML = `
      <strong>Latest Feedback on ${escapeHtml(latest.milestone_name || 'Milestone')}</strong>
      <p>"${escapeHtml(comment)}"</p>
    `;
  }

  function renderActions(notifications) {
    const actionCard = document.querySelector('.action-card');
    if (!actionCard) return;

    const button = actionCard.querySelector('button');
    actionCard.querySelectorAll('.action-item, .text-sm.text-muted').forEach((item) => item.remove());

    if (notifications.length === 0) {
      const emptyMsg = document.createElement('p');
      emptyMsg.className = 'text-sm text-muted';
      emptyMsg.textContent = 'No pending actions.';
      actionCard.insertBefore(emptyMsg, button);
      return;
    }

    notifications.forEach((n) => {
      const item = document.createElement('div');
      const urgent = n.type === 'reminder';
      item.className = `action-item ${urgent ? 'urgent' : ''}`;
      item.innerHTML = `
        ${urgent ? '<span class="action-tag urgent">URGENT</span>' : ''}
        <div class="action-content">
          <span class="title">${escapeHtml(n.type.replace(/_/g, ' ').toUpperCase())}</span>
          <span class="desc">${escapeHtml(n.message)}</span>
        </div>
      `;
      actionCard.insertBefore(item, button);
    });
  }

  function renderFiles(files) {
    const fileList = document.querySelector('.file-list');
    if (!fileList) return;

    const uploadFiles = files.filter((file) => Boolean(file.file_path));
    if (uploadFiles.length === 0) {
      fileList.innerHTML = '<p class="text-sm text-muted">No files uploaded yet.</p>';
      return;
    }

    fileList.innerHTML = uploadFiles.map((f) => {
      const fileName = (f.file_path || '').split('/').pop() || 'Submitted file';
      return `
        <div class="file-row">
          <div class="file-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14 2 14 8 20 8"></polyline>
            </svg>
          </div>
          <div class="file-details">
            <span class="font-bold text-sm">${escapeHtml(fileName)}</span>
            <span class="text-xs text-muted">${escapeHtml(f.type || 'Milestone')} - ${formatDate(f.submitted_at)}</span>
          </div>
          <a href="${escapeAttribute(f.file_path)}" target="_blank" class="btn btn-outline file-download-btn" aria-label="Open submitted file">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="7 10 12 15 17 10"></polyline>
              <line x1="12" y1="15" x2="12" y2="3"></line>
            </svg>
          </a>
        </div>
      `;
    }).join('');
  }

  function renderNotificationDropdown(notifications) {
    if (notifications.length > 0) {
      if (notifBadge) notifBadge.style.display = 'block';
      if (notifList) {
        notifList.innerHTML = notifications.map((n) => `
          <div class="notification-row">
            <strong>${escapeHtml(n.type.replace(/_/g, ' ').toUpperCase())}</strong>
            <span>${escapeHtml(n.message)}</span>
          </div>
        `).join('');
      }
    } else if (notifList) {
      notifList.innerHTML = '<p class="text-sm text-muted">No new notifications.</p>';
    }
  }

  document.getElementById('projectDetailsForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();

    const alertBox = document.getElementById('projectUpdateAlert');
    const saveBtn = document.getElementById('saveProjectBtn');
    const payload = {
      title: document.getElementById('projectTitleInput').value.trim(),
      abstract: document.getElementById('projectAbstractInput').value.trim()
    };

    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';
    alertBox.className = 'alert';
    alertBox.textContent = '';

    try {
      const res = await fetch('php/api/dashboard.php?action=update_project_details', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const json = await res.json();
      if (!res.ok || json.status !== 200) {
        throw new Error(json.message || 'Failed to update project details.');
      }
      dashboardData.project.title = payload.title;
      dashboardData.project.abstract = payload.abstract;
      alertBox.className = 'alert alert-success show';
      alertBox.textContent = 'Project details updated successfully.';
    } catch (err) {
      alertBox.className = 'alert alert-error show';
      alertBox.textContent = err.message;
    } finally {
      saveBtn.disabled = false;
      saveBtn.textContent = dashboardData?.progress?.can_edit_project ? 'Save Project Details' : 'Locked at Current Status';
    }
  });

  async function initSupervisorRequest(project) {
    const form = document.getElementById('supervisorRequestForm');
    const select = document.getElementById('requestedSupervisorSelect');
    const alertBox = document.getElementById('supervisorRequestAlert');
    const submitBtn = document.getElementById('submitSupervisorRequestBtn');

    // Collapsible header logic
    const requestHeader = document.getElementById('toggleSupervisorRequestHeader');
    const requestContent = document.getElementById('supervisorRequestFormContent');
    const requestIcon = document.getElementById('toggleRequestIcon');

    if (requestHeader && requestContent && requestIcon) {
      requestHeader.addEventListener('click', () => {
        const isHidden = requestContent.style.display === 'none';
        requestContent.style.display = isHidden ? 'block' : 'none';
        requestIcon.textContent = isHidden ? '[-] Collapse' : '[+] Expand';
      });
    }

    if (!form || !select || !project.supervisor_id) {
      document.querySelector('.supervisor-request-card')?.remove();
      return;
    }

    try {
      const [availableRes, statusRes] = await Promise.all([
        fetch('php/api/student_supervisors.php?action=get_available'),
        fetch('php/api/student_supervisors.php?action=change_request_status')
      ]);
      const availableJson = await availableRes.json();
      const statusJson = await statusRes.json();

      if (availableRes.ok && availableJson.status === 200) {
        const supervisors = availableJson.data.supervisors || [];
        select.innerHTML = '<option value="">No specific preference</option>' + supervisors
          .filter((sup) => String(sup.user_id) !== String(project.supervisor_id) && !sup.is_full)
          .map((sup) => `<option value="${escapeAttribute(sup.user_id)}">${escapeHtml(sup.full_name)} (${escapeHtml(sup.department || 'Faculty')})</option>`)
          .join('');
      }

      const latest = statusJson?.data || {};
      if (latest.status === 'pending') {
        alertBox.className = 'alert alert-info show';
        alertBox.textContent = 'You already have a pending supervisor change request.';
        form.querySelectorAll('select, textarea, button').forEach((el) => { el.disabled = true; });
      }
    } catch (err) {
      alertBox.className = 'alert alert-error show';
      alertBox.textContent = 'Could not load supervisor request options.';
    }

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      submitBtn.disabled = true;
      submitBtn.textContent = 'Submitting...';
      alertBox.className = 'alert';
      alertBox.textContent = '';

      try {
        const payload = {
          requested_supervisor_id: select.value,
          reason: document.getElementById('supervisorRequestReason').value.trim()
        };
        const res = await fetch('php/api/student_supervisors.php?action=request_change', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (!res.ok || ![200, 201].includes(json.status)) {
          throw new Error(json.message || 'Failed to submit supervisor change request.');
        }
        alertBox.className = 'alert alert-success show';
        alertBox.textContent = 'Supervisor change request submitted for Coordinator review.';
        form.reset();
        form.querySelectorAll('select, textarea, button').forEach((el) => { el.disabled = true; });
      } catch (err) {
        alertBox.className = 'alert alert-error show';
        alertBox.textContent = err.message;
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit Request';
      }
    });
  }

  if (notifToggleBtn && notifDropdown) {
    notifToggleBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      notifDropdown.style.display = notifDropdown.style.display === 'none' ? 'block' : 'none';
      if (notifBadge) notifBadge.style.display = 'none';
    });

    document.addEventListener('click', (e) => {
      if (!notifDropdown.contains(e.target) && e.target !== notifToggleBtn) {
        notifDropdown.style.display = 'none';
      }
    });
  }
});

function milestoneState(milestone) {
  const status = milestone.submission_status;
  if (['supervisor_approved', 'coordinator_approved', 'approved', 'satisfactory'].includes(status)) {
    return { label: 'Completed', className: 'is-complete' };
  }
  if (status === 'cleared_for_defense') return { label: 'Cleared for Defense', className: 'is-active' };
  if (milestone.is_locked) return { label: 'Locked', className: 'is-locked' };
  if (status === 'satisfactory_with_corrections') return { label: 'Satisfactory with Corrections', className: 'is-revision' };
  if (status === 'fail_redo') return { label: 'Fail - Redo', className: 'is-revision' };
  if (status === 'revision_required') return { label: 'Revision Required', className: 'is-revision' };
  if (status === 'rejected') return { label: 'Rejected', className: 'is-revision' };
  if (status === 'submitted') return { label: 'Under Review', className: 'is-active' };
  if (milestone.due_date && new Date(milestone.due_date) < new Date()) {
    return { label: 'Overdue', className: 'is-overdue' };
  }
  return { label: 'Pending', className: 'is-pending' };
}

function percentForMilestone(status) {
  if (['supervisor_approved', 'coordinator_approved', 'approved', 'satisfactory'].includes(status)) return 100;
  if (['submitted', 'cleared_for_defense'].includes(status)) return 50;
  if (['revision_required', 'satisfactory_with_corrections', 'fail_redo'].includes(status)) return 25;
  return 0;
}

function colorForMilestone(milestone) {
  const state = milestoneState(milestone);
  if (state.className === 'is-complete') return 'var(--color-success)';
  if (state.className === 'is-revision') return 'var(--color-error)';
  if (state.className === 'is-active' || state.className === 'is-overdue') return 'var(--color-warning)';
  return '#E5E7EB';
}

function statusLabel(status) {
  return status ? status.replace(/_/g, ' ') : 'Pending';
}

function labelForType(type) {
  if (type === 'proposal') return 'Proposal';
  if (type === 'sub_milestone') return 'Sub Milestone';
  if (type === 'defense') return 'Project Defense';
  return 'Milestone';
}

function badgeClassForStatus(status) {
  const map = {
    draft: 'badge-draft',
    revision_required: 'badge-revision',
    supervisor_review: 'badge-submitted',
    coordinator_review: 'badge-submitted',
    approved: 'badge-approved',
    completed: 'badge-approved'
  };
  return map[status] || 'badge-draft';
}

function formatDate(value) {
  if (!value) return 'N/A';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return 'N/A';
  return date.toLocaleDateString();
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function escapeAttribute(value) {
  return escapeHtml(value).replace(/`/g, '&#096;');
}
