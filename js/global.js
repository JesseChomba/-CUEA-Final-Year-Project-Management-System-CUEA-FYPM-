/**
 * global.js - Loaded on all protected dashboard pages
 * Handles: Session Inactivity, Fetch Interceptor (401 catch), Profile Modal
 */

'use strict';

function redirectToLogin(reason = 'session_expired') {
    window.location.href = `index.html?reason=${encodeURIComponent(reason)}`;
}

//  Fetch Interceptor 
const originalFetch = window.fetch;
window.fetch = async function (...args) {
    try {
        const response = await originalFetch(...args);
        if (response.status === 401 || response.status === 403) {
            let reason = response.status === 403 ? 'role_changed' : 'session_expired';
            try {
                const data = await response.clone().json();
                reason = data?.data?.reason || reason;
            } catch (e) { }
            redirectToLogin(reason);
            return response; // Return anyway, though redirect happens
        }
        return response;
    } catch (error) {
        throw error;
    }
};

//  Inactivity Tracker 
const INACTIVITY_TIMEOUT_MS = 15 * 60 * 1000; // 15 minutes
let inactivityTimer;

function resetInactivityTimer() {
    clearTimeout(inactivityTimer);
    inactivityTimer = setTimeout(() => {
        // Ping logout endpoint and redirect
        fetch('php/api/auth.php?action=logout', { method: 'POST' })
            .then(() => {
                redirectToLogin('session_expired');
            }).catch(() => {
                redirectToLogin('session_expired');
            });
    }, INACTIVITY_TIMEOUT_MS);
}

// Listen to activity
['mousemove', 'mousedown', 'keypress', 'touchmove'].forEach(event => {
    document.addEventListener(event, resetInactivityTimer, { passive: true });
});
// Start timer initially
resetInactivityTimer();

//  Profile Modal 
function createProfileModal() {
    if (document.getElementById('profileEditModal')) return;

    const modalHtml = `
    <div id="profileEditModal" class="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;">
        <div class="modal-content" style="background: white; padding: 24px; border-radius: 8px; width: 400px; max-width: 90%;">
            <h3 style="margin-top: 0;">Edit Profile</h3>
            <form id="profileEditForm">
                <div class="form-group" style="margin-bottom: 12px;">
                    <label style="display:block; font-size:0.85rem; margin-bottom:4px; color:var(--color-muted);">Full Name</label>
                    <input type="text" id="profileFullName" class="form-input" style="width: 100%; padding: 8px; border: 1px solid var(--color-border); border-radius: 4px;" required>
                </div>
                <div class="form-group" style="margin-bottom: 12px;">
                    <label style="display:block; font-size:0.85rem; margin-bottom:4px; color:var(--color-muted);">New Password (leave blank to keep current)</label>
                    <input type="password" id="profileNewPassword" class="form-input" style="width: 100%; padding: 8px; border: 1px solid var(--color-border); border-radius: 4px;">
                </div>
                <div id="profileAlert" class="alert" style="display: none; margin-bottom:12px;"></div>
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px;">
                    <button type="button" class="btn btn-outline" id="closeProfileModal" style="padding: 8px 16px;">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveProfileBtn" style="padding: 8px 16px;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    `;

    document.body.insertAdjacentHTML('beforeend', modalHtml);

    document.getElementById('closeProfileModal').addEventListener('click', () => {
        document.getElementById('profileEditModal').style.display = 'none';
    });

    document.getElementById('profileEditForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const alertBox = document.getElementById('profileAlert');
        const fullName = document.getElementById('profileFullName').value;
        const newPassword = document.getElementById('profileNewPassword').value;
        const btn = document.getElementById('saveProfileBtn');

        btn.disabled = true;
        btn.textContent = "Saving...";

        try {
            const res = await fetch('php/api/auth.php?action=update_profile', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ full_name: fullName, password: newPassword })
            });
            const data = await res.json();

            alertBox.style.display = 'block';
            if (res.ok && data.status === 200) {
                alertBox.className = 'alert alert-success show';
                alertBox.textContent = "Profile updated successfully!";
                setTimeout(() => {
                    document.getElementById('profileEditModal').style.display = 'none';
                    window.location.reload();
                }, 1000);
            } else {
                alertBox.className = 'alert alert-error show';
                alertBox.textContent = data.message || "Failed to update profile.";
                btn.disabled = false;
                btn.textContent = "Save Changes";
            }
        } catch (err) {
            alertBox.style.display = 'block';
            alertBox.className = 'alert alert-error show';
            alertBox.textContent = "Network error.";
            btn.disabled = false;
            btn.textContent = "Save Changes";
        }
    });
}

function openProfileModal(currentFullName) {
    createProfileModal();
    document.getElementById('profileFullName').value = currentFullName || '';
    document.getElementById('profileNewPassword').value = '';
    document.getElementById('profileAlert').style.display = 'none';
    document.getElementById('profileEditModal').style.display = 'flex';
}

// Attach to profile clicks in sidebar
document.addEventListener('DOMContentLoaded', () => {
    // Add edit profile button to sidebars if user-info exists
    const userInfoBoxes = document.querySelectorAll('.sidebar-footer .user-info');
    userInfoBoxes.forEach(box => {
        const nameEl = box.querySelector('.name');
        if (nameEl) {
            box.style.cursor = 'pointer';
            box.title = "Click to edit profile";
            box.addEventListener('click', () => openProfileModal(nameEl.textContent));
            // Add a small edit icon visually next to name
            if (!nameEl.querySelector('.edit-icon')) {
                nameEl.innerHTML += ` <svg class="edit-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity:0.5; margin-left:4px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>`;
            }
        }
    });

    // Run lazy check for overdue milestones
    fetch('php/api/notifications.php?action=lazy_overdue_check', { method: 'POST' }).catch(e => console.error(e));

    // Intercept logout button clicks
    const logoutBtns = document.querySelectorAll('.logout-btn');
    logoutBtns.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            fetch('php/api/auth.php?action=logout', { method: 'POST' })
                .then(() => redirectToLogin('logged_out'))
                .catch(() => redirectToLogin('logged_out'));
        });
    });
});
