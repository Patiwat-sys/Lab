<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
requireLogin();

header('Location: dashboard.php#links-center');
exit;
