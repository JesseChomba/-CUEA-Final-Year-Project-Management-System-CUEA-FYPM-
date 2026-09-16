/**
 * CUEA FYPM – Supervisor Portal JS
 */

'use strict';

// Global fetch interceptor for session expiration
const originalFetch = window.fetch;
window.fetch = async function () {
    const res = await originalFetch.apply(this, arguments);
    if (res.status === 401 || res.status === 403) {
        const cloned = res.clone();
        try {
            const data = await cloned.json();
            if (data.status === 401 || data.message.toLowerCase().includes('unauthorized') || data.message.toLowerCase().includes('session')) {
                const reason = data?.data?.reason || (res.status === 403 ? 'role_changed' : 'session_expired');
                window.location.href = `index.html?reason=${encodeURIComponent(reason)}`;
            }
        } catch (e) { }
    }
    return res;
};

document.addEventListener('DOMContentLoaded', async () => {
    let superviseesCache = [];
    //  Tab Switching 
    const navItems = document.querySelectorAll('.sidebar-nav .nav-item[data-tab]');
    const tabContents = document.querySelectorAll('.tab-content');

    navItems.forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();

            navItems.forEach(n => n.classList.remove('active'));
            tabContents.forEach(t => t.classList.remove('active'));

            item.classList.add('active');
            const tabId = item.getAttribute('data-tab');
            document.getElementById(`tab-${tabId}`).classList.add('active');

            if (tabId === 'supervisees') {
                loadDashboardSummary();
                loadSupervisees();
            }
        });
    });

    const requestedTab = new URLSearchParams(window.location.search).get('tab');
    if (requestedTab) {
        const requestedItem = document.querySelector(`.sidebar-nav .nav-item[data-tab="${CSS.escape(requestedTab)}"]`);
        if (requestedItem) requestedItem.click();
    }

    //  Initial Load Data 
    const sessionRes = await fetch('php/api/auth.php?action=session');
    const sessionData = await sessionRes.json();
    const supName = sessionData.data.full_name || 'Supervisor';

    // Update avatar and name
    const avatarUrl = `https://ui-avatars.com/api/?name=${encodeURIComponent(supName)}&background=D4A017&color=1A1A1A`;
    document.querySelector('.sidebar-footer img.avatar').src = avatarUrl;
    document.querySelector('.sidebar-footer .user-info .name').textContent = supName;

    loadDashboardSummary();
    loadActivityLogs();
    loadSupervisees();

    document.getElementById('submissionCohortFilter')?.addEventListener('change', () => {
        loadDashboardSummary();
        renderProjectOptions(superviseesCache);
        document.getElementById('submissionsContainer').innerHTML = '<p class="text-muted">Select a project above to view submissions.</p>';
    });

    // Populate supervisee cohort filter 
    (async function loadSuperviseeCohortFilter() {
        try {
            const res = await fetch('php/api/supervisor_api.php?action=get_cohorts');
            const json = await res.json();
            const sel = document.getElementById('superviseeCohortFilter');
            if (sel && json.status === 200 && json.data.length > 0) {
                sel.innerHTML = '<option value="">All Cohorts</option>' +
                    json.data.map(c => `<option value="${c.cohort_id}">${escapeHtml(c.name)}</option>`).join('');
            }
        } catch (e) { /* non-fatal */ }
    })();

    document.getElementById('superviseeCohortFilter')?.addEventListener('change', loadSupervisees);

    async function loadDashboardSummary() {
        const container = document.getElementById('supervisorSummaryContainer');
        if (!container) return;
        const cohortId = document.getElementById('submissionCohortFilter')?.value || '';
        try {
            const res = await fetch(`php/api/supervisor_api.php?action=get_dashboard_summary&cohort_id=${encodeURIComponent(cohortId)}`);
            const json = await res.json();
            if (!res.ok || json.status !== 200) throw new Error(json.message || 'Unable to load dashboard summary.');
            const recent = json.data.recent_files || [];
            const metrics = json.data.cohort_metrics || [];
            container.innerHTML = `
                <div class="responsive-metrics-grid">
                    <div class="metric-card"><strong>${json.data.pending_actions || 0}</strong><span>Pending Actions</span></div>
                    <div class="metric-card"><strong>${recent.length}</strong><span>Recent Files</span></div>
                    <div class="metric-card"><strong>${metrics.length}</strong><span>Assigned Cohorts</span></div>
                </div>
                <div class="responsive-form-grid" style="margin-top:16px;">
                    <div>
                        <h3 style="font-size:1rem;margin-bottom:8px;">Recent Files</h3>
                        ${recent.length ? recent.map(f => `
                            <div style="padding:8px 0;border-bottom:1px solid var(--color-border);">
                                <strong>${escapeHtml(f.student_name)}</strong><br>
                                <a href="${escapeAttr(f.file_path)}" target="_blank">${escapeHtml(f.milestone_name)}</a>
                                <small class="text-muted"> - ${escapeHtml(f.cohort_name)}</small>
                            </div>
                        `).join('') : '<p class="text-muted">No recent files.</p>'}
                    </div>
                    <div>
                        <h3 style="font-size:1rem;margin-bottom:8px;">Cohort Metrics</h3>
                        ${metrics.length ? metrics.map(m => `
                            <div style="padding:8px 0;border-bottom:1px solid var(--color-border);">
                                <strong>${escapeHtml(m.name)}</strong>
                                <span class="text-muted">${m.active_projects || 0} projects, ${m.pending_submissions || 0} pending</span>
                            </div>
                        `).join('') : '<p class="text-muted">No cohort metrics.</p>'}
                    </div>
                </div>
            `;
        } catch (e) {
            container.innerHTML = '<p>Error loading dashboard summary.</p>';
        }
    }

    //  Activity Logs (collapsible + paginated) 
    let allLogsCache_sup = [];
    let logsPage_sup = 1;
    const LOGS_PER_PAGE_SUP = 10;
    let logsExpanded_sup = false;

    async function loadActivityLogs() {
        const container = document.getElementById('supervisorLogsContainer');
        if (!container) return;
        try {
            const res = await fetch('php/api/supervisor_api.php?action=get_activity_logs');
            const json = await res.json();
            allLogsCache_sup = json.data || [];
            logsPage_sup = 1;
            renderLogsUI_sup();
        } catch (e) {
            document.getElementById('supervisorLogsContainer').innerHTML =
                '<p class="text-muted">Unable to load logs.</p>';
        }
    }

    function renderLogsUI_sup() {
        const container = document.getElementById('supervisorLogsContainer');
        if (!container) return;
        const logs = allLogsCache_sup;

        if (logs.length === 0) {
            container.innerHTML = '<p class="text-muted">No recent logs.</p>';
            return;
        }

        const totalPages = Math.ceil(logs.length / LOGS_PER_PAGE_SUP);
        const start = (logsPage_sup - 1) * LOGS_PER_PAGE_SUP;
        const pageLogs = logs.slice(start, start + LOGS_PER_PAGE_SUP);

        const actionTypeColors = {
            'login': '#3B82F6',
            'logout': '#6B7280',
            'create': '#059669',
            'update': '#D97706',
            'delete': '#DC2626',
            'assign': '#7C3AED',
            'approve': '#059669',
            'reject': '#DC2626',
            'grade': '#0891B2',
            'comment': '#7C3AED',
        };
        function getLogColor(action) {
            const lower = (action || '').toLowerCase();
            for (const [key, color] of Object.entries(actionTypeColors)) {
                if (lower.includes(key)) return color;
            }
            return 'var(--color-primary)';
        }

        // Build items HTML
        const itemsHtml = pageLogs.map(log => {
            const color = getLogColor(log.action);
            const label = log.action.replace(/_/g, ' ').toUpperCase();
            const ts = new Date(log.timestamp).toLocaleString();
            return `
            <div class="action-item" style="display:flex; gap:14px; align-items:flex-start; padding:14px 16px; margin-bottom:10px; border-radius:8px; border:1px solid var(--color-border); background:#fff; transition: box-shadow 0.15s;">
                <div style="flex-shrink:0; width:36px; height:36px; border-radius:50%; background:${color}18; display:flex; align-items:center; justify-content:center;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="${color}" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
                </div>
                <div class="action-content" style="flex:1; min-width:0;">
                    <span class="title" style="display:block; font-weight:700; font-size:0.82rem; color:${color}; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:3px;">${escapeHtml(label)}</span>
                    <span class="desc" style="font-size:0.8rem; color:var(--color-muted);">${escapeHtml(log.full_name || 'System')}</span>
                    <span style="display:block; font-size:0.75rem; color:#9CA3AF; margin-top:2px;">${ts}</span>
                </div>
            </div>`;
        }).join('');

        // Pagination controls
        const paginationHtml = totalPages > 1 ? `
        <div style="display:flex; align-items:center; justify-content:space-between; margin-top:14px; padding-top:12px; border-top:1px solid var(--color-border);">
            <button id="logsPrevBtn_sup" class="btn btn-outline" style="padding:6px 14px; font-size:0.82rem;" ${logsPage_sup <= 1 ? 'disabled' : ''}>← Prev</button>
            <span style="font-size:0.82rem; color:var(--color-muted);">Page ${logsPage_sup} of ${totalPages} &nbsp;·&nbsp; ${logs.length} entries</span>
            <button id="logsNextBtn_sup" class="btn btn-outline" style="padding:6px 14px; font-size:0.82rem;" ${logsPage_sup >= totalPages ? 'disabled' : ''}>Next →</button>
        </div>` : `<p style="font-size:0.78rem; color:var(--color-muted); margin-top:10px; text-align:right;">${logs.length} entr${logs.length !== 1 ? 'ies' : 'y'} total</p>`;

        // Collapsible body
        const bodyStyle = logsExpanded_sup ? '' : 'display:none;';
        const chevron = logsExpanded_sup
            ? `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>`
            : `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>`;

        container.innerHTML = `
        <div id="logsToggleHeader_sup" style="display:flex; align-items:center; justify-content:space-between; cursor:pointer; padding:10px 0 12px; border-bottom:1px solid var(--color-border); margin-bottom:12px; user-select:none;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:28px; height:28px; border-radius:6px; background:var(--color-primary)18; display:flex; align-items:center; justify-content:center;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                </div>
                <span style="font-weight:600; font-size:0.9rem; color:var(--color-text);">Activity Log</span>
                <span style="background:var(--color-primary); color:#fff; font-size:0.72rem; font-weight:700; padding:2px 8px; border-radius:10px;">${logs.length}</span>
            </div>
            <div style="display:flex; align-items:center; gap:6px; color:var(--color-muted); font-size:0.8rem;">
                <span>${logsExpanded_sup ? 'Collapse' : 'Expand'}</span>
                ${chevron}
            </div>
        </div>
        <div id="logsBody_sup" style="${bodyStyle}">
            ${itemsHtml}
            ${paginationHtml}
        </div>`;

        // Toggle
        document.getElementById('logsToggleHeader_sup').addEventListener('click', () => {
            logsExpanded_sup = !logsExpanded_sup;
            renderLogsUI_sup();
        });

        // Pagination buttons
        document.getElementById('logsPrevBtn_sup')?.addEventListener('click', (e) => {
            e.stopPropagation();
            logsPage_sup = Math.max(1, logsPage_sup - 1);
            renderLogsUI_sup();
        });
        document.getElementById('logsNextBtn_sup')?.addEventListener('click', (e) => {
            e.stopPropagation();
            logsPage_sup = Math.min(totalPages, logsPage_sup + 1);
            renderLogsUI_sup();
        });
    }

    async function loadSupervisees() {
        const container = document.getElementById('superviseesContainer');
        const select = document.getElementById('projectSelect');
        const cohortId = document.getElementById('superviseeCohortFilter')?.value || '';

        container.innerHTML = "Loading...";
        try {
            const res = await fetch(`php/api/supervisor_api.php?action=get_supervisees&cohort_id=${encodeURIComponent(cohortId)}`);
            const json = await res.json();

            if (json.status === 200 && json.data.length > 0) {
                superviseesCache = json.data;
                // Populate Table
                let html = '<table class="data-table"><tr><th>Student Name</th><th>ID Number</th><th>Cohort</th><th>Project Title</th><th>Progress</th><th>Defense</th></tr>';

                json.data.forEach(s => {
                    const progColor = s.progress === 100 ? 'var(--color-success)' : 'var(--color-primary)';

                    // Determine button state
                    let defenseBtn;
                    if (s.is_cleared_for_defense) {
                        defenseBtn = `<button class="btn btn-outline" disabled title="Already cleared for defense" style="padding:6px 10px; opacity:0.5; cursor:not-allowed;">✓ Cleared</button>`;
                    } else if (!s.can_clear_for_defense) {
                        defenseBtn = `<button class="btn btn-outline" disabled title="Cannot clear for defense: one or more milestones are not yet completed" style="padding:6px 10px; opacity:0.5; cursor:not-allowed;">Clear for Defense</button>`;
                    } else {
                        defenseBtn = `<button class="btn btn-outline clear-defense-btn" data-project="${s.project_id}" style="padding:6px 10px;">Clear for Defense</button>`;
                    }

                    html += `<tr>
                        <td><strong>${escapeHtml(s.full_name)}</strong></td>
                        <td>${escapeHtml(s.university_id_number || 'N/A')}</td>
                        <td>${escapeHtml(s.cohort_name)}</td>
                        <td>${escapeHtml(s.title)}</td>
                        <td>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div style="flex:1; background:#e2e8f0; height:8px; border-radius:4px; overflow:hidden;">
                                    <div style="width:${s.progress}%; height:100%; background:${progColor};"></div>
                                </div>
                                <span style="font-size:0.85rem; font-weight:600;">${s.progress}%</span>
                            </div>
                        </td>
                        <td>${defenseBtn}</td>
                    </tr>`;
                });

                html += '</table>';
                container.innerHTML = html;
                renderProjectOptions(json.data);
                document.querySelectorAll('.clear-defense-btn').forEach(btn =>
                    btn.addEventListener('click', () => clearForDefense(btn.dataset.project))
                );
            } else {
                container.innerHTML = "<p>You have no students assigned yet.</p>";
                if (select) select.innerHTML = '<option value="">Select a student project...</option>';
            }
        } catch (e) {
            container.innerHTML = "<p>Error loading supervisees.</p>";
        }
    }

    function renderProjectOptions(supervisees) {
        const select = document.getElementById('projectSelect');
        if (!select) return;
        const cohortId = document.getElementById('submissionCohortFilter')?.value || '';
        const filtered = cohortId ? supervisees.filter(s => String(s.cohort_id) === String(cohortId)) : supervisees;
        select.innerHTML = '<option value="">Select a student project...</option>' +
            filtered.map(s => `<option value="${s.project_id}">${escapeHtml(s.full_name)} - ${escapeHtml(s.title)}</option>`).join('');
    }

    function statusOptionsForSubmission(sub) {
        const standard = [
            ['submitted', 'Submitted (Pending Review)'],
            ['revision_required', 'Revision Required'],
            ['supervisor_approved', 'Supervisor Approved'],
            ['rejected', 'Rejected']
        ];
        const defense = [
            ['cleared_for_defense', 'Cleared for Defense'],
            ['satisfactory', 'Satisfactory'],
            ['satisfactory_with_corrections', 'Satisfactory with Corrections'],
            ['fail_redo', 'Fail (Redo)']
        ];
        const options = sub.type === 'defense' ? defense : standard;
        return options.map(([value, label]) =>
            `<option value="${value}" ${sub.status === value ? 'selected' : ''}>${label}</option>`
        ).join('');
    }

    async function clearForDefense(projectId) {
        if (!confirm('Clear this student for Project Defense?')) return;
        const res = await fetch('php/api/supervisor_api.php?action=clear_for_defense', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ project_id: projectId })
        });
        const json = await res.json();
        if (res.ok && json.status === 201) {
            alert('Student successfully cleared for Defense.');
            loadSupervisees(); // Reload to update button states
        } else {
            alert(json.message || 'Failed to clear for defense.');
        }
    }

    //  Load Submissions 
    document.getElementById('projectSelect')?.addEventListener('change', async (e) => {
        const pId = e.target.value;
        const container = document.getElementById('submissionsContainer');

        if (!pId) {
            container.innerHTML = '<p class="text-muted">Select a project above to view submissions.</p>';
            return;
        }

        container.innerHTML = "Loading submissions...";
        try {
            const cohortId = document.getElementById('submissionCohortFilter')?.value || '';
            const res = await fetch(`php/api/supervisor_api.php?action=get_submissions&project_id=${encodeURIComponent(pId)}&cohort_id=${encodeURIComponent(cohortId)}`);
            const json = await res.json();

            if (json.status === 200 && json.data.length > 0) {
                let html = '';
                json.data.forEach(sub => {
                    const date = new Date(sub.submitted_at).toLocaleString();
                    const statusClass = ['supervisor_approved', 'coordinator_approved', 'approved', 'satisfactory'].includes(sub.status) ? 'status-approved' : 'status-pending';
                    html += `
                    <div class="submission-item ${statusClass}">
                        <div style="display:flex; justify-content:space-between; margin-bottom:12px;">
                            <div>
                                <h3 style="margin:0; font-size:1.1rem;">${sub.milestone_name} <span style="font-size:0.8rem; color:#6B7280; font-weight:normal;">(v${sub.version || 1} - ${sub.type})</span></h3>
                                <p style="margin:4px 0 0; font-size:0.85rem; color:#6B7280;">Submitted: ${date}</p>
                            </div>
                            ${sub.file_path ? `<a href="${sub.file_path}" target="_blank" class="btn btn-outline" style="padding:6px 12px; font-size:0.85rem;">View File</a>` : ''}
                        </div>
                        
                        ${sub.student_text ? `
                            <div style="background:#F9FAFB; padding:12px; border-radius:8px; border:1px solid var(--color-border); margin-bottom:16px;">
                                <h4 style="margin:0 0 8px 0; font-size:0.9rem; color:var(--color-text);">Student Update:</h4>
                                <p style="margin:0; font-size:0.85rem; color:var(--color-muted); white-space:pre-wrap;">${sub.student_text}</p>
                            </div>
                        ` : ''}
                        
                        <form class="grading-form" data-sub-id="${sub.sub_id}">
                            <div class="responsive-review-grid">
                                <div class="form-group">
                                    <label>Status</label>
                                    <select name="status" class="status-select">
                                        ${statusOptionsForSubmission(sub)}
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Supervisor Feedback</label>
                                    <textarea name="feedback" rows="3" placeholder="Enter detailed feedback here...">${sub.supervisor_feedback || ''}</textarea>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary" style="background:var(--color-primary); color:#fff; font-size:0.85rem; padding:8px 16px;">Save Feedback & Status</button>
                            <span class="save-msg" style="margin-left:10px; font-size:0.85rem; font-weight:bold;"></span>
                        </form>
                        <div class="comment-panel" data-sub-id="${sub.sub_id}" style="margin-top:16px; border-top:1px solid var(--color-border); padding-top:12px;">
                            <h4 style="margin:0 0 8px 0;">Review Comments</h4>
                            <div class="comment-list">Loading comments...</div>
                            <form class="comment-form" style="margin-top:10px;">
                                <textarea rows="2" placeholder="Add another comment..." style="width:100%; padding:8px; border:1px solid var(--color-border); border-radius:6px;"></textarea>
                                <button class="btn btn-outline" type="submit" style="margin-top:6px; padding:6px 12px;">Add Comment</button>
                            </form>
                        </div>
                    </div>`;
                });
                container.innerHTML = html;
                attachGradingHandlers();
                attachCommentHandlers();
            } else {
                container.innerHTML = "<p>No submissions found for this project.</p>";
            }
        } catch (e) {
            container.innerHTML = "<p>Error loading submissions.</p>";
        }
    });

    //  Submit Feedback 
    function attachGradingHandlers() {
        document.querySelectorAll('.grading-form').forEach(form => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const subId = form.getAttribute('data-sub-id');
                const status = form.querySelector('[name="status"]').value;
                const feedback = form.querySelector('[name="feedback"]').value;
                const msgEl = form.querySelector('.save-msg');

                msgEl.textContent = "Saving...";
                msgEl.style.color = "var(--color-text)";

                try {
                    const res = await fetch('php/api/supervisor_api.php?action=grade_submission', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ sub_id: subId, status, supervisor_feedback: feedback })
                    });
                    const data = await res.json();

                    if (res.ok) {
                        msgEl.textContent = "Saved successfully!";
                        msgEl.style.color = "var(--color-success, #10B981)";
                        setTimeout(() => msgEl.textContent = "", 3000);
                    } else {
                        msgEl.textContent = data.message || "Error saving.";
                        msgEl.style.color = "red";
                    }
                } catch (err) {
                    msgEl.textContent = "Network error.";
                    msgEl.style.color = "red";
                }
            });
        });
    }

    function attachCommentHandlers() {
        document.querySelectorAll('.comment-panel').forEach(panel => {
            const subId = panel.dataset.subId;
            loadComments(panel, subId);
            panel.querySelector('.comment-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                const textarea = panel.querySelector('.comment-form textarea');
                const body = textarea.value.trim();
                if (!body) return;
                const res = await fetch('php/api/supervisor_api.php?action=add_comment', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ sub_id: subId, body })
                });
                const json = await res.json();
                if (!res.ok) return alert(json.message || 'Failed to add comment.');
                textarea.value = '';
                loadComments(panel, subId);
            });
        });
    }

    async function loadComments(panel, subId) {
        const list = panel.querySelector('.comment-list');
        try {
            const res = await fetch(`php/api/supervisor_api.php?action=get_comments&sub_id=${encodeURIComponent(subId)}`);
            const json = await res.json();
            if (json.status !== 200 || json.data.length === 0) {
                list.innerHTML = '<p class="text-muted">No comments yet.</p>';
                return;
            }
            list.innerHTML = json.data.map(c => `
                <div class="submission-comment" style="background:#F9FAFB; border:1px solid var(--color-border); border-radius:6px; padding:10px; margin-bottom:8px;">
                    <div style="display:flex; justify-content:space-between; gap:10px;">
                        <strong>${escapeHtml(c.author_name)}</strong>
                        <small class="text-muted">${new Date(c.created_at).toLocaleString()}</small>
                    </div>
                    <textarea data-comment-id="${c.comment_id}" rows="2" style="width:100%; margin-top:6px; padding:8px; border:1px solid var(--color-border); border-radius:6px;">${escapeHtml(c.body)}</textarea>
                    <button class="btn btn-outline update-comment" data-comment-id="${c.comment_id}" style="margin-top:6px; padding:5px 10px;">Update Comment</button>
                </div>
            `).join('');
            list.querySelectorAll('.update-comment').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const commentId = btn.dataset.commentId;
                    const body = list.querySelector(`textarea[data-comment-id="${commentId}"]`).value.trim();
                    const res = await fetch('php/api/supervisor_api.php?action=update_comment', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ comment_id: commentId, body })
                    });
                    const data = await res.json();
                    alert(data.message);
                    if (res.ok) loadComments(panel, subId);
                });
            });
        } catch (e) {
            list.innerHTML = '<p class="text-muted">Unable to load comments.</p>';
        }
    }

    //  Sub-Milestone Management 
    loadMilestoneCohorts();

    async function loadMilestoneCohorts() {
        try {
            const res = await fetch('php/api/supervisor_api.php?action=get_cohorts');
            const json = await res.json();
            if (json.status === 200) {
                const opts = '<option value="">Select Cohort...</option>' +
                    json.data.map(c => `<option value="${c.cohort_id}">${c.name}</option>`).join('');
                document.getElementById('sm_cohort').innerHTML = opts;
                const submissionFilter = document.getElementById('submissionCohortFilter');
                if (submissionFilter) {
                    submissionFilter.innerHTML = '<option value="">All assigned cohorts</option>' +
                        json.data.map(c => `<option value="${c.cohort_id}">${escapeHtml(c.name)}</option>`).join('');
                }
            } else {
                document.getElementById('sm_cohort').innerHTML = '<option value="">No assigned cohorts</option>';
            }
        } catch (e) { }
    }

    document.getElementById('sm_cohort')?.addEventListener('change', async (e) => {
        const cId = e.target.value;
        const container = document.getElementById('existingMilestonesContainer');
        if (!cId) {
            document.getElementById('sm_parent').innerHTML = '<option value="">None</option>';
            if (container) container.innerHTML = '<p class="text-muted">Select a cohort above to view milestones.</p>';
            return;
        }
        try {
            const res = await fetch(`php/api/supervisor_api.php?action=get_parent_milestones&cohort_id=${cId}`);
            const json = await res.json();
            if (json.status === 200) {
                const opts = '<option value="">None</option>' +
                    json.data.filter(m => m.type !== 'sub_milestone').map(m => `<option value="${m.milestone_id}">${m.name}</option>`).join('');
                document.getElementById('sm_parent').innerHTML = opts;

                if (container) {
                    if (json.data.length > 0) {
                        let html = '<table class="data-table"><tr><th>Name</th><th>Due Date</th><th>Type</th><th>Actions</th></tr>';
                        json.data.forEach(m => {
                            const typeLabel = m.type === 'sub_milestone' ? 'Your Sub-Milestone' : 'Coordinator Milestone';
                            html += `<tr>
                                <td>${m.type === 'sub_milestone' ? `<input id="sub_name_${m.milestone_id}" value="${escapeAttr(m.name)}">` : escapeHtml(m.name)}</td>
                                <td>${m.type === 'sub_milestone' ? `<input type="date" id="sub_due_${m.milestone_id}" value="${escapeAttr(m.due_date || '')}">` : (m.due_date || 'N/A')}</td>
                                <td><span style="font-size:0.8rem; padding:4px 8px; border-radius:12px; background:#F3F4F6;">${typeLabel}</span></td>
                                <td>
                                  ${m.type === 'sub_milestone' ? `
                                    <textarea id="sub_desc_${m.milestone_id}" style="width:100%; margin-bottom:6px;" rows="2">${escapeHtml(m.description || '')}</textarea>
                                    <button class="btn btn-primary update-sub" data-id="${m.milestone_id}" style="padding:5px 10px; background:var(--color-primary); color:#fff;">Save</button>
                                    <button class="btn btn-outline delete-sub" data-id="${m.milestone_id}" style="padding:5px 10px;">Delete</button>
                                  ` : ''}
                                </td>
                             </tr>`;
                        });
                        html += '</table>';
                        container.innerHTML = html;
                        container.querySelectorAll('.update-sub').forEach(btn => btn.addEventListener('click', () => updateSubMilestone(btn.dataset.id)));
                        container.querySelectorAll('.delete-sub').forEach(btn => btn.addEventListener('click', () => deleteSubMilestone(btn.dataset.id)));
                    } else {
                        container.innerHTML = '<p class="text-muted">No milestones found for this cohort.</p>';
                    }
                }
            }
        } catch (e) { }
    });

    document.getElementById('createSubMilestoneForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const alertEl = document.getElementById('sm_alert');
        alertEl.textContent = "Processing...";

        const payload = {
            cohort_id: document.getElementById('sm_cohort').value,
            name: document.getElementById('sm_name').value,
            due_date: document.getElementById('sm_date').value,
            parent_id: document.getElementById('sm_parent').value,
            description: document.getElementById('sm_desc').value
        };

        try {
            const res = await fetch('php/api/supervisor_api.php?action=create_sub_milestone', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            alertEl.textContent = data.message;
            if (res.ok) {
                const cohortId = document.getElementById('sm_cohort').value;
                e.target.reset();
                document.getElementById('sm_cohort').value = cohortId;
                document.getElementById('sm_cohort').dispatchEvent(new Event('change'));
            }
        } catch (err) {
            alertEl.textContent = "Network error.";
        }
    });

    async function updateSubMilestone(id) {
        const payload = {
            milestone_id: id,
            cohort_id: document.getElementById('sm_cohort').value,
            name: document.getElementById(`sub_name_${id}`).value,
            due_date: document.getElementById(`sub_due_${id}`).value,
            description: document.getElementById(`sub_desc_${id}`).value,
            parent_id: document.getElementById('sm_parent').value
        };
        const res = await fetch('php/api/supervisor_api.php?action=update_sub_milestone', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        alert(json.message);
        if (res.ok) document.getElementById('sm_cohort').dispatchEvent(new Event('change'));
    }

    async function deleteSubMilestone(id) {
        if (!confirm('Delete this sub-milestone?')) return;
        const res = await fetch('php/api/supervisor_api.php?action=delete_sub_milestone', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ milestone_id: id })
        });
        const json = await res.json();
        alert(json.message);
        if (res.ok) document.getElementById('sm_cohort').dispatchEvent(new Event('change'));
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#096;');
    }

});
