<?php
declare(strict_types=1);

// Legacy compatibility file. The application now uses SQLite via includes/bootstrap.php.
require_once __DIR__ . '/includes/bootstrap.php';
$pdo = db();
