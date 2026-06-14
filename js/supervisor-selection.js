/**
 * CUEA FYPM – Student Supervisor Selection JS
 */

'use strict';

// Global fetch interceptor for session expiration
const originalFetch = window.fetch;
window.fetch = async function() {
    const res = await originalFetch.apply(this, arguments);
    if (res.status === 401 || res.status === 403) {
        const cloned = res.clone();
        try {
            const data = await cloned.json();
            if (data.status === 401 || data.message.toLowerCase().includes('unauthorized') || data.message.toLowerCase().includes('session')) {
                alert("Your session has expired. Please refresh the page to log in again.");
                window.location.href = 'index.html?reason=session_expired';
            }
        } catch(e) {}
    }
    return res;
};

document.addEventListener('DOMContentLoaded', () => {
    const facultyList = document.getElementById('facultyList');
    const preferenceList = document.getElementById('preferenceList');
    const submitBtn = document.getElementById('submitPreferences');
    const confirmCheckbox = document.getElementById('confirmChoices');
    
    let projectId = null;
    let selectedPrefs = []; // Array of supervisor IDs
    let allSupervisors = []; // To hold all fetched supervisors for filtering

    // Verify session
    (async function checkSession() {
        try {
            const res = await fetch('php/api/auth.php?action=session');
            const data = await res.json();
            if (!res.ok || data.data.role !== 'student') {
                window.location.href = 'index.html?reason=session_expired';
                return;
            }
            // If they already have a supervisor assigned, redirect to dashboard
            if (data.data.has_supervisor) {
                window.location.href = 'dashboard.php';
                return;
            }
            if (data.data.has_pending_supervisor_preferences) {
                window.location.href = 'pending-supervisor.php';
                return;
            }
            
            // Populate user info in header
            document.querySelector('.user-profile-summary span').textContent = data.data.full_name;
            const avatarUrl = `https://ui-avatars.com/api/?name=${encodeURIComponent(data.data.full_name)}&background=7D1316&color=fff`;
            document.querySelector('.user-profile-summary img.avatar').src = avatarUrl;
            
            loadSupervisors();
        } catch (e) {
            window.location.href = 'index.html?reason=session_expired';
        }
    })();

    async function loadSupervisors() {
        facultyList.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px;">Loading supervisors...</td></tr>';
        
        try {
            const res = await fetch('php/api/student_supervisors.php?action=get_available');
            const json = await res.json();
            
            if (json.status === 200) {
                projectId = json.data.project_id;
                allSupervisors = json.data.supervisors || [];
                renderSupervisors(allSupervisors);
            } else {
                facultyList.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:20px; color:red;">${json.message}</td></tr>`;
            }
        } catch (e) {
            facultyList.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:red;">Network Error loading supervisors.</td></tr>';
        }
    }

    // Filtering logic
    const searchInput = document.querySelector('.filter-search input');
    const deptSelect = document.querySelector('.filter-select');

    function filterSupervisors() {
        const query = searchInput.value.toLowerCase();
        const dept = deptSelect.value;
        const filtered = allSupervisors.filter(sup => {
            const matchesQuery = sup.full_name.toLowerCase().includes(query) || (sup.department && sup.department.toLowerCase().includes(query));
            const matchesDept = dept === "" || sup.department === dept;
            return matchesQuery && matchesDept;
        });
        renderSupervisors(filtered);
    }

    if (searchInput) searchInput.addEventListener('input', filterSupervisors);
    if (deptSelect) deptSelect.addEventListener('change', filterSupervisors);

    function renderSupervisors(supervisors) {
        if (!supervisors || supervisors.length === 0) {
            facultyList.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px;">No supervisors available.</td></tr>';
            return;
        }

        let html = '';
        supervisors.forEach(sup => {
            // Calculate availability bar
            let fillPct = (sup.assigned / sup.capacity) * 100;
            if (fillPct > 100) fillPct = 100;
            
            let colorClass = 'success';
            let textColor  = 'var(--color-success)';
            if (fillPct >= 100) { colorClass = 'error'; textColor = 'var(--color-error)'; }
            else if (fillPct >= 80) { colorClass = 'warning'; textColor = '#F59E0B'; }

            const avatarName = encodeURIComponent(sup.full_name);
            const isFull = sup.is_full;
            
            html += `
            <tr>
              <td>
                <div class="faculty-info">
                  <img src="https://ui-avatars.com/api/?name=${avatarName}&background=random&color=fff" alt="${sup.full_name}" class="avatar">
                  <div class="faculty-details">
                    <span class="name">${sup.full_name}</span>
                    <span class="dept">${sup.department}</span>
                  </div>
                </div>
              </td>
              <td>
                <div class="availability-wrap">
                  <div class="availability-text">
                    <span style="color: ${textColor}">${sup.assigned}/${sup.capacity} ${isFull ? 'Full' : 'Filled'}</span>
                  </div>
                  <div class="progress-bar-container">
                    <div class="progress-bar-fill ${colorClass}" style="width: ${fillPct}%;"></div>
                  </div>
                </div>
              </td>
              <td>
                <button class="action-btn add-pref" 
                        data-id="${sup.user_id}" 
                        data-name="${sup.full_name}" 
                        ${isFull ? 'disabled' : ''}>
                  ${isFull ? `
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
                  </svg>` : `
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                    <polyline points="12 5 19 12 12 19"></polyline>
                  </svg>`}
                </button>
              </td>
            </tr>
            `;
        });
        
        facultyList.innerHTML = html;
        
        // Update pagination text
        document.querySelector('.text-muted.text-sm').textContent = `Showing 1 to ${supervisors.length} of ${supervisors.length} results`;

        attachAddListeners();
    }

    function attachAddListeners() {
        document.querySelectorAll('.add-pref').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const supId = btn.getAttribute('data-id');
                const supName = btn.getAttribute('data-name');
                
                if (selectedPrefs.length >= 5) {
                    alert("You can only select up to 5 preferences.");
                    return;
                }
                
                if (selectedPrefs.some(p => p.id === supId)) {
                    alert("This supervisor is already in your preferences.");
                    return;
                }

                selectedPrefs.push({ id: supId, name: supName });
                renderPreferences();
            });
        });
    }

    function renderPreferences() {
        preferenceList.innerHTML = '';
        
        for (let i = 0; i < 5; i++) {
            const rank = i + 1;
            if (i < selectedPrefs.length) {
                const pref = selectedPrefs[i];
                preferenceList.innerHTML += `
                    <div class="preference-slot filled" style="display:flex; justify-content:space-between; align-items:center; background:#fff; border:1px solid var(--color-primary); padding:12px; border-radius:8px; margin-bottom:8px;">
                        <div><strong style="color:var(--color-primary); margin-right:8px;">${rank}.</strong> ${pref.name}</div>
                        <button class="remove-pref" data-index="${i}" style="background:none; border:none; color:var(--color-error); cursor:pointer;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        </button>
                    </div>
                `;
            } else {
                preferenceList.innerHTML += `<div class="preference-slot" style="background:#F3F4F6; color:#9CA3AF; padding:12px; border-radius:8px; margin-bottom:8px; text-align:center;">Rank ${rank} (Empty)</div>`;
            }
        }

        // Attach remove listeners
        document.querySelectorAll('.remove-pref').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const idx = btn.getAttribute('data-index');
                selectedPrefs.splice(idx, 1);
                renderPreferences();
            });
        });

        checkSubmitState();
    }

    function checkSubmitState() {
        if (selectedPrefs.length >= 3 && confirmCheckbox.checked) {
            submitBtn.disabled = false;
        } else {
            submitBtn.disabled = true;
        }
    }

    confirmCheckbox.addEventListener('change', checkSubmitState);

    submitBtn.addEventListener('click', async () => {
        if (!projectId) return;
        
        submitBtn.disabled = true;
        submitBtn.textContent = "Submitting...";

        const payload = {
            project_id: projectId,
            preferences: selectedPrefs.map(p => p.id)
        };

        try {
            const res = await fetch('php/api/student_supervisors.php?action=submit_preferences', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            
            if (res.ok) {
                alert("Preferences submitted successfully! The Coordinator will allocate your supervisor shortly.");
                window.location.href = 'pending-supervisor.php';
            } else {
                alert("Error: " + data.message);
                submitBtn.disabled = false;
                submitBtn.textContent = "Submit Preferences";
            }
        } catch(e) {
            alert("Network error.");
            submitBtn.disabled = false;
            submitBtn.textContent = "Submit Preferences";
        }
    });

});
