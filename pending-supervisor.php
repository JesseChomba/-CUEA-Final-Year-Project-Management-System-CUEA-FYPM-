<?php
require_once __DIR__ . '/includes/page_guard.php';
$currentUser = requirePageRole(['student']);
$state = studentSupervisorState((int)$currentUser['user_id']);
if ($state['has_supervisor']) {
    header('Location: dashboard.php');
    exit;
}
if (!$state['has_pending_preferences']) {
    header('Location: supervisor-selection.html');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Supervisor Approval Pending - CUEA FYPM</title>
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/auth.css">
  <style>
    .pending-shell {
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 24px;
      background: #F8F9FA;
    }
    .pending-card {
      width: min(640px, 100%);
      background: #fff;
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-md);
      padding: 34px;
      text-align: center;
      border-top: 5px solid var(--color-accent);
    }
    .pending-card img {
      width: 54px;
      height: 54px;
      object-fit: contain;
      margin: 0 auto 18px;
    }
    .pending-card h1 {
      color: var(--color-primary);
      font-size: 1.6rem;
      margin-bottom: 10px;
    }
    .pending-card p {
      color: var(--color-muted);
      margin-bottom: 18px;
    }
    .pending-actions {
      display: flex;
      justify-content: center;
      gap: 12px;
      flex-wrap: wrap;
      margin-top: 24px;
    }
  </style>
</head>
<body>
  <main class="pending-shell">
    <section class="pending-card">
      <img src="cuea_logo.png/screen.png" alt="CUEA Logo" onerror="this.style.display='none'">
      <h1>Pending Coordinator Approval</h1>
      <p>Your supervisor preferences have been submitted. You will regain dashboard access once the Coordinator officially assigns your supervisor.</p>
      <p class="text-sm">Signed in as <strong><?php echo htmlspecialchars($currentUser['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
      <div class="pending-actions">
        <button class="btn btn-outline" type="button" id="refreshStatusBtn">Check Status</button>
        <button class="btn btn-primary" type="button" id="logoutBtn">Sign Out</button>
      </div>
    </section>
  </main>

  <script>
    document.getElementById('refreshStatusBtn').addEventListener('click', async () => {
      const res = await fetch('php/api/auth.php?action=session', { cache: 'no-store' });
      const json = await res.json();
      if (res.ok && json.status === 200 && json.data.has_supervisor) {
        window.location.href = 'dashboard.php';
      } else {
        alert('Still pending Coordinator approval.');
      }
    });
    document.getElementById('logoutBtn').addEventListener('click', async () => {
      await fetch('php/api/auth.php?action=logout', { method: 'POST' }).catch(() => {});
      window.location.href = 'index.html?reason=logged_out';
    });
  </script>
</body>
</html>
