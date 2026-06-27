<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
requireAdmin();

$rows = db()->query(
    'SELECT l.*, u.username
     FROM activity_logs l
     LEFT JOIN users u ON u.id = l.user_id
     ORDER BY l.id DESC
     LIMIT 1000'
)->fetchAll();

renderHeader('Activity Logs', 'logs');
?>
<section class="hero">
  <h1>Activity Logs</h1>
  <p>Track important actions and audit historical activity.</p>
</section>

<section class="card" style="margin-top: 16px;">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Time</th>
          <th>User</th>
          <th>Action</th>
          <th>Module</th>
          <th>Detail</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><?= h((string) $row['created_at']) ?></td>
            <td><?= h((string) ($row['username'] ?? '-')) ?></td>
            <td><?= h((string) $row['action']) ?></td>
            <td><?= h((string) $row['module']) ?></td>
            <td><?= h((string) $row['detail']) ?></td>
            <td><?= h((string) $row['ip_address']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="muted">No activity logs yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php renderFooter();
