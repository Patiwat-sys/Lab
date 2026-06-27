<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/gas_line_notify.php';
requireAdmin();

$resultMessage = '';
$resultOk = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = (string) ($_POST['mode'] ?? '');
    $messageType = (string) ($_POST['message_type'] ?? 'flex');
    $testText = trim((string) ($_POST['test_text'] ?? ''));
    if ($testText === '') {
        $testText = '🔔 ทดสอบแจ้งเตือน Lab Gas — ' . date('Y-m-d H:i');
    }

    if ($messageType === 'flex') {
        $flex = gasLineBuildTestFlexMessage();
        $messages = $flex !== null ? [$flex['message']] : [];
        if ($messages === []) {
            $resultMessage = 'สร้าง Flex Message ไม่สำเร็จ';
            $resultOk = false;
        }
    } else {
        $messages = [['type' => 'text', 'text' => $testText]];
    }

    if ($resultMessage === '') {
        if ($mode === 'broadcast') {
            $result = gasLineBroadcastMessages($messages);
            $resultMessage = $result['message'] ?? '';
            $resultOk = !empty($result['ok']);
        } elseif ($mode === 'custom') {
            $raw = (string) ($_POST['custom_ids'] ?? '');
            $ids = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $result = gasLinePushToUserIds($ids, $messages);
            $resultMessage = ($result['message'] ?? '')
                . ' (' . count($ids) . ' ปลายทาง)';
            $resultOk = !empty($result['ok']);
        } else {
            $ids = gasLineToIds();
            $result = gasLinePushToUserIds($ids, $messages);
            $resultMessage = ($result['message'] ?? '')
                . ' (' . count($ids) . ' ปลายทางใน to_ids)';
            $resultOk = !empty($result['ok']);
        }
    }
}

$configIds = gasLineToIds();
$lineEnabled = gasLineNotifyIsEnabled();
$broadcastOn = gasLineBroadcastNotifyEnabled();

renderHeader('LINE — ทดสอบส่งข้อความ');
?>
<main class="page-main">
  <article class="card">
    <h2>ทดสอบส่ง LINE</h2>
    <p class="muted">
      ใช้ทดสอบบน XAMPP ได้ — ไม่ต้องตั้ง Webhook สำหรับการส่งออก
      <?php if (!$lineEnabled): ?>
      <br><strong>ยังไม่พร้อมส่ง:</strong> ตรวจ <code>enabled</code>, <code>channel_access_token</code>
      และ <code>to_ids</code> หรือเปิด <code>broadcast_notify</code>
      <?php endif; ?>
      <?php if ($broadcastOn): ?>
      <br><strong>โหมดทดสอบเปิดอยู่:</strong> แจ้งเตือนจาก Dashboard ส่งถึงผู้ติดตามบอททุกคน
      <?php endif; ?>
    </p>

    <?php if ($resultMessage !== ''): ?>
    <p class="<?= $resultOk ? 'flash-success' : 'flash-error' ?>" style="margin-top:12px;padding:10px 14px;border-radius:8px;<?= $resultOk ? 'background:#e6f7ed;color:#276749' : 'background:#ffecec;color:#c53030' ?>">
      <?= h($resultMessage) ?>
    </p>
    <?php endif; ?>

    <p style="margin-top:14px"><strong>to_ids ปัจจุบัน (<?= count($configIds) ?> คน)</strong></p>
    <?php if ($configIds === []): ?>
    <p class="muted">ยังว่าง — ใช้ Broadcast หรือเปิด <code>broadcast_notify</code> ใน config</p>
    <?php else: ?>
    <ul>
      <?php foreach ($configIds as $id): ?>
      <li><code><?= h($id) ?></code></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <form method="post" style="margin-top:20px;display:grid;gap:16px;max-width:520px">
      <div>
        <label class="gf-label">รูปแบบข้อความ</label>
        <label style="display:block;margin:4px 0"><input type="radio" name="message_type" value="flex" checked> Flex (แบบเดียวกับ Dashboard)</label>
        <label style="display:block"><input type="radio" name="message_type" value="text"> ข้อความธรรมดา</label>
      </div>
      <div>
        <label class="gf-label" for="test_text">ข้อความทดสอบ (ใช้เมื่อเลือกข้อความธรรมดา)</label>
        <input type="text" id="test_text" name="test_text" class="gf-input" value="🔔 ทดสอบแจ้งเตือน Lab Gas" style="width:100%">
      </div>

      <div class="card" style="padding:14px;background:#f7fbff">
        <h4 style="margin:0 0 8px">① ส่งหาผู้ติดตามบอททุกคน (แนะนำ)</h4>
        <p class="muted" style="margin:0 0 10px;font-size:13px">
          ส่งถึงทุกคนที่แอดบอทแล้ว (เช่น 2 คน) — ไม่ต้องรู้ User ID
        </p>
        <button type="submit" name="mode" value="broadcast" class="btn">ส่ง Broadcast ทดสอบ</button>
      </div>

      <div class="card" style="padding:14px">
        <h4 style="margin:0 0 8px">② ส่งตาม to_ids ใน config</h4>
        <button type="submit" name="mode" value="config" class="btn" <?= $configIds === [] ? 'disabled' : '' ?>>ส่งตาม to_ids</button>
      </div>

      <div class="card" style="padding:14px">
        <h4 style="margin:0 0 8px">③ ส่งตาม User ID ที่พิมพ์เอง</h4>
        <textarea id="custom_ids" name="custom_ids" class="gf-input" rows="3" placeholder="Uxxxxxxxx&#10;Uyyyyyyyy" style="width:100%"></textarea>
        <button type="submit" name="mode" value="custom" class="btn" style="margin-top:8px">ส่งตามรายการนี้</button>
      </div>
    </form>

    <p class="muted" style="margin-top:16px">
      ทดสอบจาก Dashboard: เปิด <code>broadcast_notify</code> ใน <code>storage/line.local.php</code>
      · <a href="line_followers.php">ดูผู้ติดตามบอท</a>
    </p>
  </article>
</main>
<?php renderFooter();
