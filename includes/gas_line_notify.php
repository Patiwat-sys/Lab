<?php
declare(strict_types=1);

function gasLineConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = [
        'enabled' => false,
        'channel_access_token' => '',
        'to_id' => '',
        /** @var list<string> รายการ User/Group ID — ส่งพร้อมกันทุกคน (ใช้ร่วมกับ to_id ได้) */
        'to_ids' => [],
        'channel_secret' => '',
        'app_url' => '',
        'brand_name' => 'Lab Gas Dashboard',
        /** ทดสอบบน XAMPP: ส่งแจ้งเตือนแก๊สถึงผู้ติดตามบอททุกคน (ไม่ต้องรู้ User ID ทุกคน) */
        'broadcast_notify' => false,
    ];

    $file = dirname(DB_PATH) . '/line.local.php';
    if (is_file($file)) {
        $loaded = require $file;
        if (is_array($loaded)) {
            $defaults = array_merge($defaults, $loaded);
        }
    }

    if (trim((string) ($defaults['channel_access_token'] ?? '')) === '') {
        $defaults['channel_access_token'] = trim(appSetting('gas_line_channel_token', ''));
    }
    if (trim((string) ($defaults['to_id'] ?? '')) === '') {
        $defaults['to_id'] = trim(appSetting('gas_line_to_id', ''));
    }
    if (empty($defaults['enabled']) && appSetting('gas_line_enabled', '0') === '1') {
        $defaults['enabled'] = true;
    }

    $config = $defaults;

    return $config;
}

function gasLineToIds(): array
{
    $config = gasLineConfig();
    $ids = [];

    if (isset($config['to_ids']) && is_array($config['to_ids'])) {
        foreach ($config['to_ids'] as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
    }

    $single = trim((string) ($config['to_id'] ?? ''));
    if ($single !== '') {
        $ids[] = $single;
    }

    return array_values(array_unique($ids));
}

function gasLineBroadcastNotifyEnabled(): bool
{
    return !empty(gasLineConfig()['broadcast_notify']);
}

function gasLineNotifyIsEnabled(): bool
{
    $config = gasLineConfig();
    $hasToken = trim((string) ($config['channel_access_token'] ?? '')) !== '';
    $hasRecipients = gasLineToIds() !== [] || gasLineBroadcastNotifyEnabled();

    return !empty($config['enabled']) && $hasToken && $hasRecipients;
}

function gasLineChannelToken(): string
{
    return trim((string) (gasLineConfig()['channel_access_token'] ?? ''));
}

function gasLineChannelSecret(): string
{
    return trim((string) (gasLineConfig()['channel_secret'] ?? ''));
}

function gasLineVerifyWebhookSignature(string $body, string $signature): bool
{
    $secret = gasLineChannelSecret();
    if ($secret === '') {
        return true;
    }
    if ($signature === '') {
        return false;
    }
    $hash = base64_encode(hash_hmac('sha256', $body, $secret, true));

    return hash_equals($hash, $signature);
}

/** @param list<array<string,mixed>> $messages */
function gasLineReplyMessages(string $replyToken, array $messages): array
{
    $token = gasLineChannelToken();
    if ($token === '' || $replyToken === '' || $messages === []) {
        return ['ok' => false, 'message' => 'ไม่พร้อมตอบกลับ'];
    }

    $payload = json_encode([
        'replyToken' => $replyToken,
        'messages' => $messages,
    ], JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        return ['ok' => false, 'message' => 'สร้างข้อความตอบกลับไม่สำเร็จ'];
    }

    return gasLinePostJson('https://api.line.me/v2/bot/message/reply', $payload, $token);
}

/** @return array{ok:bool,message:string,http_code?:int} */
function gasLinePostJson(string $url, string $payload, string $token): array
{
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];
    $response = '';
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            $curlError = curl_error($ch);
            curl_close($ch);

            return [
                'ok' => false,
                'message' => 'เชื่อมต่อ LINE ไม่สำเร็จ (cURL): ' . $curlError,
                'http_code' => 0,
            ];
        }
        $response = (string) $response;
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $payload,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);
        $response = (string) @file_get_contents($url, false, $context);
        if (isset($http_response_header[0]) && preg_match('/\d{3}/', (string) $http_response_header[0], $m)) {
            $httpCode = (int) $m[0];
        }
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['ok' => true, 'message' => 'สำเร็จ', 'http_code' => $httpCode];
    }

    $detail = $response !== '' ? $response : 'ไม่มีรายละเอียดจาก LINE API';

    return [
        'ok' => false,
        'message' => 'LINE API ตอบกลับ HTTP ' . $httpCode . ': ' . $detail,
        'http_code' => $httpCode,
    ];
}

/** @deprecated use gasLineToIds() */
function gasLineToId(): string
{
    $ids = gasLineToIds();

    return $ids[0] ?? '';
}

function gasLineBrandName(): string
{
    $name = trim((string) (gasLineConfig()['brand_name'] ?? ''));

    return $name !== '' ? $name : 'Lab Gas Dashboard';
}

function gasLineAppUrl(): string
{
    $url = trim((string) (gasLineConfig()['app_url'] ?? ''));
    if ($url !== '') {
        return rtrim($url, '/');
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($scriptDir === '/' || $scriptDir === '.') {
        $scriptDir = '';
    }

    return rtrim($scheme . '://' . $host . $scriptDir, '/');
}

function gasLineLog(string $message): void
{
    $dir = dirname(DB_PATH) . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($dir . '/gas_line.log', $line, FILE_APPEND | LOCK_EX);
}

function gasLineKnownUsersPath(): string
{
    return dirname(DB_PATH) . '/line_known_users.json';
}

/** @return array<string, array<string, mixed>> */
function gasLineKnownUsersLoad(): array
{
    $path = gasLineKnownUsersPath();
    if (!is_file($path)) {
        return [];
    }

    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : [];
}

function gasLineKnownUsersSave(array $users): void
{
    $path = gasLineKnownUsersPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    file_put_contents(
        $path,
        json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

/** @return array{display_name?:string,picture_url?:string}|null */
function gasLineFetchUserProfile(string $userId): ?array
{
    $token = gasLineChannelToken();
    if ($token === '' || $userId === '') {
        return null;
    }

    $url = 'https://api.line.me/v2/bot/profile/' . rawurlencode($userId);
    $response = '';
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$token}\r\n",
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $response = (string) @file_get_contents($url, false, $context);
    }

    $data = json_decode($response, true);

    return is_array($data) ? $data : null;
}

function gasLineRememberLineUser(string $userId, string $eventType, ?bool $followed = null): void
{
    $userId = trim($userId);
    if ($userId === '') {
        return;
    }

    $users = gasLineKnownUsersLoad();
    $now = nowIso();
    $row = $users[$userId] ?? [
        'user_id' => $userId,
        'display_name' => '',
        'first_seen' => $now,
        'last_seen' => $now,
        'followed' => true,
        'in_notify_list' => false,
        'events' => [],
    ];

    $profile = gasLineFetchUserProfile($userId);
    if (is_array($profile) && trim((string) ($profile['displayName'] ?? '')) !== '') {
        $row['display_name'] = (string) $profile['displayName'];
    }

    $row['last_seen'] = $now;
    if ($followed !== null) {
        $row['followed'] = $followed;
    } elseif ($eventType === 'follow') {
        $row['followed'] = true;
    } elseif ($eventType === 'unfollow') {
        $row['followed'] = false;
    }

    $events = is_array($row['events'] ?? null) ? $row['events'] : [];
    if (!in_array($eventType, $events, true)) {
        $events[] = $eventType;
    }
    $row['events'] = $events;

    $notifyIds = gasLineToIds();
    $row['in_notify_list'] = in_array($userId, $notifyIds, true);

    $users[$userId] = $row;
    gasLineKnownUsersSave($users);
    gasLineLog('Webhook — ' . $eventType . ' User ID: ' . $userId
        . ($row['display_name'] !== '' ? ' (' . $row['display_name'] . ')' : ''));
}

/** @return list<array<string, mixed>> */
function gasLineKnownUsersList(bool $activeOnly = false): array
{
    $users = gasLineKnownUsersLoad();
    $notifyIds = gasLineToIds();
    $rows = [];

    foreach ($users as $userId => $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($activeOnly && empty($row['followed'])) {
            continue;
        }
        $row['user_id'] = (string) ($row['user_id'] ?? $userId);
        $row['in_notify_list'] = in_array($row['user_id'], $notifyIds, true);
        $rows[] = $row;
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp((string) ($b['last_seen'] ?? ''), (string) ($a['last_seen'] ?? ''));
    });

    return $rows;
}

/** @param list<string> $toIds */
/** @param list<array<string,mixed>> $messages */
/** @return array{ok:bool,message:string,http_code?:int} */
function gasLinePushToUserIds(array $toIds, array $messages): array
{
    $token = gasLineChannelToken();
    $toIds = array_values(array_unique(array_filter(array_map(
        static fn ($id): string => trim((string) $id),
        $toIds
    ))));

    if ($token === '' || $toIds === []) {
        return ['ok' => false, 'message' => 'ยังไม่ได้ตั้งค่า Channel Access Token หรือ User ID'];
    }

    if ($messages === []) {
        return ['ok' => false, 'message' => 'ไม่มีข้อความที่จะส่ง'];
    }

    $useMulticast = count($toIds) > 1;
    $payload = json_encode(
        $useMulticast
            ? ['to' => $toIds, 'messages' => $messages]
            : ['to' => $toIds[0], 'messages' => $messages],
        JSON_UNESCAPED_UNICODE
    );

    if ($payload === false) {
        return ['ok' => false, 'message' => 'สร้างข้อความไม่สำเร็จ'];
    }

    $apiUrl = $useMulticast
        ? 'https://api.line.me/v2/bot/message/multicast'
        : 'https://api.line.me/v2/bot/message/push';

    $result = gasLinePostJson($apiUrl, (string) $payload, $token);
    if ($result['ok']) {
        gasLineLog(
            'ส่งสำเร็จ → ' . count($toIds) . ' ปลายทาง ('
            . ($useMulticast ? 'multicast' : 'push') . ')'
        );
    }

    return $result;
}

/** @param list<array<string,mixed>> $messages */
/** @return array{ok:bool,message:string,http_code?:int} */
function gasLineBroadcastMessages(array $messages): array
{
    $token = gasLineChannelToken();
    if ($token === '') {
        return ['ok' => false, 'message' => 'ยังไม่ได้ตั้งค่า Channel Access Token'];
    }

    if ($messages === []) {
        return ['ok' => false, 'message' => 'ไม่มีข้อความที่จะส่ง'];
    }

    $payload = json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return ['ok' => false, 'message' => 'สร้างข้อความไม่สำเร็จ'];
    }

    $result = gasLinePostJson(
        'https://api.line.me/v2/bot/message/broadcast',
        (string) $payload,
        $token
    );
    if ($result['ok']) {
        gasLineLog('Broadcast สำเร็จ → ผู้ติดตามบอททุกคน');
    }

    return $result;
}

/** @param list<array<string,mixed>> $messages */
/** @return array{ok:bool,message:string,http_code?:int} */
function gasLineDeliverMessages(array $messages): array
{
    if (gasLineBroadcastNotifyEnabled()) {
        return gasLineBroadcastMessages($messages);
    }

    return gasLinePushMessages($messages);
}

/** @return array{ok:bool,message:string,http_code?:int} */
function gasLinePushMessages(array $messages): array
{
    return gasLinePushToUserIds(gasLineToIds(), $messages);
}

/** @return array{ok:bool,message:string,http_code?:int} */
function gasLinePushText(string $text): array
{
    return gasLineDeliverMessages([
        ['type' => 'text', 'text' => $text],
    ]);
}

function gasLineEntryActorName(array $entry): string
{
    $userId = (int) ($entry['created_by'] ?? 0);
    if ($userId < 1) {
        return 'ระบบ';
    }

    $stmt = db()->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();

    return $row ? (string) ($row['username'] ?? 'ผู้ใช้') : 'ผู้ใช้';
}

function gasLineFormatRecordDate(string $recordDate): string
{
    $dt = DateTime::createFromFormat('Y-m-d', $recordDate);
    if (!$dt) {
        return $recordDate;
    }

    static $thaiMonths = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
    ];
    $month = (int) $dt->format('n');

    return $dt->format('j') . ' ' . ($thaiMonths[$month] ?? $dt->format('m')) . ' ' . $dt->format('Y');
}

function gasLineFormatRecordDateTime(array $entry): string
{
    $recordDate = (string) ($entry['record_date'] ?? date('Y-m-d'));
    $dateLabel = gasLineFormatRecordDate($recordDate);
    $createdAt = trim((string) ($entry['created_at'] ?? ''));
    if ($createdAt === '') {
        return $dateLabel;
    }

    $dt = DateTime::createFromFormat('Y-m-d H:i:s', substr($createdAt, 0, 19));
    if (!$dt) {
        $dt = DateTime::createFromFormat(DateTime::ATOM, $createdAt) ?: null;
    }

    return $dt ? $dateLabel . '  ' . $dt->format('H:i') : $dateLabel;
}

function gasLineFlexField(string $label, string $value): array
{
    return [
        'type' => 'box',
        'layout' => 'baseline',
        'margin' => 'md',
        'spacing' => 'sm',
        'contents' => [
            [
                'type' => 'text',
                'text' => $label,
                'color' => '#8A8A8E',
                'size' => 'sm',
                'flex' => 2,
            ],
            [
                'type' => 'text',
                'text' => $value,
                'wrap' => true,
                'size' => 'sm',
                'color' => '#1C1C1E',
                'flex' => 5,
                'weight' => 'bold',
            ],
        ],
    ];
}

function gasLineFlexSummaryRow(string $label, int $remain, bool $highlight = false): array
{
    $color = $highlight ? '#2B7CB8' : '#3A3A3C';

    return [
        'type' => 'box',
        'layout' => 'horizontal',
        'margin' => 'sm',
        'contents' => [
            [
                'type' => 'text',
                'text' => $label,
                'size' => 'sm',
                'color' => $color,
                'flex' => 4,
                'weight' => $highlight ? 'bold' : 'regular',
            ],
            [
                'type' => 'text',
                'text' => number_format($remain) . ' ถัง',
                'size' => 'sm',
                'align' => 'end',
                'color' => $color,
                'flex' => 2,
                'weight' => $highlight ? 'bold' : 'regular',
            ],
        ],
    ];
}

function gasLinePastelBlueGradient(string $start = '#7ECAE8', string $end = '#B8E6FA', string $angle = '90deg'): array
{
    return [
        'type' => 'linearGradient',
        'angle' => $angle,
        'startColor' => $start,
        'endColor' => $end,
    ];
}

/** @return array{altText:string,message:array}|null */
function gasLineBuildGasEntryFlexMessage(array $entry): ?array
{
    $entryType = (string) ($entry['entry_type'] ?? '');
    if (!in_array($entryType, ['adding', 'usage'], true)) {
        return null;
    }

    $gasType = (string) ($entry['gas_type'] ?? '');
    if (!isset(gasTypes()[$gasType])) {
        return null;
    }

    $quantity = max(0, (int) ($entry['quantity'] ?? 0));
    if ($quantity < 1) {
        return null;
    }

    $recordDate = (string) ($entry['record_date'] ?? date('Y-m-d'));
    $yearMonth = substr($recordDate, 0, 7);
    $gasLabel = gasTypeLabel($gasType);
    $subType = trim((string) ($entry['sub_type'] ?? ''));
    $actor = gasLineEntryActorName($entry);
    $remain = max(0, (int) (gasMonthSummaryWithRemaining($yearMonth)[$gasType]['remaining'] ?? 0));
    $isAdding = $entryType === 'adding';

    $accentColor = '#2B7CB8';
    $headerGradient = gasLinePastelBlueGradient('#6BB9E8', '#A8D8F5');
    $remainGradient = gasLinePastelBlueGradient('#C5E8FA', '#E8F6FF', '180deg');
    $buttonColor = '#5BAFE8';

    if ($isAdding) {
        $title = 'Update การแอดถังแก๊ส';
        $quantityLabel = '+' . number_format($quantity) . ' ถัง';
        $altAction = 'แอดถังแก๊ส';
    } else {
        $title = 'Update การใช้ถังแก๊ส';
        $quantityLabel = '−' . number_format($quantity) . ' ถัง';
        $altAction = 'ใช้ถังแก๊ส';
    }

    $bodyContents = [
        gasLineFlexField('วันที่', gasLineFormatRecordDateTime($entry)),
        gasLineFlexField('ประเภทแก๊ส', $gasLabel),
        gasLineFlexField('จำนวน', $quantityLabel),
    ];

    if ($subType !== '') {
        $bodyContents[] = gasLineFlexField('รายละเอียด', $subType);
    }

    $bodyContents[] = gasLineFlexField('บันทึกโดย', $actor);
    $bodyContents[] = [
        'type' => 'box',
        'layout' => 'vertical',
        'margin' => 'lg',
        'paddingAll' => '14px',
        'cornerRadius' => '12px',
        'background' => $remainGradient,
        'contents' => [
            [
                'type' => 'text',
                'text' => '✨ คงเหลือ ' . $gasLabel,
                'size' => 'xs',
                'color' => '#4A7FA8',
            ],
            [
                'type' => 'text',
                'text' => number_format($remain) . ' ถัง',
                'size' => 'xxl',
                'weight' => 'bold',
                'color' => $accentColor,
                'margin' => 'xs',
            ],
        ],
    ];
    $bodyContents[] = [
        'type' => 'separator',
        'margin' => 'lg',
    ];
    $bodyContents[] = [
        'type' => 'text',
        'text' => 'สรุปปริมาณคงเหลือทั้งหมด',
        'weight' => 'bold',
        'size' => 'sm',
        'color' => '#1C1C1E',
        'margin' => 'md',
    ];

    $summary = gasMonthSummaryWithRemaining($yearMonth);
    foreach (gasTypes() as $gasKey => $meta) {
        $typeRemain = max(0, (int) ($summary[$gasKey]['remaining'] ?? 0));
        $label = $meta['label'] ?? gasTypeLabel($gasKey);
        $bodyContents[] = gasLineFlexSummaryRow($label, $typeRemain, $gasKey === $gasType);
    }

    $bubble = [
        'type' => 'bubble',
        'size' => 'mega',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'background' => $headerGradient,
            'paddingAll' => '18px',
            'contents' => [
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => '🔔',
                            'size' => 'xl',
                            'flex' => 0,
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'flex' => 1,
                            'margin' => 'sm',
                            'contents' => [
                                [
                                    'type' => 'text',
                                    'text' => $title,
                                    'color' => '#FFFFFF',
                                    'weight' => 'bold',
                                    'size' => 'md',
                                    'wrap' => true,
                                ],
                                [
                                    'type' => 'text',
                                    'text' => gasLineBrandName(),
                                    'color' => '#E8F6FF',
                                    'size' => 'xs',
                                    'margin' => 'xs',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '18px',
            'backgroundColor' => '#FFFFFF',
            'contents' => $bodyContents,
        ],
    ];

    $dashboardUrl = gasLineAppUrl() !== '' ? gasLineAppUrl() . '/gas_consumption.php?tab=dashboard' : '';
    if ($dashboardUrl !== '') {
        $bubble['footer'] = [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'paddingAll' => '14px',
            'backgroundColor' => '#FFFFFF',
            'contents' => [
                [
                    'type' => 'button',
                    'style' => 'primary',
                    'height' => 'sm',
                    'color' => $buttonColor,
                    'action' => [
                        'type' => 'uri',
                        'label' => 'เปิด Dashboard',
                        'uri' => $dashboardUrl,
                    ],
                ],
            ],
        ];
    }

    $altText = $altAction . ' ' . $gasLabel . ' ' . $quantityLabel
        . ' · เหลือ ' . number_format($remain) . ' ถัง';

    return [
        'altText' => $altText,
        'message' => [
            'type' => 'flex',
            'altText' => $altText,
            'contents' => $bubble,
        ],
    ];
}

/** @return array{altText:string,message:array}|null */
function gasLineBuildTestFlexMessage(): ?array
{
    return gasLineBuildGasEntryFlexMessage([
        'entry_type' => 'adding',
        'gas_type' => 'o2_96',
        'quantity' => 1,
        'record_date' => date('Y-m-d'),
        'created_at' => date('Y-m-d H:i:s'),
        'sub_type' => '',
        'created_by_name' => 'ทดสอบระบบ',
    ]);
}

function gasNotifyLineOnGasEntry(array $entry): void
{
    if (!gasLineNotifyIsEnabled()) {
        return;
    }

    $flex = gasLineBuildGasEntryFlexMessage($entry);
    if ($flex === null) {
        return;
    }

    try {
        $result = gasLineDeliverMessages([$flex['message']]);
        if (!$result['ok']) {
            gasLineLog('ส่ง Flex ไม่สำเร็จ: ' . ($result['message'] ?? ''));
            $fallback = gasLinePushText($flex['altText']);
            if (!$fallback['ok']) {
                gasLineLog('ส่งข้อความสำรองไม่สำเร็จ: ' . ($fallback['message'] ?? ''));
            }
        }
    } catch (Throwable $e) {
        gasLineLog('ข้อผิดพลาด: ' . $e->getMessage());
    }
}
