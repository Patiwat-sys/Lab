<?php
declare(strict_types=1);

/**
 * LINE Webhook — บันทึกคนที่แอดบอท / ทักข้อความ และตอบ User ID
 * ตั้งค่า Webhook URL ใน LINE Developers Console ชี้มาที่ไฟล์นี้
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/gas_line_notify.php';

$body = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '');

if ($body !== '' && !gasLineVerifyWebhookSignature($body, $signature)) {
    http_response_code(403);
    echo 'Invalid signature';
    exit;
}

$data = json_decode($body, true);
if (!is_array($data)) {
    http_response_code(200);
    echo 'OK';
    exit;
}

foreach ($data['events'] ?? [] as $event) {
    if (!is_array($event)) {
        continue;
    }

    $type = (string) ($event['type'] ?? '');
    $userId = (string) ($event['source']['userId'] ?? '');
    $replyToken = (string) ($event['replyToken'] ?? '');

    if ($type === 'follow' && $userId !== '') {
        gasLineRememberLineUser($userId, 'follow', true);
        if ($replyToken !== '') {
            gasLineReplyMessages($replyToken, [[
                'type' => 'text',
                'text' => "สวัสดีค่ะ ขอบคุณที่เพิ่มเพื่อนบอท Lab Gas\n\n"
                    . "User ID ของคุณ:\n" . $userId
                    . "\n\nแจ้ง admin เพื่อเพิ่มในรายการแจ้งเตือน",
            ]]);
        }
        continue;
    }

    if ($type === 'unfollow' && $userId !== '') {
        gasLineRememberLineUser($userId, 'unfollow', false);
        continue;
    }

    if ($type !== 'message' || $userId === '' || $replyToken === '') {
        continue;
    }

    $message = $event['message'] ?? [];
    if (!is_array($message) || (string) ($message['type'] ?? '') !== 'text') {
        continue;
    }

    gasLineRememberLineUser($userId, 'message', true);

    gasLineReplyMessages($replyToken, [[
        'type' => 'text',
        'text' => "User ID ของคุณ:\n" . $userId
            . "\n\nแจ้ง admin เพื่อเพิ่มใน storage/line.local.php → to_ids"
            . "\n(ตอบกลับฟรี — ไม่นับโควต้า push)",
    ]]);
}

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo 'OK';
