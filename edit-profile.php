<?php
require_once __DIR__ . '/includes/page_guard.php';
$currentUser = requirePageRole(['student', 'supervisor', 'coordinator']);
require_once __DIR__ . '/includes/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Profile - CUEA FYPM</title>
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/dashboard.css">
  </head>
<body class="dashboard-layout">
  <?php renderSidebar($currentUser, 'profile'); ?>
<div class="main-wrapper">
    <header class="top-bar">
      <div class="top-bar-title">Account Security</div>
    </header>

    <main class="content-body">
      <section class="welcome-banner">
        <h2>Edit Profile</h2>
        <p>Keep your contact details current and replace the setup password with one only you know.</p>
      </section>

      <section class="card profile-edit-card">
        <form id="profileForm" autocomplete="off">
          <div id="profileAlert" class="alert"></div>

          <div class="profile-form-grid">
            <div class="form-group">
              <label class="form-label" for="fullName">Full Name</label>
              <input class="form-input" type="text" id="fullName" name="full_name" required>
            </div>

            <div class="form-group">
              <label class="form-label" for="email">Email Address</label>
              <input class="form-input" type="email" id="email" name="email" required>
            </div>

            <div class="form-group">
              <label class="form-label" for="department">Department</label>
              <input class="form-input" type="text" id="department" name="department">
            </div>

            <div class="form-group">
              <label class="form-label" for="identifier">University / Staff ID</label>
              <input class="form-input" type="text" id="identifier" disabled>
            </div>
          </div>

          <hr class="profile-divider">

          <div class="profile-form-grid">
            <div class="form-group">
              <label class="form-label" for="currentPassword">Current Password</label>
              <input class="form-input" type="password" id="currentPassword" name="current_password" autocomplete="current-password">
              <p class="form-hint">Required only when setting a new password.</p>
            </div>

            <div class="form-group">
              <label class="form-label" for="newPassword">New Password</label>
              <input class="form-input" type="password" id="newPassword" name="new_password" minlength="8" autocomplete="new-password">
            </div>

            <div class="form-group">
              <label class="form-label" for="confirmPassword">Confirm New Password</label>
              <input class="form-input" type="password" id="confirmPassword" name="confirm_password" minlength="8" autocomplete="new-password">
            </div>
          </div>

          <div class="profile-actions">
            <button class="btn btn-primary" type="submit" id="saveProfileBtn">Save Changes</button>
          </div>
        </form>
      </section>
    </main>
  </div>

  <script src="js/edit-profile.js"></script>
  <script src="js/session-guard.js"></script>
  <script src="js/sidebar.js"></script>
</body>
</html>






