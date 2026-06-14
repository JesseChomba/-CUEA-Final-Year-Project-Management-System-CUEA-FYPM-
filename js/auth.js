/**
 * CUEA FYPM – Authentication JS
 * Handles: Login form, Register multi-step form
 */

'use strict';

/* ─────────────────────────────────────────────────────────
   UTILITIES
───────────────────────────────────────────────────────── */
function showAlert(id, type, message) {
  const el = document.getElementById(id);
  if (!el) return;
  el.className = `alert alert-${type} show`;
  el.textContent = message;
  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function hideAlert(id) {
  const el = document.getElementById(id);
  if (el) { el.className = 'alert'; el.textContent = ''; }
}

function setLoading(btnId, loading) {
  const btn = document.getElementById(btnId);
  if (!btn) return;
  btn.disabled = loading;
  if (loading) btn.classList.add('btn-loading');
  else btn.classList.remove('btn-loading');
}

/* ─────────────────────────────────────────────────────────
   LOGIN PAGE
───────────────────────────────────────────────────────── */
(function initLogin() {
  const form = document.getElementById('loginForm');
  if (!form) return;  // Not on login page

  const reasonMessages = {
    session_expired: 'Your session expired. Please log in again.',
    session_invalid: 'Your previous session is no longer valid. Please log in again.',
    role_changed: 'You are signed in as a different role in this browser. Please log in again to continue.',
    account_switched: 'This tab was opened for a different account. Please log in again to continue safely.',
    logged_out: 'You have been signed out successfully.'
  };
  const reason = new URLSearchParams(window.location.search).get('reason');
  if (reason && reasonMessages[reason]) {
    showAlert('loginAlert', reason === 'logged_out' ? 'success' : 'info', reasonMessages[reason]);
  }

  // ── Password show/hide toggle ──────────────────────────
  const togglePwd  = document.getElementById('togglePwd');
  const pwdInput   = document.getElementById('loginPassword');
  const eyeIcon    = document.getElementById('eyeIcon');
  const eyeOffIcon = document.getElementById('eyeOffIcon');

  if (togglePwd) {
    togglePwd.addEventListener('click', () => {
      const isHidden = pwdInput.type === 'password';
      pwdInput.type  = isHidden ? 'text' : 'password';
      eyeIcon.style.display    = isHidden ? 'none'  : '';
      eyeOffIcon.style.display = isHidden ? ''      : 'none';
      togglePwd.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });
  }

  // ── Login submit ──────────────────────────────────────
  form.addEventListener('submit', handleLogin);

  async function handleLogin(e) {
    e.preventDefault();
    hideAlert('loginAlert');

    const universityId = document.getElementById('universityId').value.trim();
    const password     = document.getElementById('loginPassword').value;
    const remember     = document.getElementById('rememberMe')?.checked ?? false;

    if (!universityId) { showAlert('loginAlert', 'error', 'Please enter your University ID.'); return; }
    if (!password)     { showAlert('loginAlert', 'error', 'Please enter your password.'); return; }

    setLoading('loginBtn', true);

    try {
      const res  = await fetch('php/api/auth.php?action=login', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body:    JSON.stringify({ university_id: universityId, password, remember })
      });
      const data = await res.json();

      if (res.ok && data.status === 200) {
        sessionStorage.setItem('cuea_session_user_id', String(data.data.user_id));
        sessionStorage.setItem('cuea_session_role', data.data.role);

        // Role-based redirect
        let redirectUrl = 'dashboard.php';
        if (data.data.role === 'student') {
          redirectUrl = data.data.has_supervisor
            ? 'dashboard.php'
            : (data.data.has_pending_supervisor_preferences ? 'pending-supervisor.php' : 'supervisor-selection.html');
        } else if (data.data.role === 'supervisor') {
          redirectUrl = 'supervisor-dashboard.php';
        } else if (data.data.role === 'coordinator') {
          redirectUrl = 'coordinator.php';
        }

        showAlert('loginAlert', 'success', `Welcome back, ${data.data.full_name}! Redirecting…`);
        setTimeout(() => {
          window.location.href = redirectUrl;
        }, 800);
      } else {
        showAlert('loginAlert', 'error', data.message || 'Login failed. Please try again.');
        setLoading('loginBtn', false);
      }
    } catch (err) {
      showAlert('loginAlert', 'error', 'Network error. Please check your connection and try again.');
      setLoading('loginBtn', false);
    }
  }

  // ── Clear error on input ──────────────────────────────
  ['universityId', 'loginPassword'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', () => hideAlert('loginAlert'));
  });
})();


/* ─────────────────────────────────────────────────────────
   END OF AUTH JS (Registration removed)
───────────────────────────────────────────────────────── */

