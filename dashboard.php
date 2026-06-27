<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
requireLogin();

$user = currentUser();
$isAdmin = $user && $user['role'] === 'admin';

$coal = latestByPeriod('coal_records');
$lime = latestByPeriod('limestone_records');

$todayCoal = $coal['today'] ?? ['sample' => 0, 'tm' => null, 'ash' => null, 'cv' => null, 'sulfur' => null];
$todayLime = $lime['today'] ?? ['sample' => 0, 'm_power' => null, 'caco_power' => null, 'm_auto' => null, 'caco_auto' => null];

$gasToday = gasUsageOnDate(date('Y-m-d'));
$gasMonth = gasUsageInMonth(date('Y-m'));

$coalMonthStmt = db()->prepare('SELECT COALESCE(SUM(sample),0) FROM coal_records WHERE period = :period AND strftime("%Y-%m", record_date) = :month');
$coalMonthStmt->execute([':period' => 'today', ':month' => date('Y-m')]);
$coalMonthTotal = (int) $coalMonthStmt->fetchColumn();

$limeMonthStmt = db()->prepare('SELECT COALESCE(SUM(sample),0) FROM limestone_records WHERE period = :period AND strftime("%Y-%m", record_date) = :month');
$limeMonthStmt->execute([':period' => 'today', ':month' => date('Y-m')]);
$limeMonthTotal = (int) $limeMonthStmt->fetchColumn();

$logTodayStmt = db()->prepare('SELECT COUNT(*) FROM activity_logs WHERE date(created_at) = :today');
$logTodayStmt->execute([':today' => date('Y-m-d')]);
$logToday = (int) $logTodayStmt->fetchColumn();

$memberCount = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();

$coalLinks = appLinksByCategory('coal');
$limeLinks = appLinksByCategory('limestone');

renderHeader('Dashboard', 'dashboard');
?>
<section class="hero">
  <h1>Laboratory Operations Dashboard</h1>
  <p>Modern minimal workspace for Coal, Limestone, Gas, and historical operations.</p>
</section>

<section class="dashboard-flow">
  <article class="card dash-highlight">
    <div class="badge">Today Summary</div>
    <h2>Quality + Utility At A Glance</h2>
    <p class="muted">Clean one-screen view for the most critical lab indicators.</p>

    <div class="dash-highlight-grid">
      <div class="kpi-item">
        <div class="label">Coal Samples (Today)</div>
        <div class="value"><?= number_format((int) ($todayCoal['sample'] ?? 0)) ?></div>
      </div>
      <div class="kpi-item">
        <div class="label">Limestone Samples (Today)</div>
        <div class="value"><?= number_format((int) ($todayLime['sample'] ?? 0)) ?></div>
      </div>
      <div class="kpi-item">
        <div class="label">Coal CV</div>
        <div class="value"><?= $todayCoal['cv'] !== null ? number_format((float) $todayCoal['cv']) : '-' ?></div>
      </div>
      <?php if ($isAdmin): ?>
      <div class="kpi-item">
        <div class="label">Gas Used Today (ถัง)</div>
        <div class="value"><?= number_format($gasToday) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <div class="dash-tags">
      <span class="dash-tag">TM: <?= $todayCoal['tm'] !== null ? h((string) $todayCoal['tm']) : '-' ?></span>
      <span class="dash-tag">ASH: <?= $todayCoal['ash'] !== null ? h((string) $todayCoal['ash']) : '-' ?></span>
      <span class="dash-tag">M Power: <?= $todayLime['m_power'] !== null ? h((string) $todayLime['m_power']) : '-' ?></span>
      <span class="dash-tag">CaCO3: <?= $todayLime['caco_power'] !== null ? h((string) $todayLime['caco_power']) : '-' ?></span>
    </div>
  </article>

  <article class="card dash-side-metrics">
    <h3>Operations Pulse</h3>
    <div class="pulse-list">
      <div class="pulse-item"><span>Coal Samples This Month</span><strong><?= number_format($coalMonthTotal) ?></strong></div>
      <div class="pulse-item"><span>Limestone Samples This Month</span><strong><?= number_format($limeMonthTotal) ?></strong></div>
      <?php if ($isAdmin): ?>
      <div class="pulse-item"><span>Gas Used This Month (ถัง)</span><strong><?= number_format($gasMonth) ?></strong></div>
      <?php endif; ?>
      <div class="pulse-item"><span>Activity Logs Today</span><strong><?= number_format($logToday) ?></strong></div>
      <div class="pulse-item"><span>Total Members</span><strong><?= number_format($memberCount) ?></strong></div>
    </div>
  </article>
</section>

<section class="dashboard-links" style="margin-top: 16px;">
  <a class="card module-link coal" href="coal_dashboard.php">
    <h3>Coal Dashboard</h3>
    <p>Track incoming samples and key coal quality metrics.</p>
  </a>
  <a class="card module-link lime" href="limestone_dashboard.php">
    <h3>Limestone Dashboard</h3>
    <p>Monitor limestone measurements for power and auto sampling.</p>
  </a>
  <?php if ($isAdmin): ?>
  <a class="card module-link gas" href="gas_consumption.php">
    <h3>Gas Consumption</h3>
    <p>Monthly cylinder records, tank inventory, and usage dashboards.</p>
  </a>
  <?php endif; ?>
</section>

<section class="card links-center" id="links-center" style="margin-top: 16px;">
  <div class="links-center-head">
    <div>
      <h3>Reference Links Center</h3>
      <p class="muted">All Coal and Limestone links are now available directly in Dashboard.</p>
    </div>
  </div>

  <div class="links-grid">
    <article class="links-column">
      <h4>Coal Links</h4>
      <div class="links-list">
        <?php foreach ($coalLinks as $link): ?>
          <a class="quick-link" href="<?= h($link['url']) ?>" target="_blank" rel="noopener noreferrer">
            <span class="quick-link-title"><?= h($link['title']) ?></span>
            <span class="quick-link-url"><?= h($link['url']) ?></span>
          </a>
        <?php endforeach; ?>
        <?php if (!$coalLinks): ?>
          <p class="muted">No coal links found.</p>
        <?php endif; ?>
      </div>
    </article>

    <article class="links-column">
      <h4>Limestone Links</h4>
      <div class="links-list">
        <?php foreach ($limeLinks as $link): ?>
          <a class="quick-link" href="<?= h($link['url']) ?>" target="_blank" rel="noopener noreferrer">
            <span class="quick-link-title"><?= h($link['title']) ?></span>
            <span class="quick-link-url"><?= h($link['url']) ?></span>
          </a>
        <?php endforeach; ?>
        <?php if (!$limeLinks): ?>
          <p class="muted">No limestone links found.</p>
        <?php endif; ?>
      </div>
    </article>
  </div>
</section>
<?php renderFooter();
