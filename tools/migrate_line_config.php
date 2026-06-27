<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$token = trim(appSetting('gas_line_channel_token', ''));
$toId = trim(appSetting('gas_line_to_id', ''));
$enabled = appSetting('gas_line_enabled', '0') === '1';
$path = __DIR__ . '/storage/line.local.php';

if ($token === '' && $toId === '') {
    echo "No DB credentials to migrate\n";
    exit(0);
}

if (is_file($path)) {
    echo "line.local.php already exists, skip\n";
    exit(0);
}

$content = "<?php\nreturn [\n"
    . "    'enabled' => " . ($enabled ? 'true' : 'false') . ",\n"
    . "    'channel_access_token' => " . var_export($token, true) . ",\n"
    . "    'to_id' => " . var_export($toId, true) . ",\n"
    . "];\n";

file_put_contents($path, $content);
setAppSetting('gas_line_channel_token', '');
setAppSetting('gas_line_to_id', '');

echo "Migrated to storage/line.local.php and cleared token from DB\n";
echo 'Notify ready: ' . (gasLineNotifyIsEnabled() ? 'yes' : 'no') . "\n";
