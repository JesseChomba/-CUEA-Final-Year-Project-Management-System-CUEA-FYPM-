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
                const reason = data?.data?.reason || (res.status === 403 ? 'role_changed' : 'session_expired');
                window.location.href = `index.html?reason=${encodeURIComponent(reason)}`;
            }
        } catch(e) {}
    }
    return res;
};

document.addEventListener('DOMContentLoaded', async () => {
    try {
        const sessionRes = await fetch('php/api/auth.php?action=session');
        if (!sessionRes.ok) return;
        const sessionData = await sessionRes.json();
        if (sessionData.status === 200 && sessionData.data) {
            const studentName = sessionData.data.full_name || 'Student';
            const avatarUrl = `https://ui-avatars.com/api/?name=${encodeURIComponent(studentName)}&background=D4A017&color=1A1A1A`;
            
            const avatarImg = document.querySelector('.sidebar-footer img.avatar');
            if (avatarImg) avatarImg.src = avatarUrl;
            
            const nameEl = document.querySelector('.sidebar-footer .user-info .name');
            if (nameEl) nameEl.textContent = studentName;
            
            const idEl = document.querySelector('.sidebar-footer .user-info .id');
            if (idEl) idEl.textContent = `Student ID: ${sessionData.data.university_id_number || ''}`;
        }
    } catch(e) {
        console.error("Failed to load session info", e);
    }
});
