'use strict';

(function initTabSessionGuard() {
  const TIMEOUT_MS = 15 * 60 * 1000;
  const WARNING_MS = 60 * 1000;
  let warningTimer = null;
  let logoutTimer = null;
  let countdownTimer = null;

  function redirectToLogin(reason = 'session_expired') {
    window.location.href = `index.html?reason=${encodeURIComponent(reason)}`;
  }

  function ensureSessionModal() {
    let modal = document.getElementById('sessionExpiryModal');
    if (modal) return modal;

    modal = document.createElement('div');
    modal.id = 'sessionExpiryModal';
    modal.style.cssText = 'position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.55);padding:20px;';
    modal.innerHTML = `
      <div style="width:min(420px,100%);background:#fff;border-radius:8px;padding:22px;box-shadow:0 20px 60px rgba(15,23,42,.25);">
        <h2 style="margin:0 0 8px;font-size:1.2rem;color:#0f172a;">Session expiring</h2>
        <p style="margin:0 0 18px;color:#475569;">Your session will expire in <strong id="sessionExpiryCountdown">60</strong> seconds.</p>
        <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
          <button type="button" id="sessionLogoutBtn" class="btn btn-outline">Logout</button>
          <button type="button" id="sessionExtendBtn" class="btn btn-primary">Extend Session</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);

    document.getElementById('sessionLogoutBtn')?.addEventListener('click', async () => {
      await fetch('php/api/auth.php?action=logout', { method: 'POST' }).catch(() => {});
      redirectToLogin('logged_out');
    });
    document.getElementById('sessionExtendBtn')?.addEventListener('click', extendSession);
    return modal;
  }

  function hideSessionWarning() {
    const modal = document.getElementById('sessionExpiryModal');
    if (modal) modal.style.display = 'none';
    if (countdownTimer) clearInterval(countdownTimer);
    countdownTimer = null;
  }

  function showSessionWarning() {
    const modal = ensureSessionModal();
    const counter = document.getElementById('sessionExpiryCountdown');
    let seconds = 60;
    if (counter) counter.textContent = String(seconds);
    modal.style.display = 'flex';
    if (countdownTimer) clearInterval(countdownTimer);
    countdownTimer = setInterval(() => {
      seconds -= 1;
      if (counter) counter.textContent = String(Math.max(0, seconds));
    }, 1000);
  }

  function scheduleExpiryTimers() {
    if (warningTimer) clearTimeout(warningTimer);
    if (logoutTimer) clearTimeout(logoutTimer);
    hideSessionWarning();
    warningTimer = setTimeout(showSessionWarning, TIMEOUT_MS - WARNING_MS);
    logoutTimer = setTimeout(async () => {
      await fetch('php/api/auth.php?action=logout', { method: 'POST' }).catch(() => {});
      redirectToLogin('session_expired');
    }, TIMEOUT_MS);
  }

  async function extendSession() {
    try {
      const res = await fetch('php/api/auth.php?action=keep_alive', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (!res.ok) {
        redirectToLogin('session_expired');
        return;
      }
      scheduleExpiryTimers();
    } catch (err) {
      redirectToLogin('session_expired');
    }
  }

  async function verifyCurrentTabAccount() {
    try {
      const res = await fetch('php/api/auth.php?action=session', {
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const json = await res.json();

      if (!res.ok || json.status !== 200 || !json.data) {
        const reason = json?.data?.reason || 'session_expired';
        redirectToLogin(reason);
        return;
      }

      const actualUserId = String(json.data.user_id);
      const actualRole = String(json.data.role || '');
      const expectedUserId = sessionStorage.getItem('cuea_session_user_id');
      const expectedRole = sessionStorage.getItem('cuea_session_role');

      if (!expectedUserId) {
        sessionStorage.setItem('cuea_session_user_id', actualUserId);
        sessionStorage.setItem('cuea_session_role', actualRole);
        return;
      }

      if (expectedUserId !== actualUserId || expectedRole !== actualRole) {
        redirectToLogin('account_switched');
      }
    } catch (err) {
      redirectToLogin('session_expired');
    }
  }

  window.addEventListener('focus', verifyCurrentTabAccount);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) verifyCurrentTabAccount();
  });

  ['click', 'keydown', 'mousemove', 'touchstart'].forEach((eventName) => {
    window.addEventListener(eventName, scheduleExpiryTimers, { passive: true });
  });

  scheduleExpiryTimers();
  verifyCurrentTabAccount();
})();
