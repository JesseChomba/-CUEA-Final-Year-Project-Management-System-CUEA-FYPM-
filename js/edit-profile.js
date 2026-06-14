'use strict';

document.addEventListener('DOMContentLoaded', async () => {
  const form = document.getElementById('profileForm');
  const alertBox = document.getElementById('profileAlert');
  const saveBtn = document.getElementById('saveProfileBtn');
  function showAlert(type, message) {
    alertBox.className = `alert alert-${type} show`;
    alertBox.textContent = message;
  }

  try {
    const res = await fetch('php/api/profile.php?action=get_profile');
    const json = await res.json();

    if (!res.ok || json.status !== 200) {
      throw new Error(json.message || 'Failed to load profile.');
    }

    const profile = json.data;
    document.getElementById('fullName').value = profile.full_name || '';
    document.getElementById('email').value = profile.email || '';
    document.getElementById('department').value = profile.department || '';
    document.getElementById('identifier').value = profile.university_id_number || 'N/A';

  } catch (err) {
    showAlert('error', err.message);
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const payload = {
      full_name: document.getElementById('fullName').value.trim(),
      email: document.getElementById('email').value.trim(),
      department: document.getElementById('department').value.trim(),
      current_password: document.getElementById('currentPassword').value,
      new_password: document.getElementById('newPassword').value,
      confirm_password: document.getElementById('confirmPassword').value
    };

    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';
    alertBox.className = 'alert';
    alertBox.textContent = '';

    try {
      const res = await fetch('php/api/profile.php?action=update_profile', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const json = await res.json();

      if (!res.ok || json.status !== 200) {
        throw new Error(json.message || 'Failed to update profile.');
      }

      document.getElementById('currentPassword').value = '';
      document.getElementById('newPassword').value = '';
      document.getElementById('confirmPassword').value = '';
      const sidebarName = document.querySelector('.sidebar-footer .user-info .name');
      const sidebarAvatar = document.querySelector('.sidebar-footer img.avatar');
      if (sidebarName) sidebarName.textContent = payload.full_name;
      if (sidebarAvatar) {
        sidebarAvatar.src = `https://ui-avatars.com/api/?name=${encodeURIComponent(payload.full_name || 'User')}&background=D4A017&color=1A1A1A`;
      }
      showAlert('success', 'Profile updated successfully.');
    } catch (err) {
      showAlert('error', err.message);
    } finally {
      saveBtn.disabled = false;
      saveBtn.textContent = 'Save Changes';
    }
  });

});
