<?php
require_once __DIR__ . '/includes/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password - CUEA FYPM</title>
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/auth.css">
</head>
<body class="auth-body">
  <div class="auth-split">
    <div class="auth-left">
      <div class="auth-brand">
        <div class="auth-logo-box">
          <img src="cuea_logo.png/screen.png" alt="CUEA Logo" class="auth-logo-img">
        </div>
        <div>
          <div class="auth-brand-name">CUEA</div>
          <div class="auth-brand-sub">PROJECT MANAGEMENT SYSTEM</div>
        </div>
      </div>
      <div class="auth-quote-area">
        <div class="auth-quote-marks">&ldquo;</div>
        <blockquote class="auth-quote">Password recovery keeps your project records protected.</blockquote>
      </div>
    </div>
    <div class="auth-right">
      <div class="auth-form-wrap">
        <h1 class="auth-title">Reset Password</h1>
        <p class="auth-subtitle">Enter your registered email address. A temporary password will be sent to you.</p>
        <div id="resetAlert" class="alert" role="alert"></div>
        <form id="resetPasswordForm" novalidate>
          <div class="form-group">
            <label class="form-label" for="resetEmail">Email Address</label>
            <input type="email" id="resetEmail" class="form-input" autocomplete="email" required>
          </div>
          <button type="submit" class="btn btn-accent btn-full" id="resetBtn">Send Temporary Password</button>
        </form>
        <div class="auth-footer-links">
          <a href="index.html">Back to Sign In</a>
          <a href="mailto:itsupport@cuea.edu">IT Support</a>
        </div>
      </div>
    </div>
  </div>
  <script>
    'use strict';
    const form = document.getElementById('resetPasswordForm');
    const alertBox = document.getElementById('resetAlert');
    const btn = document.getElementById('resetBtn');
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const email = document.getElementById('resetEmail').value.trim();
      if (!email) {
        alertBox.className = 'alert alert-error show';
        alertBox.textContent = 'Email address is required.';
        return;
      }
      btn.disabled = true;
      alertBox.className = 'alert alert-info show';
      alertBox.textContent = 'Sending temporary password...';
      try {
        const res = await fetch('php/api/auth.php?action=forgot_password', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({ identifier: email })
        });
        const data = await res.json();
        alertBox.className = `alert ${res.ok ? 'alert-success' : 'alert-error'} show`;
        alertBox.textContent = data.message || 'Password reset request completed.';
      } catch (e) {
        alertBox.className = 'alert alert-error show';
        alertBox.textContent = 'Network error. Please try again.';
      } finally {
        btn.disabled = false;
      }
    });
  </script>
</body>
</html>
