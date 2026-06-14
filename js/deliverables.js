'use strict';
document.addEventListener('DOMContentLoaded', async () => {
    try {
        const res = await fetch('php/api/dashboard.php');
        const json = await res.json();
        if (json.status === 200 && json.data && json.data.milestones) {
            const container = document.querySelector('.card');
            const milestones = json.data.milestones;
            if (milestones.length === 0) {
                container.innerHTML = `<h3>Milestones</h3><p class="text-muted">No milestones found.</p>`;
                return;
            }
            let html = `<h3>Milestones</h3><div style="margin-top: 15px; display: flex; flex-direction: column; gap: 15px;">`;
            milestones.forEach(m => {
                const status = (m.submission_status || 'Pending').replace(/_/g, ' ').toUpperCase();
                const statusClass = approvedStatuses().includes(m.submission_status) ? 'status-approved' : 'status-pending';
                html += `
                    <div class="milestone-submit-card ${statusClass}" style="padding: 15px; border: 1px solid var(--color-border); border-radius: 8px;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                            <h4 style="margin: 0;">${escapeHtml(m.name)}</h4>
                            <span class="status-pill" style="font-size: 0.8rem; padding: 4px 8px; border-radius: 4px;">${status}</span>
                        </div>
                        <p class="text-sm text-muted" style="margin-bottom: 10px;">${escapeHtml(m.description || 'No description')}</p>
                        <div class="text-xs" style="margin-bottom: 12px;">
                            <strong>Due:</strong> ${new Date(m.due_date).toLocaleDateString()}<br>
                            ${m.file_path ? `<a href="${escapeAttr(m.file_path)}" target="_blank" style="color: var(--color-primary); margin-top: 5px; display: inline-block;">View Latest Submitted File</a>` : ''}
                        </div>
                        
                        ${approvedStatuses().includes(m.submission_status) ? `<div class="alert alert-success show" style="margin-top:10px;">Milestone Completed. No further submissions required.</div>` : ''}
                        ${m.is_locked ? `<div class="alert alert-warning show" style="margin-top:10px;">${escapeHtml(m.locked_reason || 'Locked: Complete previous milestone first.')}</div>` : ''}
                        ${m.can_submit ? `
                        <form class="submission-form" data-milestone="${m.milestone_id}" style="margin-top: 15px; border-top: 1px solid var(--color-border); padding-top: 15px;">
                            <h5 style="margin: 0 0 10px 0;">New Submission / Update</h5>
                            <textarea name="student_text" rows="3" placeholder="Type your update or reflection here..." style="width:100%; padding:8px; border:1px solid var(--color-border); border-radius:4px; margin-bottom:10px;"></textarea>
                            <input type="file" name="file" style="margin-bottom:10px; width:100%;">
                            <button type="submit" class="btn btn-primary" style="padding: 6px 12px; font-size: 0.8rem;">Submit Update</button>
                            <div class="sub-alert" style="margin-top: 8px; font-size: 0.85rem; font-weight: bold;"></div>
                        </form>
                        ` : ''}
                    </div>
                `;
            });
            html += `</div>`;
            container.innerHTML = html;

            // Attach listeners
            document.querySelectorAll('.submission-form').forEach(form => {
                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const milestoneId = form.getAttribute('data-milestone');
                    const alertBox = form.querySelector('.sub-alert');
                    const btn = form.querySelector('button');

                    const formData = new FormData(form);
                    formData.append('milestone_id', milestoneId);

                    btn.disabled = true;
                    btn.textContent = 'Submitting...';
                    alertBox.textContent = '';

                    try {
                        const res = await fetch('php/api/submissions.php?action=submit_milestone', {
                            method: 'POST',
                            body: formData // FormData automatically sets multipart/form-data headers
                        });
                        const data = await res.json();

                        if (res.ok) {
                            alertBox.style.color = 'var(--color-success)';
                            alertBox.textContent = "Submission successful!";
                            setTimeout(() => window.location.reload(), 1000);
                        } else {
                            alertBox.style.color = 'var(--color-error)';
                            alertBox.textContent = data.message || "Failed to submit.";
                            btn.disabled = false;
                            btn.textContent = 'Submit Update';
                        }
                    } catch (err) {
                        alertBox.style.color = 'var(--color-error)';
                        alertBox.textContent = "Network error.";
                        btn.disabled = false;
                        btn.textContent = 'Submit Update';
                    }
                });
            });
        }
    } catch (e) {
        console.error("Error loading deliverables:", e);
    }
});

function approvedStatuses() {
    return ['supervisor_approved', 'coordinator_approved', 'approved', 'satisfactory'];
}

function escapeHtml(value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function escapeAttr(value) {
    return escapeHtml(value).replace(/`/g, '&#096;');
}
