'use strict';
document.addEventListener('DOMContentLoaded', () => {
    let page = 1;
    loadMessages(page);

    async function loadMessages(nextPage) {
        page = nextPage;
    try {
        const res = await fetch(`php/api/dashboard.php?action=get_feedback&page=${page}`);
        const json = await res.json();
        if (json.status === 200 && json.data) {
            const container = document.querySelector('.card');
            const feedback = json.data.messages || [];
            if (feedback.length === 0) {
                container.innerHTML = `<h3>Messages & Feedback</h3><p class="text-muted">No direct messages or feedback available.</p>`;
                return;
            }
            let html = `<h3>Messages & Feedback</h3><div style="margin-top: 15px; display: flex; flex-direction: column; gap: 15px;">`;
            feedback.forEach(f => {
                html += `
                    <div style="padding: 15px; border: 1px solid var(--color-border); border-radius: 8px; background: #F9FAFB;">
                        <h4 style="margin: 0 0 10px 0;">Re: ${f.milestone_name}</h4>
                        ${f.supervisor_feedback ? `<div style="margin-bottom: 10px;"><strong class="text-sm">Supervisor:</strong><p class="text-sm" style="margin-top: 4px;">${f.supervisor_feedback}</p></div>` : ''}
                        ${f.coordinator_feedback ? `<div><strong class="text-sm">Coordinator:</strong><p class="text-sm" style="margin-top: 4px;">${f.coordinator_feedback}</p></div>` : ''}
                        <div class="text-xs text-muted" style="margin-top: 10px;">Submitted: ${new Date(f.submitted_at).toLocaleDateString()}</div>
                    </div>
                `;
            });
            html += `</div>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:16px;">
                    <button class="btn btn-outline" id="messagesPrev" ${page <= 1 ? 'disabled' : ''}>Previous</button>
                    <span class="text-sm text-muted">Page ${json.data.page} of ${json.data.total_pages || 1}</span>
                    <button class="btn btn-outline" id="messagesNext" ${page >= (json.data.total_pages || 1) ? 'disabled' : ''}>Next</button>
                </div>`;
            container.innerHTML = html;
            document.getElementById('messagesPrev')?.addEventListener('click', () => loadMessages(Math.max(1, page - 1)));
            document.getElementById('messagesNext')?.addEventListener('click', () => loadMessages(page + 1));
        }
    } catch (e) { }
    }
});
