<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
requireAdmin();

header('Location: admin.php');
exit;
