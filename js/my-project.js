'use strict';

document.addEventListener('DOMContentLoaded', async () => {
    try {
        const res = await fetch('php/api/dashboard.php');
        const json = await res.json();
        
        if (json.status === 200 && json.data && json.data.project) {
            const p = json.data.project;
            const container = document.querySelector('.card');
            
            container.innerHTML = `
                <h3>Project Information</h3>
                <div style="margin-top: 15px; display: flex; flex-direction: column; gap: 10px;">
                    <div><strong>Title:</strong> ${p.title || 'Untitled Project'}</div>
                    <div><strong>Description:</strong> ${p.description || 'No description provided.'}</div>
                    <div><strong>Status:</strong> <span style="padding: 4px 8px; border-radius: 4px; background: var(--color-bg); font-size: 0.85rem;">${(p.status || 'Draft').replace(/_/g, ' ').toUpperCase()}</span></div>
                    <div><strong>Cohort:</strong> ${p.cohort_name || 'N/A'} (${p.start_date} to ${p.end_date})</div>
                </div>
            `;
        }
    } catch (e) {
        console.error("Failed to fetch project info", e);
    }
});
