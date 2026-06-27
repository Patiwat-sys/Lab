<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
requireLogin();

$rows = latestByPeriod('coal_records');
$periodOrder = ['today', 'thismonth', 'thisyear', 'lastmonth', 'lastyear'];

$groupBy = (string) ($_GET['group'] ?? 'day');
if (!in_array($groupBy, ['day', 'week', 'month'], true)) {
  $groupBy = 'day';
}

if ($groupBy === 'week') {
  $sql = 'SELECT strftime("%Y", record_date) || "-W" || strftime("%W", record_date) AS bucket, SUM(sample) AS total, MIN(record_date) AS sort_date
      FROM coal_records
      WHERE period = :period
      GROUP BY strftime("%Y", record_date), strftime("%W", record_date)
      ORDER BY sort_date DESC
      LIMIT 12';
} elseif ($groupBy === 'month') {
  $sql = 'SELECT strftime("%Y-%m", record_date) AS bucket, SUM(sample) AS total, MIN(record_date) AS sort_date
      FROM coal_records
      WHERE period = :period
      GROUP BY strftime("%Y-%m", record_date)
      ORDER BY sort_date DESC
      LIMIT 12';
} else {
  $sql = 'SELECT record_date AS bucket, SUM(sample) AS total, record_date AS sort_date
      FROM coal_records
      WHERE period = :period
      GROUP BY record_date
      ORDER BY sort_date DESC
      LIMIT 12';
}

$stmt = db()->prepare($sql);
$stmt->execute([':period' => 'today']);
$chartRows = array_reverse($stmt->fetchAll());

$chartLabels = [];
$chartValues = [];
foreach ($chartRows as $row) {
  $chartLabels[] = (string) $row['bucket'];
  $chartValues[] = (int) $row['total'];
}

if (!$chartLabels) {
  $chartLabels = ['No Data'];
  $chartValues = [0];
}

renderHeader('Coal Dashboard', 'coal');
?>
<section class="hero">
  <h1>Coal Dashboard</h1>
  <p>Prediction model removed. Data is now loaded from SQLite records only.</p>
</section>

<section class="grid cols-2" style="margin-top:16px;">
  <article class="card">
    <h3>Incoming Sample</h3>
    <div class="kpi">
      <?php foreach ($periodOrder as $period):
        $sample = (int) ($rows[$period]['sample'] ?? 0);
      ?>
      <div class="kpi-item">
        <div class="label"><?= h(periodLabel($period)) ?></div>
        <div class="value"><?= number_format($sample) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </article>

  <article class="card">
    <h3>Quality Metrics</h3>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Period</th>
            <th>TM</th>
            <th>ASH</th>
            <th>CV</th>
            <th>S</th>
            <th>Record Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($periodOrder as $period):
            $r = $rows[$period] ?? [];
          ?>
            <tr>
              <td><?= h(periodLabel($period)) ?></td>
              <td><?= h((string) ($r['tm'] ?? '-')) ?></td>
              <td><?= h((string) ($r['ash'] ?? '-')) ?></td>
              <td><?= h((string) ($r['cv'] ?? '-')) ?></td>
              <td><?= h((string) ($r['sulfur'] ?? '-')) ?></td>
              <td><?= h((string) ($r['record_date'] ?? '-')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>

<section class="card chart-card" style="margin-top:16px;">
  <div class="chart-head">
    <div>
      <h3>Incoming Sample Trend</h3>
      <p class="muted">Bar chart with selectable aggregation and value labels.</p>
    </div>
    <form method="get" class="chart-filter-form">
      <label for="coal-group">Summary By</label>
      <select id="coal-group" name="group" onchange="this.form.submit()">
        <option value="day" <?= $groupBy === 'day' ? 'selected' : '' ?>>Day</option>
        <option value="week" <?= $groupBy === 'week' ? 'selected' : '' ?>>Week</option>
        <option value="month" <?= $groupBy === 'month' ? 'selected' : '' ?>>Month</option>
      </select>
    </form>
  </div>

  <div class="chart-wrap">
    <canvas id="coalIncomingChart" height="120"></canvas>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>
<script>
(() => {
  const ctx = document.getElementById('coalIncomingChart');
  if (!ctx) return;

  const labels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
  const values = <?= json_encode($chartValues, JSON_UNESCAPED_UNICODE) ?>;

  Chart.register(ChartDataLabels);
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Incoming Sample',
        data: values,
        borderRadius: 8,
        backgroundColor: '#3f99ff',
        borderColor: '#2d78ce',
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          ticks: { precision: 0 },
          grid: { color: 'rgba(30,99,188,0.12)' }
        },
        x: {
          grid: { display: false }
        }
      },
      plugins: {
        legend: { display: false },
        tooltip: { enabled: true },
        datalabels: {
          anchor: 'end',
          align: 'end',
          color: '#154375',
          font: { weight: '700' },
          formatter: (value) => value
        }
      }
    }
  });
})();
</script>
<?php renderFooter();
