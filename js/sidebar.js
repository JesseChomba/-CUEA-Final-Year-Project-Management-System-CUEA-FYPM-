'use strict';

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.logout-btn').forEach((btn) => {
    btn.addEventListener('click', async (event) => {
      event.preventDefault();
      await fetch('php/api/auth.php?action=logout', { method: 'POST' }).catch(() => {});
      window.location.href = 'index.html?reason=logged_out';
    });
  });
});
