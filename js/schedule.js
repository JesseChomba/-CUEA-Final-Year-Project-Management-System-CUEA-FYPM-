'use strict';
document.addEventListener('DOMContentLoaded', async () => {
    // Layout Toggle Logic
    const btnList = document.getElementById('btnListView');
    const btnCal = document.getElementById('btnCalendarView');
    const viewList = document.getElementById('listView');
    const viewCal = document.getElementById('calendarView');
    let calendarInstance = null;

    btnList.addEventListener('click', () => {
        btnList.classList.add('active');
        btnCal.classList.remove('active');
        viewList.style.display = 'block';
        viewCal.style.display = 'none';
    });

    btnCal.addEventListener('click', () => {
        btnCal.classList.add('active');
        btnList.classList.remove('active');
        viewCal.style.display = 'block';
        viewList.style.display = 'none';
        if (calendarInstance) {
            calendarInstance.render();
        }
    });

    try {
        const res = await fetch('php/api/dashboard.php');
        const json = await res.json();
        
        if (json.status === 200 && json.data && json.data.milestones) {
            const container = document.querySelector('#listView .card');
            const milestones = json.data.milestones;
            
            // Render List View
            if (milestones.length === 0) {
                container.innerHTML = `<h3>Upcoming Deadlines & Meetings</h3><p class="text-muted">No schedule items found.</p>`;
            } else {
                let html = `<h3>Upcoming Deadlines & Meetings</h3><div class="schedule-list">`;
                milestones.forEach(m => {
                    const isCompleted = ['supervisor_approved', 'coordinator_approved', 'approved'].includes(m.submission_status);
                    const color = isCompleted ? 'var(--color-success)' : (m.submission_status === 'submitted' ? 'var(--color-primary)' : '#F59E0B');
                    html += `
                        <div class="schedule-item" style="border-left: 4px solid ${color};">
                            <div class="schedule-item-head">
                                <strong>${m.name}</strong>
                                <span class="text-sm text-muted">${new Date(m.due_date).toLocaleDateString()}</span>
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;
                container.innerHTML = html;
            }

            // Render Calendar View
            const calendarEl = document.getElementById('calendar');
            const events = milestones.map(m => {
                const isCompleted = ['supervisor_approved', 'coordinator_approved', 'approved'].includes(m.submission_status);
                let color = '#F59E0B'; // Pending
                if (isCompleted) color = '#10B981'; // Success
                else if (m.submission_status === 'submitted') color = '#3B82F6'; // Primary
                else if (m.submission_status === 'revision_required') color = '#EF4444'; // Error

                return {
                    title: m.name,
                    start: m.due_date,
                    color: color,
                    allDay: true
                };
            });

            const compactCalendar = window.matchMedia('(max-width: 640px)').matches;
            calendarInstance = new FullCalendar.Calendar(calendarEl, {
                initialView: compactCalendar ? 'listMonth' : 'dayGridMonth',
                events: events,
                headerToolbar: {
                    left: compactCalendar ? 'prev,next' : 'prev,next today',
                    center: 'title',
                    right: compactCalendar ? 'listMonth,dayGridMonth' : 'dayGridMonth,timeGridWeek,listWeek'
                },
                height: compactCalendar ? 'auto' : 600
            });
            // We don't render it here, wait until toggle to prevent sizing bugs
        }
    } catch(e) {
        console.error("Schedule error:", e);
    }
});
