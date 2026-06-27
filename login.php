<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (currentUser()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = db()->prepare('SELECT id, username, password_hash, role FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, (string) $user['password_hash'])) {
        $_SESSION['user_id'] = (int) $user['id'];
        db()->prepare('UPDATE users SET last_login_at = :last_login_at WHERE id = :id')->execute([
            ':last_login_at' => nowIso(),
            ':id' => (int) $user['id'],
        ]);
        logActivity('LOGIN', 'auth', 'User signed in');
        header('Location: dashboard.php');
        exit;
    }

    $error = 'Invalid username or password';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/app.css">
</head>
<body>
  <div class="login-shell">
    <div class="login-bg" aria-hidden="true">
      <span class="login-orb orb-1"></span>
      <span class="login-orb orb-2"></span>
      <span class="login-orb orb-3"></span>
    </div>

    <div class="login-layout">
      <section class="login-showcase">
        <p class="login-kicker">Laboratory Control Hub</p>
        <h1>Coal Laboratory</h1>
        <p>Hongsa Power Company Limited — secure access to Coal, Limestone, Gas Consumption, historical records, and activity logs.</p>

        <div class="login-feature-list">
          <div class="login-feature-card">
            <strong>Data Management</strong>
            <span>Edit values with versioned history in SQLite.</span>
          </div>
          <div class="login-feature-card">
            <strong>Operational Dashboard</strong>
            <span>Monitor quality and utility trends in real time.</span>
          </div>
          <div class="login-feature-card">
            <strong>Audit & Members</strong>
            <span>Role-based access with actionable activity logs.</span>
          </div>
        </div>
      </section>

      <section class="login-card">
        <h2>Welcome Back</h2>
        <p class="muted">Sign in to continue your lab operations.</p>

        <?php if ($error): ?>
          <div class="message error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($msg = flash('notice')): ?>
          <div class="message success"><?= h($msg) ?></div>
        <?php endif; ?>

        <form method="post" id="loginForm">
          <div class="row">
            <div>
              <label>Username</label>
              <input type="text" id="username" name="username" autocomplete="username" required>
            </div>
            <div>
              <label>Password</label>
              <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
          </div>
          <div class="remember-row">
            <label class="remember-toggle">
              <input type="checkbox" id="rememberLocal" checked>
              Remember last typed username and password on this browser
            </label>
            <span class="remember-info" id="rememberInfo">No saved login yet</span>
          </div>
          <button type="submit">Login</button>
        </form>

        <p class="muted" style="margin-top: 14px;">Default admin: admin / admin123</p>
      </section>
    </div>
  </div>

  <script>
  (function () {
    var form = document.querySelector('form');
    var usernameInput = document.getElementById('username');
    var passwordInput = document.getElementById('password');
    var rememberToggle = document.getElementById('rememberLocal');
    var rememberInfo = document.getElementById('rememberInfo');

    var keyUser = 'labops:last_username';
    var keyPass = 'labops:last_password';
    var keyTime = 'labops:last_saved_at';

    var savedUser = localStorage.getItem(keyUser) || '';
    var savedPass = localStorage.getItem(keyPass) || '';
    var savedTime = localStorage.getItem(keyTime) || '';

    if (savedUser) {
      usernameInput.value = savedUser;
    }
    if (savedPass) {
      passwordInput.value = savedPass;
    }

    if (savedTime) {
      rememberInfo.textContent = 'Last saved: ' + savedTime;
    }

    if (form) {
      form.addEventListener('submit', function () {
        if (!rememberToggle.checked) {
          localStorage.removeItem(keyUser);
          localStorage.removeItem(keyPass);
          localStorage.removeItem(keyTime);
          return;
        }

        localStorage.setItem(keyUser, usernameInput.value || '');
        localStorage.setItem(keyPass, passwordInput.value || '');
        localStorage.setItem(keyTime, new Date().toLocaleString());
      });
    }
  })();
  </script>
</body>
</html>
