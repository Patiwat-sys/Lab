<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
requireAdmin();

$tab = (string) ($_GET['tab'] ?? 'dashboard');
if (!in_array($tab, ['form', 'dashboard', 'summary'], true)) {
    $tab = 'dashboard';
}

$year = (int) ($_GET['year'] ?? (int) date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$detailMonth = (string) ($_GET['month'] ?? '');
if ($detailMonth !== '' && !preg_match('/^\d{4}-\d{2}$/', $detailMonth)) {
    $detailMonth = '';
}
if ($detailMonth !== '') {
    $year = (int) substr($detailMonth, 0, 4);
}

$currentMonth = date('Y-m');
$dashboardMonth = $currentMonth;

if ($tab === 'dashboard') {
    gasRefreshTankInventoryFromEntries(null, $currentMonth);
    $year = (int) date('Y');
}

$formListMonth = gasFormListMonthFromRequest($currentMonth);
$formListType = gasFormListTypeFromRequest();

$formListPerPage = 10;
$formListPage = max(1, (int) ($_GET['list_page'] ?? 1));

$export = (string) ($_GET['export'] ?? '');
if ($export === 'yearly' && $tab === 'summary' && $detailMonth === '') {
    gasExportYearlyExcel($year);
    exit;
}
if ($export === 'monthly' && $tab === 'summary' && $detailMonth !== '') {
    gasExportMonthlyDailyExcel($detailMonth);
    exit;
}

$defaultRecordDate = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_entry') {
        $gasType = (string) ($_POST['gas_type'] ?? '');
        $subType = (string) ($_POST['sub_type'] ?? '');
        $recordDate = (string) ($_POST['record_date'] ?? $defaultRecordDate);
        $entryType = (string) ($_POST['entry_type'] ?? 'usage');
        $quantity = (int) ($_POST['quantity'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));

        $dateValid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $recordDate) === 1;
        $dateObj = $dateValid ? DateTime::createFromFormat('Y-m-d', $recordDate) : false;
        $dateValid = $dateValid && $dateObj && $dateObj->format('Y-m-d') === $recordDate;
        $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        $redirectTab = (string) ($_POST['redirect_tab'] ?? 'form');
        $saved = false;
        $errorMessage = 'กรุณากรอกข้อมูลให้ครบถ้วน';

        if (
            $dateValid
            && isset(gasTypes()[$gasType])
            && gasIsValidSubTypeForEntry($gasType, $subType, $entryType)
        ) {
            try {
                addGasEntry($gasType, $subType, $recordDate, $entryType, $quantity, (int) currentUser()['id'], $note);
                logActivity('CREATE', 'gas_consumption', gasTypeLabel($gasType) . '/' . $subType . ' ' . $entryType . ': ' . $quantity . ' on ' . $recordDate);
                $saved = true;
                if (!$isAjax) {
                    flash('success', 'บันทึก ' . number_format($quantity) . ' ถัง สำเร็จแล้ว');
                }
            } catch (Throwable $e) {
                $errorMessage = 'ไม่สามารถบันทึกได้ กรุณาตรวจสอบข้อมูล';
                if (!$isAjax) {
                    flash('error', $errorMessage);
                }
            }
        } elseif (!$isAjax) {
            flash('error', $errorMessage);
        }

        if ($isAjax && $redirectTab === 'dashboard') {
            header('Content-Type: application/json; charset=utf-8');
            $groupedUnits = loadGasTankUnits();
            echo json_encode([
                'ok' => $saved,
                'message' => $saved
                    ? 'บันทึก ' . number_format($quantity) . ' ถัง สำเร็จแล้ว'
                    : $errorMessage,
                'counts' => $saved ? gasTankBoardCounts($groupedUnits) : null,
                'units' => $saved ? gasTankBoardUnitsPayload($groupedUnits, substr($recordDate, 0, 7)) : null,
                'reload_board' => $saved && $entryType === 'adding',
            ], JSON_UNESCAPED_UNICODE);
        exit;
    }

        if ($redirectTab === 'dashboard') {
            header('Location: gas_consumption.php?tab=dashboard');
        } else {
            header('Location: ' . gasFormTabUrl(gasFormListRedirectParams(array_merge($_POST, ['list_page' => 1]))));
        }
    exit;
}

    if ($action === 'update_entry') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $existingEntry = $entryId > 0 ? getGasEntry($entryId) : null;

        if ($existingEntry && (string) ($existingEntry['entry_type'] ?? '') === 'status_update') {
            try {
                $userId = (int) currentUser()['id'];
                $tankUpdates = [];
                foreach ((array) ($_POST['tank_units'] ?? []) as $tankUnitId => $status) {
                    $tankUpdates[(int) $tankUnitId] = (string) $status;
                }
                $tankMachines = [];
                foreach ((array) ($_POST['tank_machines'] ?? []) as $tankUnitId => $machine) {
                    $tankMachines[(int) $tankUnitId] = trim((string) $machine);
                }
                $tankUnitId = (int) ($existingEntry['tank_unit_id'] ?? 0);
                $newStatus = $tankUpdates[$tankUnitId] ?? '';
                $machineCode = $tankMachines[$tankUnitId] ?? '';
                if ($newStatus !== '' && applyGasStatusUpdateEntryEdit($entryId, $newStatus, $userId, $machineCode)) {
                    logActivity('UPDATE', 'gas_consumption', 'Updated status log #' . $entryId);
                    flash('success', 'แก้ไขสถานะถังสำเร็จแล้ว — Remain และ Dashboard อัปเดตแล้ว');
                } elseif (
                    $newStatus === 'in_use'
                    && count(gasUsageSubTypes((string) ($existingEntry['gas_type'] ?? ''))) > 1
                    && $machineCode === ''
                ) {
                    flash('error', 'กรุณาเลือกเครื่องเมื่อเปลี่ยนสถานะเป็นกำลังทำงาน');
                } else {
                    flash('error', 'ไม่สามารถแก้ไขสถานะได้');
                }
            } catch (Throwable $e) {
                flash('error', 'ไม่สามารถแก้ไขได้ กรุณาตรวจสอบข้อมูล');
            }
            header('Location: ' . gasFormTabUrl(gasFormListRedirectParams(array_merge($_POST, ['list_page' => 1]))));
            exit;
        }

        $gasType = (string) ($_POST['gas_type'] ?? '');
        $subType = (string) ($_POST['sub_type'] ?? '');
        $recordDate = (string) ($_POST['record_date'] ?? $defaultRecordDate);
        $entryType = (string) ($_POST['entry_type'] ?? 'usage');
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));

        $dateValid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $recordDate) === 1;
        $dateObj = $dateValid ? DateTime::createFromFormat('Y-m-d', $recordDate) : false;
        $dateValid = $dateValid && $dateObj && $dateObj->format('Y-m-d') === $recordDate;

        if (
            $entryId > 0
            && $dateValid
            && isset(gasTypes()[$gasType])
            && gasIsValidSubTypeForEntry($gasType, $subType, $entryType)
            && getGasEntry($entryId)
        ) {
            try {
                $userId = (int) currentUser()['id'];
                updateGasEntry($entryId, $gasType, $subType, $recordDate, $entryType, $quantity, $userId, $note);

                logActivity('UPDATE', 'gas_consumption', gasTypeLabel($gasType) . '/' . $subType . ' ' . $entryType . ': ' . $quantity . ' on ' . $recordDate);
                flash('success', 'แก้ไขรายการสำเร็จแล้ว');
            } catch (Throwable $e) {
                flash('error', 'ไม่สามารถแก้ไขได้ กรุณาตรวจสอบข้อมูล');
            }
        } else {
            flash('error', 'กรุณากรอกข้อมูลให้ครบถ้วน');
        }
        header('Location: ' . gasFormTabUrl(gasFormListRedirectParams(array_merge($_POST, ['list_page' => 1]))));
        exit;
    }

    if ($action === 'delete_entry') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $summaryYear = (int) ($_POST['summary_year'] ?? $year);
        if ($summaryYear < 2000 || $summaryYear > 2100) {
            $summaryYear = $year;
        }
        if ($entryId > 0 && deleteGasEntry($entryId)) {
            logActivity('DELETE', 'gas_consumption', 'Deleted entry #' . $entryId);
            flash('success', 'ลบรายการแล้ว');
        } else {
            flash('error', 'ไม่พบรายการที่ต้องการลบ');
        }
        header('Location: gas_consumption.php?tab=summary&year=' . $summaryYear);
        exit;
    }

    if ($action === 'delete_entry_form') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        if ($entryId > 0 && deleteGasEntry($entryId)) {
            logActivity('DELETE', 'gas_consumption', 'Deleted entry #' . $entryId);
            flash('success', 'ลบรายการแล้ว');
        } else {
            flash('error', 'ไม่พบรายการที่ต้องการลบ');
        }
        header('Location: ' . gasFormTabUrl(gasFormListRedirectParams($_POST)));
        exit;
    }

    if ($action === 'delete_entries_form') {
        $entryIds = array_map('intval', (array) ($_POST['entry_ids'] ?? []));
        $deleted = deleteGasEntries($entryIds);
        if ($deleted > 0) {
            logActivity('DELETE', 'gas_consumption', 'Bulk deleted ' . $deleted . ' gas entries');
            flash('success', 'ลบ ' . number_format($deleted) . ' รายการแล้ว');
        } else {
            flash('error', 'กรุณาเลือกรายการที่ต้องการลบ');
        }
        header('Location: ' . gasFormTabUrl(gasFormListRedirectParams($_POST)));
        exit;
    }

    if ($action === 'update_tank_unit') {
        $tankUnitId = (int) ($_POST['tank_unit_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');
        $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        $ok = $tankUnitId > 0 && updateGasTankUnitStatus($tankUnitId, $status, (int) currentUser()['id']);

        if ($ok) {
            logActivity('UPDATE', 'gas_consumption', 'Updated tank unit #' . $tankUnitId . ' status to ' . $status);
            if (!$isAjax) {
                flash('success', 'อัปเดตสถานะถังแล้ว');
            }
        } elseif (!$isAjax) {
            flash('error', 'ไม่สามารถอัปเดตสถานะถังได้');
        }

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: ' . gasFormTabUrl(gasFormListRedirectParams($_POST)));
        exit;
    }

    if ($action === 'save_tanks') {
        $usageRecorded = saveTankInventory(
            $_POST['tanks'] ?? [],
            (int) currentUser()['id'],
            is_array($_POST['tank_machines'] ?? null) ? $_POST['tank_machines'] : []
        );
        gasRefreshTankInventoryFromEntries(null, $currentMonth);
        logActivity('UPDATE', 'gas_consumption', 'Updated tank inventory');

        $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($isAjax) {
            $groupedUnits = loadGasTankUnits();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'counts' => gasTankBoardCounts($groupedUnits),
                'units' => gasTankBoardUnitsPayload($groupedUnits),
                'reload' => $usageRecorded > 0,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        flash('success', 'บันทึกสถานะถังแก๊สแล้ว');
        header('Location: gas_consumption.php?tab=dashboard');
        exit;
    }
}

$formMonthSummary = gasMonthSummaryWithRemaining($currentMonth);
$formListTotal = countGasEntries($formListMonth, $formListType);
$formListPages = max(1, (int) ceil($formListTotal / $formListPerPage));
if ($formListPage > $formListPages) {
    $formListPage = $formListPages;
}
$formListOffset = ($formListPage - 1) * $formListPerPage;
$formRecentEntries = listGasEntries($formListMonth, $formListPerPage, $formListOffset, $formListType);
$formListUrlParams = ['list_month' => $formListMonth, 'list_page' => $formListPage];
if ($formListType !== '') {
    $formListUrlParams['list_type'] = $formListType;
}
$manageTankUnits = $tab === 'form' ? loadGasTankUnitsFlat() : [];
$manageTankUnitsByType = [];
foreach ($manageTankUnits as $unit) {
    $manageTankUnitsByType[(string) ($unit['gas_type'] ?? '')][] = $unit;
}
$yearlyDetail = $tab === 'summary' && $detailMonth === '' ? gasYearlyDetailSummary($year) : [];
$yearlyStock = in_array($tab, ['summary', 'dashboard'], true) ? gasYearlyStockByType($year) : [];
$monthlyDaily = $tab === 'summary' && $detailMonth !== '' ? gasMonthlyDailyDetail($detailMonth) : [];
$monthDetailSummary = $tab === 'summary' && $detailMonth !== ''
    ? gasMonthSummaryWithRemaining($detailMonth)
    : [];
$dashMonthSummary = $tab === 'dashboard' ? gasDashboardMonthSummary($dashboardMonth) : [];
$dashMonthTotals = $tab === 'dashboard' ? gasDashboardMonthTotals($dashboardMonth) : [];
$tankUnits = loadGasTankUnits();
$tankBoardTotals = gasTankTotalsFromUnits($tankUnits);
$yearlySummary = gasYearlyUsageSummary($year);
$yearlyAddingSummary = gasYearlyAddingSummary($year);
$monthNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$chartLabels = array_slice($monthNames, 1);
$chartDatasets = [];
foreach (gasTypes() as $gasKey => $gasMeta) {
    $values = [];
    for ($m = 1; $m <= 12; $m++) {
        $values[] = (int) ($yearlySummary[$gasKey][$m] ?? 0);
    }
    $chartDatasets[] = [
        'label' => $gasMeta['label'],
        'data' => $values,
        'backgroundColor' => gasTypeColor($gasKey),
        'borderRadius' => 8,
    ];
}

$selectedGas = (string) ($_GET['gas'] ?? 'o2_96');
if (!isset(gasTypes()[$selectedGas])) {
    $selectedGas = 'o2_96';
}

renderHeader('Gas Consumption', 'gas');
?>
<section class="hero gas-hero">
  <div class="gas-hero-inner">
    <div>
      <p class="gas-hero-eyebrow">Laboratory Utility</p>
  <h1>Gas Consumption</h1>
      <p>บันทึกการใช้และรับถังแก๊ส — หน่วย: ถัง</p>
    </div>
  </div>
</section>

<section class="gas-layout">
  <?php if ($msg = flash('success')): ?>
    <div class="message success"><?= h($msg) ?></div>
  <?php endif; ?>
  <?php if ($msg = flash('error')): ?>
    <div class="message error"><?= h($msg) ?></div>
  <?php endif; ?>

  <div class="card data-tabs-wrap gas-tabs-wrap">
    <div class="data-tabs gas-tabs gas-tabs--three">
      <a class="data-tab gas-tab <?= $tab === 'dashboard' ? 'active' : '' ?>" href="gas_consumption.php?tab=dashboard">
        <span class="gas-tab-icon">◉</span> Dashboard
      </a>
      <a class="data-tab gas-tab <?= $tab === 'summary' ? 'active' : '' ?>" href="gas_consumption.php?tab=summary&amp;year=<?= $year ?>">
        <span class="gas-tab-icon">▤</span> Gas Usage Summary
      </a>
      <a class="data-tab gas-tab <?= $tab === 'form' ? 'active' : '' ?>" href="gas_consumption.php?tab=form">
        <span class="gas-tab-icon">✎</span> Data Management
      </a>
      </div>
      </div>

  <?php if ($tab === 'dashboard'): ?>
    <section class="gas-tank-board">
      <article class="card gas-tank-board-head">
        <div class="gas-tank-board-head-top">
          <div class="gas-tank-board-head-title">
            <h1>Gas Cylinder Tracking Chart</h1>
      </div>
          <button
            type="button"
            class="gas-dash-summary-toggle"
            id="gasDashSummaryToggle"
            aria-expanded="false"
            aria-controls="gasDashSummaryModal"
          >ดูข้อมูล Summary</button>
        </div>
        <div class="gas-tank-board-head-inner">
      <div>
            <p class="muted gas-dash-period">สรุปเดือน <?= h(gasMonthLabel($currentMonth)) ?> — ยอดต้นเดือนยกมาจาก Remain เดือนก่อน</p>
            <ul class="gas-tank-legend" aria-label="คำอธิบายสถานะถัง">
              <li><span class="gas-tank-legend-swatch is-full"></span> เต็ม</li>
              <li><span class="gas-tank-legend-swatch is-in_use"></span> กำลังทำงาน</li>
              <li><span class="gas-tank-legend-swatch is-empty"></span> หมดแล้ว</li>
              <li><span class="gas-tank-legend-swatch is-damaged"></span> ชำรุด</li>
            </ul>
      </div>
          <div class="gas-tank-kpis" id="gasTankKpis">
            <div class="gas-kpi"><span>ถังทั้งหมด</span><strong data-kpi="total"><?= (int) $dashMonthTotals['total_tanks'] ?></strong></div>
            <div class="gas-kpi gas-kpi--full"><span>เต็ม</span><strong data-kpi="full"><?= (int) $dashMonthTotals['full_tanks'] ?></strong></div>
            <div class="gas-kpi gas-kpi--empty"><span>ใช้ไปแล้ว</span><strong data-kpi="empty"><?= (int) $dashMonthTotals['empty_tanks'] ?></strong></div>
            <div class="gas-kpi gas-kpi--use<?= (int) $tankBoardTotals['in_use_tanks'] > 0 ? ' is-pulsing' : '' ?>" title="ถังที่ต่อเครื่องอยู่บนผัง"><span>กำลังทำงาน</span><strong data-kpi="in_use"><?= (int) $tankBoardTotals['in_use_tanks'] ?></strong></div>
            <div class="gas-kpi gas-kpi--damaged<?= (int) $tankBoardTotals['damaged_tanks'] > 0 ? ' has-value' : '' ?>"><span>ชำรุด</span><strong data-kpi="damaged"><?= (int) $tankBoardTotals['damaged_tanks'] ?></strong></div>
          </div>
        </div>
        <p class="gas-tank-save-hint" id="gasTankSaveHint" role="status" aria-live="polite" hidden></p>
      </article>

      <div class="gas-tank-board-body" id="gasTankBoard">
        <?php foreach (gasTypes() as $gasKey => $gasMeta):
          $units = $tankUnits[$gasKey] ?? [];
          $typeSummary = $dashMonthSummary[$gasKey] ?? [];
          $typeTotal = (int) ($typeSummary['total'] ?? 0);
          $typeFull = (int) ($typeSummary['full'] ?? 0);
          $typeUsed = (int) ($typeSummary['used'] ?? 0);
          if ($typeTotal <= 0 && !$units) {
              continue;
          }
          $units = gasSortTankUnitsForDisplay($units);
          $groupCounts = ['full' => 0, 'empty' => 0, 'in_use' => 0, 'damaged' => 0];
          foreach ($units as $unit) {
              $tankNo = (int) ($unit['tank_no'] ?? 0);
              $st = (string) ($unit['status'] ?? 'empty');
              $st = gasTankDisplayStatus($tankNo, $typeFull, $typeUsed, $st);
              if (isset($groupCounts[$st])) {
                  $groupCounts[$st]++;
              }
          }
          $groupTotal = count($units);
        ?>
        <section class="gas-tank-group card" style="--accent: <?= h(gasTypeColor($gasKey)) ?>" data-gas-type="<?= h($gasKey) ?>">
          <header class="gas-tank-group-head">
            <div class="gas-tank-group-head-row">
              <div class="gas-tank-group-title">
                <span class="gas-tank-group-badge"><?= h(gasTankShortLabel($gasKey)) ?></span>
                <h4><?= h($gasMeta['label']) ?></h4>
              </div>
              <div class="gas-tank-group-head-end">
                <?php renderGasDashboardGroupActions($gasKey); ?>
                <div class="gas-tank-group-meta">
                  <span data-group-kpi="total"><?= $groupTotal ?> ถัง</span>
                  <span class="gas-tank-group-pill is-full" data-group-kpi="full"><?= $typeFull ?> เต็ม</span>
                  <span class="gas-tank-group-pill is-empty" data-group-kpi="empty"><?= $typeUsed ?> ใช้ไปแล้ว</span>
                  <span class="gas-tank-group-pill is-use" data-group-kpi="in_use"><?= (int) $groupCounts['in_use'] ?> กำลังทำงาน</span>
                  <span class="gas-tank-group-pill is-damaged<?= (int) $groupCounts['damaged'] === 0 ? ' is-zero' : '' ?>" data-group-kpi="damaged"><?= (int) $groupCounts['damaged'] ?> ชำรุด</span>
                </div>
              </div>
            </div>
          </header>
          <div class="gas-tank-units">
            <?php foreach ($units as $unit):
              $tankNo = (int) $unit['tank_no'];
              $tankUnitId = (int) ($unit['id'] ?? 0);
              $status = (string) ($unit['status'] ?? 'empty');
              $slotRole = gasTankSlotRole($tankNo, $typeFull, $typeUsed, $status);
              $displayStatus = gasTankDisplayStatus($tankNo, $typeFull, $typeUsed, $status);
              $machineLabel = gasTankDashboardMachineLabel($unit, $displayStatus);
              $statusLabel = gasTankStatuses()[$displayStatus] ?? $displayStatus;
              $isLocked = gasTankIsDashboardLocked($tankNo, $typeFull, $typeUsed, $displayStatus);
              $selectableStatuses = gasTankDashboardSelectableStatusesFor($displayStatus);
            ?>
            <div
              class="gas-tank-unit is-<?= h($displayStatus) ?> gas-tank-unit--<?= h($slotRole) ?><?= $isLocked ? ' gas-tank-unit--locked' : '' ?><?= $machineLabel !== '' ? ' has-machine' : '' ?>"
              data-status="<?= h($displayStatus) ?>"
              data-slot="<?= h($slotRole) ?>"
              data-tank-no="<?= $tankNo ?>"
              data-tank-id="<?= (int) ($unit['id'] ?? 0) ?>"
              <?= $machineLabel !== '' ? 'data-machine="' . h($machineLabel) . '"' : '' ?>
            >
              <div class="gas-tank-unit-visual" aria-hidden="true">
                <?= renderGasTankIcon(gasTankShortLabel($gasKey)) ?>
              </div>
              <?php if ($tankUnitId > 0): ?>
              <span class="gas-tank-unit-id" title="ใช้ ID นี้แก้ไขที่ Data Management">ID <?= $tankUnitId ?></span>
              <?php endif; ?>
              <?php if ($machineLabel !== ''): ?>
              <span class="gas-tank-unit-machine"><?= h($machineLabel) ?></span>
              <?php endif; ?>
              <span class="gas-tank-unit-status-chip"><?= h($statusLabel) ?></span>
              <?php if ($slotRole === 'used'): ?>
              <span class="gas-tank-unit-note">ใช้ไปแล้ว</span>
              <?php endif; ?>
              <?php if ($isLocked): ?>
              <input
                type="hidden"
                class="gas-tank-status-hidden"
                name="tanks[<?= h($gasKey) ?>][<?= $tankNo ?>]"
                value="<?= h($displayStatus) ?>"
              >
              <?php else: ?>
              <label class="gas-tank-unit-label" for="tank-<?= h($gasKey) ?>-<?= $tankNo ?>">สถานะ</label>
              <select
                id="tank-<?= h($gasKey) ?>-<?= $tankNo ?>"
                class="gas-tank-status-select"
                name="tanks[<?= h($gasKey) ?>][<?= $tankNo ?>]"
                data-gas="<?= h($gasKey) ?>"
              >
                <?php if (in_array($displayStatus, ['full', 'in_use', 'damaged'], true)): ?>
                <option value="<?= h($displayStatus) ?>" selected disabled><?= h($statusLabel) ?></option>
                <?php endif; ?>
                <?php foreach ($selectableStatuses as $statusKey):
                  if ($statusKey === $displayStatus) {
                      continue;
                  }
                  $optionLabel = gasTankStatuses()[$statusKey] ?? $statusKey;
                ?>
                <option value="<?= h($statusKey) ?>"><?= h($optionLabel) ?></option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </section>
        <?php endforeach; ?>
      </div>
    </section>

    <div id="gasMachinePickModal" class="gas-machine-pick-modal" hidden aria-hidden="true">
      <div class="gas-machine-pick-backdrop" data-machine-pick-cancel></div>
      <div class="gas-machine-pick-dialog" role="dialog" aria-modal="true" aria-labelledby="gasMachinePickTitle">
        <header class="gas-machine-pick-head">
          <div class="gas-machine-pick-head-text">
            <span class="gas-machine-pick-eyebrow">Dashboard</span>
            <h4 id="gasMachinePickTitle">ใช้งานกับเครื่องไหน?</h4>
            <p class="gas-machine-pick-subtitle" id="gasMachinePickSubtitle"></p>
          </div>
          <button type="button" class="gas-machine-pick-close" data-machine-pick-cancel aria-label="ปิด">×</button>
        </header>
        <div class="gas-machine-pick-body">
          <p class="gas-machine-pick-hint">เลือกเครื่องที่นำถังนี้ไปต่อใช้งาน</p>
          <div id="gasMachinePickList" class="gas-machine-pick-list" role="listbox" aria-label="เลือกเครื่อง"></div>
        </div>
        <footer class="gas-machine-pick-foot">
          <button type="button" class="gas-machine-pick-cancel" data-machine-pick-cancel>ยกเลิก</button>
        </footer>
      </div>
    </div>

    <script>
    (() => {
      const gasUsageMachines = <?php
        $usageMachineMap = [];
        foreach (gasTypes() as $gasKey => $meta) {
            $usageMachineMap[$gasKey] = gasUsageSubTypes($gasKey);
        }
        echo json_encode($usageMachineMap, JSON_UNESCAPED_UNICODE);
      ?>;
      const getBoard = () => document.getElementById('gasTankBoard');
      if (!getBoard()) return;

      const saveHint = document.getElementById('gasTankSaveHint');
      const kpiRoot = document.getElementById('gasTankKpis');
      const kpiUseCard = kpiRoot?.querySelector('.gas-kpi--use');
      const kpiDamagedCard = kpiRoot?.querySelector('.gas-kpi--damaged');
      const kpiMap = {
        total: kpiRoot?.querySelector('[data-kpi="total"]'),
        full: kpiRoot?.querySelector('[data-kpi="full"]'),
        empty: kpiRoot?.querySelector('[data-kpi="empty"]'),
        in_use: kpiRoot?.querySelector('[data-kpi="in_use"]'),
        damaged: kpiRoot?.querySelector('[data-kpi="damaged"]'),
      };
      const statusLabels = { in_use: 'กำลังทำงาน', full: 'เต็ม', empty: 'หมดแล้ว', damaged: 'ชำรุด' };
      const dashboardNextStatuses = {
        full: ['in_use', 'damaged'],
        in_use: ['empty', 'damaged'],
        damaged: ['empty'],
      };
      const machinePickModal = document.getElementById('gasMachinePickModal');
      const machinePickTitle = document.getElementById('gasMachinePickTitle');
      const machinePickSubtitle = document.getElementById('gasMachinePickSubtitle');
      const machinePickList = document.getElementById('gasMachinePickList');
      let machinePickResolver = null;

      const closeMachinePick = (result = '') => {
        if (machinePickModal) {
          machinePickModal.hidden = true;
          machinePickModal.setAttribute('aria-hidden', 'true');
        }
        document.body.classList.remove('gas-summary-modal-open');
        if (machinePickResolver) {
          const resolve = machinePickResolver;
          machinePickResolver = null;
          resolve(result);
        }
      };

      machinePickModal?.querySelectorAll('[data-machine-pick-cancel]').forEach((el) => {
        el.addEventListener('click', () => closeMachinePick(''));
      });

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && machinePickModal && !machinePickModal.hidden) {
          closeMachinePick('');
        }
      });

      const promptMachine = (gasKey, gasLabel) => {
        const machines = gasUsageMachines[gasKey] || [];
        if (machines.length === 0) {
          return Promise.resolve('');
        }
        if (machines.length === 1) {
          return Promise.resolve(machines[0]);
        }
        return new Promise((resolve) => {
          machinePickResolver = resolve;
          if (machinePickTitle) {
            machinePickTitle.textContent = 'ใช้งานกับเครื่องไหน?';
          }
          if (machinePickSubtitle) {
            machinePickSubtitle.textContent = gasLabel || gasKey;
          }
          if (machinePickList) {
            machinePickList.innerHTML = '';
            machines.forEach((machine) => {
              const btn = document.createElement('button');
              btn.type = 'button';
              btn.className = 'gas-machine-pick-option';
              btn.setAttribute('role', 'option');
              const label = document.createElement('span');
              label.className = 'gas-machine-pick-option-label';
              label.textContent = machine;
              const arrow = document.createElement('span');
              arrow.className = 'gas-machine-pick-option-arrow';
              arrow.setAttribute('aria-hidden', 'true');
              arrow.textContent = '→';
              btn.append(label, arrow);
              btn.addEventListener('click', () => closeMachinePick(machine));
              machinePickList.appendChild(btn);
            });
          }
          if (machinePickModal) {
            machinePickModal.hidden = false;
            machinePickModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('gas-summary-modal-open');
            machinePickModal.querySelector('.gas-machine-pick-close')?.focus();
          }
        });
      };

      const setCardMachine = (card, machine) => {
        if (!card) return;
        if (!machine) {
          delete card.dataset.machine;
          card.classList.remove('has-machine');
          card.querySelector('.gas-tank-unit-machine')?.remove();
          return;
        }
        card.dataset.machine = machine;
        card.classList.add('has-machine');
        let el = card.querySelector('.gas-tank-unit-machine');
        if (!el) {
          el = document.createElement('span');
          el.className = 'gas-tank-unit-machine';
          const anchor = card.querySelector('.gas-tank-unit-id')
            || card.querySelector('.gas-tank-unit-visual');
          if (anchor) {
            anchor.insertAdjacentElement('afterend', el);
          } else {
            card.prepend(el);
          }
        }
        el.textContent = machine;
      };

      let saveQueue = Promise.resolve();
      let hintTimer = null;

      const clearSaveHint = () => {
        if (!saveHint) return;
        clearTimeout(hintTimer);
        saveHint.hidden = true;
        saveHint.textContent = '';
        saveHint.classList.remove('is-error');
      };

      const setSaveHint = (message, isError = false) => {
        if (!saveHint) return;
        if (!message) {
          clearSaveHint();
          return;
        }
        saveHint.hidden = false;
        saveHint.textContent = message;
        saveHint.classList.toggle('is-error', isError);
        clearTimeout(hintTimer);
        if (!isError) {
          hintTimer = setTimeout(clearSaveHint, 2200);
        }
      };

      const getCardStatus = (card) => {
        const hidden = card.querySelector('.gas-tank-status-hidden');
        if (hidden) return hidden.value;
        const select = card.querySelector('.gas-tank-status-select');
        if (!select) return card.dataset.status || 'empty';
        const value = select.value;
        const status = card.dataset.status || 'full';
        if (!value || value === status) return status;
        return value;
      };

      const collectTankPayload = () => {
        const formData = new FormData();
        formData.append('action', 'save_tanks');
        const board = getBoard();
        if (!board) return formData;
        board.querySelectorAll('.gas-tank-group').forEach((section) => {
          const gasKey = section.dataset.gasType || '';
          if (!gasKey) return;
          section.querySelectorAll('.gas-tank-unit').forEach((card) => {
            const tankNo = card.dataset.tankNo;
            if (!tankNo) return;
            const hidden = card.querySelector('.gas-tank-status-hidden');
            const select = card.querySelector('.gas-tank-status-select');
            const fieldName = `tanks[${gasKey}][${tankNo}]`;
            if (hidden) {
              formData.append(fieldName, hidden.value);
              return;
            }
            if (!select) return;
            const status = getCardStatus(card);
            if (status) formData.append(fieldName, status);
            if (status === 'in_use') {
              const machine = card.dataset.machine || '';
              if (machine) {
                formData.append(`tank_machines[${gasKey}][${tankNo}]`, machine);
              }
            }
          });
        });
        return formData;
      };

      const countBoardStatuses = () => {
        const totals = { total: 0, full: 0, empty: 0, in_use: 0, damaged: 0 };
        const groups = {};
        const board = getBoard();
        if (!board) return { totals, groups };
        board.querySelectorAll('.gas-tank-group').forEach((section) => {
          const gasKey = section.dataset.gasType || '';
          if (!gasKey) return;
          groups[gasKey] = { total: 0, full: 0, empty: 0, in_use: 0, damaged: 0 };
          section.querySelectorAll('.gas-tank-unit').forEach((card) => {
            totals.total++;
            groups[gasKey].total++;
            const status = getCardStatus(card);
            if (status in totals && status !== 'total') {
              totals[status]++;
              groups[gasKey][status]++;
            }
          });
        });
        return { totals, groups };
      };

      const normalizeBoardCounts = (counts) => {
        const totals = counts?.totals || {};
        const groups = counts?.groups || {};
        const dash = counts?.dash || {};
        const physical = counts?.physical || {};
        return {
          totals: {
            total_tanks: Number(totals.total_tanks ?? totals.total ?? 0),
            full_tanks: Number(totals.full_tanks ?? totals.full ?? 0),
            empty_tanks: Number(totals.empty_tanks ?? totals.empty ?? 0),
            in_use_tanks: Number(totals.in_use_tanks ?? physical.in_use_tanks ?? totals.in_use ?? 0),
            damaged_tanks: Number(totals.damaged_tanks ?? physical.damaged_tanks ?? totals.damaged ?? 0),
          },
          groups,
          dash,
        };
      };

      const applyBoardCounts = (counts) => {
        const { totals, groups, dash } = normalizeBoardCounts(counts);
        const board = getBoard();

        if (kpiMap.total) kpiMap.total.textContent = String(totals.total_tanks);
        if (kpiMap.full) kpiMap.full.textContent = String(totals.full_tanks);
        if (kpiMap.empty) kpiMap.empty.textContent = String(totals.empty_tanks);
        if (kpiMap.in_use) kpiMap.in_use.textContent = String(totals.in_use_tanks);
        if (kpiMap.damaged) kpiMap.damaged.textContent = String(totals.damaged_tanks);
        kpiUseCard?.classList.toggle('is-pulsing', totals.in_use_tanks > 0);
        kpiDamagedCard?.classList.toggle('has-value', totals.damaged_tanks > 0);

        if (!board) return;
        board.querySelectorAll('.gas-tank-group').forEach((section) => {
          const gasKey = section.dataset.gasType || '';
          const groupCounts = groups[gasKey] || { total: 0, full: 0, empty: 0, in_use: 0, damaged: 0 };
          const dashCounts = dash[gasKey] || null;
          const totalPill = section.querySelector('[data-group-kpi="total"]');
          const fullPill = section.querySelector('[data-group-kpi="full"]');
          const emptyPill = section.querySelector('[data-group-kpi="empty"]');
          const inUsePill = section.querySelector('[data-group-kpi="in_use"]');
          const damagedPill = section.querySelector('[data-group-kpi="damaged"]');
          if (totalPill) totalPill.textContent = groupCounts.total + ' ถัง';
          if (fullPill) {
            fullPill.textContent = (dashCounts ? dashCounts.full : groupCounts.full) + ' เต็ม';
          }
          if (emptyPill) {
            emptyPill.textContent = (dashCounts ? dashCounts.used : groupCounts.empty) + ' ใช้ไปแล้ว';
          }
          if (inUsePill) inUsePill.textContent = groupCounts.in_use + ' กำลังทำงาน';
          if (damagedPill) {
            damagedPill.textContent = groupCounts.damaged + ' ชำรุด';
            damagedPill.classList.toggle('is-zero', groupCounts.damaged === 0);
          }
        });
      };

      const rebuildTankSelect = (select, status) => {
        const nextOptions = dashboardNextStatuses[status] || [];
        select.innerHTML = '';
        if (status && status !== 'empty') {
          const current = document.createElement('option');
          current.value = status;
          current.textContent = statusLabels[status] || status;
          current.selected = true;
          current.disabled = true;
          select.appendChild(current);
        }
        nextOptions.forEach((key) => {
          const opt = document.createElement('option');
          opt.value = key;
          opt.textContent = statusLabels[key] || key;
          select.appendChild(opt);
        });
      };

      const lockTankCard = (card, status) => {
        const select = card.querySelector('.gas-tank-status-select');
        const label = card.querySelector('.gas-tank-unit-label');
        const gasKey = select?.dataset.gas || card.closest('.gas-tank-group')?.dataset.gasType || '';
        const tankNo = card.dataset.tankNo || '';
        if (select) {
          const hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.className = 'gas-tank-status-hidden';
          hidden.name = `tanks[${gasKey}][${tankNo}]`;
          hidden.value = status;
          select.replaceWith(hidden);
        }
        if (label) label.remove();
        card.classList.add('gas-tank-unit--locked');
        card.dataset.slot = 'used';
        let noteEl = card.querySelector('.gas-tank-unit-note');
        if (!noteEl) {
          noteEl = document.createElement('span');
          noteEl.className = 'gas-tank-unit-note';
          const chip = card.querySelector('.gas-tank-unit-status-chip');
          if (chip) chip.insertAdjacentElement('afterend', noteEl);
        }
        noteEl.textContent = 'ใช้ไปแล้ว';
      };

      const updateCard = (select) => {
        const card = select.closest('.gas-tank-unit');
        if (!card) return;
        const nextStatus = select.value;
        if (!nextStatus || nextStatus === card.dataset.status) return;
        card.classList.remove('is-in_use', 'is-full', 'is-empty', 'is-damaged');
        card.classList.add('is-' + nextStatus);
        card.dataset.status = nextStatus;
        const chip = card.querySelector('.gas-tank-unit-status-chip');
        if (chip) chip.textContent = statusLabels[nextStatus] || nextStatus;
        if (nextStatus === 'full' || nextStatus === 'empty') {
          setCardMachine(card, '');
        }
        if (nextStatus === 'empty') {
          lockTankCard(card, nextStatus);
          return;
        }
        rebuildTankSelect(select, nextStatus);
      };

      const applyBoardUnits = (unitsByType) => {
        if (!unitsByType) return;
        const board = getBoard();
        if (!board) return;
        Object.entries(unitsByType).forEach(([gasKey, units]) => {
          (units || []).forEach((unit) => {
            const card = board.querySelector(
              `.gas-tank-group[data-gas-type="${gasKey}"] .gas-tank-unit[data-tank-no="${unit.tank_no}"]`
            );
            if (!card) return;

            const nextStatus = unit.status || 'empty';
            const prevStatus = card.dataset.status || '';
            const isLocked = !!unit.locked;

            card.classList.remove('is-in_use', 'is-full', 'is-empty', 'is-damaged');
            card.classList.add('is-' + nextStatus);
            card.dataset.status = nextStatus;
            card.dataset.slot = unit.slot || card.dataset.slot || 'stock';

            const chip = card.querySelector('.gas-tank-unit-status-chip');
            if (chip) chip.textContent = statusLabels[nextStatus] || nextStatus;

            if (nextStatus === 'full' || nextStatus === 'empty') {
              setCardMachine(card, '');
            } else if (unit.machine_code) {
              setCardMachine(card, unit.machine_code);
            }

            const noteEl = card.querySelector('.gas-tank-unit-note');
            if (unit.slot === 'used') {
              if (noteEl) {
                noteEl.textContent = 'ใช้ไปแล้ว';
              }
            } else if (noteEl) {
              noteEl.remove();
            }

            if (isLocked) {
              if (card.querySelector('.gas-tank-status-select')) {
                lockTankCard(card, nextStatus);
              } else {
                card.classList.add('gas-tank-unit--locked');
                const hidden = card.querySelector('.gas-tank-status-hidden');
                if (hidden) hidden.value = nextStatus;
              }
            } else if (prevStatus !== nextStatus) {
              card.classList.remove('gas-tank-unit--locked');
              const select = card.querySelector('.gas-tank-status-select');
              if (select) rebuildTankSelect(select, nextStatus);
            }
          });
        });
      };

      const bindBoardSelectHandlers = (boardEl) => {
        if (!boardEl) return;
        boardEl.querySelectorAll('.gas-tank-status-select').forEach((select) => {
          select.addEventListener('change', async () => {
            const card = select.closest('.gas-tank-unit');
            const prevStatus = card?.dataset.status || '';
            const nextStatus = select.value;
            if (nextStatus === 'in_use' && prevStatus !== 'in_use') {
              const gasKey = select.dataset.gas || card?.closest('.gas-tank-group')?.dataset.gasType || '';
              const gasLabel = card?.closest('.gas-tank-group')
                ?.querySelector('.gas-tank-group-title h4')?.textContent?.trim() || gasKey;
              const machine = await promptMachine(gasKey, gasLabel);
              if (!machine) {
                select.value = prevStatus;
                return;
              }
              setCardMachine(card, machine);
            }
            if (nextStatus === 'empty' && prevStatus !== 'empty') {
              const gasLabel = card?.closest('.gas-tank-group')
                ?.querySelector('.gas-tank-group-title h4')?.textContent?.trim() || '';
              const machine = card?.dataset.machine || '';
              const targetLabel = machine !== ''
                ? (gasLabel !== '' ? gasLabel + ' · ' + machine : machine)
                : (gasLabel || 'ถังนี้');
              const ok = confirm(
                'ยืนยันถังหมดแล้ว?\n\n'
                + targetLabel + '\n'
                + 'ระบบจะหัก Remain 1 ถังใน Summary'
              );
              if (!ok) {
                select.value = prevStatus;
                return;
              }
            }
            updateCard(select);
            applyBoardCounts(countBoardStatuses());
            saveTanks();
          });
        });
      };

      const reloadGasTankBoard = async () => {
        const response = await fetch('gas_consumption.php?tab=dashboard', {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        });
        if (!response.ok) {
          throw new Error('reload failed');
        }
        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const nextBoard = doc.getElementById('gasTankBoard');
        const currentBoard = getBoard();
        if (!nextBoard || !currentBoard) {
          window.location.reload();
          return;
        }
        currentBoard.replaceWith(nextBoard);
        bindBoardSelectHandlers(getBoard());
      };

      window.gasTankBoard = {
        async refresh(counts, units, options = {}) {
          if (options.reloadBoard) {
            await reloadGasTankBoard();
            applyBoardCounts(counts || countBoardStatuses());
            return;
          }
          applyBoardUnits(units);
          applyBoardCounts(counts || countBoardStatuses());
        },
      };

      const saveTanks = () => {
        saveQueue = saveQueue.then(async () => {
          try {
            const response = await fetch('gas_consumption.php?tab=dashboard', {
              method: 'POST',
              body: collectTankPayload(),
              headers: { 'X-Requested-With': 'XMLHttpRequest' },
              credentials: 'same-origin',
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
              throw new Error('save failed');
            }
            if (data.reload) {
              await reloadGasTankBoard();
              applyBoardCounts(data.counts);
              return;
            }
            applyBoardUnits(data.units);
            applyBoardCounts(data.counts);
            clearSaveHint();
          } catch (error) {
            setSaveHint('บันทึกไม่สำเร็จ กรุณาลองอีกครั้ง', true);
          }
        });
        return saveQueue;
      };

      bindBoardSelectHandlers(getBoard());
    })();
    </script>

    <div id="gasDashEntryModal" class="gas-summary-modal gas-edit-modal gas-dash-entry-modal" hidden aria-hidden="true">
      <div class="gas-summary-modal__backdrop" data-gas-dash-entry-close></div>
      <div class="gas-summary-modal__dialog gas-edit-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="gasDashEntryTitle">
        <div class="gas-summary-modal__toolbar">
          <div>
            <h3 id="gasDashEntryTitle">Gas Inventory Record</h3>
            <p class="muted" id="gasDashEntrySubtitle"></p>
          </div>
          <button type="button" class="gas-summary-modal__close" data-gas-dash-entry-close aria-label="ปิด">×</button>
        </div>
        <div class="gas-summary-modal__body">
          <form method="post" class="gf-form gf-form--modal" id="gasDashEntryForm">
            <input type="hidden" name="action" value="save_entry">
            <input type="hidden" name="redirect_tab" value="dashboard">
            <input type="hidden" name="gas_type" id="gasDashEntryGasType" value="">
            <input type="hidden" name="entry_type" id="gasDashEntryType" value="">
            <input type="hidden" name="sub_type" id="gasDashEntrySubType" value="">
            <div class="gf-body">
              <div class="gf-block" id="gasDashEntryMachineBlock">
                <span class="gf-label">รายละเอียด (เครื่อง)</span>
                <div class="gf-choices gf-choices--panel" id="gasDashEntrySubChoices" role="group" aria-label="เครื่อง"></div>
              </div>
              <div class="gf-block" id="gasDashEntryAddingBlock" hidden>
                <span class="gf-label">ประเภทแก๊ส (รับถัง)</span>
                <p class="gas-dash-entry-gas-label" id="gasDashEntryAddingLabel"></p>
              </div>
              <div class="gf-row gf-row--qty-date">
                <div class="gf-block gf-block--qty">
                  <label class="gf-label" for="gas-dash-entry-qty">จำนวน (ถัง)</label>
                  <input type="number" id="gas-dash-entry-qty" name="quantity" class="gf-input" min="1" value="1" required>
                </div>
                <div class="gf-block gf-block--date">
                  <label class="gf-label" for="gas-dash-entry-date">วันที่</label>
                  <input type="date" id="gas-dash-entry-date" name="record_date" class="gf-input" value="<?= h($defaultRecordDate) ?>" required>
                </div>
              </div>
            </div>
    </form>
        </div>
        <div class="gas-edit-modal__footer gf-footer">
          <button type="button" class="gf-cancel" id="gasDashEntryCancelBtn" form="gasDashEntryForm">ยกเลิก</button>
          <button type="submit" class="gf-submit" id="gasDashEntrySubmitBtn" form="gasDashEntryForm">บันทึก</button>
        </div>
      </div>
    </div>

    <script>
    (() => {
      const formChoices = <?= gasFormChoicesJson() ?>;
      const modal = document.getElementById('gasDashEntryModal');
      const form = document.getElementById('gasDashEntryForm');
      const titleEl = document.getElementById('gasDashEntryTitle');
      const subtitleEl = document.getElementById('gasDashEntrySubtitle');
      const gasTypeInput = document.getElementById('gasDashEntryGasType');
      const entryTypeInput = document.getElementById('gasDashEntryType');
      const subTypeInput = document.getElementById('gasDashEntrySubType');
      const machineBlock = document.getElementById('gasDashEntryMachineBlock');
      const addingBlock = document.getElementById('gasDashEntryAddingBlock');
      const addingLabel = document.getElementById('gasDashEntryAddingLabel');
      const subChoices = document.getElementById('gasDashEntrySubChoices');
      const qtyInput = document.getElementById('gas-dash-entry-qty');
      const dateInput = document.getElementById('gas-dash-entry-date');
      const cancelBtn = document.getElementById('gasDashEntryCancelBtn');
      const defaultDate = <?= json_encode($defaultRecordDate, JSON_UNESCAPED_UNICODE) ?>;
      let lastFocus = null;
      let activeGasLabel = '';

      if (!modal || !form) return;

      const bindChoiceGroup = (container, hiddenInput) => {
        if (!container) return;
        container.addEventListener('click', (e) => {
          const btn = e.target.closest('.gf-choice');
          if (!btn || !container.contains(btn)) return;
          container.querySelectorAll('.gf-choice').forEach((el) => el.classList.remove('is-selected'));
          btn.classList.add('is-selected');
          if (hiddenInput) hiddenInput.value = btn.dataset.value || '';
        });
      };

      bindChoiceGroup(subChoices, subTypeInput);

      const rebuildUsageChoices = (gasKey) => {
        if (!subChoices) return;
        subChoices.innerHTML = '';
        const subs = formChoices.usage[gasKey] || [];
        subs.forEach((sub, index) => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'gf-choice' + (index === 0 ? ' is-selected' : '');
          btn.dataset.value = sub;
          btn.textContent = sub;
          subChoices.appendChild(btn);
        });
        if (subTypeInput) subTypeInput.value = subs[0] || '';
      };

      const closeModal = () => {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        modal.classList.remove('is-usage', 'is-adding');
        document.body.classList.remove('gas-summary-modal-open');
        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
      };

      const openModal = (btn) => {
        const gasKey = btn.dataset.gasType || '';
        const gasLabel = btn.dataset.gasLabel || gasKey;
        const entryType = btn.dataset.entryType || 'usage';
        const isAdding = entryType === 'adding';

        lastFocus = btn;
        activeGasLabel = gasLabel;
        if (gasTypeInput) gasTypeInput.value = gasKey;
        if (entryTypeInput) entryTypeInput.value = entryType;
        if (qtyInput) qtyInput.value = '1';
        if (dateInput) dateInput.value = defaultDate;

        if (machineBlock) machineBlock.hidden = isAdding;
        if (addingBlock) addingBlock.hidden = !isAdding;

        if (isAdding) {
          const item = (formChoices.adding || []).find((row) => row.gas_type === gasKey);
          if (subTypeInput) subTypeInput.value = item?.sub_type || '';
          if (addingLabel) addingLabel.textContent = item?.label || gasLabel;
          if (titleEl) titleEl.textContent = 'Adding — ' + gasLabel;
          if (subtitleEl) subtitleEl.textContent = 'บันทึกจำนวนถังที่รับเข้า';
        } else {
          rebuildUsageChoices(gasKey);
          if (titleEl) titleEl.textContent = 'Usage — ' + gasLabel;
          if (subtitleEl) subtitleEl.textContent = 'เลือกเครื่อง วันที่ และจำนวนถัง';
        }

        modal.classList.remove('is-usage', 'is-adding');
        modal.classList.add(isAdding ? 'is-adding' : 'is-usage');

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gas-summary-modal-open');
        (isAdding ? qtyInput : subChoices)?.focus();
      };

      document.addEventListener('click', (event) => {
        const btn = event.target.closest('.gas-dash-entry-btn');
        if (!btn) return;
        openModal(btn);
      });

      modal.querySelectorAll('[data-gas-dash-entry-close]').forEach((el) => {
        el.addEventListener('click', closeModal);
      });
      cancelBtn?.addEventListener('click', closeModal);
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) closeModal();
      });

      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!form.reportValidity()) return;

        const entryType = entryTypeInput?.value === 'adding' ? 'Adding' : 'Usage';
        const subType = subTypeInput?.value || '';
        const date = dateInput?.value || '';
        const qty = qtyInput?.value || '1';
        const message = [
          'ยืนยันบันทึกรายการนี้หรือไม่?',
          '',
          `แก๊ส: ${activeGasLabel}`,
          entryType === 'Usage' ? `เครื่อง: ${subType}` : `ประเภท: ${activeGasLabel}`,
          `ประเภทรายการ: ${entryType}`,
          `วันที่: ${date}`,
          `จำนวน: ${qty} ถัง`,
        ].join('\n');

        if (!confirm(message)) return;

        const formData = new FormData(form);
        try {
          const response = await fetch('gas_consumption.php?tab=dashboard', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
          });
          const data = await response.json();
          if (!response.ok || !data.ok) {
            alert(data.message || 'ไม่สามารถบันทึกได้ กรุณาตรวจสอบข้อมูล');
            return;
          }
          closeModal();
          await window.gasTankBoard?.refresh(data.counts, data.units, {
            reloadBoard: !!data.reload_board,
          });
        } catch (error) {
          alert('ไม่สามารถบันทึกได้ กรุณาลองอีกครั้ง');
        }
      });
    })();
    </script>

    <div id="gasDashSummaryModal" class="gas-summary-modal" hidden aria-hidden="true">
      <div class="gas-summary-modal__backdrop" data-gas-summary-close></div>
      <div class="gas-summary-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="gasDashSummaryTitle">
        <div class="gas-summary-modal__toolbar">
          <header class="gas-dash-summary-head">
            <h2 id="gasDashSummaryTitle">Gas Usage Summary</h2>
            <p class="muted">สรุปการใช้แก๊สประจำปี <?= $year ?></p>
          </header>
          <button type="button" class="gas-summary-modal__close" data-gas-summary-close aria-label="ปิด">×</button>
        </div>
        <div class="gas-summary-modal__body gas-dash-summary">
    <article class="card chart-card gas-chart-card">
      <div class="chart-head">
        <div>
          <h3>Monthly Usage by Gas Type</h3>
          <p class="muted">จำนวนถังที่ใช้ต่อเดือน — <?= $year ?></p>
        </div>
      </div>
      <div class="chart-wrap">
        <canvas id="gasMonthlyChart" height="120"></canvas>
      </div>
  </article>

    <article class="card gas-dash-year-card">
      <h3>Year Summary — <?= $year ?></h3>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
              <th>Gas Type</th>
              <?php foreach (array_slice($monthNames, 1) as $mn): ?>
                <th><?= h($mn) ?></th>
              <?php endforeach; ?>
              <th>Total</th>
              <th>Remain</th>
              <th>Avg</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (gasTypes() as $gasKey => $gasMeta):
              $months = $yearlySummary[$gasKey] ?? [];
              $addingMonths = $yearlyAddingSummary[$gasKey] ?? [];
              $total = array_sum($months);
              $filled = array_filter($months);
              $avg = $filled ? round($total / count($filled), 1) : 0;
              $remaining = (int) ($yearlyStock[$gasKey]['remaining'] ?? 0);
            ?>
            <tr>
              <td>
                <span class="gas-tag" style="--accent: <?= h(gasTypeColor($gasKey)) ?>"><?= h($gasMeta['label']) ?></span>
              </td>
              <?php for ($m = 1; $m <= 12; $m++):
                $usage = (int) ($months[$m] ?? 0);
                $adding = (int) ($addingMonths[$m] ?? 0);
              ?>
              <td class="gas-dash-month-cell">
                <div class="gas-dash-month-cell-inner">
                  <span class="gas-dash-usage-stack">
                    <span class="gas-dash-usage-value"><?= $usage > 0 ? number_format($usage) : '-' ?></span>
                    <?php if ($adding > 0): ?>
                    <sup class="gas-dash-adding-badge" title="เพิ่ม <?= number_format($adding) ?> ถัง">+<?= number_format($adding) ?></sup>
                    <?php endif; ?>
                  </span>
                </div>
              </td>
              <?php endfor; ?>
              <td><strong><?= $total ?></strong></td>
              <td class="gas-dash-remain<?= $remaining < 0 ? ' is-negative' : '' ?>"><strong><?= number_format($remaining) ?></strong></td>
              <td><?= $avg ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </article>
        </div>
      </div>
    </div>

    <script>
    (() => {
      const toggle = document.getElementById('gasDashSummaryToggle');
      const modal = document.getElementById('gasDashSummaryModal');
      if (!toggle || !modal) return;

      const initChart = () => {
        const ctx = document.getElementById('gasMonthlyChart');
        if (!ctx || ctx.dataset.chartReady === '1') return;

        const render = () => {
          new Chart(ctx, {
            type: 'bar',
            data: {
              labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
              datasets: <?= json_encode($chartDatasets, JSON_UNESCAPED_UNICODE) ?>
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              scales: {
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(30,99,188,0.1)' } },
                x: { grid: { display: false } }
              },
              plugins: { legend: { position: 'bottom' } }
            }
          });
          ctx.dataset.chartReady = '1';
        };

        if (window.Chart) {
          render();
          return;
        }

        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js';
        script.onload = render;
        document.head.appendChild(script);
      };

      const openModal = () => {
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        toggle.setAttribute('aria-expanded', 'true');
        document.body.classList.add('gas-summary-modal-open');
        initChart();
        modal.querySelector('.gas-summary-modal__close')?.focus();
      };

      const closeModal = () => {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        toggle.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('gas-summary-modal-open');
        toggle.focus();
      };

      toggle.addEventListener('click', openModal);
      modal.querySelectorAll('[data-gas-summary-close]').forEach((el) => {
        el.addEventListener('click', closeModal);
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
          closeModal();
        }
      });
    })();
    </script>

  <?php elseif ($tab === 'form'): ?>
    <div class="gas-form-page">
      <div class="gas-form-toolbar card">
        <div class="gas-form-toolbar-inner">
          <div>
            <h3>Data Management</h3>
            <p class="muted">ดูและแก้ไขรายการย้อนหลัง — กด ✎ เพื่อแก้รายการและสถานะถัง (กรณีกดผิดที่ Dashboard)</p>
          </div>
        </div>
      </div>

      <div class="gas-form-summary">
        <article class="card gas-summary-card">
          <h3>สรุป <?= h(gasMonthLabel($currentMonth)) ?></h3>
          <div class="gas-summary-grid gas-summary-grid--wide">
            <?php foreach (gasTypes() as $gasKey => $gasMeta):
              $used = (int) ($formMonthSummary[$gasKey]['usage'] ?? 0);
              $added = (int) ($formMonthSummary[$gasKey]['adding'] ?? 0);
              $remaining = (int) ($formMonthSummary[$gasKey]['remaining'] ?? 0);
            ?>
            <div class="gas-summary-item" style="<?= h(gasTypeCardStyleAttr($gasKey)) ?>">
              <span class="gas-summary-label"><?= h($gasMeta['label']) ?></span>
              <div class="gas-summary-values">
                <span>ใช้ <strong><?= number_format($used) ?></strong></span>
                <span>รับ <strong><?= number_format($added) ?></strong></span>
                <span class="gas-summary-remain">คงเหลือ <strong><?= number_format($remaining) ?></strong></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </article>

        <article class="card gas-recent-card" id="gasRecentCard">
          <div class="gas-recent-toolbar">
            <div class="gas-recent-head">
              <div class="gas-recent-head-row">
                <h3><?= $formListMonth === $currentMonth ? 'รายการในเดือนนี้' : 'รายการ — ' . h(gasMonthLabel($formListMonth)) ?></h3>
                <?php if ($formRecentEntries): ?>
                <button type="button" class="gas-bulk-mode-btn" id="gasBulkModeBtn">เลือกหลายรายการ</button>
                <?php endif; ?>
              </div>
              <span class="muted"><?= number_format($formListTotal) ?> รายการ<?php if ($formListTotal > $formListPerPage): ?> · แสดง <?= $formListOffset + 1 ?>–<?= min($formListOffset + count($formRecentEntries), $formListTotal) ?><?php endif; ?></span>
            </div>
            <form method="get" action="<?= h(gasFormTabUrl()) ?>" class="gas-form-list-filter gas-summary-filter" id="gasFormListFilter">
              <input type="hidden" name="tab" value="form">
              <div class="gas-form-list-filter-inner">
                <p class="gas-form-list-filter-title">ดูรายการย้อนหลัง</p>
                <div class="gas-form-list-filter-row">
                  <?php renderGasFormListMonthFilter($formListMonth); ?>
                  <?php renderGasFormListTypeFilter($formListType); ?>
                  <?php if ($formListMonth !== $currentMonth || $formListType !== ''): ?>
                  <a class="gas-form-list-reset" href="<?= h(gasFormTabUrl()) ?>">เดือนนี้</a>
                  <?php endif; ?>
                </div>
              </div>
            </form>
          </div>
          <?php if ($formRecentEntries): ?>
          <div class="gas-bulk-bar" id="gasBulkToolbar" hidden>
            <span class="gas-bulk-count" id="gasBulkCount">แตะแถวเพื่อเลือก หรือกด 「ทั้งหมด」</span>
            <div class="gas-bulk-bar__actions">
              <button type="button" class="gas-bulk-cancel-btn" id="gasBulkCancelBtn">เสร็จสิ้น</button>
              <button type="submit" class="gas-bulk-delete-btn" id="gasBulkDeleteBtn" form="gasFormListBulkForm" disabled>ลบที่เลือก</button>
            </div>
          </div>
          <?php endif; ?>
          <form method="post" id="gasFormListBulkForm" class="gas-form-list-bulk-form">
            <input type="hidden" name="action" value="delete_entries_form">
            <input type="hidden" name="list_month" value="<?= h($formListMonth) ?>">
            <input type="hidden" name="list_type" value="<?= h($formListType) ?>">
            <input type="hidden" name="list_page" value="<?= (int) $formListPage ?>">
          </form>
          <form method="post" id="gasDeleteEntryForm" class="gas-delete-form-hidden" hidden>
            <input type="hidden" name="action" value="delete_entry_form">
            <input type="hidden" name="entry_id" id="gasDeleteEntryId" value="">
            <input type="hidden" name="list_month" value="<?= h($formListMonth) ?>">
            <input type="hidden" name="list_type" value="<?= h($formListType) ?>">
            <input type="hidden" name="list_page" value="<?= (int) $formListPage ?>">
          </form>
          <div class="table-wrap">
            <table class="gas-recent-table" id="gasRecentTable">
              <thead>
                <tr>
                  <th class="gas-col-check" scope="col">
                    <label class="gas-th-check" id="gasSelectAllLabel">
                      <input type="checkbox" id="gasSelectAll" disabled aria-label="เลือกทั้งหน้า">
                      <span class="gas-th-check-text">ทั้งหมด</span>
                    </label>
                  </th>
            <th>Date</th>
            <th>Gas</th>
                  <th>Desc</th>
                  <th>Type</th>
                  <th>Qty</th>
                  <th></th>
          </tr>
        </thead>
        <tbody>
                <?php foreach ($formRecentEntries as $entry): ?>
                <tr class="gas-entry-row" data-entry-id="<?= (int) $entry['id'] ?>">
                  <td class="gas-col-check">
                    <input
                      type="checkbox"
                      class="gas-entry-check"
                      form="gasFormListBulkForm"
                      name="entry_ids[]"
                      value="<?= (int) $entry['id'] ?>"
                      aria-label="เลือกรายการ <?= h((string) $entry['record_date']) ?>"
                      disabled
                    >
                  </td>
                  <td><?= h((string) $entry['record_date']) ?></td>
                  <td>
                    <span class="gas-tag" style="--accent: <?= h(gasTypeColor((string) $entry['gas_type'])) ?>">
                      <?= h(gasEntryListGasLabel($entry)) ?>
                    </span>
                  </td>
                  <td><?= renderGasEntryDescHtml($entry) ?></td>
                  <td>
                    <?php
                      $entryTypeKey = (string) ($entry['entry_type'] ?? 'usage');
                      if ($entryTypeKey === 'adding'):
                    ?>
                      <span class="gas-entry-type gas-entry-type--adding">Adding</span>
                    <?php elseif ($entryTypeKey === 'status_update'): ?>
                      <span class="gas-entry-type gas-entry-type--status-update">Update Status</span>
                    <?php else: ?>
                      <span class="gas-entry-type gas-entry-type--usage">Usage</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($entryTypeKey === 'status_update' && (string) ($entry['status_after'] ?? '') === 'empty'): ?>
                    <strong class="gas-entry-qty-deduct" title="หัก Remain อัตโนมัติเมื่อถังหมดแล้ว"><?= gasEntryListQtyDisplay($entry) ?></strong>
                    <?php else: ?>
                    <strong><?= gasEntryListQtyDisplay($entry) ?></strong>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="gas-row-actions">
                      <?php
                        $entryTanks = $entryTypeKey === 'status_update' ? gasTankUnitsForEntry($entry) : [];
                        $entryPayloadData = [
                            'id' => (int) $entry['id'],
                            'gas_type' => (string) $entry['gas_type'],
                            'sub_type' => (string) ($entry['sub_type'] ?? ''),
                            'entry_type' => $entryTypeKey,
                            'record_date' => (string) $entry['record_date'],
                            'quantity' => (int) $entry['quantity'],
                            'note' => (string) ($entry['note'] ?? ''),
                        ];
                        if ($entryTypeKey === 'status_update') {
                            $entryPayloadData['tank_unit_id'] = (int) ($entry['tank_unit_id'] ?? 0);
                            $entryPayloadData['status_before'] = (string) ($entry['status_before'] ?? '');
                            $entryPayloadData['status_after'] = (string) ($entry['status_after'] ?? '');
                            $entryPayloadData['tank_ids'] = array_map(
                                static fn (array $unit): int => (int) ($unit['id'] ?? 0),
                                $entryTanks
                            );
                            $entryPayloadData['tank_statuses'] = array_reduce(
                                $entryTanks,
                                static function (array $carry, array $unit) use ($entry): array {
                                    $id = (int) ($unit['id'] ?? 0);
                                    if ($id > 0) {
                                        $carry[$id] = (string) ($entry['status_after'] ?? ($unit['status'] ?? 'empty'));
                                    }

                                    return $carry;
                                },
                                []
                            );
                            $tankMachineCode = '';
                            if ($entryTanks !== []) {
                                $tankUnit = $entryTanks[0];
                                $tankMachineCode = trim((string) ($tankUnit['machine_code'] ?? ''));
                                if ($tankMachineCode === '') {
                                    $tankMachineCode = gasTankLastUsageMachineForUnit((int) ($tankUnit['id'] ?? 0));
                                }
                            }
                            $entryPayloadData['tank_machine_code'] = $tankMachineCode;
                        }
                        $entryPayload = htmlspecialchars(json_encode(
                            $entryPayloadData,
                            JSON_UNESCAPED_UNICODE
                        ), ENT_QUOTES, 'UTF-8');
                      ?>
                      <button
                        type="button"
                        class="gas-edit-btn"
                        title="แก้ไข"
                        data-entry="<?= $entryPayload ?>"
                      >✎</button>
                      <button
                        type="button"
                        class="gas-delete-btn"
                        title="ลบ"
                        data-entry-id="<?= (int) $entry['id'] ?>"
                      >×</button>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$formRecentEntries): ?>
                <tr><td colspan="7" class="muted gas-empty-row">ยังไม่มีรายการใน<?= $formListMonth === $currentMonth ? 'เดือนนี้' : ' ' . h(gasMonthLabel($formListMonth)) ?></td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?php if ($formListTotal > $formListPerPage): ?>
          <nav class="gas-form-list-pager" aria-label="แบ่งหน้ารายการ">
            <?php if ($formListPage > 1): ?>
            <a class="gas-form-list-page-btn" href="<?= h(gasFormTabUrl(array_merge($formListUrlParams, ['list_page' => $formListPage - 1]))) ?>">← ก่อนหน้า</a>
            <?php endif; ?>
            <span class="gas-form-list-page-meta">หน้า <?= (int) $formListPage ?> / <?= (int) $formListPages ?></span>
            <?php if ($formListPage < $formListPages): ?>
            <a class="gas-form-list-page-btn gas-form-list-page-btn--next" href="<?= h(gasFormTabUrl(array_merge($formListUrlParams, ['list_page' => $formListPage + 1]))) ?>">Next page →</a>
            <?php endif; ?>
          </nav>
          <?php endif; ?>
        </article>
      </div>
    </div>

    <div id="gasEntryModal" class="gas-summary-modal gas-edit-modal" hidden aria-hidden="true">
      <div class="gas-summary-modal__backdrop" data-gas-entry-close></div>
      <div class="gas-summary-modal__dialog gas-edit-modal__dialog" id="gasEntryModalDialog" role="dialog" aria-modal="true" aria-labelledby="gasEntryModalTitle">
        <div class="gas-summary-modal__toolbar">
          <div>
            <h3 id="gasEntryModalTitle">Gas Inventory Record</h3>
            <p class="muted" id="gasEntryModalSubtitle">เลือกประเภทรายการ รายละเอียด วันที่ และจำนวนถัง</p>
          </div>
          <button type="button" class="gas-summary-modal__close" data-gas-entry-close aria-label="ปิด">×</button>
        </div>
        <div class="gas-summary-modal__body">
          <form method="post" class="gf-form gf-form--modal" id="gasEntryModalForm">
            <input type="hidden" name="action" id="gasEntryActionInput" value="save_entry">
            <input type="hidden" name="entry_id" id="gasEntryIdInput" value="">
            <input type="hidden" name="list_month" value="<?= h($formListMonth) ?>">
            <input type="hidden" name="list_type" value="<?= h($formListType) ?>">
            <input type="hidden" name="list_page" value="<?= (int) $formListPage ?>">
            <input type="hidden" name="entry_type" id="gasEntryTypeInput" value="usage">
            <input type="hidden" name="sub_type" id="gasEntrySubTypeInput" value="">

            <div class="gf-body">
              <div class="gf-block gf-block--row">
                <span class="gf-label">ประเภทรายการ</span>
                <div class="gf-choices" id="gasEntryTypeChoices" role="group" aria-label="ประเภทรายการ">
                  <button type="button" class="gf-choice gf-choice--usage is-selected" data-value="usage">Usage</button>
                  <button type="button" class="gf-choice gf-choice--adding" data-value="adding">Adding</button>
                </div>
              </div>

              <div class="gf-block" id="gasEntryGasTypeBlock">
                <label class="gf-label" for="gasEntryGasTypeSelect">ประเภทแก๊ส</label>
                <select id="gasEntryGasTypeSelect" name="gas_type" class="gf-select" required>
                  <?php foreach (gasTypes() as $gasKey => $gasMeta): ?>
                    <option value="<?= h($gasKey) ?>" <?= $selectedGas === $gasKey ? 'selected' : '' ?>><?= h($gasMeta['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="gf-block" id="gasEntryDetailBlock">
                <span class="gf-label" id="gasEntryDetailLabel">รายละเอียด (เครื่อง)</span>
                <div class="gf-choices gf-choices--panel" id="gasEntrySubTypeChoices" role="group" aria-label="รายละเอียด"></div>
              </div>

              <div class="gf-stack gf-stack--fields">
                <div class="gf-row gf-row--qty-date">
                  <div class="gf-block gf-block--qty">
                    <label class="gf-label" for="gas-entry-qty">จำนวน (ถัง)</label>
                    <input type="number" id="gas-entry-qty" name="quantity" class="gf-input" min="1" value="1" required>
                  </div>
                  <div class="gf-block gf-block--date">
                    <label class="gf-label" for="gas-entry-date">วันที่</label>
                    <input type="date" id="gas-entry-date" name="record_date" class="gf-input" value="<?= h($defaultRecordDate) ?>" required>
                  </div>
                </div>

                <div class="gf-block gf-block--note">
                  <label class="gf-label" for="gas-entry-note">หมายเหตุ <span class="gf-optional">(ไม่บังคับ)</span></label>
                  <textarea id="gas-entry-note" name="note" class="gf-input gf-textarea" placeholder="เพิ่มรายละเอียด..."></textarea>
                </div>
              </div>
            </div>

            <div id="gasEntryTankPanel" class="gas-entry-tank-panel" hidden>
              <div class="gas-entry-tank-panel-head">
                <h4>แก้ไขสถานะถัง</h4>
                <p class="muted" id="gasEntryTankPanelSubtitle">เลือกสถานะถังหลังแก้ไข</p>
              </div>
              <div class="gas-entry-tank-groups">
                <?php foreach (gasTypes() as $gasKey => $gasMeta):
                  $typeUnits = $manageTankUnitsByType[$gasKey] ?? [];
                  if (!$typeUnits) {
                      continue;
                  }
                ?>
                <section
                  class="gas-entry-tank-group"
                  style="--accent: <?= h(gasTypeColor($gasKey)) ?>"
                  data-gas-type="<?= h($gasKey) ?>"
                >
                  <header class="gas-entry-tank-group-head">
                    <span class="gas-entry-tank-group-badge"><?= h(gasTankShortLabel($gasKey)) ?></span>
                    <h5><?= h($gasMeta['label']) ?></h5>
                    <span class="muted gas-entry-tank-group-count"><?= count($typeUnits) ?> ถัง</span>
                  </header>
                  <div class="gas-entry-tank-list">
                    <?php foreach ($typeUnits as $unit):
                      $unitStatus = (string) ($unit['status'] ?? 'empty');
                      $tankNo = (int) ($unit['tank_no'] ?? 0);
                      $tankId = (int) ($unit['id'] ?? 0);
                    ?>
                    <div
                      class="gas-entry-tank-row"
                      data-tank-id="<?= $tankId ?>"
                      data-tank-no="<?= $tankNo ?>"
                      data-gas-type="<?= h($gasKey) ?>"
                    >
                      <div class="gas-entry-tank-row-meta">
                        <strong>ID <?= $tankId ?></strong>
                        <span>ถังที่ <?= $tankNo ?></span>
                      </div>
                      <div class="gas-entry-tank-row-controls">
                        <select
                          class="gf-select gas-entry-tank-status-select"
                          name="tank_units[<?= $tankId ?>]"
                          data-tank-id="<?= $tankId ?>"
                          data-initial-status="<?= h($unitStatus) ?>"
                          aria-label="สถานะถัง ID <?= $tankId ?>"
                        >
                          <?php foreach (gasTankStatuses() as $statusKey => $statusLabel): ?>
                          <option value="<?= h($statusKey) ?>" <?= $unitStatus === $statusKey ? 'selected' : '' ?>><?= h($statusLabel) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <?php $usageMachines = gasUsageSubTypes($gasKey); ?>
                        <?php if (count($usageMachines) > 1): ?>
                        <div class="gas-entry-tank-machine" hidden>
                          <label class="gf-label" for="tank-machine-<?= $tankId ?>">เครื่อง</label>
                          <select
                            id="tank-machine-<?= $tankId ?>"
                            class="gf-select gas-entry-tank-machine-select"
                            data-tank-id="<?= $tankId ?>"
                            aria-label="เครื่องถัง ID <?= $tankId ?>"
                          >
                            <option value="">เลือกเครื่อง</option>
                            <?php foreach ($usageMachines as $machine): ?>
                            <option value="<?= h($machine) ?>"><?= h($machine) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <?php elseif (count($usageMachines) === 1): ?>
                        <input
                          type="hidden"
                          class="gas-entry-tank-machine-hidden"
                          data-tank-id="<?= $tankId ?>"
                          value="<?= h($usageMachines[0]) ?>"
                        >
                        <?php endif; ?>
                      </div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                </section>
                <?php endforeach; ?>
              </div>
              <p class="gas-entry-tank-empty muted" id="gasEntryTankEmpty" hidden>ไม่มีข้อมูลถังสำหรับรายการนี้</p>
            </div>
          </form>
        </div>
        <div class="gas-edit-modal__footer gf-footer">
          <button type="button" class="gf-cancel" id="gasEntryCancelBtn" form="gasEntryModalForm">ยกเลิก</button>
          <button type="submit" class="gf-submit" id="gasEntrySubmitBtn" form="gasEntryModalForm">บันทึกรายการ</button>
        </div>
      </div>
    </div>

    <script>
    (() => {
      const table = document.getElementById('gasRecentTable');
      const modeBtn = document.getElementById('gasBulkModeBtn');
      const toolbar = document.getElementById('gasBulkToolbar');
      const cancelBtn = document.getElementById('gasBulkCancelBtn');
      const selectAll = document.getElementById('gasSelectAll');
      const deleteBtn = document.getElementById('gasBulkDeleteBtn');
      const countEl = document.getElementById('gasBulkCount');
      const bulkForm = document.getElementById('gasFormListBulkForm');
      if (!table || !modeBtn) return;

      const checks = () => Array.from(table.querySelectorAll('.gas-entry-check'));

      const updateCount = () => {
        const enabled = checks().filter((c) => !c.disabled);
        const selected = enabled.filter((c) => c.checked);
        const n = selected.length;
        if (countEl) {
          if (n === 0) {
            countEl.textContent = 'แตะแถวเพื่อเลือก หรือกด 「ทั้งหมด」';
          } else {
            countEl.textContent = 'เลือกแล้ว ' + n + ' รายการ';
          }
        }
        if (deleteBtn) deleteBtn.disabled = n === 0;
        if (selectAll) {
          selectAll.checked = enabled.length > 0 && enabled.every((c) => c.checked);
          selectAll.indeterminate = n > 0 && n < enabled.length;
        }
        checks().forEach((c) => {
          c.closest('tr')?.classList.toggle('is-selected', c.checked);
        });
      };

      const setBulkMode = (on) => {
        table.classList.toggle('is-bulk-mode', on);
        document.getElementById('gasRecentCard')?.classList.toggle('is-bulk-mode', on);
        modeBtn.hidden = on;
        toolbar.hidden = !on;
        checks().forEach((c) => {
          c.disabled = !on;
          if (!on) c.checked = false;
        });
        if (selectAll) {
          selectAll.disabled = !on;
          selectAll.checked = false;
          selectAll.indeterminate = false;
        }
        updateCount();
      };

      modeBtn.addEventListener('click', () => setBulkMode(true));
      cancelBtn?.addEventListener('click', () => setBulkMode(false));
      document.getElementById('gasSelectAllLabel')?.addEventListener('click', (e) => {
        if (!table.classList.contains('is-bulk-mode')) return;
        if (e.target === selectAll) return;
        if (!selectAll || selectAll.disabled) return;
        e.preventDefault();
        const shouldSelect = !selectAll.checked && !selectAll.indeterminate;
        selectAll.checked = shouldSelect;
        selectAll.indeterminate = false;
        checks().forEach((c) => {
          if (!c.disabled) c.checked = shouldSelect;
        });
        updateCount();
      });
      selectAll?.addEventListener('change', () => {
        checks().forEach((c) => {
          if (!c.disabled) c.checked = !!selectAll.checked;
        });
        updateCount();
      });
      checks().forEach((c) => c.addEventListener('change', updateCount));

      table.querySelectorAll('tbody .gas-entry-row').forEach((row) => {
        row.addEventListener('click', (e) => {
          if (!table.classList.contains('is-bulk-mode')) return;
          if (e.target.closest('a, button, input, label, form')) return;
          const cb = row.querySelector('.gas-entry-check');
          if (cb && !cb.disabled) {
            cb.checked = !cb.checked;
            updateCount();
          }
        });
      });

      bulkForm?.addEventListener('submit', (e) => {
        const n = checks().filter((c) => c.checked).length;
        if (!n) {
          e.preventDefault();
          return;
        }
        if (!confirm('ลบ ' + n + ' รายการที่เลือก?')) {
          e.preventDefault();
        }
      });

      const deleteForm = document.getElementById('gasDeleteEntryForm');
      const deleteEntryId = document.getElementById('gasDeleteEntryId');
      table.querySelectorAll('.gas-delete-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
          if (!confirm('ลบรายการนี้?')) return;
          if (deleteEntryId && deleteForm) {
            deleteEntryId.value = btn.dataset.entryId || '';
            deleteForm.submit();
          }
        });
      });
    })();
    </script>

    <script>
    (() => {
      const formChoices = <?= gasFormChoicesJson() ?>;

      function bindChoiceGroup(container, hiddenInput, onSelect) {
        if (!container) return;
        container.addEventListener('click', (e) => {
          const btn = e.target.closest('.gf-choice');
          if (!btn || !container.contains(btn)) return;
          container.querySelectorAll('.gf-choice').forEach((el) => el.classList.remove('is-selected'));
          btn.classList.add('is-selected');
          if (hiddenInput) hiddenInput.value = btn.dataset.value || '';
          if (onSelect) onSelect(btn.dataset.value || '', btn);
        });
      }

      function createGasFormController(cfg) {
        const {
          gasTypeSelect,
          gasTypeBlock,
          gasDetailLabel,
          subTypeInput,
          entryTypeInput,
          subTypeChoices,
          entryTypeChoices,
          qtyInput,
          dateInput,
          noteInput,
        } = cfg;

        function isAddingMode() {
          return entryTypeInput?.value === 'adding';
        }

        function syncGasTypeRequired() {
          if (!gasTypeSelect) return;
          if (isAddingMode()) {
            gasTypeSelect.removeAttribute('required');
          } else {
            gasTypeSelect.setAttribute('required', 'required');
          }
        }

        function rebuildDetailChoices() {
          if (!subTypeChoices) return;
          const adding = isAddingMode();
          if (gasTypeBlock) {
            gasTypeBlock.hidden = adding;
            gasTypeBlock.classList.toggle('gf-block--hidden', adding);
          }
          if (gasDetailLabel) {
            gasDetailLabel.textContent = adding ? 'ประเภทแก๊ส (รับถัง)' : 'รายละเอียด (เครื่อง)';
          }
          syncGasTypeRequired();

          const currentGas = gasTypeSelect?.value || '';
          const currentSub = subTypeInput?.value || '';
          subTypeChoices.innerHTML = '';

          if (adding) {
            let selectedGas = currentGas;
            formChoices.adding.forEach((item) => {
              const btn = document.createElement('button');
              btn.type = 'button';
              btn.className = 'gf-choice';
              btn.dataset.value = item.sub_type;
              btn.dataset.gasType = item.gas_type;
              btn.textContent = item.label;
              if (
                item.gas_type === selectedGas
                || (item.sub_type === currentSub && !selectedGas)
                || (item.gas_type === currentGas && item.sub_type === currentSub)
              ) {
                btn.classList.add('is-selected');
                selectedGas = item.gas_type;
              }
              subTypeChoices.appendChild(btn);
            });
            const selectedBtn = subTypeChoices.querySelector('.gf-choice.is-selected')
              || subTypeChoices.querySelector('.gf-choice');
            if (selectedBtn && gasTypeSelect) {
              gasTypeSelect.value = selectedBtn.dataset.gasType || selectedGas;
              if (subTypeInput) subTypeInput.value = selectedBtn.dataset.value || '';
              selectedBtn.classList.add('is-selected');
            }
            return;
          }

          const subs = formChoices.usage[gasTypeSelect?.value || ''] || [];
          let selected = subs.includes(currentSub) ? currentSub : (subs[0] || '');
          subs.forEach((sub) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'gf-choice' + (sub === selected ? ' is-selected' : '');
            btn.dataset.value = sub;
            btn.textContent = sub;
            subTypeChoices.appendChild(btn);
          });
          if (subTypeInput) subTypeInput.value = selected;
        }

        function populateEntry(entry) {
          if (entryTypeInput) entryTypeInput.value = entry.entry_type || 'usage';
          entryTypeChoices?.querySelectorAll('.gf-choice').forEach((el) => {
            el.classList.toggle('is-selected', el.dataset.value === entryTypeInput.value);
          });
          if (gasTypeSelect && entry.gas_type) {
            gasTypeSelect.value = entry.gas_type;
          }
          if (subTypeInput) subTypeInput.value = entry.sub_type || '';
          if (qtyInput) qtyInput.value = String(entry.quantity || 1);
          if (dateInput) dateInput.value = entry.record_date || '';
          if (noteInput) noteInput.value = entry.note || '';
          rebuildDetailChoices();
        }

        bindChoiceGroup(subTypeChoices, subTypeInput, (value, btn) => {
          if (isAddingMode() && btn?.dataset?.gasType && gasTypeSelect) {
            gasTypeSelect.value = btn.dataset.gasType;
          }
        });
        bindChoiceGroup(entryTypeChoices, entryTypeInput, () => rebuildDetailChoices());
        gasTypeSelect?.addEventListener('change', rebuildDetailChoices);
        rebuildDetailChoices();

        return { rebuildDetailChoices, populateEntry, isAddingMode };
      }

      const entryModal = document.getElementById('gasEntryModal');
      const entryDialog = document.getElementById('gasEntryModalDialog');
      const entryForm = document.getElementById('gasEntryModalForm');
      const entryTankPanel = document.getElementById('gasEntryTankPanel');
      const entryActionInput = document.getElementById('gasEntryActionInput');
      const entryIdInput = document.getElementById('gasEntryIdInput');
      const entryCancelBtn = document.getElementById('gasEntryCancelBtn');
      const entrySubmitBtn = document.getElementById('gasEntrySubmitBtn');
      const entryModalTitle = document.getElementById('gasEntryModalTitle');
      const entryModalSubtitle = document.getElementById('gasEntryModalSubtitle');
      const entryGasTypeSelect = document.getElementById('gasEntryGasTypeSelect');
      const entryGasTypeBlock = document.getElementById('gasEntryGasTypeBlock');
      const entryDetailLabel = document.getElementById('gasEntryDetailLabel');
      const entrySubTypeInput = document.getElementById('gasEntrySubTypeInput');
      const entryTypeInput = document.getElementById('gasEntryTypeInput');
      const entrySubTypeChoices = document.getElementById('gasEntrySubTypeChoices');
      const entryTypeChoices = document.getElementById('gasEntryTypeChoices');
      const newEntryDefaults = <?= json_encode([
          'entry_type' => 'usage',
          'gas_type' => $selectedGas,
          'sub_type' => '',
          'quantity' => 1,
          'record_date' => $defaultRecordDate,
          'note' => '',
      ], JSON_UNESCAPED_UNICODE) ?>;
      let entryLastFocus = null;
      let entryModalMode = 'new';
      const gasUsageMachines = (<?= gasFormChoicesJson() ?>).usage || {};

      const syncEntryTankMachineField = (row, preferredMachine = '') => {
        if (!row) return;
        const gasType = row.dataset.gasType || row.closest('.gas-entry-tank-group')?.dataset.gasType || '';
        const tankId = row.dataset.tankId || '';
        const statusSelect = row.querySelector('.gas-entry-tank-status-select');
        const machineBlock = row.querySelector('.gas-entry-tank-machine');
        const machineSelect = row.querySelector('.gas-entry-tank-machine-select');
        const machineHidden = row.querySelector('.gas-entry-tank-machine-hidden');
        const machines = gasUsageMachines[gasType] || [];
        const status = statusSelect?.value || '';
        const isVisible = !row.hidden && !row.classList.contains('is-hidden');
        const isInUse = status === 'in_use';
        const showPicker = isInUse && machines.length > 1;

        row.classList.toggle('has-machine-pick', showPicker && isVisible);

        if (machineBlock) {
          machineBlock.hidden = !showPicker;
        }
        if (machineSelect) {
          if (showPicker && isVisible) {
            machineSelect.setAttribute('name', `tank_machines[${tankId}]`);
            if (preferredMachine && machines.includes(preferredMachine)) {
              machineSelect.value = preferredMachine;
            }
          } else {
            machineSelect.removeAttribute('name');
          }
        }
        if (machineHidden) {
          if (isInUse && machines.length === 1 && isVisible) {
            machineHidden.setAttribute('name', `tank_machines[${tankId}]`);
            machineHidden.value = machines[0];
          } else {
            machineHidden.removeAttribute('name');
          }
        }
      };

      const entryFormController = createGasFormController({
        gasTypeSelect: entryGasTypeSelect,
        gasTypeBlock: entryGasTypeBlock,
        gasDetailLabel: entryDetailLabel,
        subTypeInput: entrySubTypeInput,
        entryTypeInput,
        subTypeChoices: entrySubTypeChoices,
        entryTypeChoices,
        qtyInput: document.getElementById('gas-entry-qty'),
        dateInput: document.getElementById('gas-entry-date'),
        noteInput: document.getElementById('gas-entry-note'),
      });

      function setEntryModalCopy(mode, entry = null) {
        entryModalMode = mode;
        const isStatusUpdate = entry?.entry_type === 'status_update';
        entryDialog?.classList.toggle('is-status-update', mode === 'edit' && isStatusUpdate);
        if (mode === 'edit' && isStatusUpdate) {
          if (entryActionInput) entryActionInput.value = 'update_entry';
          if (entryModalTitle) entryModalTitle.textContent = 'Edit Status Update';
          if (entryModalSubtitle) {
            entryModalSubtitle.textContent = `เปลี่ยนสถานะถัง ID ${entry.tank_unit_id || '—'} · ${entry.sub_type || ''}`;
          }
          if (entrySubmitBtn) entrySubmitBtn.textContent = 'บันทึกการแก้ไข';
        } else if (mode === 'edit') {
          if (entryActionInput) entryActionInput.value = 'update_entry';
          if (entryModalTitle) entryModalTitle.textContent = 'Edit Gas Inventory Record';
          if (entryModalSubtitle) entryModalSubtitle.textContent = 'ปรับข้อมูลแล้วกดบันทึกการแก้ไข';
          if (entrySubmitBtn) entrySubmitBtn.textContent = 'บันทึกการแก้ไข';
        } else {
          entryDialog?.classList.remove('is-status-update');
          if (entryActionInput) entryActionInput.value = 'save_entry';
          if (entryModalTitle) entryModalTitle.textContent = 'Gas Inventory Record';
          if (entryModalSubtitle) entryModalSubtitle.textContent = 'เลือกประเภทรายการ รายละเอียด วันที่ และจำนวนถัง';
          if (entrySubmitBtn) entrySubmitBtn.textContent = 'บันทึกรายการ';
        }
      }

      let currentEditEntry = null;

      function filterEntryTankPanel(entry) {
        const gasType = entry?.gas_type || '';
        const tankIds = new Set((entry?.tank_ids || []).map((n) => Number(n)));
        const emptyEl = document.getElementById('gasEntryTankEmpty');
        const subtitle = document.getElementById('gasEntryTankPanelSubtitle');
        let visibleCount = 0;

        entryTankPanel?.querySelectorAll('.gas-entry-tank-group').forEach((group) => {
          const groupMatch = !!gasType && group.dataset.gasType === gasType;
          let groupVisible = 0;

          group.querySelectorAll('.gas-entry-tank-row').forEach((row) => {
            const tankId = Number(row.dataset.tankId || 0);
            const show = groupMatch && tankIds.size > 0 && tankIds.has(tankId);
            row.hidden = !show;
            row.classList.toggle('is-hidden', !show);
            const select = row.querySelector('.gas-entry-tank-status-select');
            if (select) {
              if (show) {
                select.disabled = false;
                select.setAttribute('name', `tank_units[${tankId}]`);
              } else {
                select.disabled = true;
                select.removeAttribute('name');
              }
            }
            if (show) {
              groupVisible += 1;
              visibleCount += 1;
            }
          });

          group.hidden = !groupMatch || groupVisible === 0;
          group.classList.toggle('is-hidden', group.hidden);
          const countEl = group.querySelector('.gas-entry-tank-group-count');
          if (countEl && groupMatch) {
            countEl.textContent = `${groupVisible} ถัง`;
          }
        });

        if (emptyEl) {
          emptyEl.hidden = visibleCount > 0;
        }
        if (subtitle && entry?.entry_type === 'status_update') {
          if ((entry.status_after || '') === 'empty') {
            subtitle.textContent = `ถัง ID ${entry.tank_unit_id || '—'} — หมดแล้ว (หัก Remain แล้ว) · เปลี่ยนกลับเป็น เต็ม หรือ กำลังทำงาน เพื่อคืน Remain`;
          } else {
            subtitle.textContent = `ถัง ID ${entry.tank_unit_id || '—'} — ${entry.sub_type || 'เปลี่ยนสถานะ'} · เลือกสถานะที่ถูกต้อง`;
          }
        }
        if (entry?.tank_statuses) {
          Object.entries(entry.tank_statuses).forEach(([id, status]) => {
            const select = entryTankPanel?.querySelector(
              `.gas-entry-tank-row[data-tank-id="${id}"] .gas-entry-tank-status-select`
            );
            if (select && status) {
              select.value = status;
              select.dataset.initialStatus = status;
            }
          });
        }
        if (entry?.entry_type === 'status_update') {
          applyStatusUpdateEditOptions(entry);
        }
      }

      function applyStatusUpdateEditOptions(entry) {
        const tankId = String(entry?.tank_unit_id || '');
        if (!tankId) return;
        const labels = { in_use: 'กำลังทำงาน', full: 'เต็ม', empty: 'หมดแล้ว', damaged: 'ชำรุด' };
        const allowedByStatus = {
          empty: ['full', 'in_use', 'damaged'],
          full: ['in_use', 'damaged'],
          in_use: ['empty', 'damaged', 'full'],
          damaged: ['empty', 'full'],
        };
        const select = entryTankPanel?.querySelector(
          `.gas-entry-tank-row[data-tank-id="${tankId}"] .gas-entry-tank-status-select`
        );
        if (!select) return;
        const current = entry?.status_after || select.dataset.initialStatus || select.value || 'empty';
        const allowed = [...(allowedByStatus[current] || ['full', 'in_use', 'empty', 'damaged'])];
        if (current && !allowed.includes(current)) {
          allowed.unshift(current);
        }
        const selected = select.value || current;
        select.innerHTML = '';
        allowed.forEach((key) => {
          const opt = document.createElement('option');
          opt.value = key;
          opt.textContent = labels[key] || key;
          opt.selected = key === selected;
          select.appendChild(opt);
        });
        select.dataset.initialStatus = current;
        syncEntryTankMachineField(select.closest('.gas-entry-tank-row'), entry?.tank_machine_code || '');
      }

      function resetEntryTankPanelGroups() {
        entryTankPanel?.querySelectorAll('.gas-entry-tank-group').forEach((group) => {
          group.hidden = false;
          group.classList.remove('is-hidden');
          group.querySelectorAll('.gas-entry-tank-row').forEach((row) => {
            row.hidden = false;
            row.classList.remove('is-hidden');
            const select = row.querySelector('.gas-entry-tank-status-select');
            if (select) {
              select.disabled = false;
              const tankId = row.dataset.tankId || select.dataset.tankId || '';
              if (tankId) {
                select.setAttribute('name', `tank_units[${tankId}]`);
              }
              const machineSelect = row.querySelector('.gas-entry-tank-machine-select');
              if (machineSelect) machineSelect.removeAttribute('name');
              const machineHidden = row.querySelector('.gas-entry-tank-machine-hidden');
              if (machineHidden) machineHidden.removeAttribute('name');
              const machineBlock = row.querySelector('.gas-entry-tank-machine');
              if (machineBlock) machineBlock.hidden = true;
              row.classList.remove('has-machine-pick');
            }
          });
          const countEl = group.querySelector('.gas-entry-tank-group-count');
          const total = group.querySelectorAll('.gas-entry-tank-row').length;
          if (countEl) countEl.textContent = `${total} ถัง`;
        });
        currentEditEntry = null;
        const emptyEl = document.getElementById('gasEntryTankEmpty');
        if (emptyEl) emptyEl.hidden = true;
      }

      function setEntryTankPanel(mode, entry) {
        const showTanks = mode === 'edit' && entry?.entry_type === 'status_update';
        currentEditEntry = showTanks ? entry : null;
        if (entryTankPanel) {
          if (showTanks) {
            entryTankPanel.removeAttribute('hidden');
            filterEntryTankPanel(entry);
          } else {
            entryTankPanel.setAttribute('hidden', '');
            resetEntryTankPanelGroups();
            entryDialog?.classList.remove('is-with-tanks');
          }
        }
      }

      function openEntryModal(mode, entry) {
        if (!entryModal || !entryForm) return;
        entryLastFocus = document.activeElement;
        setEntryModalCopy(mode, entry);
        setEntryTankPanel(mode, entry);
        if (mode === 'edit' && entry) {
          if (entryIdInput) entryIdInput.value = String(entry.id || '');
          if (entry.entry_type !== 'status_update') {
            entryFormController.populateEntry(entry);
          }
        } else {
          if (entryIdInput) entryIdInput.value = '';
          entryForm.reset();
          entryFormController.populateEntry(newEntryDefaults);
        }
        entryModal.hidden = false;
        entryModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gas-summary-modal-open');
        entryModal.querySelector('.gas-summary-modal__close')?.focus();
      }

      function closeEntryModal() {
        if (!entryModal) return;
        entryModal.hidden = true;
        entryModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('gas-summary-modal-open');
        entryDialog?.classList.remove('is-status-update');
        setEntryTankPanel('new');
        if (entryLastFocus && typeof entryLastFocus.focus === 'function') {
          entryLastFocus.focus();
        }
      }

      entryTankPanel?.addEventListener('change', (event) => {
        const select = event.target.closest('.gas-entry-tank-status-select');
        if (!select) return;
        const row = select.closest('.gas-entry-tank-row');
        syncEntryTankMachineField(row, currentEditEntry?.tank_machine_code || '');
      });

      document.querySelectorAll('.gas-edit-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
          try {
            const entry = JSON.parse(btn.dataset.entry || '{}');
            openEntryModal('edit', entry);
          } catch (error) {
            alert('ไม่สามารถเปิดฟอร์มแก้ไขได้');
          }
        });
      });

      entryModal?.querySelectorAll('[data-gas-entry-close]').forEach((el) => {
        el.addEventListener('click', closeEntryModal);
      });

      entryCancelBtn?.addEventListener('click', () => {
        const msg = entryModalMode === 'edit'
          ? 'ยกเลิกการแก้ไขหรือไม่?'
          : 'ยกเลิกและปิดฟอร์มหรือไม่?';
        if (confirm(msg)) closeEntryModal();
      });

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && entryModal && !entryModal.hidden) {
          closeEntryModal();
        }
      });

      if (entryForm) {
        entryForm.addEventListener('submit', (e) => {
          e.preventDefault();
          const isStatusUpdate = entryDialog?.classList.contains('is-status-update');
          if (!isStatusUpdate && !entryForm.reportValidity()) return;

          const isEdit = entryModalMode === 'edit';
          let tankChanges = 0;
          if (isStatusUpdate) {
            entryTankPanel?.querySelectorAll('.gas-entry-tank-row:not([hidden])').forEach((row) => {
              const select = row.querySelector('.gas-entry-tank-status-select');
              if (select && select.value !== (select.dataset.initialStatus || '')) {
                tankChanges += 1;
              }
            });
            const visibleRow = entryTankPanel?.querySelector('.gas-entry-tank-row:not([hidden]):not(.is-hidden)');
            const statusSelect = visibleRow?.querySelector('.gas-entry-tank-status-select');
            if (statusSelect?.value === 'in_use') {
              const gasType = currentEditEntry?.gas_type || visibleRow?.dataset.gasType || '';
              const machines = gasUsageMachines[gasType] || [];
              if (machines.length > 1) {
                const machineSelect = visibleRow?.querySelector('.gas-entry-tank-machine-select');
                if (!machineSelect?.value) {
                  alert('กรุณาเลือกเครื่องเมื่อเปลี่ยนสถานะเป็นกำลังทำงาน');
                  return;
                }
              }
            }
          }

          let message;
          if (isStatusUpdate && currentEditEntry) {
            message = [
              'ยืนยันแก้ไขสถานะถังนี้หรือไม่?',
              '',
              `แก๊ส: ${currentEditEntry.gas_type || ''}`,
              `ถัง ID: ${currentEditEntry.tank_unit_id || '—'}`,
              `เดิม: ${currentEditEntry.sub_type || '—'}`,
            ];
            if (tankChanges > 0) {
              message.push('', 'สถานะถังจะถูกอัปเดตตามที่เลือก');
              const select = entryTankPanel?.querySelector('.gas-entry-tank-row:not([hidden]) .gas-entry-tank-status-select');
              const oldSt = select?.dataset.initialStatus || '';
              const newSt = select?.value || '';
              if (newSt === 'in_use') {
                const row = select?.closest('.gas-entry-tank-row');
                const gasType = currentEditEntry?.gas_type || row?.dataset.gasType || '';
                const machines = gasUsageMachines[gasType] || [];
                let machineLabel = '';
                if (machines.length > 1) {
                  machineLabel = row?.querySelector('.gas-entry-tank-machine-select')?.value || '';
                } else if (machines.length === 1) {
                  machineLabel = machines[0];
                }
                if (machineLabel) {
                  message.push(`เครื่อง: ${machineLabel}`);
                }
              }
              if (oldSt === 'empty' && newSt !== 'empty') {
                message.push('Remain ในตาราง Summary จะถูกคืน และ Dashboard จะ sync ตาม');
              } else if (newSt === 'empty' && oldSt !== 'empty') {
                message.push('Remain จะถูกหัก 1 ถัง');
              }
            }
          } else {
            const gasLabel = entryGasTypeSelect?.selectedOptions[0]?.textContent?.trim() || '';
            const subType = entrySubTypeInput?.value || '';
            const entryType = entryTypeInput?.value === 'adding' ? 'Adding' : 'Usage';
            const date = document.getElementById('gas-entry-date')?.value || '';
            const qty = document.getElementById('gas-entry-qty')?.value || '1';
            message = [
              isEdit ? 'ยืนยันแก้ไขรายการนี้หรือไม่?' : 'ยืนยันบันทึกรายการนี้หรือไม่?',
              '',
              `แก๊ส: ${gasLabel}`,
              `รายละเอียด: ${subType}`,
              `ประเภท: ${entryType}`,
              `วันที่: ${date}`,
              `จำนวน: ${qty} ถัง`,
            ];
          }

          if (confirm(message.join('\n'))) {
            entryForm.submit();
          }
        });
      }
    })();
    </script>

    <script>
    (() => {
      const listCard = document.getElementById('gasRecentCard');
      if (!listCard) return;
      const shouldScroll = window.location.hash === '#gasRecentCard'
        || /[?&]list_(year|m)=/.test(window.location.search);
      if (!shouldScroll) return;
      requestAnimationFrame(() => {
        listCard.scrollIntoView({ block: 'start', behavior: 'instant' });
      });
    })();
    </script>

  <?php else: ?>
    <div class="gas-summary-layout gas-year-layout">
      <?php if ($detailMonth !== ''):
        $daysInMonth = (int) ($monthlyDaily['days_in_month'] ?? 31);
        $sundayDays = $monthlyDaily['sundays'] ?? [];
        $monthNum = (int) substr($detailMonth, 5, 2);
      ?>
      <div id="gasMonthDetailRoot" class="gas-month-detail-root">
      <article class="card gas-month-toolbar" id="gasMonthToolbar">
        <div class="gas-month-toolbar-inner">
          <a class="gas-month-back" href="gas_consumption.php?tab=summary&amp;year=<?= $year ?>">← กลับสรุปรายปี Y<?= $year ?></a>
          <div>
            <h3>Gas Usage — <?= h(gasMonthLabel($detailMonth)) ?></h3>
           
          </div>
        </div>
        <div class="gas-month-tabs" role="tablist" aria-label="เลือกเดือน">
          <?php for ($m = 1; $m <= 12; $m++):
            $ym = sprintf('%04d-%02d', $year, $m);
            $isActive = $m === $monthNum;
          ?>
          <a class="gas-month-tab<?= $isActive ? ' is-active' : '' ?>"
             href="gas_consumption.php?tab=summary&amp;year=<?= $year ?>&amp;month=<?= h($ym) ?>"
             role="tab"
             aria-selected="<?= $isActive ? 'true' : 'false' ?>">
            <?= h($monthNames[$m]) ?>
          </a>
          <?php endfor; ?>
        </div>
      </article>

      <div class="gas-year-kpis gas-month-kpis">
        <?php foreach (gasTypes() as $gasKey => $gasMeta):
          $monthStock = $monthDetailSummary[$gasKey] ?? [];
          $added = (int) ($monthStock['adding'] ?? 0);
          $used = (int) ($monthStock['usage'] ?? 0);
          $remaining = (int) ($monthStock['remaining'] ?? 0);
        ?>
        <article class="gas-year-kpi gas-month-kpi" style="<?= h(gasTypeCardStyleAttr($gasKey)) ?>">
          <span class="gas-year-kpi-label"><?= h($gasMeta['label']) ?></span>
          <div class="gas-year-kpi-values gas-month-kpi-values">
            <span>เพิ่ม <strong><?= number_format($added) ?></strong></span>
            <span>ใช้ไป <strong><?= number_format($used) ?></strong></span>
            <span class="gas-summary-remain">คงเหลือ <strong><?= number_format($remaining) ?></strong></span>
          </div>
        </article>
        <?php endforeach; ?>
      </div>

      <article class="card gas-year-table-card gas-month-daily-card">
        <div class="gas-year-table-head">
          <div class="gas-year-table-head-main">
            <h3>Gas Usage summary on <?= h(gasMonthLabel($detailMonth)) ?></h3>
            <p class="muted">Type / Adding·Usage / Description / Previous Month Remaining / Day 1–<?= $daysInMonth ?> / Total / Remain</p>
          </div>
          <a class="gas-export-btn" href="gas_consumption.php?tab=summary&amp;year=<?= $year ?>&amp;month=<?= h($detailMonth) ?>&amp;export=monthly">Export to Excel</a>
        </div>
        <div class="table-wrap gas-year-table-wrap gas-month-daily-wrap">
          <table class="gas-year-table gas-month-daily-table">
            <thead>
              <tr>
                <th class="gas-year-sticky gas-year-col-type">Type</th>
                <th class="gas-year-sticky gas-month-col-category">Adding / Usage</th>
                <th class="gas-year-sticky gas-year-col-desc gas-month-col-desc">Description</th>
                <th class="gas-year-sticky gas-month-col-open gas-month-col-open--head">
                  <span class="gas-col-open-line">Previous Month</span>
                  <span class="gas-col-open-line">Remaining</span>
                </th>
                <?php for ($d = 1; $d <= 31; $d++):
                  $isSunday = in_array($d, $sundayDays, true);
                  $isOutOfMonth = $d > $daysInMonth;
                ?>
                <th class="gas-month-col-day<?= $isSunday ? ' is-sunday' : '' ?><?= $isOutOfMonth ? ' is-void' : '' ?>"><?= $d ?></th>
                <?php endfor; ?>
                <th class="gas-year-col-total">Total</th>
                <th class="gas-year-col-remain">Remain</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (($monthlyDaily['groups'] ?? []) as $gasKey => $group):
                $rowCount = count($group['rows']);
                $groupOpening = (int) ($group['total']['opening'] ?? 0);
                $groupRemaining = (int) ($group['total']['remaining'] ?? 0);
                $rowIndex = 0;
                foreach ($group['rows'] as $rowData):
                  $rowIndex++;
                  $isAdding = ($rowData['kind'] ?? 'usage') === 'adding';
                  $usageRowspan = (int) ($rowData['usage_category_rowspan'] ?? 0);
              ?>
              <tr class="gas-year-row<?= $isAdding ? ' gas-year-row-adding' : ' gas-year-row-usage' ?>" style="<?= h(gasTypeRowStyleAttr($gasKey)) ?>">
                <?php if ($rowIndex === 1): ?>
                <td class="gas-year-sticky gas-year-col-type gas-year-type-cell" rowspan="<?= $rowCount ?>">
                  <span class="gas-year-type-label"><?= h($group['label']) ?></span>
                </td>
                <?php endif; ?>
                <?php if ($isAdding): ?>
                <td class="gas-year-sticky gas-month-col-category gas-entry-type gas-entry-type--adding">Adding</td>
                <?php elseif ($usageRowspan > 0): ?>
                <td class="gas-year-sticky gas-month-col-category gas-entry-type gas-entry-type--usage" rowspan="<?= $usageRowspan ?>">Usage</td>
                <?php endif; ?>
                <td class="gas-year-sticky gas-year-col-desc gas-month-col-desc"><?= h((string) $rowData['description']) ?></td>
                <?php if ($rowIndex === 1): ?>
                <td class="gas-year-sticky gas-month-col-open gas-month-col-open--merged<?= $groupOpening !== 0 ? ' has-value' : '' ?>" rowspan="<?= $rowCount ?>"><?= $groupOpening ?></td>
                <?php endif; ?>
                <?php for ($d = 1; $d <= 31; $d++):
                  $isSunday = in_array($d, $sundayDays, true);
                  $isOutOfMonth = $d > $daysInMonth;
                  $val = $isOutOfMonth ? null : (int) ($rowData['days'][$d] ?? 0);
                  $dayClass = 'gas-month-col-day' . ($isSunday ? ' is-sunday' : '') . ($isOutOfMonth ? ' is-void' : '');
                  if (!$isOutOfMonth && $val > 0) {
                      $dayClass .= $isAdding ? ' has-value has-value--adding' : ' has-value has-value--usage';
                  }
                ?>
                <td class="<?= $dayClass ?>">
                  <?= $isOutOfMonth ? '' : ($val > 0 ? $val : '-') ?>
                </td>
                <?php endfor; ?>
                <td class="gas-year-col-total<?= $isAdding ? ' gas-year-col-total--adding' : '' ?>">
                  <strong><?= (int) $rowData['total'] ?: '-' ?></strong>
                </td>
                <?php if ($rowIndex === 1): ?>
                <td class="gas-year-col-remain gas-year-remain gas-year-col-remain--merged<?= $groupRemaining < 0 ? ' is-negative' : '' ?>" rowspan="<?= $rowCount ?>">
                  <strong><?= number_format($groupRemaining) ?></strong>
                </td>
                <?php endif; ?>
            </tr>
          <?php endforeach; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </article>
      </div>

      <script>
      (() => {
        if (!document.getElementById('gasMonthDetailRoot')) return;

        let loading = false;

        const loadMonthDetail = async (url, { push = false } = {}) => {
          if (loading) return;
          loading = true;
          try {
            const response = await fetch(url, {
              headers: { 'X-Requested-With': 'XMLHttpRequest' },
              credentials: 'same-origin',
            });
            if (!response.ok) {
              throw new Error('load failed');
            }
            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const next = doc.getElementById('gasMonthDetailRoot');
            const current = document.getElementById('gasMonthDetailRoot');
            if (!next || !current) {
              window.location.href = url;
              return;
            }
            current.replaceWith(next);
            if (doc.title) {
              document.title = doc.title;
            }
            if (push) {
              history.pushState({ gasMonthView: true }, '', url);
            }
          } catch (error) {
            window.location.href = url;
          } finally {
            loading = false;
          }
        };

        document.addEventListener('click', (event) => {
          const link = event.target.closest('.gas-month-tab');
          if (!link || !document.getElementById('gasMonthDetailRoot')) {
            return;
          }
          if (link.classList.contains('is-active')) {
            event.preventDefault();
            return;
          }
          event.preventDefault();
          loadMonthDetail(link.href, { push: true });
        });

        document.addEventListener('mouseover', (event) => {
          const link = event.target.closest('.gas-month-tab');
          if (!link || link.dataset.prefetched === '1') {
            return;
          }
          link.dataset.prefetched = '1';
          const prefetch = document.createElement('link');
          prefetch.rel = 'prefetch';
          prefetch.href = link.href;
          document.head.appendChild(prefetch);
        }, true);

        window.addEventListener('popstate', () => {
          if (!document.getElementById('gasMonthDetailRoot')) {
            return;
          }
          if (!/[?&]month=/.test(window.location.search)) {
            window.location.reload();
            return;
          }
          loadMonthDetail(window.location.href);
        });
      })();
      </script>

      <?php else: ?>
      <article class="card gas-summary-toolbar gas-year-toolbar">
        <form method="get" class="gas-year-filter" id="gasYearFilterForm">
          <input type="hidden" name="tab" value="summary">
          <div class="gas-year-filter-main">
            <div class="gas-year-filter-field">
              <label id="summary-year-label">เลือกปี</label>
              <?php renderGasYearDropdown('summary-year', $year); ?>
            </div>
            <div class="gas-year-filter-actions">
              <a class="gas-year-nav" href="gas_consumption.php?tab=summary&amp;year=<?= $year - 1 ?>">← <?= $year - 1 ?></a>
              <?php if ($year !== (int) date('Y')): ?>
              <a class="gas-year-nav gas-year-nav--today" href="gas_consumption.php?tab=summary&amp;year=<?= (int) date('Y') ?>">ปีนี้</a>
          <?php endif; ?>
              <a class="gas-year-nav" href="gas_consumption.php?tab=summary&amp;year=<?= $year + 1 ?>"><?= $year + 1 ?> →</a>
            </div>
          </div>
          <p class="muted">สรุปการใช้ถังแก๊สรายปี — <strong>คลิกชื่อเดือน</strong>หรือตัวเลขในตารางเพื่อดูรายละเอียดรายวัน</p>
        </form>
      </article>

      <div class="gas-year-kpis">
        <?php foreach (gasTypes() as $gasKey => $gasMeta):
          $stock = $yearlyStock[$gasKey] ?? [];
          $added = (int) ($stock['adding'] ?? 0);
          $used = (int) ($stock['usage'] ?? 0);
          $remaining = (int) ($stock['remaining'] ?? 0);
        ?>
        <article class="gas-year-kpi" style="<?= h(gasTypeCardStyleAttr($gasKey)) ?>">
          <span class="gas-year-kpi-label"><?= h($gasMeta['label']) ?></span>
          <div class="gas-year-kpi-values">
            <span class="gas-year-kpi-adding">มีเพิ่มมา <strong><?= number_format($added) ?></strong> ถัง</span>
            <span>ใช้ทั้งปี <strong><?= number_format($used) ?></strong></span>
            <span class="gas-summary-remain">คงเหลือ <strong><?= number_format($remaining) ?></strong></span>
          </div>
        </article>
        <?php endforeach; ?>
      </div>

      <article class="card gas-year-table-card">
        <div class="gas-year-table-head">
          <div class="gas-year-table-head-main">
            <h3>Gas Usage Summary — Y<?= $year ?></h3>
            <p class="muted">Type / Description / Jan–Dec (คลิกเดือนเพื่อดูรายวัน) / Total / Remain / Average</p>
          </div>
          <a class="gas-export-btn" href="gas_consumption.php?tab=summary&amp;year=<?= $year ?>&amp;export=yearly">Export to Excel</a>
        </div>
        <div class="table-wrap gas-year-table-wrap">
          <table class="gas-year-table">
            <thead>
              <tr>
                <th class="gas-year-sticky gas-year-col-type">Type</th>
                <th class="gas-year-sticky gas-year-col-desc">Description</th>
                <?php foreach (array_slice($monthNames, 1) as $idx => $mn):
                  $m = $idx + 1;
                  $ym = sprintf('%04d-%02d', $year, $m);
                ?>
                <th class="gas-year-col-month gas-year-col-month--link">
                  <a href="gas_consumption.php?tab=summary&amp;year=<?= $year ?>&amp;month=<?= h($ym) ?>" title="ดูรายวัน <?= h($mn) ?>"><?= h($mn) ?></a>
                </th>
                <?php endforeach; ?>
                <th class="gas-year-col-total">Total</th>
                <th class="gas-year-col-remain">Remain</th>
                <th class="gas-year-col-avg">Average</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($yearlyDetail as $gasKey => $group):
                $rowCount = count($group['rows']);
                $showGroupTotal = $rowCount > 1;
                $typeRowspan = $showGroupTotal ? $rowCount + 1 : $rowCount;
                $groupRemaining = (int) ($group['total']['remaining'] ?? 0);
                $rowIndex = 0;
                foreach ($group['rows'] as $sub => $rowData):
                  $rowIndex++;
              ?>
              <tr class="gas-year-row" style="<?= h(gasTypeRowStyleAttr($gasKey)) ?>">
                <?php if ($rowIndex === 1): ?>
                <td class="gas-year-sticky gas-year-col-type gas-year-type-cell" rowspan="<?= $typeRowspan ?>">
                  <span class="gas-year-type-label"><?= h($group['label']) ?></span>
                </td>
                <?php endif; ?>
                <td class="gas-year-sticky gas-year-col-desc"><?= h($sub) ?></td>
                <?php for ($m = 1; $m <= 12; $m++):
                  $val = (int) ($rowData['months'][$m] ?? 0);
                  $ym = sprintf('%04d-%02d', $year, $m);
                ?>
                <td class="gas-year-col-month<?= $val > 0 ? ' has-value' : '' ?>">
                  <a class="gas-month-drill" href="gas_consumption.php?tab=summary&amp;year=<?= $year ?>&amp;month=<?= h($ym) ?>" title="ดูรายวัน <?= h($monthNames[$m]) ?>">
                    <?= $val > 0 ? $val : '-' ?>
                  </a>
                </td>
                <?php endfor; ?>
                <td class="gas-year-col-total"><strong><?= (int) $rowData['total_usage'] ?: '-' ?></strong></td>
                <?php if ($rowIndex === 1): ?>
                <td class="gas-year-col-remain gas-year-remain gas-year-col-remain--merged<?= $groupRemaining < 0 ? ' is-negative' : '' ?>" rowspan="<?= $rowCount ?>">
                  <strong><?= number_format($groupRemaining) ?></strong>
                </td>
                <?php endif; ?>
                <td class="gas-year-col-avg"><?= $rowData['average'] > 0 ? $rowData['average'] : '-' ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if ($showGroupTotal): ?>
              <tr class="gas-year-row gas-year-row-total" style="<?= h(gasTypeRowStyleAttr($gasKey)) ?>">
                <td class="gas-year-sticky gas-year-col-desc">Total</td>
                <?php for ($m = 1; $m <= 12; $m++):
                  $val = (int) ($group['total']['months'][$m] ?? 0);
                  $ym = sprintf('%04d-%02d', $year, $m);
                ?>
                <td class="gas-year-col-month">
                  <a class="gas-month-drill" href="gas_consumption.php?tab=summary&amp;year=<?= $year ?>&amp;month=<?= h($ym) ?>">
                    <strong><?= $val > 0 ? $val : '-' ?></strong>
                  </a>
                </td>
                <?php endfor; ?>
                <td class="gas-year-col-total"><strong><?= (int) $group['total']['total_usage'] ?></strong></td>
                <td class="gas-year-col-remain gas-year-remain<?= $groupRemaining < 0 ? ' is-negative' : '' ?>">
                  <strong><?= number_format($groupRemaining) ?></strong>
                </td>
                <td class="gas-year-col-avg"><strong><?= $group['total']['average'] > 0 ? $group['total']['average'] : '-' ?></strong></td>
              </tr>
              <?php endif; ?>
              <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
<script>
(function () {
  function closeGasYearDropdown(wrap) {
    var menu = wrap.querySelector('.gas-year-dropdown__menu');
    var trigger = wrap.querySelector('.gas-year-dropdown__trigger');
    if (!menu || !trigger) return;
    menu.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    wrap.classList.remove('is-open');
  }

  document.querySelectorAll('[data-gas-year-dropdown]').forEach(function (wrap) {
    var select = wrap.querySelector('select');
    var trigger = wrap.querySelector('.gas-year-dropdown__trigger');
    var menu = wrap.querySelector('.gas-year-dropdown__menu');
    var valueEl = wrap.querySelector('.gas-year-dropdown__value');
    if (!select || !trigger || !menu || !valueEl) return;

    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      var willOpen = !wrap.classList.contains('is-open');
      document.querySelectorAll('[data-gas-year-dropdown].is-open').forEach(closeGasYearDropdown);
      if (willOpen) {
        menu.hidden = false;
        wrap.classList.add('is-open');
        trigger.setAttribute('aria-expanded', 'true');
      }
    });

    menu.querySelectorAll('.gas-year-dropdown__option').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var y = btn.getAttribute('data-year');
        if (!y) return;
        select.value = y;
        valueEl.textContent = y;
        menu.querySelectorAll('.gas-year-dropdown__option').forEach(function (opt) {
          var selected = opt.getAttribute('data-year') === y;
          opt.classList.toggle('is-selected', selected);
          opt.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
        closeGasYearDropdown(wrap);
        select.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
  });

  document.addEventListener('click', function () {
    document.querySelectorAll('[data-gas-year-dropdown].is-open').forEach(closeGasYearDropdown);
    document.querySelectorAll('[data-gas-form-dropdown].is-open').forEach(closeGasFormDropdown);
  });

  function closeGasFormDropdown(wrap) {
    var menu = wrap.querySelector('.gas-form-list-dropdown__menu');
    var trigger = wrap.querySelector('.gas-form-list-dropdown__trigger');
    if (!menu || !trigger) return;
    menu.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    wrap.classList.remove('is-open');
  }

  document.querySelectorAll('[data-gas-form-dropdown]').forEach(function (wrap) {
    var select = wrap.querySelector('select');
    var trigger = wrap.querySelector('.gas-form-list-dropdown__trigger');
    var menu = wrap.querySelector('.gas-form-list-dropdown__menu');
    var valueEl = wrap.querySelector('.gas-form-list-dropdown__value');
    if (!select || !trigger || !menu || !valueEl) return;

    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      var willOpen = !wrap.classList.contains('is-open');
      document.querySelectorAll('[data-gas-form-dropdown].is-open').forEach(closeGasFormDropdown);
      document.querySelectorAll('[data-gas-year-dropdown].is-open').forEach(closeGasYearDropdown);
      if (willOpen) {
        menu.hidden = false;
        wrap.classList.add('is-open');
        trigger.setAttribute('aria-expanded', 'true');
      }
    });

    menu.querySelectorAll('.gas-form-list-dropdown__option').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!btn.hasAttribute('data-value')) return;
        var val = btn.getAttribute('data-value');
        select.value = val;
        valueEl.textContent = btn.textContent.trim();
        menu.querySelectorAll('.gas-form-list-dropdown__option').forEach(function (opt) {
          var selected = opt.getAttribute('data-value') === val;
          opt.classList.toggle('is-selected', selected);
          opt.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
        closeGasFormDropdown(wrap);
        if (select.form) {
          if (!select.form.action.includes('#')) {
            select.form.action = select.form.action.replace(/#.*$/, '') + '#gasRecentCard';
          }
          select.form.submit();
        }
      });
    });
  });
})();
</script>
<?php renderFooter();
