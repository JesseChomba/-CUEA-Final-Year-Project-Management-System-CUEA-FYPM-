/**
 * CUEA FYPM – Coordinator Portal JS
 */

'use strict';

const originalFetch = window.fetch;
window.fetch = async function(...args) {
    const res = await originalFetch.apply(this, args);
    if (res.status === 401 || res.status === 403) {
        let reason = res.status === 403 ? 'role_changed' : 'session_expired';
        try {
            const data = await res.clone().json();
            reason = data?.data?.reason || reason;
        } catch (e) {}
        window.location.href = `index.html?reason=${encodeURIComponent(reason)}`;
    }
    return res;
};

document.addEventListener('DOMContentLoaded', () => {
    let latestSupervisorOptions = '<option value="">Select Supervisor...</option>';
    let manualTransferQuery = '';
    let manualTransferPage = 1;
    let templatesCache = [];

    // ── Tab Switching ─────────────────────────────────────────
    const navItems = document.querySelectorAll('.sidebar-nav .nav-item[data-tab]');
    const tabContents = document.querySelectorAll('.tab-content');

    navItems.forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            // Remove active classes
            navItems.forEach(n => n.classList.remove('active'));
            tabContents.forEach(t => t.classList.remove('active'));
            
            // Add active class
            item.classList.add('active');
            const tabId = item.getAttribute('data-tab');
            document.getElementById(`tab-${tabId}`).classList.add('active');
            
            // Trigger loads
            if (tabId === 'allocations') loadAllocationWorkspace();
            if (tabId === 'overview') {
                loadMetrics();
                loadOverview();
            }
            if (tabId === 'cohorts') loadCohortCrud();
            if (tabId === 'milestones') {
                loadMilestoneCrud();
                loadTemplates();
            }
        });
    });

    const requestedTab = new URLSearchParams(window.location.search).get('tab');
    if (requestedTab) {
        const requestedItem = document.querySelector(`.sidebar-nav .nav-item[data-tab="${CSS.escape(requestedTab)}"]`);
        if (requestedItem) requestedItem.click();
    }

    // ── Password Visibility Toggle ────────────────────────────
    const toggleCuPwd = document.getElementById('toggleCuPwd');
    const cuPwdInput = document.getElementById('cu_password');
    const cuEyeIcon = document.getElementById('cuEyeIcon');
    const cuEyeOffIcon = document.getElementById('cuEyeOffIcon');

    if (toggleCuPwd) {
        toggleCuPwd.addEventListener('click', () => {
            const isHidden = cuPwdInput.type === 'password';
            cuPwdInput.type = isHidden ? 'text' : 'password';
            cuEyeIcon.style.display = isHidden ? 'none' : '';
            cuEyeOffIcon.style.display = isHidden ? '' : 'none';
        });
    }

    // ── Initial Load Data ─────────────────────────────────────
    loadCohorts();
    loadOverviewFilters();
    loadMetrics();
    loadOverview();
    loadSettings();
    loadActivityLogs();

    async function loadOverviewFilters() {
        try {
            const resCohorts = await fetch('php/api/coordinator.php?action=get_cohorts');
            const jsonCohorts = await resCohorts.json();
            if (jsonCohorts.status === 200) {
                const opts = '<option value="">All Cohorts</option>' + 
                             jsonCohorts.data.map(c => `<option value="${c.cohort_id}">${c.name}</option>`).join('');
                document.getElementById('overviewCohortFilter').innerHTML = opts;
            }

            const resSups = await fetch('php/api/coordinator.php?action=get_allocations');
            const jsonSups = await resSups.json();
            if (jsonSups.status === 200) {
                const opts = '<option value="">All Supervisors</option>' + 
                             jsonSups.data.map(s => `<option value="${s.user_id}">${s.full_name}</option>`).join('');
                document.getElementById('overviewSupervisorFilter').innerHTML = opts;
            }
        } catch(e) {}
    }

    async function loadOverview() {
        const container = document.getElementById('overviewResults');
        container.innerHTML = "Loading...";
        const cohortId = document.getElementById('overviewCohortFilter').value;
        const supId = document.getElementById('overviewSupervisorFilter').value;
        const milestoneStatus = document.getElementById('overviewMilestoneStatusFilter')?.value || '';

        try {
            const res = await fetch(`php/api/coordinator.php?action=get_overview_data&cohort_id=${cohortId}&supervisor_id=${supId}&milestone_status=${milestoneStatus}`);
            const data = await res.json();
            if (res.ok && data.data.length > 0) {
                let html = '<table class="data-table"><tr><th>Student</th><th>Project Title</th><th>Current Milestone Status</th><th>Supervisor</th><th>Progress</th></tr>';
                data.data.forEach(p => {
                    const supName = p.supervisor_name || '<em>Unassigned</em>';
                    const progColor = p.progress === 100 ? 'var(--color-success)' : 'var(--color-primary)';
                    html += `<tr>
                        <td><strong>${p.student_name}</strong><br><small>${p.cohort_name}</small></td>
                        <td>${escapeHtml(p.title)}</td>
                        <td><span style="text-transform: capitalize; padding: 4px 8px; background: #f1f5f9; border-radius: 12px; font-size: 0.8rem;">${escapeHtml(p.current_milestone_status || 'No submissions yet')}</span></td>
                        <td>${supName}</td>
                        <td>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div style="flex:1; background:#e2e8f0; height:8px; border-radius:4px; overflow:hidden;">
                                    <div style="width:${p.progress}%; height:100%; background:${progColor};"></div>
                                </div>
                                <span style="font-size:0.85rem; font-weight:600;">${p.progress}%</span>
                            </div>
                        </td>
                    </tr>`;
                });
                html += '</table>';
                container.innerHTML = html;
            } else {
                container.innerHTML = "<p>No projects match the selected filters.</p>";
            }
        } catch(e) {
            container.innerHTML = "<p>Error loading overview data.</p>";
        }
    }

    document.getElementById('overviewCohortFilter')?.addEventListener('change', loadOverview);
    document.getElementById('overviewSupervisorFilter')?.addEventListener('change', loadOverview);
    document.getElementById('overviewMilestoneStatusFilter')?.addEventListener('change', loadOverview);
    document.getElementById('milestoneListCohortFilter')?.addEventListener('change', loadMilestoneCrud);

    async function loadMetrics() {
        const grid = document.getElementById('metricsGrid');
        if (!grid) return;
        try {
            const res = await fetch('php/api/coordinator.php?action=get_eagle_metrics');
            const json = await res.json();
            if (json.status === 200) {
                const metrics = [
                    ['Total Students', json.data.total_students],
                    ['Total Lecturers', json.data.total_lecturers],
                    ['Active Projects', json.data.active_projects],
                    ['Pending Transfers', json.data.pending_transfers]
                ];
                grid.innerHTML = metrics.map(([label, value]) => `
                    <div style="border:1px solid var(--color-border); border-radius:8px; padding:16px; background:#F9FAFB;">
                        <div style="font-size:0.8rem; color:var(--color-muted); font-weight:700;">${label}</div>
                        <div style="font-size:1.8rem; font-weight:800; color:var(--color-primary);">${value}</div>
                    </div>
                `).join('');
            }
        } catch (e) {
            grid.innerHTML = '<p class="text-muted">Unable to load metrics.</p>';
        }
    }

    // ── Activity Logs (collapsible + paginated) ───────────────
    let allLogsCache_coord = [];
    let logsPage_coord = 1;
    const LOGS_PER_PAGE = 10;
    let logsExpanded_coord = false;

    async function loadActivityLogs() {
        const container = document.getElementById('coordinatorLogsContainer');
        if (!container) return;
        try {
            const res = await fetch('php/api/coordinator.php?action=get_activity_logs');
            const json = await res.json();
            allLogsCache_coord = json.data || [];
            logsPage_coord = 1;
            renderLogsUI_coord();
        } catch (e) {
            document.getElementById('coordinatorLogsContainer').innerHTML =
                '<p class="text-muted">Unable to load logs.</p>';
        }
    }

    function renderLogsUI_coord() {
        const container = document.getElementById('coordinatorLogsContainer');
        if (!container) return;
        const logs = allLogsCache_coord;

        if (logs.length === 0) {
            container.innerHTML = '<p class="text-muted">No recent logs.</p>';
            return;
        }

        const totalPages = Math.ceil(logs.length / LOGS_PER_PAGE);
        const start = (logsPage_coord - 1) * LOGS_PER_PAGE;
        const pageLogs = logs.slice(start, start + LOGS_PER_PAGE);

        const actionTypeColors = {
            'login': '#3B82F6',
            'logout': '#6B7280',
            'create': '#059669',
            'update': '#D97706',
            'delete': '#DC2626',
            'assign': '#7C3AED',
            'approve': '#059669',
            'reject': '#DC2626',
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
            <button id="logsPrevBtn_coord" class="btn btn-outline" style="padding:6px 14px; font-size:0.82rem;" ${logsPage_coord <= 1 ? 'disabled' : ''}>← Prev</button>
            <span style="font-size:0.82rem; color:var(--color-muted);">Page ${logsPage_coord} of ${totalPages} &nbsp;·&nbsp; ${logs.length} entries</span>
            <button id="logsNextBtn_coord" class="btn btn-outline" style="padding:6px 14px; font-size:0.82rem;" ${logsPage_coord >= totalPages ? 'disabled' : ''}>Next →</button>
        </div>` : `<p style="font-size:0.78rem; color:var(--color-muted); margin-top:10px; text-align:right;">${logs.length} entr${logs.length !== 1 ? 'ies' : 'y'} total</p>`;

        // Collapsible body
        const bodyStyle = logsExpanded_coord ? '' : 'display:none;';
        const chevron = logsExpanded_coord
            ? `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>`
            : `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>`;

        container.innerHTML = `
        <div id="logsToggleHeader_coord" style="display:flex; align-items:center; justify-content:space-between; cursor:pointer; padding:10px 0 12px; border-bottom:1px solid var(--color-border); margin-bottom:12px; user-select:none;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:28px; height:28px; border-radius:6px; background:var(--color-primary)18; display:flex; align-items:center; justify-content:center;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                </div>
                <span style="font-weight:600; font-size:0.9rem; color:var(--color-text);">Activity Log</span>
                <span style="background:var(--color-primary); color:#fff; font-size:0.72rem; font-weight:700; padding:2px 8px; border-radius:10px;">${logs.length}</span>
            </div>
            <div style="display:flex; align-items:center; gap:6px; color:var(--color-muted); font-size:0.8rem;">
                <span>${logsExpanded_coord ? 'Collapse' : 'Expand'}</span>
                ${chevron}
            </div>
        </div>
        <div id="logsBody_coord" style="${bodyStyle}">
            ${itemsHtml}
            ${paginationHtml}
        </div>`;

        // Toggle
        document.getElementById('logsToggleHeader_coord').addEventListener('click', () => {
            logsExpanded_coord = !logsExpanded_coord;
            renderLogsUI_coord();
        });

        // Pagination buttons
        document.getElementById('logsPrevBtn_coord')?.addEventListener('click', (e) => {
            e.stopPropagation();
            logsPage_coord = Math.max(1, logsPage_coord - 1);
            renderLogsUI_coord();
        });
        document.getElementById('logsNextBtn_coord')?.addEventListener('click', (e) => {
            e.stopPropagation();
            logsPage_coord = Math.min(totalPages, logsPage_coord + 1);
            renderLogsUI_coord();
        });
    }

    async function loadSettings() {
        try {
            const res = await fetch('php/api/coordinator.php?action=get_settings');
            const json = await res.json();
            if (json.status === 200 && document.getElementById('settingMaxTotalStudents')) {
                document.getElementById('settingMaxTotalStudents').value = json.data.max_total_students_per_supervisor;
            }
        } catch (e) {}
    }

    document.getElementById('settingsForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const alertEl = document.getElementById('settingsAlert');
        try {
            const res = await fetch('php/api/coordinator.php?action=update_settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ max_total_students_per_supervisor: document.getElementById('settingMaxTotalStudents').value })
            });
            const json = await res.json();
            alertEl.textContent = json.message;
            alertEl.style.color = res.ok ? 'var(--color-success)' : 'var(--color-error)';
            loadAllocations();
        } catch (err) {
            alertEl.textContent = 'Network error.';
            alertEl.style.color = 'var(--color-error)';
        }
    });

    async function loadCohorts() {
        try {
            const res = await fetch('php/api/coordinator.php?action=get_cohorts');
            const json = await res.json();
            if (json.status === 200) {
                const opts = '<option value="">Select Cohort...</option>' + 
                             json.data.map(c => `<option value="${c.cohort_id}">${c.name}</option>`).join('');
                document.getElementById('cu_cohort').innerHTML = opts;
                document.getElementById('cm_cohort').innerHTML = opts;
                document.getElementById('templateCohort') && (document.getElementById('templateCohort').innerHTML = opts);
                document.getElementById('scCohort') && (document.getElementById('scCohort').innerHTML = opts);
                document.getElementById('milestoneListCohortFilter') && (document.getElementById('milestoneListCohortFilter').innerHTML = opts);
                loadCohortCrud();
                loadMilestoneCrud();
            }
        } catch(e) {}
    }

    async function loadCohortCrud() {
        const container = document.getElementById('cohortCrudContainer');
        if (!container) return;
        try {
            const res = await fetch('php/api/coordinator.php?action=get_cohorts');
            const json = await res.json();
            if (json.status !== 200 || json.data.length === 0) {
                container.innerHTML = '<p>No cohorts found.</p>';
                return;
            }
            container.innerHTML = `<table class="data-table"><tr><th>Name</th><th>Dates</th><th>Max/Cohort</th><th>Status</th><th>Actions</th></tr>
                ${json.data.map(c => `
                    <tr>
                        <td><input id="cohort_name_${c.cohort_id}" value="${escapeAttr(c.name)}"></td>
                        <td>
                            <input type="date" id="cohort_start_${c.cohort_id}" value="${escapeAttr(c.start_date)}" style="margin-bottom:6px;">
                            <input type="date" id="cohort_end_${c.cohort_id}" value="${escapeAttr(c.end_date)}">
                        </td>
                        <td><input type="number" min="1" id="cohort_max_${c.cohort_id}" value="${escapeAttr(c.max_students_per_supervisor)}"></td>
                        <td><label><input type="checkbox" id="cohort_active_${c.cohort_id}" ${Number(c.is_active) ? 'checked' : ''}> Active</label></td>
                        <td>
                            <button class="btn btn-primary update-cohort" data-id="${c.cohort_id}" style="padding:6px 10px; background:var(--color-primary); color:#fff;">Save</button>
                            <button class="btn btn-outline delete-cohort" data-id="${c.cohort_id}" style="padding:6px 10px;">Delete</button>
                        </td>
                    </tr>
                `).join('')}</table>`;
            document.querySelectorAll('.update-cohort').forEach(btn => btn.addEventListener('click', () => updateCohort(btn.dataset.id)));
            document.querySelectorAll('.delete-cohort').forEach(btn => btn.addEventListener('click', () => deleteCohort(btn.dataset.id)));
        } catch (e) {
            container.innerHTML = '<p>Error loading cohorts.</p>';
        }
    }

    async function updateCohort(id) {
        const payload = {
            cohort_id: id,
            name: document.getElementById(`cohort_name_${id}`).value,
            start_date: document.getElementById(`cohort_start_${id}`).value,
            end_date: document.getElementById(`cohort_end_${id}`).value,
            max_students: document.getElementById(`cohort_max_${id}`).value,
            is_active: document.getElementById(`cohort_active_${id}`).checked
        };
        const res = await fetch('php/api/coordinator.php?action=update_cohort', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        alert(json.message);
        if (res.ok) loadCohorts();
    }

    async function deleteCohort(id) {
        if (!confirm('Delete this cohort? Cohorts with projects cannot be deleted.')) return;
        const res = await fetch('php/api/coordinator.php?action=delete_cohort', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ cohort_id: id })
        });
        const json = await res.json();
        alert(json.message);
        if (res.ok) loadCohorts();
    }

    async function loadMilestoneCrud() {
        const container = document.getElementById('milestoneCrudContainer');
        if (!container) return;
        const cohortId = document.getElementById('milestoneListCohortFilter')?.value || '';
        try {
            const res = await fetch(`php/api/coordinator.php?action=get_milestones&cohort_id=${encodeURIComponent(cohortId)}`);
            const json = await res.json();
            if (json.status !== 200 || json.data.length === 0) {
                container.innerHTML = '<p>No coordinator milestones found.</p>';
                return;
            }
            const cohortOptions = Array.from(document.getElementById('cm_cohort').options).map(o => `<option value="${o.value}">${o.textContent}</option>`).join('');
            container.innerHTML = `<table class="data-table"><tr><th>Name</th><th>Cohort/Type</th><th>Due</th><th>Description</th><th>Actions</th></tr>
                ${json.data.map(m => `
                    <tr>
                        <td><input id="milestone_name_${m.milestone_id}" value="${escapeAttr(m.name)}"></td>
                        <td>
                            <select id="milestone_cohort_${m.milestone_id}">${cohortOptions}</select>
                            <select id="milestone_type_${m.milestone_id}" style="margin-top:6px;">
                                <option value="proposal">Proposal</option>
                                <option value="standard_milestone">Standard Milestone</option>
                                <option value="sub_milestone">Sub-Milestone</option>
                                <option value="defense">Project Defense</option>
                            </select>
                        </td>
                        <td><input type="date" id="milestone_due_${m.milestone_id}" value="${escapeAttr(m.due_date)}"></td>
                        <td><textarea id="milestone_desc_${m.milestone_id}" rows="3">${escapeHtml(m.description || '')}</textarea></td>
                        <td>
                            ${m.instruction_file_path ? `<a href="${escapeAttr(m.instruction_file_path)}" target="_blank" class="btn btn-outline" style="padding:6px 10px;">Instruction</a>` : ''}
                            <input type="file" id="instruction_${m.milestone_id}" accept=".pdf,.doc,.docx" style="width:100%; margin:6px 0;">
                            <button class="btn btn-outline upload-instruction" data-id="${m.milestone_id}" style="padding:6px 10px;">Upload Instruction</button>
                            <button class="btn btn-primary update-milestone" data-id="${m.milestone_id}" style="padding:6px 10px; background:var(--color-primary); color:#fff;">Save</button>
                            <button class="btn btn-outline delete-milestone" data-id="${m.milestone_id}" style="padding:6px 10px;">Delete</button>
                        </td>
                    </tr>
                `).join('')}</table>`;
            json.data.forEach(m => {
                document.getElementById(`milestone_cohort_${m.milestone_id}`).value = m.cohort_id;
                document.getElementById(`milestone_type_${m.milestone_id}`).value = m.type;
            });
            document.querySelectorAll('.update-milestone').forEach(btn => btn.addEventListener('click', () => updateMilestone(btn.dataset.id)));
            document.querySelectorAll('.delete-milestone').forEach(btn => btn.addEventListener('click', () => deleteMilestone(btn.dataset.id)));
            document.querySelectorAll('.upload-instruction').forEach(btn => btn.addEventListener('click', () => uploadMilestoneInstruction(btn.dataset.id)));
        } catch (e) {
            container.innerHTML = '<p>Error loading milestones.</p>';
        }
    }

    async function loadTemplates() {
        const select = document.getElementById('templateSelect');
        if (!select) return;
        try {
            const res = await fetch('php/api/coordinator.php?action=get_templates');
            const json = await res.json();
            templatesCache = json.data || [];
            select.innerHTML = templatesCache.length
                ? templatesCache.map(t => `<option value="${t.template_id}">${escapeHtml(t.name)}</option>`).join('')
                : '<option value="">No templates yet</option>';
        } catch (err) {
            select.innerHTML = '<option value="">Error loading templates</option>';
        }
    }

    document.getElementById('templateCreateForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const alertEl = document.getElementById('templateAlert');
        const lines = document.getElementById('templateItems').value.split(/\r?\n/).map(v => v.trim()).filter(Boolean);
        const items = lines.map((name, index) => ({
            name,
            description: '',
            type: name.toLowerCase().includes('defense') ? 'defense' : (name.toLowerCase().includes('proposal') ? 'proposal' : 'standard_milestone'),
            display_order: index + 1
        }));
        const res = await fetch('php/api/coordinator.php?action=create_template', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: document.getElementById('templateName').value.trim(), description: '', items })
        });
        const json = await res.json();
        alertEl.textContent = json.message;
        alertEl.style.color = res.ok ? 'var(--color-success)' : 'var(--color-error)';
        if (res.ok) {
            e.target.reset();
            loadTemplates();
        }
    });

    document.getElementById('templateApplyForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const alertEl = document.getElementById('templateAlert');
        const templateId = document.getElementById('templateSelect').value;
        const template = templatesCache.find(t => String(t.template_id) === String(templateId));
        const defaultDate = document.getElementById('templateDefaultDate').value;
        const dates = {};
        (template?.items || []).forEach(item => { dates[item.item_id] = defaultDate; });
        const res = await fetch('php/api/coordinator.php?action=apply_template_to_cohort', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ template_id: templateId, cohort_id: document.getElementById('templateCohort').value, dates })
        });
        const json = await res.json();
        alertEl.textContent = json.message;
        alertEl.style.color = res.ok ? 'var(--color-success)' : 'var(--color-error)';
        if (res.ok) loadMilestoneCrud();
    });

    document.getElementById('saveDefenseDeadlineBtn')?.addEventListener('click', async () => {
        const alertEl = document.getElementById('templateAlert');
        const res = await fetch('php/api/coordinator.php?action=set_defense_deadline', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                cohort_id: document.getElementById('templateCohort').value,
                defense_deadline: document.getElementById('defenseDeadline').value
            })
        });
        const json = await res.json();
        alertEl.textContent = json.message;
        alertEl.style.color = res.ok ? 'var(--color-success)' : 'var(--color-error)';
        if (res.ok) loadMilestoneCrud();
    });

    async function uploadMilestoneInstruction(id) {
        const input = document.getElementById(`instruction_${id}`);
        if (!input?.files?.[0]) return alert('Choose an instruction file first.');
        const form = new FormData();
        form.append('milestone_id', id);
        form.append('instruction_file', input.files[0]);
        const res = await fetch('php/api/coordinator.php?action=upload_milestone_instruction', {
            method: 'POST',
            body: form
        });
        const json = await res.json();
        alert(json.message);
        if (res.ok) loadMilestoneCrud();
    }

    async function updateMilestone(id) {
        const payload = {
            milestone_id: id,
            cohort_id: document.getElementById(`milestone_cohort_${id}`).value,
            name: document.getElementById(`milestone_name_${id}`).value,
            type: document.getElementById(`milestone_type_${id}`).value,
            due_date: document.getElementById(`milestone_due_${id}`).value,
            description: document.getElementById(`milestone_desc_${id}`).value
        };
        const res = await fetch('php/api/coordinator.php?action=update_milestone', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        alert(json.message);
        if (res.ok) loadMilestoneCrud();
    }

    async function deleteMilestone(id) {
        if (!confirm('Delete this milestone? Milestones with submissions cannot be deleted.')) return;
        const res = await fetch('php/api/coordinator.php?action=delete_milestone', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ milestone_id: id })
        });
        const json = await res.json();
        alert(json.message);
        if (res.ok) loadMilestoneCrud();
    }

    // Load parent milestones when milestone cohort changes
    document.getElementById('cm_cohort')?.addEventListener('change', async (e) => {
        const cId = e.target.value;
        if (!cId) {
            document.getElementById('cm_parent').innerHTML = '<option value="">None</option>';
            return;
        }
        try {
            const res = await fetch(`php/api/coordinator.php?action=get_parent_milestones&cohort_id=${cId}`);
            const json = await res.json();
            if (json.status === 200) {
                const opts = '<option value="">None</option>' + 
                             json.data.map(m => `<option value="${m.milestone_id}">${m.name}</option>`).join('');
                document.getElementById('cm_parent').innerHTML = opts;
            }
        } catch(e) {}
    });

    // ── Form Submissions ──────────────────────────────────────

    // 1. Create User
    document.getElementById('createUserForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const alertEl = document.getElementById('cu_alert');
        alertEl.textContent = "Processing...";
        alertEl.style.color = "var(--color-primary)";

        const payload = {
            full_name: document.getElementById('cu_name').value,
            email: document.getElementById('cu_email').value,
            role: document.getElementById('cu_role').value,
            university_id_number: document.getElementById('cu_uid').value,
            department: document.getElementById('cu_department').value,
            password: document.getElementById('cu_password').value,
            cohort_id: document.getElementById('cu_cohort').value
        };

        try {
            const res = await fetch('php/api/coordinator.php?action=create_user', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            alertEl.textContent = data.message;
            if (res.ok) e.target.reset();
        } catch (err) {
            alertEl.textContent = "Network error.";
        }
    });

    // 2. Create Cohort
    document.getElementById('createCohortForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const alertEl = document.getElementById('cc_alert');
        alertEl.textContent = "Processing...";

        const payload = {
            name: document.getElementById('cc_name').value,
            max_students: document.getElementById('cc_max').value,
            start_date: document.getElementById('cc_start').value,
            end_date: document.getElementById('cc_end').value
        };

        try {
            const res = await fetch('php/api/coordinator.php?action=create_cohort', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            alertEl.textContent = data.message;
            if (res.ok) {
                e.target.reset();
                loadCohorts(); // reload dropdowns
            }
        } catch (err) {
            alertEl.textContent = "Network error.";
        }
    });

    // 3. Create Milestone
    document.getElementById('createMilestoneForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const alertEl = document.getElementById('cm_alert');
        alertEl.textContent = "Processing...";

        const payload = {
            cohort_id: document.getElementById('cm_cohort').value,
            name: document.getElementById('cm_name').value,
            type: document.getElementById('cm_type').value,
            due_date: document.getElementById('cm_date').value,
            parent_id: document.getElementById('cm_parent').value,
            description: document.getElementById('cm_desc').value
        };

        try {
            const res = await fetch('php/api/coordinator.php?action=create_milestone', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            alertEl.textContent = data.message;
            if (res.ok) e.target.reset();
        } catch (err) {
            alertEl.textContent = "Network error.";
        }
    });

    document.getElementById('supervisorCohortForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const alertEl = document.getElementById('supervisorCohortAlert');
        try {
            const res = await fetch('php/api/coordinator.php?action=assign_supervisor_cohort', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    supervisor_id: document.getElementById('scSupervisor').value,
                    cohort_id: document.getElementById('scCohort').value
                })
            });
            const json = await res.json();
            alertEl.textContent = json.message;
            alertEl.style.color = res.ok ? 'var(--color-success)' : 'var(--color-error)';
            loadAllocations();
            loadUnassignedCohortOptions();
        } catch (err) {
            alertEl.textContent = 'Network error.';
            alertEl.style.color = 'var(--color-error)';
        }
    });

    document.getElementById('scSupervisor')?.addEventListener('change', loadUnassignedCohortOptions);

    document.getElementById('manualTransferSearchForm')?.addEventListener('submit', (e) => {
        e.preventDefault();
        manualTransferQuery = document.getElementById('manualTransferSearchInput')?.value.trim() || '';
        manualTransferPage = 1;
        loadManualTransferProjects(latestSupervisorOptions);
    });

    async function loadAllocationWorkspace() {
        await loadSettings();
        await loadAllocations();
        await loadTransferRequests();
    }

    // 4. Load Allocations
    async function loadAllocations() {
        const container = document.getElementById('allocationContainer');
        container.innerHTML = "Loading...";
        try {
            const res = await fetch('php/api/coordinator.php?action=get_allocations');
            const data = await res.json();
            
            // To build the dropdown for assignment
            let supOptions = '<option value="">Select Supervisor...</option>';

            if (res.ok && data.data.length > 0) {
                let html = '<table class="data-table"><tr><th>Supervisor Name</th><th>Assigned Projects</th><th>Cohorts</th></tr>';
                data.data.forEach(s => {
                    const loadText = `${s.assigned_count}/${s.max_total_students} students${s.is_full ? ' (Full)' : ''}`;
                    html += `<tr>
                        <td>${s.full_name}</td>
                        <td>${loadText}</td>
                        <td>${s.cohorts || '<em>None assigned</em>'}</td>
                    </tr>`;
                    supOptions += `<option value="${s.user_id}" ${s.is_full ? 'disabled' : ''}>${s.full_name} (${loadText})</option>`;
                });
                latestSupervisorOptions = supOptions;
                html += '</table>';
                container.innerHTML = html;
                const scSupervisor = document.getElementById('scSupervisor');
                if (scSupervisor) scSupervisor.innerHTML = '<option value="">Select Supervisor...</option>' + data.data.map(s => `<option value="${s.user_id}">${s.full_name}</option>`).join('');
                loadUnassignedCohortOptions();
            } else {
                container.innerHTML = "<p>No supervisors found.</p>";
            }

            loadUnassigned(supOptions);
            loadManualTransferProjects(supOptions);
        } catch (e) {
            container.innerHTML = "<p>Error loading allocations.</p>";
        }
    }

    async function loadUnassignedCohortOptions() {
        const supId = document.getElementById('scSupervisor')?.value || '';
        const cohortSelect = document.getElementById('scCohort');
        if (!cohortSelect) return;
        if (!supId) {
            cohortSelect.innerHTML = '<option value="">Select a supervisor first...</option>';
            return;
        }
        cohortSelect.innerHTML = '<option value="">Loading...</option>';
        try {
            const res = await fetch(`php/api/coordinator.php?action=get_supervisor_unassigned_cohorts&supervisor_id=${encodeURIComponent(supId)}`);
            const json = await res.json();
            const cohorts = json.data || [];
            cohortSelect.innerHTML = cohorts.length
                ? '<option value="">Select Cohort...</option>' + cohorts.map(c => `<option value="${c.cohort_id}">${escapeHtml(c.name)}</option>`).join('')
                : '<option value="">No remaining cohorts</option>';
        } catch (err) {
            cohortSelect.innerHTML = '<option value="">Error loading cohorts</option>';
        }
    }

    async function loadUnassigned(supOptions) {
        const container = document.getElementById('pendingAllocationsContainer');
        container.innerHTML = "Loading...";
        try {
            const res = await fetch('php/api/coordinator.php?action=get_unassigned_projects');
            const data = await res.json();

            if (res.ok && data.data.length > 0) {
                let html = '<table class="data-table"><tr><th>Student</th><th>Cohort</th><th>Top Preferences</th><th>Assign Supervisor</th></tr>';
                data.data.forEach(p => {
                    let prefs = p.preferences.map(pref => `${pref.priority_rank}. ${pref.full_name}`).join('<br>');
                    if (!prefs) prefs = '<em>No preferences submitted</em>';

                    html += `<tr>
                        <td>${p.student_name}</td>
                        <td>${p.cohort_name}</td>
                        <td style="font-size:0.85rem;">${prefs}</td>
                        <td>
                            <select class="assign-select" id="assign_${p.project_id}" style="margin-bottom:8px; width:100%; padding:6px; border-radius:4px; border:1px solid #ccc;">
                                ${supOptions}
                            </select>
                            <button class="btn btn-primary assign-btn" data-project="${p.project_id}" style="padding:6px 12px; font-size:0.8rem; background:var(--color-primary); color:#fff; width:100%;">Assign</button>
                        </td>
                    </tr>`;
                });
                html += '</table>';
                container.innerHTML = html;

                document.querySelectorAll('.assign-btn').forEach(btn => {
                    btn.addEventListener('click', async (e) => {
                        const pId = btn.getAttribute('data-project');
                        const sId = document.getElementById(`assign_${pId}`).value;
                        if (!sId) return alert("Please select a supervisor.");
                        
                        btn.disabled = true;
                        btn.textContent = "Assigning...";
                        
                        try {
                            const r = await fetch('php/api/coordinator.php?action=assign_supervisor', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({ project_id: pId, supervisor_id: sId })
                            });
                            if (r.ok) {
                                loadAllocations(); // Reload both tables
                            } else {
                                const json = await r.json().catch(() => ({ message: 'Failed to assign.' }));
                                alert(json.message || "Failed to assign.");
                                btn.disabled = false;
                                btn.textContent = "Assign";
                            }
                        } catch(err) {
                            alert("Network Error");
                            btn.disabled = false;
                            btn.textContent = "Assign";
                        }
                    });
                });
            } else {
                container.innerHTML = "<p>No pending allocations.</p>";
            }
        } catch(e) {
            container.innerHTML = "<p>Error loading pending allocations.</p>";
        }
    }

    async function loadTransferRequests() {
        const container = document.getElementById('transferRequestsContainer');
        if (!container) return;
        try {
            const [reqRes, supRes] = await Promise.all([
                fetch('php/api/coordinator.php?action=get_transfer_requests'),
                fetch('php/api/coordinator.php?action=get_allocations')
            ]);
            const reqJson = await reqRes.json();
            const supJson = await supRes.json();
            const supervisors = supJson.data || [];
            const supOptions = '<option value="">Use requested supervisor</option>' + supervisors.map(s => `<option value="${s.user_id}" ${s.is_full ? 'disabled' : ''}>${s.full_name} (${s.assigned_count}/${s.max_total_students})</option>`).join('');

            if (!reqJson.data || reqJson.data.length === 0) {
                container.innerHTML = '<p>No transfer requests yet.</p>';
                return;
            }

            container.innerHTML = `<table class="data-table"><tr><th>Student</th><th>Current</th><th>Requested</th><th>Reason</th><th>Status/Action</th></tr>
                ${reqJson.data.map(r => `
                    <tr>
                        <td><strong>${escapeHtml(r.student_name)}</strong><br><small>${escapeHtml(r.project_title)}</small></td>
                        <td>${escapeHtml(r.current_supervisor_name || 'None')}</td>
                        <td>${escapeHtml(r.requested_supervisor_name || 'No specific preference')}</td>
                        <td>${escapeHtml(r.reason)}</td>
                        <td>
                            <div style="margin-bottom:8px;"><strong>${escapeHtml(r.status)}</strong></div>
                            ${r.status === 'pending' ? `
                                <select id="transfer_sup_${r.request_id}" style="width:100%; margin-bottom:6px;">${supOptions}</select>
                                <textarea id="transfer_comment_${r.request_id}" rows="2" placeholder="Coordinator comment" style="width:100%; margin-bottom:6px;"></textarea>
                                <button class="btn btn-primary approve-transfer" data-id="${r.request_id}" style="padding:6px 10px; background:var(--color-primary); color:#fff;">Approve</button>
                                <button class="btn btn-outline reject-transfer" data-id="${r.request_id}" style="padding:6px 10px;">Reject</button>
                            ` : ''}
                        </td>
                    </tr>
                `).join('')}</table>`;
            document.querySelectorAll('.approve-transfer').forEach(btn => btn.addEventListener('click', () => processTransferRequest(btn.dataset.id, 'approved')));
            document.querySelectorAll('.reject-transfer').forEach(btn => btn.addEventListener('click', () => processTransferRequest(btn.dataset.id, 'rejected')));
        } catch (e) {
            container.innerHTML = '<p>Error loading transfer requests.</p>';
        }
    }

    async function processTransferRequest(id, decision) {
        const res = await fetch('php/api/coordinator.php?action=process_transfer_request', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                request_id: id,
                decision,
                supervisor_id: document.getElementById(`transfer_sup_${id}`)?.value || '',
                coordinator_comment: document.getElementById(`transfer_comment_${id}`)?.value || ''
            })
        });
        const json = await res.json();
        alert(json.message);
        if (res.ok) {
            loadTransferRequests();
            loadAllocations();
            loadOverview();
            loadMetrics();
        }
    }

    async function loadManualTransferProjects(supOptions) {
        const container = document.getElementById('manualTransferContainer');
        if (!container) return;
        if (!manualTransferQuery) {
            container.innerHTML = '<p class="text-muted">Enter an admission number to search assigned students.</p>';
            return;
        }
        try {
            const res = await fetch(`php/api/coordinator.php?action=search_transfer_students&admission_number=${encodeURIComponent(manualTransferQuery)}&page=${manualTransferPage}`);
            const json = await res.json();
            const assigned = (json.data?.results || []).filter(p => p.supervisor_name);
            if (assigned.length === 0) {
                container.innerHTML = '<p>No assigned students found for that admission number.</p>';
                return;
            }
            const totalPages = Number(json.data?.total_pages || 1);
            container.innerHTML = `<table class="data-table"><tr><th>Student</th><th>Current Supervisor</th><th>Transfer To</th></tr>
                ${assigned.map(p => `
                    <tr>
                        <td><strong>${escapeHtml(p.student_name)}</strong><br><small>${escapeHtml(p.university_id_number)} - ${escapeHtml(p.title)}</small></td>
                        <td>${escapeHtml(p.supervisor_name || 'Unassigned')}</td>
                        <td>
                            <select id="manual_transfer_${p.project_id}" style="width:100%; margin-bottom:6px;">${supOptions}</select>
                            <button class="btn btn-primary manual-transfer-btn" data-project="${p.project_id}" style="padding:6px 12px; background:var(--color-primary); color:#fff; width:100%;">Transfer</button>
                        </td>
                    </tr>
                `).join('')}</table>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:12px;">
                    <button class="btn btn-outline" id="manualTransferPrev" ${manualTransferPage <= 1 ? 'disabled' : ''}>Previous</button>
                    <span class="text-muted">Page ${manualTransferPage} of ${totalPages}</span>
                    <button class="btn btn-outline" id="manualTransferNext" ${manualTransferPage >= totalPages ? 'disabled' : ''}>Next</button>
                </div>`;
            document.getElementById('manualTransferPrev')?.addEventListener('click', () => {
                manualTransferPage = Math.max(1, manualTransferPage - 1);
                loadManualTransferProjects(supOptions);
            });
            document.getElementById('manualTransferNext')?.addEventListener('click', () => {
                manualTransferPage += 1;
                loadManualTransferProjects(supOptions);
            });
            document.querySelectorAll('.manual-transfer-btn').forEach(btn => btn.addEventListener('click', async () => {
                const pId = btn.dataset.project;
                const sId = document.getElementById(`manual_transfer_${pId}`).value;
                if (!sId) return alert('Select a supervisor.');
                const r = await fetch('php/api/coordinator.php?action=transfer_student', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ project_id: pId, supervisor_id: sId })
                });
                const data = await r.json();
                alert(data.message);
                if (r.ok) loadAllocationWorkspace();
            }));
        } catch (e) {
            container.innerHTML = '<p>Error loading manual transfer projects.</p>';
        }
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#096;');
    }
});
