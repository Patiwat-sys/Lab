<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
requireAdmin();

$module = (string) ($_GET['module'] ?? 'coal');
if (!in_array($module, ['coal', 'limestone', 'gas'], true)) {
    $module = 'coal';
}

header('Location: admin.php?tab=history&module=' . urlencode($module));
exit;
