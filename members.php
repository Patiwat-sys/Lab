<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $role = (string) ($_POST['role'] ?? 'member');

    if ($username === '' || $password === '' || !in_array($role, ['admin', 'member'], true)) {
        flash('error', 'Invalid member input.');
        header('Location: members.php');
        exit;
    }

    try {
        db()->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (:username, :password_hash, :role, :created_at)')
            ->execute([
                ':username' => $username,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':role' => $role,
                ':created_at' => nowIso(),
            ]);

        logActivity('CREATE', 'members', 'Created member: ' . $username . ' (' . $role . ')');
        flash('success', 'Member created.');
    } catch (Throwable $e) {
        flash('error', 'Cannot create member. Username may already exist.');
    }

    header('Location: members.php');
    exit;
}

$users = db()->query('SELECT id, username, role, created_at, last_login_at FROM users ORDER BY id DESC')->fetchAll();

renderHeader('Members', 'members');
?>
<section class="hero">
  <h1>Member Management</h1>
  <p>Create and review user accounts.</p>
</section>

<section class="grid cols-2" style="margin-top: 16px;">
  <article class="card">
    <h3>Create Member</h3>
    <?php if ($msg = flash('success')): ?><div class="message success"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($msg = flash('error')): ?><div class="message error"><?= h($msg) ?></div><?php endif; ?>

    <form method="post">
      <div class="row">
        <div><label>Username</label><input type="text" name="username" required></div>
        <div><label>Password</label><input type="password" name="password" required></div>
      </div>
      <div class="row">
        <div>
          <label>Role</label>
          <select name="role">
            <option value="member">Member</option>
            <option value="admin">Admin</option>
          </select>
        </div>
      </div>
      <button type="submit">Create</button>
    </form>
  </article>

  <article class="card">
    <h3>Users</h3>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Username</th><th>Role</th><th>Created</th><th>Last Login</th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= h((string) $u['username']) ?></td>
              <td><?= h((string) $u['role']) ?></td>
              <td><?= h((string) $u['created_at']) ?></td>
              <td><?= h((string) ($u['last_login_at'] ?? '-')) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$users): ?><tr><td colspan="4" class="muted">No users.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
<?php renderFooter();
