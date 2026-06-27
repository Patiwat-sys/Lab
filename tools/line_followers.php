<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/gas_line_notify.php';
requireAdmin();

$users = gasLineKnownUsersList();
$notifyIds = gasLineToIds();
$webhookEnabled = gasLineChannelSecret() !== '';

renderHeader('LINE — ผู้ติดตามบอท');
?>
<main class="page-main">
  <article class="card">
    <h2>ผู้ที่แอดบอท / ทักข้อความ</h2>
    <p class="muted">
      รายชื่อมาจาก Webhook เมื่อมีคนกดเพิ่มเพื่อนหรือส่งข้อความหาบอท
      <?php if (!$webhookEnabled): ?>
      <br><strong>ยังไม่ได้ใส่ channel_secret</strong> — ตั้ง Webhook ใน LINE Developers และใส่ secret ใน <code>storage/line.local.php</code>
      <?php endif; ?>
    </p>
    <p class="muted">
      Webhook URL: <code><?= h(gasLineAppUrl()) ?>/line_webhook.php</code>
      · ดูรายชื่อที่แจ้งเตือนอยู่แล้ว: <?= count($notifyIds) ?> คน
      · <a href="line_test_push.php">ทดสอบส่ง LINE</a>
    </p>

    <?php if ($users === []): ?>
    <p class="muted" style="margin-top:16px">
      ยังไม่มีข้อมูล — ให้สมาชิกทีม <strong>แอดบอท</strong> หรือ <strong>ส่งข้อความ</strong> หาบอท 1 ครั้ง
      (ต้องเปิด Webhook ก่อน)
    </p>
    <?php else: ?>
    <div class="table-wrap" style="margin-top:16px">
      <table>
        <thead>
          <tr>
            <th>ชื่อ LINE</th>
            <th>User ID</th>
            <th>สถานะ</th>
            <th>แจ้งเตือน</th>
            <th>เห็นล่าสุด</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $row): ?>
          <tr>
            <td><?= h((string) ($row['display_name'] ?? '—')) ?></td>
            <td><code><?= h((string) ($row['user_id'] ?? '')) ?></code></td>
            <td><?= !empty($row['followed']) ? 'เพิ่มเพื่อนอยู่' : 'เลิกติดตามแล้ว' ?></td>
            <td><?= !empty($row['in_notify_list']) ? '✓ อยู่ใน to_ids' : '— ยังไม่ได้ใส่' ?></td>
            <td><?= h((string) ($row['last_seen'] ?? '')) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="muted" style="margin-top:12px">
      คัดลอก User ID ไปใส่ใน <code>storage/line.local.php</code> → <code>to_ids</code> เพื่อให้ได้รับแจ้งเตือนแก๊ส
    </p>
    <?php endif; ?>
  </article>
</main>
<?php renderFooter();
