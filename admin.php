<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
requireAdmin();

$periods = ['today', 'thismonth', 'thisyear', 'lastmonth', 'lastyear'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = (string) ($_POST['section'] ?? '');

    if ($section === 'coal') {
        $input = $_POST['coal'] ?? [];
        $stmt = db()->prepare(
            'INSERT INTO coal_records (period, sample, tm, ash, cv, sulfur, record_date, created_by, created_at)
             VALUES (:period, :sample, :tm, :ash, :cv, :sulfur, :record_date, :created_by, :created_at)'
        );

        foreach ($periods as $period) {
            $row = $input[$period] ?? [];
            $stmt->execute([
                ':period' => $period,
                ':sample' => (int) ($row['sample'] ?? 0),
                ':tm' => parseNullableFloat($row['tm'] ?? null),
                ':ash' => parseNullableFloat($row['ash'] ?? null),
                ':cv' => parseNullableFloat($row['cv'] ?? null),
                ':sulfur' => parseNullableFloat($row['sulfur'] ?? null),
                ':record_date' => date('Y-m-d'),
                ':created_by' => currentUser()['id'],
                ':created_at' => nowIso(),
            ]);
        }

        logActivity('UPDATE', 'coal', 'Updated all coal periods');
        flash('success', 'Coal data updated.');
    }

    if ($section === 'limestone') {
        $input = $_POST['limestone'] ?? [];
        $stmt = db()->prepare(
            'INSERT INTO limestone_records (period, sample, m_power, caco_power, m_auto, caco_auto, record_date, created_by, created_at)
             VALUES (:period, :sample, :m_power, :caco_power, :m_auto, :caco_auto, :record_date, :created_by, :created_at)'
        );

        foreach ($periods as $period) {
            $row = $input[$period] ?? [];
            $stmt->execute([
                ':period' => $period,
                ':sample' => (int) ($row['sample'] ?? 0),
                ':m_power' => parseNullableFloat($row['m_power'] ?? null),
                ':caco_power' => parseNullableFloat($row['caco_power'] ?? null),
                ':m_auto' => parseNullableFloat($row['m_auto'] ?? null),
                ':caco_auto' => parseNullableFloat($row['caco_auto'] ?? null),
                ':record_date' => date('Y-m-d'),
                ':created_by' => currentUser()['id'],
                ':created_at' => nowIso(),
            ]);
        }

        logActivity('UPDATE', 'limestone', 'Updated all limestone periods');
        flash('success', 'Limestone data updated.');
    }

    if ($section === 'links') {
        $titles = $_POST['title'] ?? [];
        $urls = $_POST['url'] ?? [];
        $categories = $_POST['category'] ?? [];

        db()->beginTransaction();
        db()->exec('DELETE FROM external_links');
        $insert = db()->prepare(
            'INSERT INTO external_links (category, title, url, sort_order, created_at, updated_at)
             VALUES (:category, :title, :url, :sort_order, :created_at, :updated_at)'
        );

        $sort = 1;
        for ($i = 0; $i < count($titles); $i++) {
            $title = trim((string) ($titles[$i] ?? ''));
            $url = trim((string) ($urls[$i] ?? ''));
            $category = (string) ($categories[$i] ?? 'coal');
            if ($title === '' || $url === '') {
                continue;
            }
            if (!in_array($category, ['coal', 'limestone'], true)) {
                $category = 'coal';
            }

            $insert->execute([
                ':category' => $category,
                ':title' => $title,
                ':url' => $url,
                ':sort_order' => $sort++,
                ':created_at' => nowIso(),
                ':updated_at' => nowIso(),
            ]);
        }
        db()->commit();

        logActivity('UPDATE', 'links', 'Replaced all external links');
        flash('success', 'Links updated.');
    }

    header('Location: admin.php?tab=manage');
    exit;
}

  $tab = (string) ($_GET['tab'] ?? 'manage');
  if (!in_array($tab, ['manage', 'history'], true)) {
    $tab = 'manage';
  }

  $historyModule = (string) ($_GET['module'] ?? 'coal');
  if (!in_array($historyModule, ['coal', 'limestone', 'gas'], true)) {
    $historyModule = 'coal';
  }

$coal = latestByPeriod('coal_records');
$limestone = latestByPeriod('limestone_records');
$links = db()->query('SELECT id, category, title, url FROM external_links ORDER BY sort_order ASC, id ASC')->fetchAll();

renderHeader('Data Management', 'admin');
?>
<section class="hero">
  <h1>Data Management</h1>
  <p>Manage data and review historical records in one place.</p>
</section>

<section class="admin-layout">
  <?php if ($msg = flash('success')): ?>
    <div class="message success"><?= h($msg) ?></div>
  <?php endif; ?>
  <?php if ($err = flash('error')): ?>
    <div class="message error"><?= h($err) ?></div>
  <?php endif; ?>

  <div class="card data-tabs-wrap">
    <div class="data-tabs">
      <a class="data-tab <?= $tab === 'manage' ? 'active' : '' ?>" href="admin.php?tab=manage">Manage Data</a>
      <a class="data-tab <?= $tab === 'history' ? 'active' : '' ?>" href="admin.php?tab=history&module=<?= h($historyModule) ?>">History</a>
    </div>
  </div>

  <?php if ($tab === 'manage'): ?>
  <div class="admin-toolbar card">
    <div class="admin-toolbar-left">
      <h3>Quick Navigation</h3>
      <p class="muted">Jump to the section you want to edit.</p>
    </div>
    <div class="admin-toolbar-links">
      <a class="admin-chip" href="#coal-section">Coal</a>
      <a class="admin-chip" href="#limestone-section">Limestone</a>
      <a class="admin-chip" href="#links-section">Links</a>
    </div>
  </div>

  <article class="card admin-section" id="coal-section">
    <div class="admin-section-head">
      <h3>Update Coal Data</h3>
      <p class="muted">Each save creates a new version for all periods.</p>
    </div>

    <form method="post">
      <input type="hidden" name="section" value="coal">

      <?php foreach ($periods as $period): $r = $coal[$period] ?? []; ?>
        <details class="admin-period" <?= $period === 'today' ? 'open' : '' ?>>
          <summary><?= h(periodLabel($period)) ?></summary>
          <div class="admin-fields">
            <div>
              <label>Sample</label>
              <input type="number" name="coal[<?= h($period) ?>][sample]" value="<?= h((string) ($r['sample'] ?? 0)) ?>">
            </div>
            <div>
              <label>TM</label>
              <input type="number" step="0.01" name="coal[<?= h($period) ?>][tm]" value="<?= h((string) ($r['tm'] ?? '')) ?>">
            </div>
            <div>
              <label>ASH</label>
              <input type="number" step="0.01" name="coal[<?= h($period) ?>][ash]" value="<?= h((string) ($r['ash'] ?? '')) ?>">
            </div>
            <div>
              <label>CV</label>
              <input type="number" step="0.01" name="coal[<?= h($period) ?>][cv]" value="<?= h((string) ($r['cv'] ?? '')) ?>">
            </div>
            <div>
              <label>S</label>
              <input type="number" step="0.01" name="coal[<?= h($period) ?>][sulfur]" value="<?= h((string) ($r['sulfur'] ?? '')) ?>">
            </div>
          </div>
        </details>
      <?php endforeach; ?>

      <div class="admin-actions">
        <button type="submit">Save Coal</button>
      </div>
    </form>
  </article>

  <article class="card admin-section" id="limestone-section">
    <div class="admin-section-head">
      <h3>Update Limestone Data</h3>
      <p class="muted">Edit by period to keep changes readable.</p>
    </div>

    <form method="post">
      <input type="hidden" name="section" value="limestone">

      <?php foreach ($periods as $period): $r = $limestone[$period] ?? []; ?>
        <details class="admin-period" <?= $period === 'today' ? 'open' : '' ?>>
          <summary><?= h(periodLabel($period)) ?></summary>
          <div class="admin-fields">
            <div>
              <label>Sample</label>
              <input type="number" name="limestone[<?= h($period) ?>][sample]" value="<?= h((string) ($r['sample'] ?? 0)) ?>">
            </div>
            <div>
              <label>M Power</label>
              <input type="number" step="0.01" name="limestone[<?= h($period) ?>][m_power]" value="<?= h((string) ($r['m_power'] ?? '')) ?>">
            </div>
            <div>
              <label>CaCO3 Power</label>
              <input type="number" step="0.01" name="limestone[<?= h($period) ?>][caco_power]" value="<?= h((string) ($r['caco_power'] ?? '')) ?>">
            </div>
            <div>
              <label>M Auto</label>
              <input type="number" step="0.01" name="limestone[<?= h($period) ?>][m_auto]" value="<?= h((string) ($r['m_auto'] ?? '')) ?>">
            </div>
            <div>
              <label>CaCO3 Auto</label>
              <input type="number" step="0.01" name="limestone[<?= h($period) ?>][caco_auto]" value="<?= h((string) ($r['caco_auto'] ?? '')) ?>">
            </div>
          </div>
        </details>
      <?php endforeach; ?>

      <div class="admin-actions">
        <button type="submit">Save Limestone</button>
      </div>
    </form>
  </article>

  <article class="card admin-section" id="links-section">
    <div class="admin-section-head">
      <h3>Update Links</h3>
      <p class="muted">Each link entry has its own block for cleaner editing.</p>
    </div>

    <form method="post">
      <input type="hidden" name="section" value="links">

      <div class="admin-links-list">
        <?php foreach ($links as $link): ?>
          <div class="admin-link-item">
            <div class="admin-fields admin-fields-links">
              <div>
                <label>Title</label>
                <input type="text" name="title[]" value="<?= h((string) $link['title']) ?>">
              </div>
              <div>
                <label>URL</label>
                <input type="text" name="url[]" value="<?= h((string) $link['url']) ?>">
              </div>
              <div>
                <label>Category</label>
                <select name="category[]">
                  <option value="coal" <?= $link['category'] === 'coal' ? 'selected' : '' ?>>Coal</option>
                  <option value="limestone" <?= $link['category'] === 'limestone' ? 'selected' : '' ?>>Limestone</option>
                </select>
              </div>
            </div>
          </div>
        <?php endforeach; ?>

        <div class="admin-link-item admin-link-new">
          <h4>Add New Link</h4>
          <div class="admin-fields admin-fields-links">
            <div>
              <label>Title</label>
              <input type="text" name="title[]" placeholder="Title">
            </div>
            <div>
              <label>URL</label>
              <input type="text" name="url[]" placeholder="URL">
            </div>
            <div>
              <label>Category</label>
              <select name="category[]">
                <option value="coal">Coal</option>
                <option value="limestone">Limestone</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="admin-actions">
        <button type="submit">Save Links</button>
      </div>
    </form>
  </article>

  <?php else: ?>
  <article class="card admin-section">
    <div class="admin-section-head">
      <h3>Historical Data</h3>
      <p class="muted">Review previous records for Coal, Limestone, and Gas.</p>
    </div>

    <form method="get" class="history-filter-form">
      <input type="hidden" name="tab" value="history">
      <div class="row">
        <div>
          <label>Module</label>
          <select name="module">
            <option value="coal" <?= $historyModule === 'coal' ? 'selected' : '' ?>>Coal</option>
            <option value="limestone" <?= $historyModule === 'limestone' ? 'selected' : '' ?>>Limestone</option>
            <option value="gas" <?= $historyModule === 'gas' ? 'selected' : '' ?>>Gas Consumption</option>
          </select>
        </div>
        <div style="display:flex;align-items:flex-end;">
          <button type="submit">Filter</button>
        </div>
      </div>
    </form>

    <?php if ($historyModule === 'coal'): ?>
      <h4>Coal History</h4>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Date</th><th>Period</th><th>Sample</th><th>TM</th><th>ASH</th><th>CV</th><th>S</th></tr></thead>
          <tbody>
            <?php
            $rows = db()->query('SELECT record_date, period, sample, tm, ash, cv, sulfur FROM coal_records ORDER BY id DESC LIMIT 500')->fetchAll();
            foreach ($rows as $row):
            ?>
              <tr>
                <td><?= h((string) $row['record_date']) ?></td>
                <td><?= h(periodLabel((string) $row['period'])) ?></td>
                <td><?= number_format((int) $row['sample']) ?></td>
                <td><?= h((string) $row['tm']) ?></td>
                <td><?= h((string) $row['ash']) ?></td>
                <td><?= h((string) $row['cv']) ?></td>
                <td><?= h((string) $row['sulfur']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="muted">No records.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($historyModule === 'limestone'): ?>
      <h4>Limestone History</h4>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Date</th><th>Period</th><th>Sample</th><th>M Power</th><th>CaCO3 Power</th><th>M Auto</th><th>CaCO3 Auto</th></tr></thead>
          <tbody>
            <?php
            $rows = db()->query('SELECT record_date, period, sample, m_power, caco_power, m_auto, caco_auto FROM limestone_records ORDER BY id DESC LIMIT 500')->fetchAll();
            foreach ($rows as $row):
            ?>
              <tr>
                <td><?= h((string) $row['record_date']) ?></td>
                <td><?= h(periodLabel((string) $row['period'])) ?></td>
                <td><?= number_format((int) $row['sample']) ?></td>
                <td><?= h((string) $row['m_power']) ?></td>
                <td><?= h((string) $row['caco_power']) ?></td>
                <td><?= h((string) $row['m_auto']) ?></td>
                <td><?= h((string) $row['caco_auto']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="muted">No records.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>

    <?php else: ?>
      <h4>Gas Consumption History</h4>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Gas</th>
              <th>Description</th>
              <th>Type</th>
              <th>Qty (ถัง)</th>
              <th>Note</th>
              <th>By</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $rows = db()->query(
                'SELECT e.record_date, e.gas_type, e.sub_type, e.entry_type, e.quantity, e.note, u.username
                 FROM gas_entries e
                 LEFT JOIN users u ON u.id = e.created_by
                 ORDER BY e.record_date DESC, e.id DESC
                 LIMIT 500'
            )->fetchAll();
            foreach ($rows as $row):
            ?>
              <tr>
                <td><?= h((string) $row['record_date']) ?></td>
                <td><?= h(gasTypeLabel((string) $row['gas_type'])) ?></td>
                <td><?= h((string) ($row['sub_type'] ?? '-')) ?></td>
                <td><?= h((string) $row['entry_type'] === 'adding' ? 'รับถัง' : 'ใช้ถัง') ?></td>
                <td><?= number_format((int) $row['quantity']) ?></td>
                <td><?= h((string) ($row['note'] ?? '')) ?></td>
                <td><?= h((string) ($row['username'] ?? '-')) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="muted">No records.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </article>
  <?php endif; ?>
</section>
<?php renderFooter();
