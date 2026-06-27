<?php
declare(strict_types=1);

function gasEntryTypes(): array
{
    return [
        'usage' => 'Usage (ใช้ถัง)',
        'adding' => 'Adding (รับถังเพิ่ม)',
        'status_update' => 'Update Status (เปลี่ยนสถานะ)',
    ];
}

function gasEntryTypeShortLabel(string $type): string
{
    return match ($type) {
        'adding' => 'Adding',
        'usage' => 'Usage',
        'status_update' => 'Update Status',
        default => gasEntryTypeLabel($type),
    };
}

function gasStatusTransitionLabel(string $statusBefore, string $statusAfter): string
{
    $labels = gasTankStatuses();

    return ($labels[$statusBefore] ?? $statusBefore) . ' → ' . ($labels[$statusAfter] ?? $statusAfter);
}

function gasStatusKeyFromLabel(string $label): string
{
    foreach (gasTankStatuses() as $key => $text) {
        if ($text === $label) {
            return $key;
        }
    }

    return '';
}

function renderGasStatusLabelHtml(string $statusKey, string $label): string
{
    $class = 'gas-status-label';
    if (in_array($statusKey, ['full', 'in_use', 'damaged', 'empty'], true)) {
        $class .= ' is-' . $statusKey;
    }

    return '<span class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</span>';
}

function renderGasStatusTransitionHtml(string $statusBefore, string $statusAfter): string
{
    $labels = gasTankStatuses();
    $beforeLabel = $labels[$statusBefore] ?? $statusBefore;
    $afterLabel = $labels[$statusAfter] ?? $statusAfter;

    return renderGasStatusLabelHtml($statusBefore, $beforeLabel)
        . '<span class="gas-status-arrow" aria-hidden="true"> → </span>'
        . renderGasStatusLabelHtml($statusAfter, $afterLabel);
}

function renderGasStatusTransitionFromSubType(string $subType): string
{
    $parts = preg_split('/\s*→\s*/u', $subType, 2);
    if ($parts === false || count($parts) !== 2) {
        return htmlspecialchars($subType, ENT_QUOTES, 'UTF-8');
    }

    $beforeKey = gasStatusKeyFromLabel(trim($parts[0]));
    $afterKey = gasStatusKeyFromLabel(trim($parts[1]));
    if ($beforeKey === '' || $afterKey === '') {
        return htmlspecialchars($subType, ENT_QUOTES, 'UTF-8');
    }

    return renderGasStatusTransitionHtml($beforeKey, $afterKey);
}

/** DESC column in Data Management — color-coded status transitions. */
function renderGasEntryDescHtml(array $entry): string
{
    $entryType = (string) ($entry['entry_type'] ?? '');
    if ($entryType === 'status_update') {
        $before = (string) ($entry['status_before'] ?? '');
        $after = (string) ($entry['status_after'] ?? '');
        if ($before !== '' && $after !== '') {
            return renderGasStatusTransitionHtml($before, $after);
        }
    }

    $subType = (string) ($entry['sub_type'] ?? '');
    if ($subType !== '' && str_contains($subType, '→')) {
        return renderGasStatusTransitionFromSubType($subType);
    }

    if ($subType === '') {
        return '—';
    }

    return htmlspecialchars($subType, ENT_QUOTES, 'UTF-8');
}

/** Gas column label in Data Management list. */
function gasEntryListGasLabel(array $entry): string
{
    $label = gasTypeLabel((string) ($entry['gas_type'] ?? ''));
    if ((string) ($entry['entry_type'] ?? '') === 'status_update') {
        $tankUnitId = (int) ($entry['tank_unit_id'] ?? 0);
        if ($tankUnitId > 0) {
            return $label . ' (ID ' . $tankUnitId . ')';
        }
    }

    return $label;
}

function gasEntryListQtyDisplay(array $entry): string
{
    if ((string) ($entry['entry_type'] ?? '') === 'status_update') {
        if ((string) ($entry['status_after'] ?? '') === 'empty') {
            return '−1';
        }

        return '—';
    }

    return number_format((int) ($entry['quantity'] ?? 0));
}

/** Usage ที่ระบบสร้างอัตโนมัติเมื่อกดหมดแล้วบน Dashboard — ไม่แสดงใน Data Management */
function gasEntryIsDashboardAutoUsage(array $entry): bool
{
    if ((string) ($entry['entry_type'] ?? '') !== 'usage') {
        return false;
    }

    if ((int) ($entry['tank_unit_id'] ?? 0) > 0) {
        return true;
    }

    $note = (string) ($entry['note'] ?? '');

    return str_contains($note, 'ถังหมดแล้ว');
}

function gasSqlExcludeDashboardAutoUsage(string $tableAlias = 'e'): string
{
    $col = static fn (string $name): string => ($tableAlias !== '' ? $tableAlias . '.' : '') . $name;

    return ' AND NOT (' . $col('entry_type') . " = 'usage' AND ("
        . '(' . $col('tank_unit_id') . ' IS NOT NULL AND ' . $col('tank_unit_id') . ' > 0)'
        . ' OR ' . $col('note') . " LIKE '%ถังหมดแล้ว%'"
        . '))';
}

function gasTouchGasEntryUpdatedAt(int $id, ?string $when = null): void
{
    if ($id < 1) {
        return;
    }

    db()->prepare('UPDATE gas_entries SET updated_at = :updated_at WHERE id = :id')->execute([
        ':updated_at' => $when ?? nowIso(),
        ':id' => $id,
    ]);
}

function gasSqlOrderGasEntriesByRecent(string $tableAlias = 'e'): string
{
    $updated = ($tableAlias !== '' ? $tableAlias . '.' : '') . 'updated_at';
    $created = ($tableAlias !== '' ? $tableAlias . '.' : '') . 'created_at';
    $id = ($tableAlias !== '' ? $tableAlias . '.' : '') . 'id';

    return " ORDER BY COALESCE(NULLIF({$updated}, ''), {$created}) DESC, {$id} DESC";
}

function gasTankUnitById(int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM gas_tank_units WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function gasLatestStatusUpdateEntryId(int $tankUnitId): int
{
    if ($tankUnitId < 1) {
        return 0;
    }

    $stmt = db()->prepare(
        'SELECT id FROM gas_entries
         WHERE entry_type = :entry_type AND tank_unit_id = :tank_unit_id
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':entry_type' => 'status_update',
        ':tank_unit_id' => $tankUnitId,
    ]);
    $row = $stmt->fetch();

    return (int) ($row['id'] ?? 0);
}

/** หนึ่งถังต่อหนึ่งแถว — อัปเดตสถานะล่าสุดแทนการเพิ่มแถวซ้ำ */
function addGasStatusUpdateEntry(
    string $gasType,
    int $tankUnitId,
    string $statusBefore,
    string $statusAfter,
    string $recordDate,
    int $userId
): void {
    if ($statusBefore === $statusAfter) {
        return;
    }
    if (!isset(gasTypes()[$gasType]) || $tankUnitId < 1) {
        return;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recordDate)) {
        $recordDate = date('Y-m-d');
    }

    $subType = gasStatusTransitionLabel($statusBefore, $statusAfter);
    $existingId = gasLatestStatusUpdateEntryId($tankUnitId);

    if ($existingId > 0) {
        $now = nowIso();
        db()->prepare(
            'UPDATE gas_entries
             SET gas_type = :gas_type,
                 sub_type = :sub_type,
                 record_date = :record_date,
                 status_before = :status_before,
                 status_after = :status_after,
                 created_by = :created_by,
                 updated_at = :updated_at
             WHERE id = :id'
        )->execute([
            ':gas_type' => $gasType,
            ':sub_type' => $subType,
            ':record_date' => $recordDate,
            ':status_before' => $statusBefore,
            ':status_after' => $statusAfter,
            ':created_by' => $userId,
            ':updated_at' => $now,
            ':id' => $existingId,
        ]);

        return;
    }

    $now = nowIso();
    db()->prepare(
        'INSERT INTO gas_entries (
            gas_type, sub_type, record_date, entry_type, quantity, note,
            tank_unit_id, status_before, status_after, created_by, created_at, updated_at
         ) VALUES (
            :gas_type, :sub_type, :record_date, :entry_type, :quantity, :note,
            :tank_unit_id, :status_before, :status_after, :created_by, :created_at, :updated_at
         )'
    )->execute([
        ':gas_type' => $gasType,
        ':sub_type' => $subType,
        ':record_date' => $recordDate,
        ':entry_type' => 'status_update',
        ':quantity' => 1,
        ':note' => '',
        ':tank_unit_id' => $tankUnitId,
        ':status_before' => $statusBefore,
        ':status_after' => $statusAfter,
        ':created_by' => $userId,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

/** ลบแถว Update Status ซ้ำ — เหลือเฉพาะรายการล่าสุดต่อถัง */
function gasConsolidateDuplicateStatusUpdateEntries(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    $groups = $pdo->query(
        'SELECT tank_unit_id, MAX(id) AS keep_id
         FROM gas_entries
         WHERE entry_type = \'status_update\'
           AND tank_unit_id IS NOT NULL
           AND tank_unit_id > 0
         GROUP BY tank_unit_id
         HAVING COUNT(*) > 1'
    )->fetchAll();

    if ($groups === []) {
        return;
    }

    $delete = $pdo->prepare(
        'DELETE FROM gas_entries
         WHERE entry_type = \'status_update\'
           AND tank_unit_id = :tank_unit_id
           AND id != :keep_id'
    );

    foreach ($groups as $row) {
        $delete->execute([
            ':tank_unit_id' => (int) ($row['tank_unit_id'] ?? 0),
            ':keep_id' => (int) ($row['keep_id'] ?? 0),
        ]);
    }
}

/** เชื่อม Usage อัตโนมัติกับ tank_unit_id จาก note (ข้อมูลเก่า) */
function gasBackfillUsageTankUnitIds(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    $rows = $pdo->query(
        "SELECT id, note FROM gas_entries
         WHERE entry_type = 'usage'
           AND (tank_unit_id IS NULL OR tank_unit_id = 0)
           AND note LIKE '%ถังหมดแล้ว ID %'"
    )->fetchAll();

    if ($rows === []) {
        return;
    }

    $update = $pdo->prepare('UPDATE gas_entries SET tank_unit_id = :tank_unit_id WHERE id = :id');
    foreach ($rows as $row) {
        if (!preg_match('/ID (\d+)/', (string) ($row['note'] ?? ''), $matches)) {
            continue;
        }
        $tankUnitId = (int) ($matches[1] ?? 0);
        if ($tankUnitId < 1) {
            continue;
        }
        $update->execute([
            ':tank_unit_id' => $tankUnitId,
            ':id' => (int) ($row['id'] ?? 0),
        ]);
    }
}

/** บันทึก Usage 1 ถังเมื่อถังในคลังถูกเปลี่ยนเป็นหมดแล้ว (หัก Remain ใน Summary). */
function gasRecordUsageIfTankEmptied(
    string $gasType,
    int $tankNo,
    string $oldStatus,
    string $newStatus,
    string $recordDate,
    int $userId,
    int $tankUnitId = 0,
    string $machineCode = ''
): void {
    if ($newStatus !== 'empty' || $oldStatus === 'empty' || !isset(gasTypes()[$gasType])) {
        return;
    }

    $month = substr($recordDate, 0, 7);
    $monthRow = gasDashboardMonthSummary($month)[$gasType] ?? [];
    $fullCount = max(0, (int) ($monthRow['full'] ?? 0));
    $usedCount = max(0, (int) ($monthRow['used'] ?? 0));

    if (gasTankSlotRole($tankNo, $fullCount, $usedCount, $oldStatus) !== 'stock') {
        return;
    }

    $subType = trim($machineCode);
    if ($subType === '' && $tankUnitId > 0) {
        $unit = gasTankUnitById($tankUnitId);
        $subType = trim((string) ($unit['machine_code'] ?? ''));
    }
    if ($subType === '' || !gasIsValidSubTypeForEntry($gasType, $subType, 'usage')) {
        $usageSubs = gasUsageSubTypes($gasType);
        $subType = $usageSubs[0] ?? gasAddingSubType($gasType);
    }
    $note = $tankUnitId > 0
        ? 'Dashboard — ถังหมดแล้ว ID ' . $tankUnitId
        : 'Dashboard — ถังหมดแล้ว';

    addGasEntry($gasType, $subType, $recordDate, 'usage', 1, $userId, $note, $tankUnitId);
}

/** ลบ Usage อัตโนมัติที่สร้างตอนกดหมดแล้ว — คืน Remain เมื่อแก้สถานะกลับ */
function gasDeleteAutoUsageForTankEmptied(string $gasType, int $tankUnitId, string $yearMonth): bool
{
    if (!isset(gasTypes()[$gasType])) {
        return false;
    }

    $prefix = $yearMonth . '%';

    if ($tankUnitId > 0) {
        $byUnit = db()->prepare(
            'SELECT id FROM gas_entries
             WHERE gas_type = :gas_type
               AND entry_type = :entry_type
               AND record_date LIKE :prefix
               AND tank_unit_id = :tank_unit_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $byUnit->execute([
            ':gas_type' => $gasType,
            ':entry_type' => 'usage',
            ':prefix' => $prefix,
            ':tank_unit_id' => $tankUnitId,
        ]);
        $row = $byUnit->fetch();
        if ($row && gasDeleteGasEntryRow((int) ($row['id'] ?? 0))) {
            return true;
        }

        $needle = '%ถังหมดแล้ว ID ' . $tankUnitId . '%';
        $byNote = db()->prepare(
            'SELECT id FROM gas_entries
             WHERE gas_type = :gas_type
               AND entry_type = :entry_type
               AND record_date LIKE :prefix
               AND note LIKE :needle
             ORDER BY id DESC
             LIMIT 1'
        );
        $byNote->execute([
            ':gas_type' => $gasType,
            ':entry_type' => 'usage',
            ':prefix' => $prefix,
            ':needle' => $needle,
        ]);
        $row = $byNote->fetch();
        if ($row && gasDeleteGasEntryRow((int) ($row['id'] ?? 0))) {
            return true;
        }
    }

    $autoNote = db()->prepare(
        'SELECT id FROM gas_entries
         WHERE gas_type = :gas_type
           AND entry_type = :entry_type
           AND record_date LIKE :prefix
           AND note LIKE :auto_note
         ORDER BY id DESC
         LIMIT 1'
    );
    $autoNote->execute([
        ':gas_type' => $gasType,
        ':entry_type' => 'usage',
        ':prefix' => $prefix,
        ':auto_note' => '%ถังหมดแล้ว%',
    ]);
    $row = $autoNote->fetch();

    return $row ? gasDeleteGasEntryRow((int) ($row['id'] ?? 0)) : false;
}

/** คืน Remain 1 ถังจากรายการ Usage ที่บันทึกผ่านฟอร์ม (ไม่ใช่ auto Dashboard). */
function gasRestoreOneManualUsageCredit(string $gasType, string $yearMonth): bool
{
    if (!isset(gasTypes()[$gasType])) {
        return false;
    }

    $prefix = $yearMonth . '%';
    $stmt = db()->prepare(
        'SELECT id FROM gas_entries
         WHERE gas_type = :gas_type
           AND entry_type = :entry_type
           AND record_date LIKE :prefix
         ORDER BY id DESC'
    );
    $stmt->execute([
        ':gas_type' => $gasType,
        ':entry_type' => 'usage',
        ':prefix' => $prefix,
    ]);

    foreach ($stmt->fetchAll() as $row) {
        $entry = getGasEntry((int) ($row['id'] ?? 0));
        if (!$entry || gasEntryIsDashboardAutoUsage($entry)) {
            continue;
        }

        $id = (int) ($entry['id'] ?? 0);
        $qty = max(0, (int) ($entry['quantity'] ?? 0));
        if ($id < 1 || $qty < 1) {
            continue;
        }

        if ($qty > 1) {
            db()->prepare(
                'UPDATE gas_entries SET quantity = :quantity, updated_at = :updated_at WHERE id = :id'
            )->execute([
                ':quantity' => $qty - 1,
                ':updated_at' => nowIso(),
                ':id' => $id,
            ]);
            gasRefreshTankInventoryFromEntries(null, $yearMonth);

            return true;
        }

        return deleteGasEntry($id);
    }

    return false;
}

/** คืน Remain เมื่อแก้จากหมดแล้ว → เต็ม/กำลังทำงาน/ชำรุด (กรณีกดผิด) */
function gasReverseUsageIfTankRestored(
    string $gasType,
    int $tankNo,
    string $oldStatus,
    string $newStatus,
    string $recordDate,
    int $userId,
    int $tankUnitId = 0
): void {
    if ($oldStatus !== 'empty' || $newStatus === 'empty' || !isset(gasTypes()[$gasType])) {
        return;
    }

    $month = substr($recordDate, 0, 7);
    if ($tankUnitId > 0 && gasDeleteAutoUsageForTankEmptied($gasType, $tankUnitId, $month)) {
        return;
    }

    gasRestoreOneManualUsageCredit($gasType, $month);
}

/** @deprecated Identity-based slots — correcting the emptied tank is enough; no swap. */
function gasSwapStatusesForUsedZoneCorrection(
    string $gasType,
    int $tankUnitId,
    string $newStatus,
    string $yearMonth
): void {
}

function applyGasStatusUpdateEntryEdit(int $entryId, string $newStatus, int $userId, string $machineCode = ''): bool
{
    $entry = getGasEntry($entryId);
    if (!$entry || (string) ($entry['entry_type'] ?? '') !== 'status_update') {
        return false;
    }
    if (!in_array($newStatus, array_keys(gasTankStatuses()), true)) {
        return false;
    }

    $tankUnitId = (int) ($entry['tank_unit_id'] ?? 0);
    if ($tankUnitId < 1) {
        return false;
    }

    $unit = gasTankUnitById($tankUnitId);
    if (!$unit) {
        return false;
    }

    $physicalStatus = (string) ($unit['status'] ?? 'empty');
    $recordedStatus = (string) ($entry['status_after'] ?? '');
    $effectiveCurrent = $recordedStatus !== '' ? $recordedStatus : $physicalStatus;
    $statusBefore = (string) ($entry['status_before'] ?? $effectiveCurrent);
    $recordDate = (string) ($entry['record_date'] ?? date('Y-m-d'));
    $gasType = (string) ($entry['gas_type'] ?? '');
    $yearMonth = substr($recordDate, 0, 7);
    $tankNo = (int) ($unit['tank_no'] ?? 0);
    $machineCode = trim($machineCode);
    $usageMachines = gasUsageSubTypes($gasType);

    if ($newStatus === 'in_use') {
        if ($machineCode === '' && count($usageMachines) === 1) {
            $machineCode = $usageMachines[0];
        }
        if (
            $machineCode === ''
            || !gasIsValidSubTypeForEntry($gasType, $machineCode, 'usage')
        ) {
            if (count($usageMachines) > 1) {
                return false;
            }
            $machineCode = $usageMachines[0] ?? '';
        }
    }

    if ($effectiveCurrent === $newStatus) {
        return false;
    }

    $machinesInput = $machineCode !== '' ? [$tankUnitId => $machineCode] : [];
    $usageMachine = $machineCode !== ''
        ? $machineCode
        : trim((string) ($unit['machine_code'] ?? ''));

    if ($effectiveCurrent === 'empty' && $newStatus !== 'empty') {
        gasReverseUsageIfTankRestored(
            $gasType,
            $tankNo,
            'empty',
            $newStatus,
            $recordDate,
            $userId,
            $tankUnitId
        );
        gasSwapStatusesForUsedZoneCorrection($gasType, $tankUnitId, $newStatus, $yearMonth);

        $summary = gasDashboardMonthSummary($yearMonth)[$gasType] ?? [];
        $fullCount = max(0, (int) ($summary['full'] ?? 0));
        $usedCount = max(0, (int) ($summary['used'] ?? 0));
        updateGasTankUnitsBatch([$tankUnitId => $newStatus], $userId, false, $machinesInput);
    } else {
        gasRecordUsageIfTankEmptied(
            $gasType,
            $tankNo,
            $effectiveCurrent,
            $newStatus,
            $recordDate,
            $userId,
            $tankUnitId,
            $usageMachine
        );
        updateGasTankUnitsBatch([$tankUnitId => $newStatus], $userId, false, $machinesInput);
    }

    db()->prepare(
        'UPDATE gas_entries
         SET sub_type = :sub_type, status_before = :status_before, status_after = :status_after, record_date = :record_date, updated_at = :updated_at
         WHERE id = :id'
    )->execute([
        ':sub_type' => gasStatusTransitionLabel($statusBefore, $newStatus),
        ':status_before' => $statusBefore,
        ':status_after' => $newStatus,
        ':record_date' => date('Y-m-d'),
        ':updated_at' => nowIso(),
        ':id' => $entryId,
    ]);

    gasRefreshTankInventoryFromEntries(null, $yearMonth);

    return true;
}

function gasEntryTypeLabel(string $type): string
{
    return gasEntryTypes()[$type] ?? $type;
}

function gasTypeThemes(): array
{
    return [
        'o2_96' => [
            'accent' => '#9ca3af',
            'bg' => '#fafafa',
            'bgStrong' => '#eeeeef',
            'bgSummary' => '#f5f5f6',
        ],
        'n2' => [
            'accent' => '#64748b',
            'bg' => '#f1f5f9',
            'bgStrong' => '#e2e8f0',
            'bgSummary' => '#eef2f6',
        ],
        'o2_100' => [
            'accent' => '#0a0a0c',
            'bg' => '#e4e4e7',
            'bgStrong' => '#d4d4d8',
            'bgSummary' => '#e8e8ea',
        ],
        'helium' => [
            'accent' => '#8b5a3c',
            'bg' => '#faf6f2',
            'bgStrong' => '#efe4d9',
            'bgSummary' => '#f3ebe3',
        ],
    ];
}

function gasTypeColor(string $gasType): string
{
    return gasTypeThemes()[$gasType]['accent'] ?? '#64748b';
}

function gasTypeCardStyleAttr(string $gasType): string
{
    $theme = gasTypeThemes()[$gasType] ?? [
        'accent' => '#64748b',
        'bgSummary' => '#f7fbff',
    ];

    return sprintf(
        '--accent:%s;--gas-card-bg:%s',
        $theme['accent'],
        $theme['bgSummary']
    );
}

function gasTypeRowStyleAttr(string $gasType): string
{
    $theme = gasTypeThemes()[$gasType] ?? [
        'accent' => '#64748b',
        'bg' => '#f5f9ff',
        'bgStrong' => '#e8f0ff',
        'bgSummary' => '#eef4ff',
    ];

    return sprintf(
        '--accent:%s;--gas-row-bg:%s;--gas-row-bg-strong:%s;--gas-row-summary:%s',
        $theme['accent'],
        $theme['bg'],
        $theme['bgStrong'],
        $theme['bgSummary']
    );
}

function addGasEntry(
    string $gasType,
    string $subType,
    string $recordDate,
    string $entryType,
    int $quantity,
    int $userId,
    string $note = '',
    int $tankUnitId = 0
): void {
    if (!isset(gasTypes()[$gasType])) {
        throw new InvalidArgumentException('Invalid gas type');
    }
    if (!gasIsValidSubTypeForEntry($gasType, $subType, $entryType)) {
        throw new InvalidArgumentException('Invalid sub type for entry');
    }
    if (!isset(gasEntryTypes()[$entryType])) {
        throw new InvalidArgumentException('Invalid entry type');
    }
    if ($quantity < 1) {
        throw new InvalidArgumentException('Quantity must be at least 1');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recordDate)) {
        throw new InvalidArgumentException('Invalid date');
    }

    $now = nowIso();
    db()->prepare(
        'INSERT INTO gas_entries (
            gas_type, sub_type, record_date, entry_type, quantity, note,
            tank_unit_id, created_by, created_at, updated_at
         ) VALUES (
            :gas_type, :sub_type, :record_date, :entry_type, :quantity, :note,
            :tank_unit_id, :created_by, :created_at, :updated_at
         )'
    )->execute([
        ':gas_type' => $gasType,
        ':sub_type' => $subType,
        ':record_date' => $recordDate,
        ':entry_type' => $entryType,
        ':quantity' => $quantity,
        ':note' => $note,
        ':tank_unit_id' => $tankUnitId > 0 ? $tankUnitId : null,
        ':created_by' => $userId,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $newId = (int) db()->lastInsertId();
    $yearMonth = substr($recordDate, 0, 7);
    gasRefreshTankInventoryFromEntries(null, $yearMonth);

    if ($newId > 0) {
        $newEntry = getGasEntry($newId);
        if ($newEntry) {
            if ($entryType === 'adding') {
                gasEnsureAddingTankStatuses($newEntry, $userId);
            } elseif ($entryType === 'usage' && !gasEntryIsDashboardAutoUsage($newEntry)) {
                gasEnsureUsageTankStatuses($newEntry, $userId);
                gasSyncTankStatusesFromAccounting(db());
            }
            gasNotifyLineOnGasEntry($newEntry);
        }
    }
}

function gasDeleteGasEntryRow(int $id): bool
{
    if ($id < 1) {
        return false;
    }

    $stmt = db()->prepare('DELETE FROM gas_entries WHERE id = :id');
    $stmt->execute([':id' => $id]);

    return $stmt->rowCount() > 0;
}

function deleteGasEntry(int $id): bool
{
    $entry = getGasEntry($id);
    if (!$entry) {
        return false;
    }

    $stmt = db()->prepare('DELETE FROM gas_entries WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $deleted = $stmt->rowCount() > 0;
    if ($deleted) {
        gasRevertTankStatusAfterEntryDelete($entry);
        gasRefreshTankInventoryFromEntries(null, substr((string) $entry['record_date'], 0, 7));
    }

    return $deleted;
}

function deleteGasEntries(array $ids): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return 0;
    }

    $entries = [];
    $months = [];
    foreach ($ids as $id) {
        $entry = getGasEntry($id);
        if ($entry) {
            $entries[] = $entry;
            $months[substr((string) $entry['record_date'], 0, 7)] = true;
        }
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('DELETE FROM gas_entries WHERE id IN (' . $placeholders . ')');
    $stmt->execute($ids);
    $deleted = $stmt->rowCount();

    foreach ($entries as $entry) {
        gasRevertTankStatusAfterEntryDelete($entry);
    }
    foreach (array_keys($months) as $month) {
        gasRefreshTankInventoryFromEntries(null, $month);
    }

    return $deleted;
}

function getGasEntry(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM gas_entries WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function updateGasEntry(int $id, string $gasType, string $subType, string $recordDate, string $entryType, int $quantity, int $userId, string $note = ''): bool
{
    $existing = getGasEntry($id);
    if ($id < 1 || !$existing) {
        return false;
    }
    if (!isset(gasTypes()[$gasType])) {
        throw new InvalidArgumentException('Invalid gas type');
    }
    if (!gasIsValidSubTypeForEntry($gasType, $subType, $entryType)) {
        throw new InvalidArgumentException('Invalid sub type for entry');
    }
    if (!isset(gasEntryTypes()[$entryType])) {
        throw new InvalidArgumentException('Invalid entry type');
    }
    if ($quantity < 1) {
        throw new InvalidArgumentException('Quantity must be at least 1');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recordDate)) {
        throw new InvalidArgumentException('Invalid date');
    }

    $stmt = db()->prepare(
        'UPDATE gas_entries
         SET gas_type = :gas_type,
             sub_type = :sub_type,
             record_date = :record_date,
             entry_type = :entry_type,
             quantity = :quantity,
             note = :note,
             updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':gas_type' => $gasType,
        ':sub_type' => $subType,
        ':record_date' => $recordDate,
        ':entry_type' => $entryType,
        ':quantity' => $quantity,
        ':note' => $note,
        ':updated_at' => nowIso(),
        ':id' => $id,
    ]);

    $months = [substr($recordDate, 0, 7)];
    $oldMonth = substr((string) ($existing['record_date'] ?? ''), 0, 7);
    if ($oldMonth !== '' && !in_array($oldMonth, $months, true)) {
        $months[] = $oldMonth;
    }

    foreach ($months as $month) {
        gasRefreshTankInventoryFromEntries(null, $month);
    }

    return true;
}

function countGasEntries(string $yearMonth, string $entryType = ''): int
{
    $sql = 'SELECT COUNT(*) FROM gas_entries WHERE record_date LIKE :prefix';
    $sql .= gasSqlExcludeDashboardAutoUsage('');
    $params = [':prefix' => $yearMonth . '%'];
    if ($entryType !== '' && isset(gasEntryTypes()[$entryType])) {
        $sql .= ' AND entry_type = :entry_type';
        $params[':entry_type'] = $entryType;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function listGasEntries(string $yearMonth, int $limit = 100, int $offset = 0, string $entryType = ''): array
{
    $sql = 'SELECT e.*, u.username
         FROM gas_entries e
         LEFT JOIN users u ON u.id = e.created_by
         WHERE e.record_date LIKE :prefix';
    $sql .= gasSqlExcludeDashboardAutoUsage('e');
    if ($entryType !== '' && isset(gasEntryTypes()[$entryType])) {
        $sql .= ' AND e.entry_type = :entry_type';
    }
    $sql .= gasSqlOrderGasEntriesByRecent('e') . '
         LIMIT :limit OFFSET :offset';

    $stmt = db()->prepare($sql);
    $stmt->bindValue(':prefix', $yearMonth . '%');
    if ($entryType !== '' && isset(gasEntryTypes()[$entryType])) {
        $stmt->bindValue(':entry_type', $entryType);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function gasMonthSummaryByType(string $yearMonth): array
{
    $summary = [];
    foreach (array_keys(gasTypes()) as $gasType) {
        $summary[$gasType] = ['usage' => 0, 'adding' => 0];
    }

    $stmt = db()->prepare(
        'SELECT gas_type, entry_type, COALESCE(SUM(quantity), 0) AS total
         FROM gas_entries
         WHERE record_date LIKE :prefix
         GROUP BY gas_type, entry_type'
    );
    $stmt->execute([':prefix' => $yearMonth . '%']);

    foreach ($stmt->fetchAll() as $row) {
        $gasType = (string) $row['gas_type'];
        $entryType = (string) $row['entry_type'];
        if (isset($summary[$gasType][$entryType])) {
            $summary[$gasType][$entryType] = (int) $row['total'];
        }
    }

    return $summary;
}

function gasOpeningBalanceByType(string $yearMonth): array
{
    return gasTypeStockBalancesBefore($yearMonth . '-01');
}

/** Entries for one gas type strictly before a given row (date, then id). */
function gasEntriesBeforeEntry(string $gasType, string $recordDate, int $entryId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM gas_entries
         WHERE gas_type = :gas_type
           AND (
             record_date < :record_date
             OR (record_date = :record_date AND id < :entry_id)
           )
         ORDER BY record_date ASC, id ASC'
    );
    $stmt->execute([
        ':gas_type' => $gasType,
        ':record_date' => $recordDate,
        ':entry_id' => $entryId,
    ]);

    return $stmt->fetchAll();
}

/** Remain stock for a gas type immediately before an entry is applied. */
function gasRemainBeforeEntry(string $gasType, string $recordDate, int $entryId): int
{
    $yearMonth = substr($recordDate, 0, 7);
    $remain = (int) (gasOpeningBalanceByType($yearMonth)[$gasType] ?? 0);

    foreach (gasEntriesBeforeEntry($gasType, $recordDate, $entryId) as $row) {
        $qty = (int) ($row['quantity'] ?? 0);
        if ((string) ($row['entry_type'] ?? '') === 'adding') {
            $remain += $qty;
        } elseif ((string) ($row['entry_type'] ?? '') === 'usage') {
            $remain -= $qty;
        }
    }

    return max(0, $remain);
}

/** Tank slot numbers from accounting at the time of an entry. */
function gasTankSlotNosForEntry(array $entry): array
{
    $gasType = (string) ($entry['gas_type'] ?? '');
    $recordDate = (string) ($entry['record_date'] ?? '');
    $entryId = (int) ($entry['id'] ?? 0);
    $quantity = max(0, (int) ($entry['quantity'] ?? 0));
    $entryType = (string) ($entry['entry_type'] ?? 'usage');

    if ($quantity < 1 || $entryId < 1 || $gasType === '' || !isset(gasTypes()[$gasType])) {
        return [];
    }

    $remainBefore = gasRemainBeforeEntry($gasType, $recordDate, $entryId);

    if ($entryType === 'adding') {
        $start = $remainBefore + 1;
        $end = $remainBefore + $quantity;
    } else {
        $end = $remainBefore;
        $start = $remainBefore - $quantity + 1;
    }

    if ($end < 1 || $start > $end) {
        return [];
    }

    $start = max(1, $start);

    return range($start, $end);
}

/** Stock-slot tank numbers for an Adding entry (matches Dashboard, live status). */
function gasTankStockNosForAddingEntry(array $entry): array
{
    $gasType = (string) ($entry['gas_type'] ?? '');
    $recordDate = (string) ($entry['record_date'] ?? '');
    $entryId = (int) ($entry['id'] ?? 0);
    $quantity = max(0, (int) ($entry['quantity'] ?? 0));

    if ($quantity < 1 || $entryId < 1 || $gasType === '' || !isset(gasTypes()[$gasType])) {
        return [];
    }

    $atAddNos = gasTankSlotNosForEntry($entry);
    $month = substr($recordDate, 0, 7);
    $stockSlots = max(0, (int) (gasDashboardMonthSummary($month)[$gasType]['full'] ?? 0));
    $currentStockNos = $stockSlots > 0 ? range(1, $stockSlots) : [];
    $linked = array_values(array_intersect($atAddNos, $currentStockNos));

    if (count($linked) >= $quantity) {
        return array_slice($linked, 0, $quantity);
    }

    // Original add slots may now be in the "used" zone — use live Dashboard stock row (1..remain).
    if ($stockSlots > 0) {
        return range(1, min($quantity, $stockSlots));
    }

    return array_slice($atAddNos, 0, $quantity);
}

/** Used-zone tank numbers for a Usage entry (matches Dashboard). */
function gasTankUsedNosForUsageEntry(array $entry): array
{
    $gasType = (string) ($entry['gas_type'] ?? '');
    $recordDate = (string) ($entry['record_date'] ?? '');
    $entryId = (int) ($entry['id'] ?? 0);
    $quantity = max(0, (int) ($entry['quantity'] ?? 0));

    if ($quantity < 1 || $entryId < 1 || $gasType === '' || !isset(gasTypes()[$gasType])) {
        return [];
    }

    $atUsageNos = gasTankSlotNosForEntry($entry);
    $month = substr($recordDate, 0, 7);
    $monthRow = gasDashboardMonthSummary($month)[$gasType] ?? [];
    $stockSlots = max(0, (int) ($monthRow['full'] ?? 0));
    $usedSlots = max(0, (int) ($monthRow['used'] ?? 0));

    if ($usedSlots > 0) {
        $usedEnd = $stockSlots + $usedSlots;
        $usedStart = $stockSlots + 1;
        $currentUsedNos = range($usedStart, $usedEnd);
        $linked = array_values(array_intersect($atUsageNos, $currentUsedNos));

        if (count($linked) >= $quantity) {
            return array_slice($linked, 0, $quantity);
        }

        $usageOffset = gasUsageQuantityBeforeEntry($gasType, $recordDate, $entryId);
        $entryStart = $usedStart + $usageOffset;
        $entryEnd = $entryStart + $quantity - 1;
        if ($entryStart <= $usedEnd && $entryEnd >= $entryStart) {
            return range($entryStart, min($entryEnd, $usedEnd));
        }

        if ($usedSlots >= $quantity) {
            return range($usedEnd - $quantity + 1, $usedEnd);
        }

        return $currentUsedNos;
    }

    if ($atUsageNos !== []) {
        return array_slice($atUsageNos, 0, $quantity);
    }

    return [];
}

/** Total usage quantity for a gas type before an entry row. */
function gasUsageQuantityBeforeEntry(string $gasType, string $recordDate, int $entryId): int
{
    $sum = 0;
    foreach (gasEntriesBeforeEntry($gasType, $recordDate, $entryId) as $row) {
        if ((string) ($row['entry_type'] ?? '') === 'usage') {
            $sum += max(0, (int) ($row['quantity'] ?? 0));
        }
    }

    return $sum;
}

/** Tank numbers tied to a single gas_entries row (matches Dashboard). */
function gasTankNosForEntry(array $entry): array
{
    $entryType = (string) ($entry['entry_type'] ?? 'usage');

    if ($entryType === 'adding') {
        return gasTankStockNosForAddingEntry($entry);
    }

    if ($entryType === 'status_update') {
        $nos = [];
        foreach (gasTankUnitsForEntry($entry) as $unit) {
            $nos[] = (int) ($unit['tank_no'] ?? 0);
        }

        return $nos;
    }

    return gasTankUsedNosForUsageEntry($entry);
}

/** Tank units for an Adding entry — live status from Dashboard stock slots. */
function gasTankUnitsForAddingEntry(array $entry): array
{
    $gasType = (string) ($entry['gas_type'] ?? '');
    $tankNos = gasTankStockNosForAddingEntry($entry);
    if ($tankNos === []) {
        return [];
    }

    $noLookup = array_flip($tankNos);
    $units = [];
    foreach (loadGasTankUnitsFlat() as $unit) {
        if ((string) ($unit['gas_type'] ?? '') !== $gasType) {
            continue;
        }
        $tankNo = (int) ($unit['tank_no'] ?? 0);
        if (isset($noLookup[$tankNo])) {
            $units[] = $unit;
        }
    }

    usort($units, static fn (array $a, array $b): int => ((int) ($a['tank_no'] ?? 0)) <=> ((int) ($b['tank_no'] ?? 0)));

    return $units;
}

/** After Adding, mark new stock slots as full (does not overwrite in_use/damaged). */
function gasEnsureAddingTankStatuses(array $entry, int $userId): void
{
    if ((string) ($entry['entry_type'] ?? '') !== 'adding') {
        return;
    }

    $updates = [];
    foreach (gasTankUnitsForAddingEntry($entry) as $unit) {
        $id = (int) ($unit['id'] ?? 0);
        $status = (string) ($unit['status'] ?? '');
        if ($id > 0 && $status === 'empty') {
            $updates[$id] = 'full';
        }
    }

    if ($updates !== []) {
        updateGasTankUnitsBatch($updates, $userId);
    }
}

/** After manual Usage, clear in_use tanks that were consumed (Remain already deducted). */
function gasEnsureUsageTankStatuses(array $entry, int $userId): void
{
    if ((string) ($entry['entry_type'] ?? '') !== 'usage' || gasEntryIsDashboardAutoUsage($entry)) {
        return;
    }

    $gasType = (string) ($entry['gas_type'] ?? '');
    $quantity = max(0, (int) ($entry['quantity'] ?? 0));
    $recordDate = (string) ($entry['record_date'] ?? date('Y-m-d'));
    if ($quantity < 1 || !isset(gasTypes()[$gasType])) {
        return;
    }

    $month = substr($recordDate, 0, 7);
    $monthRow = gasDashboardMonthSummary($month)[$gasType] ?? [];
    $fullCount = max(0, (int) ($monthRow['full'] ?? 0));
    $usedCount = max(0, (int) ($monthRow['used'] ?? 0));

    $candidates = [];
    foreach (loadGasTankUnits()[$gasType] ?? [] as $unit) {
        if ((string) ($unit['status'] ?? '') !== 'in_use') {
            continue;
        }
        $tankNo = (int) ($unit['tank_no'] ?? 0);
        $candidates[] = [
            'unit' => $unit,
            'tank_no' => $tankNo,
            'used_zone' => gasTankSlotRole($tankNo, $fullCount, $usedCount, (string) ($unit['status'] ?? '')) === 'used',
        ];
    }

    usort($candidates, static function (array $a, array $b): int {
        if ($a['used_zone'] !== $b['used_zone']) {
            return $b['used_zone'] <=> $a['used_zone'];
        }

        return $a['tank_no'] <=> $b['tank_no'];
    });

    $updates = [];
    foreach (array_slice($candidates, 0, $quantity) as $row) {
        $id = (int) ($row['unit']['id'] ?? 0);
        if ($id > 0) {
            $updates[$id] = 'empty';
        }
    }

    if ($updates !== []) {
        updateGasTankUnitsBatch($updates, $userId, false);
    }
}

/** Physical tank unit rows for one gas_entries row. */
function gasTankUnitsForEntry(array $entry): array
{
    $entryType = (string) ($entry['entry_type'] ?? 'usage');

    if ($entryType === 'status_update') {
        $tankUnitId = (int) ($entry['tank_unit_id'] ?? 0);
        if ($tankUnitId < 1) {
            return [];
        }
        $unit = gasTankUnitById($tankUnitId);

        return $unit ? [$unit] : [];
    }

    if ($entryType === 'adding') {
        return gasTankUnitsForAddingEntry($entry);
    }

    $tankNos = gasTankUsedNosForUsageEntry($entry);
    if ($tankNos === []) {
        return [];
    }

    $gasType = (string) ($entry['gas_type'] ?? '');
    $noLookup = array_flip($tankNos);
    $units = [];

    foreach (loadGasTankUnitsFlat() as $unit) {
        if ((string) ($unit['gas_type'] ?? '') !== $gasType) {
            continue;
        }
        $tankNo = (int) ($unit['tank_no'] ?? 0);
        if (isset($noLookup[$tankNo])) {
            $units[] = $unit;
        }
    }

    usort($units, static fn (array $a, array $b): int => ((int) ($a['tank_no'] ?? 0)) <=> ((int) ($b['tank_no'] ?? 0)));

    return $units;
}

/** Running stock per gas type before a date (all adding − all usage). */
function gasTypeStockBalancesBefore(string $beforeDate): array
{
    $balances = [];
    foreach (array_keys(gasTypes()) as $gasType) {
        $balances[$gasType] = 0;
    }

    $stmt = db()->prepare(
        'SELECT gas_type, entry_type, COALESCE(SUM(quantity), 0) AS total
         FROM gas_entries
         WHERE record_date < :before_date
         GROUP BY gas_type, entry_type'
    );
    $stmt->execute([':before_date' => $beforeDate]);

    foreach ($stmt->fetchAll() as $row) {
        $gasType = (string) $row['gas_type'];
        $entryType = (string) $row['entry_type'];
        if (!isset($balances[$gasType])) {
            continue;
        }
        $total = (int) $row['total'];
        if ($entryType === 'adding') {
            $balances[$gasType] += $total;
        } elseif ($entryType === 'usage') {
            $balances[$gasType] -= $total;
        }
    }

    return $balances;
}

function gasMonthSummaryWithRemaining(string $yearMonth): array
{
    $summary = gasMonthSummaryByType($yearMonth);
    $opening = gasOpeningBalanceByType($yearMonth);

    foreach (array_keys(gasTypes()) as $gasType) {
        $used = (int) ($summary[$gasType]['usage'] ?? 0);
        $added = (int) ($summary[$gasType]['adding'] ?? 0);
        $open = (int) ($opening[$gasType] ?? 0);
        $summary[$gasType]['opening'] = $open;
        $summary[$gasType]['remaining'] = $open + $added - $used;
    }

    return $summary;
}

/**
 * Dashboard monthly tank counts from gas_entries (not Excel).
 * เต็ม = Remain ท้ายเดือน, ว่าง = Usage ในเดือน, ถังทั้งหมด = เต็ม + ว่าง.
 */
function gasDashboardMonthSummary(string $yearMonth): array
{
    $summary = gasMonthSummaryWithRemaining($yearMonth);

    foreach (array_keys(gasTypes()) as $gasType) {
        $remaining = max(0, (int) ($summary[$gasType]['remaining'] ?? 0));
        $usage = (int) ($summary[$gasType]['usage'] ?? 0);
        $summary[$gasType]['full'] = $remaining;
        $summary[$gasType]['used'] = $usage;
        $summary[$gasType]['total'] = $remaining + $usage;
    }

    return $summary;
}

function gasDashboardMonthTotals(string $yearMonth): array
{
    $summary = gasDashboardMonthSummary($yearMonth);
    $totals = ['total_tanks' => 0, 'full_tanks' => 0, 'empty_tanks' => 0];

    foreach ($summary as $row) {
        $totals['total_tanks'] += (int) ($row['total'] ?? 0);
        $totals['full_tanks'] += (int) ($row['full'] ?? 0);
        $totals['empty_tanks'] += (int) ($row['used'] ?? 0);
    }

    return $totals;
}

function gasTypes(): array
{
    return [
        'o2_96' => ['label' => 'O2 (96%)', 'sub_types' => ['O2', 'TGA701', 'SC632', 'AC500']],
        'n2' => ['label' => 'N2', 'sub_types' => ['N2', 'TGA701']],
        'o2_100' => ['label' => 'O2 (100%)', 'sub_types' => ['O2', 'CHN']],
        'helium' => ['label' => 'Helium', 'sub_types' => ['Helium', 'CHN']],
    ];
}

function gasSubTypes(string $gasType): array
{
    return gasTypes()[$gasType]['sub_types'] ?? [];
}

/** Equipment rows for Usage entries (excludes gas-name row used for Adding). */
function gasUsageSubTypes(string $gasType): array
{
    $subs = gasSubTypes($gasType);
    if (count($subs) <= 1) {
        return $subs;
    }

    return array_slice($subs, 1);
}

/** @alias gasUsageSubTypes */
function gasYearlySummarySubTypes(string $gasType): array
{
    return gasUsageSubTypes($gasType);
}

function gasAddingSubType(string $gasType): string
{
    $subs = gasSubTypes($gasType);
    return $subs[0] ?? gasTypeLabel($gasType);
}

function gasIsValidSubTypeForEntry(string $gasType, string $subType, string $entryType): bool
{
    if ($entryType === 'status_update') {
        return $subType !== '';
    }
    if ($entryType === 'adding') {
        return $subType === gasAddingSubType($gasType);
    }

    return in_array($subType, gasUsageSubTypes($gasType), true);
}

function gasFormChoicesJson(): string
{
    $usage = [];
    foreach (gasTypes() as $key => $meta) {
        $usage[$key] = gasUsageSubTypes($key);
    }

    $adding = [];
    foreach (gasTypes() as $key => $meta) {
        $adding[] = [
            'gas_type' => $key,
            'label' => $meta['label'],
            'sub_type' => gasAddingSubType($key),
        ];
    }

    return json_encode(['usage' => $usage, 'adding' => $adding], JSON_UNESCAPED_UNICODE);
}

function gasSubTypesJson(): string
{
    $map = [];
    foreach (gasTypes() as $key => $meta) {
        $map[$key] = $meta['sub_types'];
    }
    return json_encode($map, JSON_UNESCAPED_UNICODE);
}

function gasTypeLabel(string $gasType): string
{
    return gasTypes()[$gasType]['label'] ?? $gasType;
}

function gasEquipments(string $gasType): array
{
    return gasSubTypes($gasType);
}

function gasMonthLabel(string $yearMonth): string
{
    $dt = DateTime::createFromFormat('Y-m', $yearMonth);
    return $dt ? $dt->format('F Y') : $yearMonth;
}

function gasDaysInMonth(string $yearMonth): int
{
    $dt = DateTime::createFromFormat('Y-m-d', $yearMonth . '-01');
    return $dt ? (int) $dt->format('t') : 31;
}

/** @return list<int> */
function gasSelectableYears(int $selectedYear): array
{
    $current = (int) date('Y');
    $min = 2000;
    $max = max($current + 1, $selectedYear);
    $years = [];
    for ($y = $max; $y >= $min; $y--) {
        $years[] = $y;
    }
    if ($selectedYear < $min || $selectedYear > $max) {
        array_unshift($years, $selectedYear);
        rsort($years);
    }
    return $years;
}

function renderGasYearDropdown(string $selectId, int $year): void
{
    $years = gasSelectableYears($year);
    $menuId = $selectId . '-menu';
    ?>
    <div class="gas-year-dropdown" data-gas-year-dropdown>
      <select id="<?= h($selectId) ?>" name="year" class="gas-year-select" tabindex="-1" aria-hidden="true" onchange="if (this.form) this.form.submit()">
        <?php foreach ($years as $y): ?>
          <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="gas-year-dropdown__trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="<?= h($menuId) ?>">
        <span class="gas-year-dropdown__value"><?= $year ?></span>
        <span class="gas-year-dropdown__chevron" aria-hidden="true"></span>
      </button>
      <ul class="gas-year-dropdown__menu" id="<?= h($menuId) ?>" role="listbox" hidden>
        <?php foreach ($years as $y): ?>
          <li role="presentation">
            <button type="button"
              class="gas-year-dropdown__option<?= $y === $year ? ' is-selected' : '' ?>"
              role="option"
              data-year="<?= $y ?>"
              <?= $y === $year ? ' aria-selected="true"' : '' ?>><?= $y ?></button>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php
}

function gasFormListTypeFromRequest(): string
{
    $type = (string) ($_GET['list_type'] ?? '');
    if ($type !== '' && isset(gasEntryTypes()[$type])) {
        return $type;
    }

    return '';
}

function gasFormListMonthFromRequest(string $currentMonth): string
{
    if (isset($_GET['list_year'], $_GET['list_m'])) {
        $y = (int) $_GET['list_year'];
        $m = (int) $_GET['list_m'];
        if ($y >= 2000 && $y <= 2100 && $m >= 1 && $m <= 12) {
            return sprintf('%04d-%02d', $y, $m);
        }
    }
    if (isset($_GET['list_month'])) {
        $listMonth = (string) $_GET['list_month'];
        if (preg_match('/^\d{4}-\d{2}$/', $listMonth)) {
            return $listMonth;
        }
    }
    return $currentMonth;
}

/** Years shown in Form list filter (recent years only — menu opens downward). */
function gasFormListSelectableYears(int $selectedYear): array
{
    $current = (int) date('Y');
    $min = min($current - 5, $selectedYear);
    if ($min < 2000) {
        $min = 2000;
    }
    $max = max($current + 1, $selectedYear);
    $years = [];
    for ($y = $max; $y >= $min; $y--) {
        $years[] = $y;
    }

    return $years;
}

function renderGasFormListMonthFilter(string $yearMonth): void
{
    $year = (int) substr($yearMonth, 0, 4);
    $month = (int) substr($yearMonth, 5, 2);
    $monthNames = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $years = gasFormListSelectableYears($year);
    ?>
    <div class="gas-form-list-field gas-form-list-field--year">
      <span class="gas-form-list-field-label">ปี</span>
      <div class="gas-form-list-dropdown" data-gas-form-dropdown>
        <select name="list_year" class="gas-form-list-dropdown-native" tabindex="-1" aria-hidden="true" onchange="if (this.form) this.form.submit()">
          <?php foreach ($years as $y): ?>
            <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="gas-form-list-dropdown__trigger" aria-haspopup="listbox" aria-expanded="false">
          <span class="gas-form-list-dropdown__value"><?= $year ?></span>
          <span class="gas-form-list-dropdown__chevron" aria-hidden="true"></span>
        </button>
        <ul class="gas-form-list-dropdown__menu" role="listbox" hidden>
          <?php foreach ($years as $y): ?>
            <li role="presentation">
              <button type="button"
                class="gas-form-list-dropdown__option<?= $y === $year ? ' is-selected' : '' ?>"
                role="option"
                data-value="<?= $y ?>"
                <?= $y === $year ? ' aria-selected="true"' : '' ?>><?= $y ?></button>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <div class="gas-form-list-field gas-form-list-field--month">
      <span class="gas-form-list-field-label">เดือน</span>
      <div class="gas-form-list-dropdown" data-gas-form-dropdown>
        <select name="list_m" class="gas-form-list-dropdown-native" tabindex="-1" aria-hidden="true" onchange="if (this.form) this.form.submit()">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= h($monthNames[$m]) ?></option>
          <?php endfor; ?>
        </select>
        <button type="button" class="gas-form-list-dropdown__trigger" aria-haspopup="listbox" aria-expanded="false">
          <span class="gas-form-list-dropdown__value"><?= h($monthNames[$month]) ?></span>
          <span class="gas-form-list-dropdown__chevron" aria-hidden="true"></span>
        </button>
        <ul class="gas-form-list-dropdown__menu" role="listbox" hidden>
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <li role="presentation">
              <button type="button"
                class="gas-form-list-dropdown__option<?= $m === $month ? ' is-selected' : '' ?>"
                role="option"
                data-value="<?= $m ?>"
                <?= $m === $month ? ' aria-selected="true"' : '' ?>><?= h($monthNames[$m]) ?></button>
            </li>
          <?php endfor; ?>
        </ul>
      </div>
    </div>
    <?php
}

function renderGasFormListTypeFilter(string $selectedType): void
{
    $options = [
        '' => 'ทุกประเภท',
        'adding' => 'Adding',
        'usage' => 'Usage',
        'status_update' => 'Update Status',
    ];
    $label = $options[$selectedType] ?? $options[''];
    ?>
    <div class="gas-form-list-field gas-form-list-field--type">
      <span class="gas-form-list-field-label">ประเภท</span>
      <div class="gas-form-list-dropdown" data-gas-form-dropdown>
        <select name="list_type" class="gas-form-list-dropdown-native" tabindex="-1" aria-hidden="true" onchange="if (this.form) this.form.submit()">
          <?php foreach ($options as $value => $text): ?>
            <option value="<?= h($value) ?>" <?= $value === $selectedType ? 'selected' : '' ?>><?= h($text) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="gas-form-list-dropdown__trigger" aria-haspopup="listbox" aria-expanded="false">
          <span class="gas-form-list-dropdown__value"><?= h($label) ?></span>
          <span class="gas-form-list-dropdown__chevron" aria-hidden="true"></span>
        </button>
        <ul class="gas-form-list-dropdown__menu" role="listbox" hidden>
          <?php foreach ($options as $value => $text): ?>
            <li role="presentation">
              <button type="button"
                class="gas-form-list-dropdown__option<?= $value === $selectedType ? ' is-selected' : '' ?>"
                role="option"
                data-value="<?= h($value) ?>"
                <?= $value === $selectedType ? ' aria-selected="true"' : '' ?>><?= h($text) ?></button>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php
}

function gasFormListRedirectParams(array $source = []): array
{
    $params = [
        'list_month' => (string) ($source['list_month'] ?? gasFormListMonthFromRequest(date('Y-m'))),
        'list_page' => max(1, (int) ($source['list_page'] ?? 1)),
    ];
    $type = (string) ($source['list_type'] ?? '');
    if ($type !== '' && isset(gasEntryTypes()[$type])) {
        $params['list_type'] = $type;
    }

    return $params;
}

function gasFormTabQuery(array $params = []): string
{
    $parts = ['tab=form'];
    $listMonth = (string) ($params['list_month'] ?? '');
    $currentMonth = date('Y-m');
    if ($listMonth !== '' && preg_match('/^\d{4}-\d{2}$/', $listMonth) && $listMonth !== $currentMonth) {
        $parts[] = 'list_year=' . (int) substr($listMonth, 0, 4);
        $parts[] = 'list_m=' . (int) substr($listMonth, 5, 2);
    }
    $listPage = (int) ($params['list_page'] ?? 1);
    if ($listPage > 1) {
        $parts[] = 'list_page=' . $listPage;
    }
    $listType = (string) ($params['list_type'] ?? '');
    if ($listType !== '' && isset(gasEntryTypes()[$listType])) {
        $parts[] = 'list_type=' . rawurlencode($listType);
    }
    if (!empty($params['edit'])) {
        $parts[] = 'edit=' . (int) $params['edit'];
    }
    if (isset($params['new']) && $params['new'] === '1') {
        $parts[] = 'new=1';
    }
    return implode('&', $parts);
}

function gasFormListAnchor(): string
{
    return 'gasRecentCard';
}

function gasFormTabUrl(array $params = []): string
{
    return 'gas_consumption.php?' . gasFormTabQuery($params) . '#' . gasFormListAnchor();
}

function getOrCreateMonthlyRecord(string $yearMonth, string $gasType, ?int $userId = null): array
{
    $stmt = db()->prepare('SELECT * FROM gas_monthly_records WHERE year_month = :year_month AND gas_type = :gas_type');
    $stmt->execute([':year_month' => $yearMonth, ':gas_type' => $gasType]);
    $row = $stmt->fetch();

    if ($row) {
        return $row;
    }

    db()->prepare(
        'INSERT INTO gas_monthly_records (year_month, gas_type, opening_remain, po_order, po_received, created_by, created_at, updated_at)
         VALUES (:year_month, :gas_type, 0, 0, 0, :created_by, :created_at, :updated_at)'
    )->execute([
        ':year_month' => $yearMonth,
        ':gas_type' => $gasType,
        ':created_by' => $userId,
        ':created_at' => nowIso(),
        ':updated_at' => nowIso(),
    ]);

    $stmt->execute([':year_month' => $yearMonth, ':gas_type' => $gasType]);
    return $stmt->fetch() ?: [];
}

function loadMonthlyGasData(string $yearMonth): array
{
    $result = [];

    foreach (array_keys(gasTypes()) as $gasType) {
        $record = getOrCreateMonthlyRecord($yearMonth, $gasType);
        $monthlyId = (int) ($record['id'] ?? 0);

        $addingStmt = db()->prepare('SELECT day_of_month, quantity FROM gas_daily_adding WHERE monthly_id = :monthly_id ORDER BY day_of_month');
        $addingStmt->execute([':monthly_id' => $monthlyId]);
        $adding = [];
        foreach ($addingStmt->fetchAll() as $row) {
            $adding[(int) $row['day_of_month']] = (int) $row['quantity'];
        }

        $usageStmt = db()->prepare('SELECT equipment, day_of_month, quantity FROM gas_daily_usage WHERE monthly_id = :monthly_id');
        $usageStmt->execute([':monthly_id' => $monthlyId]);
        $usage = [];
        foreach ($usageStmt->fetchAll() as $row) {
            $equipment = (string) $row['equipment'];
            $usage[$equipment][(int) $row['day_of_month']] = (int) $row['quantity'];
        }

        $stats = computeGasMonthlyStats($record, $adding, $usage);

        $result[$gasType] = [
            'record' => $record,
            'adding' => $adding,
            'usage' => $usage,
            'stats' => $stats,
        ];
    }

    return $result;
}

function computeGasMonthlyStats(array $record, array $adding, array $usage): array
{
    $opening = (int) ($record['opening_remain'] ?? 0);
    $poOrder = (int) ($record['po_order'] ?? 0);
    $poReceived = (int) ($record['po_received'] ?? 0);

    $totalAdding = array_sum($adding);
    $totalUsage = 0;
    $usageByEquipment = [];

    foreach ($usage as $equipment => $days) {
        $sum = array_sum($days);
        $usageByEquipment[$equipment] = $sum;
        $totalUsage += $sum;
    }

    return [
        'opening_remain' => $opening,
        'total_adding' => $totalAdding,
        'total_usage' => $totalUsage,
        'closing_remain' => $opening + $totalAdding - $totalUsage,
        'po_order' => $poOrder,
        'po_received' => $poReceived,
        'po_remain' => max(0, $poOrder - $poReceived),
        'usage_by_equipment' => $usageByEquipment,
    ];
}

function saveMonthlyGasData(string $yearMonth, array $input, int $userId): void
{
    db()->beginTransaction();

    try {
        foreach (array_keys(gasTypes()) as $gasType) {
            $gasInput = $input[$gasType] ?? [];
            $record = getOrCreateMonthlyRecord($yearMonth, $gasType, $userId);
            $monthlyId = (int) $record['id'];

            db()->prepare(
                'UPDATE gas_monthly_records
                 SET opening_remain = :opening_remain,
                     po_order = :po_order,
                     po_received = :po_received,
                     created_by = :created_by,
                     updated_at = :updated_at
                 WHERE id = :id'
            )->execute([
                ':opening_remain' => (int) ($gasInput['opening_remain'] ?? 0),
                ':po_order' => (int) ($gasInput['po_order'] ?? 0),
                ':po_received' => (int) ($gasInput['po_received'] ?? 0),
                ':created_by' => $userId,
                ':updated_at' => nowIso(),
                ':id' => $monthlyId,
            ]);

            db()->prepare('DELETE FROM gas_daily_adding WHERE monthly_id = :monthly_id')->execute([':monthly_id' => $monthlyId]);
            db()->prepare('DELETE FROM gas_daily_usage WHERE monthly_id = :monthly_id')->execute([':monthly_id' => $monthlyId]);

            $addingInsert = db()->prepare(
                'INSERT INTO gas_daily_adding (monthly_id, day_of_month, quantity) VALUES (:monthly_id, :day_of_month, :quantity)'
            );

            $addingRows = $gasInput['adding_rows'] ?? [];
            if (is_array($addingRows)) {
                foreach ($addingRows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $day = (int) ($row['day'] ?? 0);
                    $qty = (int) ($row['qty'] ?? 0);
                    if ($day >= 1 && $day <= 31 && $qty > 0) {
                        $addingInsert->execute([
                            ':monthly_id' => $monthlyId,
                            ':day_of_month' => $day,
                            ':quantity' => $qty,
                        ]);
                    }
                }
            }

            $usageInsert = db()->prepare(
                'INSERT INTO gas_daily_usage (monthly_id, equipment, day_of_month, quantity)
                 VALUES (:monthly_id, :equipment, :day_of_month, :quantity)'
            );
            foreach (gasEquipments($gasType) as $equipment) {
                $equipmentDays = $gasInput['usage'][$equipment] ?? [];
                if (!is_array($equipmentDays)) {
                    continue;
                }
                foreach ($equipmentDays as $day => $qty) {
                    $day = (int) $day;
                    $qty = (int) $qty;
                    if ($day >= 1 && $day <= 31 && $qty > 0) {
                        $usageInsert->execute([
                            ':monthly_id' => $monthlyId,
                            ':equipment' => $equipment,
                            ':day_of_month' => $day,
                            ':quantity' => $qty,
                        ]);
                    }
                }
            }
        }

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function loadTankInventory(): array
{
    $rows = db()->query('SELECT * FROM gas_tank_inventory ORDER BY id ASC')->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[(string) $row['gas_type']] = $row;
    }
    return $result;
}

function saveTankInventory(array $input, int $userId, array $machinesInput = []): int
{
    return saveGasTankUnits($input, $userId, $machinesInput);
}

function gasTankStatuses(): array
{
    return [
        'in_use' => 'กำลังทำงาน',
        'full' => 'เต็ม',
        'empty' => 'หมดแล้ว',
        'damaged' => 'ชำรุด',
    ];
}

/** เรียงแสดง: กำลังทำงาน → ชำรุด → เต็ม → หมดแล้ว */
function gasSortTankUnitsForDisplay(array $units): array
{
    $rank = ['in_use' => 0, 'damaged' => 1, 'full' => 2, 'empty' => 3];
    usort($units, static function (array $a, array $b) use ($rank): int {
        $sa = (string) ($a['status'] ?? 'empty');
        $sb = (string) ($b['status'] ?? 'empty');
        $oa = $rank[$sa] ?? 9;
        $ob = $rank[$sb] ?? 9;
        if ($oa !== $ob) {
            return $oa <=> $ob;
        }

        return ((int) ($a['tank_no'] ?? 0)) <=> ((int) ($b['tank_no'] ?? 0));
    });

    return $units;
}

function gasTankSlotRole(int $tankNo, int $fullCount, int $usedCount, ?string $unitStatus = null): string
{
    if ($unitStatus === 'empty' && $usedCount > 0) {
        return 'used';
    }
    if ($unitStatus !== null && in_array($unitStatus, ['full', 'in_use', 'damaged', 'empty'], true)) {
        return 'stock';
    }

    if ($tankNo <= $fullCount) {
        return 'stock';
    }
    if ($tankNo <= $fullCount + $usedCount) {
        return 'used';
    }

    return 'stock';
}

/** ถังที่บัญชีระบุว่าใช้ไปแล้ว — ไม่แก้สถานะบน Dashboard */
function gasTankIsDashboardLocked(int $tankNo, int $fullCount, int $usedCount, string $status): bool
{
    return gasTankSlotRole($tankNo, $fullCount, $usedCount, $status) === 'used';
}

/** สถานะที่บัญชีกำหนด — ช่องคลัง (Remain) ต้องเป็นเต็ม/in_use/ชำรุด, ช่องใช้ไปแล้วต้องหมดแล้ว */
function gasTankExpectedStatusFromAccounting(int $tankNo, int $fullCount, int $usedCount, string $currentStatus): string
{
    if (gasTankSlotRole($tankNo, $fullCount, $usedCount, $currentStatus) === 'used') {
        if ($currentStatus === 'damaged') {
            return 'damaged';
        }

        return 'empty';
    }

    if (in_array($currentStatus, ['in_use', 'damaged'], true)) {
        return $currentStatus;
    }

    return 'full';
}

/** สถานะที่แสดงบน Dashboard — ช่องใช้ไปแล้วไม่แสดงกำลังทำงาน */
function gasTankDisplayStatus(int $tankNo, int $fullCount, int $usedCount, string $status): string
{
    if ($status === 'in_use' && gasTankSlotRole($tankNo, $fullCount, $usedCount, $status) === 'used') {
        return 'empty';
    }

    return $status;
}

/** สถานะที่เลือกได้บน Dashboard ตามสถานะปัจจุบัน */
function gasTankDashboardSelectableStatusesFor(string $currentStatus): array
{
    if ($currentStatus === 'full') {
        return ['in_use', 'damaged'];
    }
    if ($currentStatus === 'in_use') {
        return ['empty', 'damaged'];
    }
    if ($currentStatus === 'damaged') {
        return ['empty'];
    }

    return [];
}

/** @deprecated use gasTankDashboardSelectableStatusesFor() */
function gasTankDashboardSelectableStatuses(): array
{
    return ['in_use', 'damaged'];
}

function gasSanitizeDashboardTankStatus(
    int $tankNo,
    int $fullCount,
    int $usedCount,
    string $currentStatus,
    string $requestedStatus
): string {
    if (gasTankSlotRole($tankNo, $fullCount, $usedCount, $currentStatus) === 'used') {
        return 'empty';
    }

    if ($requestedStatus === $currentStatus) {
        return $currentStatus;
    }

    $allowed = gasTankDashboardSelectableStatusesFor($currentStatus);
    if (in_array($requestedStatus, $allowed, true)) {
        return $requestedStatus;
    }

    return $currentStatus;
}

function gasTankShortLabel(string $gasType): string
{
    return [
        'o2_96' => 'O2',
        'n2' => 'N2',
        'o2_100' => 'O2 100%',
        'helium' => 'He',
    ][$gasType] ?? gasTypeLabel($gasType);
}

function gasTankTargetCountForType(string $gasType, string $yearMonth): int
{
    $summary = gasDashboardMonthSummary($yearMonth);

    return (int) ($summary[$gasType]['total'] ?? 0);
}

function gasRefreshTankInventoryFromEntries(?PDO $pdo = null, ?string $yearMonth = null): void
{
    $pdo = $pdo ?? db();
    $yearMonth = $yearMonth ?? date('Y-m');
    $summary = gasDashboardMonthSummary($yearMonth);

    foreach (array_keys(gasTypes()) as $gasType) {
        gasCleanupOrphanDashboardAutoUsage($gasType, $yearMonth);
    }

    $summary = gasDashboardMonthSummary($yearMonth);

    $updateAll = $pdo->prepare(
        'UPDATE gas_tank_inventory
         SET total_tanks = :total_tanks,
             full_tanks = :full_tanks,
             empty_tanks = :empty_tanks,
             remain_stock = :remain_stock,
             updated_at = :updated_at
         WHERE gas_type = :gas_type'
    );

    foreach (array_keys(gasTypes()) as $gasType) {
        $full = (int) ($summary[$gasType]['full'] ?? 0);
        $used = (int) ($summary[$gasType]['used'] ?? 0);
        $total = (int) ($summary[$gasType]['total'] ?? 0);
        $remaining = (int) ($summary[$gasType]['remaining'] ?? 0);

        $updateAll->execute([
            ':gas_type' => $gasType,
            ':total_tanks' => $total,
            ':full_tanks' => $full,
            ':empty_tanks' => $used,
            ':remain_stock' => $remaining,
            ':updated_at' => nowIso(),
        ]);
    }

    ensureGasTankUnits($pdo);
    gasSyncTankStatusesFromAccounting($pdo, $yearMonth);
}

/** สถานะถังจากประวัติ Update Status ที่เหลือ (ไม่มีแถว = เต็ม) */
function gasTankStatusFromRemainingHistory(int $tankUnitId, string $default = 'full'): string
{
    if ($tankUnitId < 1) {
        return $default;
    }

    $entryId = gasLatestStatusUpdateEntryId($tankUnitId);
    if ($entryId < 1) {
        return $default;
    }

    $entry = getGasEntry($entryId);
    if (!$entry) {
        return $default;
    }

    $status = (string) ($entry['status_after'] ?? $default);

    return in_array($status, array_keys(gasTankStatuses()), true) ? $status : $default;
}

/** tank_unit_id ที่บัญชี Usage ผูกไว้ — ถังที่ต้องเป็นหมดแล้ว */
function gasUsedTankUnitIdsForMonth(string $gasType, string $yearMonth): array
{
    if (!isset(gasTypes()[$gasType])) {
        return [];
    }

    $stmt = db()->prepare(
        'SELECT * FROM gas_entries
         WHERE gas_type = :gas_type
           AND entry_type = :entry_type
           AND record_date LIKE :prefix
         ORDER BY record_date ASC, id ASC'
    );
    $stmt->execute([
        ':gas_type' => $gasType,
        ':entry_type' => 'usage',
        ':prefix' => $yearMonth . '%',
    ]);

    $unitIds = [];
    $unitsByNo = [];
    foreach (loadGasTankUnits()[$gasType] ?? [] as $unit) {
        $unitsByNo[(int) ($unit['tank_no'] ?? 0)] = (int) ($unit['id'] ?? 0);
    }

    foreach ($stmt->fetchAll() as $row) {
        $quantity = max(0, (int) ($row['quantity'] ?? 0));
        if ($quantity < 1) {
            continue;
        }

        $tankUnitId = (int) ($row['tank_unit_id'] ?? 0);
        if ($tankUnitId > 0) {
            for ($i = 0; $i < $quantity; $i++) {
                $unitIds[] = $tankUnitId;
            }
            continue;
        }

        foreach (gasTankUsedNosForUsageEntry($row) as $tankNo) {
            $mappedId = $unitsByNo[(int) $tankNo] ?? 0;
            if ($mappedId > 0) {
                $unitIds[] = $mappedId;
            }
        }
    }

    return array_values(array_unique($unitIds));
}

/** ลบ Usage อัตโนมัติจาก Dashboard ที่ไม่มี Update Status รองรับแล้ว */
function gasCleanupOrphanDashboardAutoUsage(string $gasType, string $yearMonth): int
{
    if (!isset(gasTypes()[$gasType])) {
        return 0;
    }

    $stmt = db()->prepare(
        'SELECT id, tank_unit_id, note, entry_type
         FROM gas_entries
         WHERE gas_type = :gas_type
           AND entry_type = :entry_type
           AND record_date LIKE :prefix'
    );
    $stmt->execute([
        ':gas_type' => $gasType,
        ':entry_type' => 'usage',
        ':prefix' => $yearMonth . '%',
    ]);

    $deleted = 0;
    foreach ($stmt->fetchAll() as $row) {
        if (!gasEntryIsDashboardAutoUsage($row)) {
            continue;
        }

        $tankUnitId = (int) ($row['tank_unit_id'] ?? 0);
        if ($tankUnitId < 1) {
            continue;
        }

        $latestId = gasLatestStatusUpdateEntryId($tankUnitId);
        if ($latestId < 1) {
            if (gasDeleteGasEntryRow((int) ($row['id'] ?? 0))) {
                $deleted++;
            }
            continue;
        }

        $latest = getGasEntry($latestId);
        if ($latest && (string) ($latest['status_after'] ?? '') !== 'empty') {
            if (gasDeleteGasEntryRow((int) ($row['id'] ?? 0))) {
                $deleted++;
            }
        }
    }

    return $deleted;
}

/** คืนสถานะถังหลังลบรายการ — sync จะจัดให้ตรงบัญชีอีกครั้ง */
function gasRevertTankStatusAfterEntryDelete(array $entry): void
{
    $tankUnitId = (int) ($entry['tank_unit_id'] ?? 0);
    $entryType = (string) ($entry['entry_type'] ?? '');
    $gasType = (string) ($entry['gas_type'] ?? '');
    $recordDate = (string) ($entry['record_date'] ?? '');
    $month = substr($recordDate, 0, 7);

    if ($tankUnitId < 1 || !isset(gasTypes()[$gasType])) {
        return;
    }

    if ($entryType === 'status_update') {
        if ((string) ($entry['status_after'] ?? '') === 'empty') {
            gasDeleteAutoUsageForTankEmptied($gasType, $tankUnitId, $month);
        }
    } elseif ($entryType === 'usage' && !gasEntryIsDashboardAutoUsage($entry)) {
        // Manual Usage row removed — tank status follows remaining history.
    } else {
        return;
    }

    $status = gasTankStatusFromRemainingHistory($tankUnitId, 'full');
    if ($status === 'empty') {
        $status = 'full';
    }

    updateGasTankUnitsBatch([$tankUnitId => $status], 1, false);
}

/** สถานะที่ควรเป็น — ผูกกับ Usage + ประวัติ Update Status */
function gasExpectedTankUnitStatuses(string $gasType, string $yearMonth): array
{
    $summary = gasDashboardMonthSummary($yearMonth)[$gasType] ?? [];
    $usedCount = max(0, (int) ($summary['used'] ?? 0));
    $usedUnitIds = gasUsedTankUnitIdsForMonth($gasType, $yearMonth);

    if ($usedCount > 0 && count($usedUnitIds) < $usedCount) {
        $fullCount = max(0, (int) ($summary['full'] ?? 0));
        $unitsByNo = [];
        foreach (loadGasTankUnits()[$gasType] ?? [] as $unit) {
            $unitsByNo[(int) ($unit['tank_no'] ?? 0)] = (int) ($unit['id'] ?? 0);
        }
        $usedEnd = $fullCount + $usedCount;
        for ($tankNo = $fullCount + 1; $tankNo <= $usedEnd; $tankNo++) {
            $mappedId = $unitsByNo[$tankNo] ?? 0;
            if ($mappedId > 0 && !in_array($mappedId, $usedUnitIds, true)) {
                $usedUnitIds[] = $mappedId;
            }
        }
    }

    if ($usedCount > 0 && count($usedUnitIds) > $usedCount) {
        $usedUnitIds = array_slice($usedUnitIds, 0, $usedCount);
    }

    $expected = [];
    foreach (loadGasTankUnits()[$gasType] ?? [] as $unit) {
        $unitId = (int) ($unit['id'] ?? 0);
        if ($unitId < 1) {
            continue;
        }

        if ($usedCount > 0 && in_array($unitId, $usedUnitIds, true)) {
            $expected[$unitId] = 'empty';
            continue;
        }

        $status = gasTankStatusFromRemainingHistory($unitId, 'full');
        if ($status === 'empty') {
            $status = 'full';
        }
        $expected[$unitId] = $status;
    }

    return $expected;
}

function gasStatusForTankNo(int $tankNo, int $fullCount, int $usedCount): string
{
    // ถังเต็ม (Remain) อยู่ลำดับหน้า → เต็ม, ถังที่ใช้ไปแล้วอยู่หลัง → หมดแล้ว
    if ($tankNo <= $fullCount) {
        return 'full';
    }
    if ($tankNo <= $fullCount + $usedCount) {
        return 'empty';
    }

    return 'empty';
}

/** จัดสถานะถังจาก Remain (เต็ม) + Usage (ว่าง) — ผูกถังกับรายการ Usage จริง */
function gasSyncTankStatusesFromAccounting(PDO $pdo, ?string $yearMonth = null): void
{
    $yearMonth = $yearMonth ?? date('Y-m');

    $unitsStmt = $pdo->query(
        'SELECT id, gas_type, tank_no, status FROM gas_tank_units ORDER BY gas_type ASC, tank_no ASC'
    );
    $unitsByType = [];
    foreach ($unitsStmt->fetchAll() as $row) {
        $unitsByType[(string) $row['gas_type']][] = $row;
    }

    $update = $pdo->prepare(
        'UPDATE gas_tank_units
         SET status = :status, updated_at = :updated_at
         WHERE gas_type = :gas_type AND tank_no = :tank_no'
    );

    foreach (array_keys(gasTypes()) as $gasType) {
        $expectedById = gasExpectedTankUnitStatuses($gasType, $yearMonth);

        foreach ($unitsByType[$gasType] ?? [] as $unit) {
            $tankNo = (int) ($unit['tank_no'] ?? 0);
            $unitId = (int) ($unit['id'] ?? 0);
            if ($tankNo < 1 || $unitId < 1) {
                continue;
            }

            $current = (string) ($unit['status'] ?? 'empty');
            $expected = $expectedById[$unitId] ?? 'full';
            if ($current === $expected) {
                continue;
            }

            $update->execute([
                ':gas_type' => $gasType,
                ':tank_no' => $tankNo,
                ':status' => $expected,
                ':updated_at' => nowIso(),
            ]);
        }
    }
}

function renderGasTankIcon(string $shortLabel): string
{
    $label = htmlspecialchars($shortLabel, ENT_QUOTES, 'UTF-8');

    return '<svg class="gas-tank-icon" viewBox="0 0 64 96" aria-hidden="true" focusable="false">'
        . '<rect class="gas-tank-icon-valve" x="27" y="5" width="10" height="11" rx="2.5"/>'
        . '<rect class="gas-tank-icon-stem" x="30" y="14" width="4" height="6" rx="1"/>'
        . '<ellipse class="gas-tank-icon-cap" cx="32" cy="24" rx="19" ry="7.5"/>'
        . '<rect class="gas-tank-icon-shell" x="13" y="24" width="38" height="58" rx="9"/>'
        . '<rect class="gas-tank-icon-fill" x="15" y="26" width="34" height="54" rx="7"/>'
        . '<ellipse class="gas-tank-icon-base" cx="32" cy="82" rx="19" ry="7.5"/>'
        . '<text class="gas-tank-icon-label" x="32" y="56" text-anchor="middle">' . $label . '</text>'
        . '</svg>';
}

function gasTankSlotsForType(string $gasType, array $inventoryRow): int
{
    $total = (int) ($inventoryRow['total_tanks'] ?? 0);
    if ($total > 0) {
        return $total;
    }

    $statusSum = (int) ($inventoryRow['full_tanks'] ?? 0)
        + (int) ($inventoryRow['empty_tanks'] ?? 0)
        + (int) ($inventoryRow['in_use_tanks'] ?? 0);
    if ($statusSum > 0) {
        return $statusSum;
    }

    return (int) ($inventoryRow['remain_stock'] ?? 0);
}

function gasNormalizeTankInventoryTotals(PDO $pdo): void
{
    $rows = $pdo->query('SELECT * FROM gas_tank_inventory')->fetchAll();
    $update = $pdo->prepare(
        'UPDATE gas_tank_inventory
         SET total_tanks = :total_tanks, updated_at = :updated_at
         WHERE gas_type = :gas_type AND total_tanks = 0'
    );

    foreach ($rows as $row) {
        if ((int) ($row['total_tanks'] ?? 0) > 0) {
            continue;
        }

        $derived = gasTankSlotsForType((string) $row['gas_type'], $row);
        if ($derived <= 0) {
            continue;
        }

        $update->execute([
            ':gas_type' => (string) $row['gas_type'],
            ':total_tanks' => $derived,
            ':updated_at' => nowIso(),
        ]);
    }
}

function ensureGasTankUnits(PDO $pdo): void
{
    gasNormalizeTankInventoryTotals($pdo);

    $inventoryRows = $pdo->query('SELECT * FROM gas_tank_inventory ORDER BY id ASC')->fetchAll();
    $inventory = [];
    foreach ($inventoryRows as $row) {
        $inventory[(string) $row['gas_type']] = $row;
    }

    $insert = $pdo->prepare(
        'INSERT INTO gas_tank_units (gas_type, tank_no, status, updated_at)
         VALUES (:gas_type, :tank_no, :status, :updated_at)'
    );
    $deleteExtra = $pdo->prepare(
        'DELETE FROM gas_tank_units WHERE gas_type = :gas_type AND tank_no > :tank_no'
    );
    $deleteAll = $pdo->prepare('DELETE FROM gas_tank_units WHERE gas_type = :gas_type');

    foreach (array_keys(gasTypes()) as $gasType) {
        $inv = $inventory[$gasType] ?? [];
        $targetCount = gasTankSlotsForType($gasType, $inv);

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM gas_tank_units WHERE gas_type = :gas_type');
        $countStmt->execute([':gas_type' => $gasType]);
        $existingCount = (int) $countStmt->fetchColumn();

        if ($targetCount <= 0) {
            if ($existingCount > 0) {
                $deleteAll->execute([':gas_type' => $gasType]);
            }
            continue;
        }

        if ($existingCount === 0) {
            $fullCount = max(0, (int) ($inv['full_tanks'] ?? 0));
            $usedCount = max(0, (int) ($inv['empty_tanks'] ?? 0));
            for ($tankNo = 1; $tankNo <= $targetCount; $tankNo++) {
                $insert->execute([
                    ':gas_type' => $gasType,
                    ':tank_no' => $tankNo,
                    ':status' => gasStatusForTankNo($tankNo, $fullCount, $usedCount),
                    ':updated_at' => nowIso(),
                ]);
            }
            continue;
        }

        if ($existingCount > $targetCount) {
            $deleteExtra->execute([
                ':gas_type' => $gasType,
                ':tank_no' => $targetCount,
            ]);
        } elseif ($existingCount < $targetCount) {
            $fullCount = max(0, (int) ($inv['full_tanks'] ?? 0));
            $usedCount = max(0, (int) ($inv['empty_tanks'] ?? 0));
            for ($tankNo = $existingCount + 1; $tankNo <= $targetCount; $tankNo++) {
                $insert->execute([
                    ':gas_type' => $gasType,
                    ':tank_no' => $tankNo,
                    ':status' => gasStatusForTankNo($tankNo, $fullCount, $usedCount),
                    ':updated_at' => nowIso(),
                ]);
            }
        }
    }
}

function loadGasTankUnits(): array
{
    $rows = db()->query('SELECT * FROM gas_tank_units ORDER BY gas_type ASC, tank_no ASC')->fetchAll();
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(string) $row['gas_type']][] = $row;
    }
    return $grouped;
}

function loadGasTankUnitsFlat(): array
{
    return db()->query('SELECT * FROM gas_tank_units ORDER BY gas_type ASC, tank_no ASC')->fetchAll();
}

function updateGasTankUnitStatus(int $id, string $status, int $userId): bool
{
    return updateGasTankUnitsBatch([$id => $status], $userId) > 0;
}

/** @param array<int|string, string> $updates tank_unit_id => status */
/** @param array<int|string, string> $machinesInput tank_unit_id => machine_code */
function updateGasTankUnitsBatch(array $updates, int $userId, bool $applyAccounting = true, array $machinesInput = []): int
{
    $validStatuses = array_keys(gasTankStatuses());
    $stmt = db()->prepare(
        'UPDATE gas_tank_units SET status = :status, machine_code = :machine_code, updated_at = :updated_at WHERE id = :id'
    );
    $updated = 0;
    $now = nowIso();

    foreach ($updates as $id => $status) {
        $id = (int) $id;
        $status = (string) $status;
        if ($id < 1 || !in_array($status, $validStatuses, true)) {
            continue;
        }

        $unit = gasTankUnitById($id);
        if (!$unit) {
            continue;
        }

        $gasType = (string) ($unit['gas_type'] ?? '');
        $oldStatus = (string) ($unit['status'] ?? 'empty');
        if ($oldStatus === $status) {
            continue;
        }

        $existingMachine = trim((string) ($unit['machine_code'] ?? ''));
        $requestedMachine = trim((string) ($machinesInput[$id] ?? $machinesInput[(string) $id] ?? ''));
        $machineForUnit = $existingMachine;

        if ($status === 'in_use') {
            if ($requestedMachine !== '' && gasIsValidSubTypeForEntry($gasType, $requestedMachine, 'usage')) {
                $machineForUnit = $requestedMachine;
            } elseif ($machineForUnit === '') {
                $usageMachines = gasUsageSubTypes($gasType);
                if (count($usageMachines) === 1) {
                    $machineForUnit = $usageMachines[0];
                }
            }
        } elseif ($status === 'full') {
            $machineForUnit = '';
        }

        if ($applyAccounting) {
            gasReverseUsageIfTankRestored(
                $gasType,
                (int) ($unit['tank_no'] ?? 0),
                $oldStatus,
                $status,
                date('Y-m-d'),
                $userId,
                $id
            );
            gasRecordUsageIfTankEmptied(
                $gasType,
                (int) ($unit['tank_no'] ?? 0),
                $oldStatus,
                $status,
                date('Y-m-d'),
                $userId,
                $id,
                $machineForUnit !== '' ? $machineForUnit : $existingMachine
            );
        }

        $stmt->execute([
            ':id' => $id,
            ':status' => $status,
            ':machine_code' => $machineForUnit !== '' ? $machineForUnit : null,
            ':updated_at' => $now,
        ]);
        $updated++;
    }

    if ($updated > 0) {
        syncTankInventoryFromUnits($userId);
    }

    return $updated;
}

function renderGasDashboardGroupActions(string $gasKey): void
{
    if (!isset(gasTypes()[$gasKey])) {
        return;
    }

    $gasMeta = gasTypes()[$gasKey];
    ?>
    <div class="gas-tank-group-actions" data-gas-type="<?= h($gasKey) ?>">
      <button
        type="button"
        class="gas-dash-entry-btn gas-dash-entry-btn--usage"
        data-gas-type="<?= h($gasKey) ?>"
        data-gas-label="<?= h($gasMeta['label']) ?>"
        data-entry-type="usage"
        title="Usage (−) — <?= h($gasMeta['label']) ?>"
        aria-label="บันทึก Usage <?= h($gasMeta['label']) ?>"
      >−</button>
      <button
        type="button"
        class="gas-dash-entry-btn gas-dash-entry-btn--adding"
        data-gas-type="<?= h($gasKey) ?>"
        data-gas-label="<?= h($gasMeta['label']) ?>"
        data-entry-type="adding"
        title="Adding (+) — <?= h($gasMeta['label']) ?>"
        aria-label="บันทึก Adding <?= h($gasMeta['label']) ?>"
      >+</button>
    </div>
    <?php
}

/** @deprecated use gasTankDashboardMachineLabel */
function gasTankMachineLabel(string $gasType, int $tankNo): string
{
    $machines = gasUsageSubTypes($gasType);
    if ($machines === [] || $tankNo < 1) {
        return '';
    }

    $index = $tankNo - 1;

    return $machines[$index] ?? '';
}

function gasTankLastUsageMachineForUnit(int $tankUnitId): string
{
    if ($tankUnitId < 1) {
        return '';
    }

    static $cache = [];
    if (array_key_exists($tankUnitId, $cache)) {
        return $cache[$tankUnitId];
    }

    $stmt = db()->prepare(
        'SELECT sub_type FROM gas_entries
         WHERE entry_type = :entry_type AND tank_unit_id = :tank_unit_id
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':entry_type' => 'usage',
        ':tank_unit_id' => $tankUnitId,
    ]);
    $row = $stmt->fetch();
    $cache[$tankUnitId] = trim((string) ($row['sub_type'] ?? ''));

    return $cache[$tankUnitId];
}

/** ชื่อเครื่องบนการ์ด Dashboard — แสดงเฉพาะกำลังทำงาน / ชำรุด */
function gasTankDashboardMachineLabel(array $unit, string $displayStatus): string
{
    if (!in_array($displayStatus, ['in_use', 'damaged'], true)) {
        return '';
    }

    $machine = trim((string) ($unit['machine_code'] ?? ''));
    if ($machine !== '') {
        return $machine;
    }

    if ($displayStatus !== 'damaged') {
        return '';
    }

    return gasTankLastUsageMachineForUnit((int) ($unit['id'] ?? 0));
}

function renderGasDashboardGroupEntry(string $gasKey, string $defaultDate): void
{
    renderGasDashboardGroupActions($gasKey);
}

function saveGasTankUnits(array $input, int $userId, array $machinesInput = []): int
{
    $validStatuses = array_keys(gasTankStatuses());
    $currentUnits = loadGasTankUnits();
    $monthSummary = gasDashboardMonthSummary(date('Y-m'));
    $recordDate = date('Y-m-d');
    $usageAfterCommit = [];
    $stmt = db()->prepare(
        'UPDATE gas_tank_units
         SET status = :status, machine_code = :machine_code, updated_at = :updated_at
         WHERE gas_type = :gas_type AND tank_no = :tank_no'
    );

    db()->beginTransaction();
    try {
        foreach ($input as $gasType => $tanks) {
            if (!isset(gasTypes()[(string) $gasType]) || !is_array($tanks)) {
                continue;
            }
            $gasTypeKey = (string) $gasType;
            $typeSummary = $monthSummary[$gasTypeKey] ?? [];
            $fullCount = (int) ($typeSummary['full'] ?? 0);
            $usedCount = (int) ($typeSummary['used'] ?? 0);
            $existingByNo = [];
            $unitIdByNo = [];
            $machineByNo = [];
            foreach ($currentUnits[$gasTypeKey] ?? [] as $unit) {
                $tankNo = (int) ($unit['tank_no'] ?? 0);
                $existingByNo[$tankNo] = (string) ($unit['status'] ?? 'empty');
                $unitIdByNo[$tankNo] = (int) ($unit['id'] ?? 0);
                $machineByNo[$tankNo] = trim((string) ($unit['machine_code'] ?? ''));
            }

            foreach ($tanks as $tankNo => $status) {
                $tankNo = (int) $tankNo;
                $status = (string) $status;
                if (!in_array($status, $validStatuses, true)) {
                    continue;
                }
                $currentStatus = $existingByNo[$tankNo] ?? 'empty';
                $newStatus = gasSanitizeDashboardTankStatus(
                    $tankNo,
                    $fullCount,
                    $usedCount,
                    $currentStatus,
                    $status
                );
                $existingMachine = $machineByNo[$tankNo] ?? '';
                $requestedMachine = trim((string) ($machinesInput[$gasTypeKey][$tankNo] ?? $machinesInput[$gasType][$tankNo] ?? ''));
                $machineForUnit = $existingMachine;

                if ($newStatus === 'in_use') {
                    if ($requestedMachine !== '' && gasIsValidSubTypeForEntry($gasTypeKey, $requestedMachine, 'usage')) {
                        $machineForUnit = $requestedMachine;
                    } elseif ($existingMachine === '' && $newStatus !== $currentStatus) {
                        continue;
                    }
                } elseif ($newStatus === 'full') {
                    $machineForUnit = '';
                }

                $statusChanged = $newStatus !== $currentStatus;
                $machineChanged = $newStatus === 'in_use' && $machineForUnit !== $existingMachine;
                if (!$statusChanged && !$machineChanged) {
                    continue;
                }

                if ($statusChanged) {
                    $tankUnitId = $unitIdByNo[$tankNo] ?? 0;
                    if ($tankUnitId > 0) {
                        addGasStatusUpdateEntry(
                            $gasTypeKey,
                            $tankUnitId,
                            $currentStatus,
                            $newStatus,
                            $recordDate,
                            $userId
                        );
                    }
                    if (
                        $newStatus === 'empty'
                        && $currentStatus !== 'empty'
                        && gasTankSlotRole($tankNo, $fullCount, $usedCount, $currentStatus) === 'stock'
                    ) {
                        $usageAfterCommit[] = [
                            'gas_type' => $gasTypeKey,
                            'tank_no' => $tankNo,
                            'old_status' => $currentStatus,
                            'tank_unit_id' => $tankUnitId,
                            'machine_code' => $existingMachine !== '' ? $existingMachine : $machineForUnit,
                        ];
                    }
                }
                $stmt->execute([
                    ':gas_type' => $gasTypeKey,
                    ':tank_no' => $tankNo,
                    ':status' => $newStatus,
                    ':machine_code' => $machineForUnit !== '' ? $machineForUnit : null,
                    ':updated_at' => nowIso(),
                ]);
                $existingByNo[$tankNo] = $newStatus;
                $machineByNo[$tankNo] = $machineForUnit;
            }
        }

        syncTankInventoryFromUnits($userId);
        db()->commit();

        foreach ($usageAfterCommit as $usageRow) {
            gasRecordUsageIfTankEmptied(
                $usageRow['gas_type'],
                (int) $usageRow['tank_no'],
                (string) $usageRow['old_status'],
                'empty',
                $recordDate,
                $userId,
                (int) ($usageRow['tank_unit_id'] ?? 0),
                (string) ($usageRow['machine_code'] ?? '')
            );
        }

        return count($usageAfterCommit);
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function syncTankInventoryFromUnits(int $userId): void
{
    $grouped = loadGasTankUnits();
    $entrySummary = gasDashboardMonthSummary(date('Y-m'));
    $stmt = db()->prepare(
        'UPDATE gas_tank_inventory
         SET total_tanks = :total_tanks,
             full_tanks = :full_tanks,
             empty_tanks = :empty_tanks,
             in_use_tanks = :in_use_tanks,
             remain_stock = :remain_stock,
             updated_by = :updated_by,
             updated_at = :updated_at
         WHERE gas_type = :gas_type'
    );

    foreach (array_keys(gasTypes()) as $gasType) {
        $units = $grouped[$gasType] ?? [];
        $inUseCount = 0;
        foreach ($units as $unit) {
            if ((string) ($unit['status'] ?? '') === 'in_use') {
                $inUseCount++;
            }
        }

        // Stock slot counts from gas_entries (Usage / Adding only) — not physical full/damaged.
        $accountFull = max(0, (int) ($entrySummary[$gasType]['full'] ?? 0));
        $accountUsed = max(0, (int) ($entrySummary[$gasType]['used'] ?? 0));
        $accountTotal = max(0, (int) ($entrySummary[$gasType]['total'] ?? 0));

        $stmt->execute([
            ':gas_type' => $gasType,
            ':total_tanks' => $accountTotal,
            ':full_tanks' => $accountFull,
            ':empty_tanks' => $accountUsed,
            ':in_use_tanks' => $inUseCount,
            ':remain_stock' => $accountFull,
            ':updated_by' => $userId,
            ':updated_at' => nowIso(),
        ]);
    }
}

function gasTankBoardCounts(array $groupedUnits): array
{
    $physicalTotals = gasTankTotalsFromUnits($groupedUnits);
    $entrySummary = gasDashboardMonthSummary(date('Y-m'));
    $groups = [];
    $dash = [];
    $accountTotals = [
        'total_tanks' => 0,
        'full_tanks' => 0,
        'empty_tanks' => 0,
        'in_use_tanks' => (int) ($physicalTotals['in_use_tanks'] ?? 0),
        'damaged_tanks' => (int) ($physicalTotals['damaged_tanks'] ?? 0),
    ];

    foreach (array_keys(gasTypes()) as $gasType) {
        $fullCount = max(0, (int) ($entrySummary[$gasType]['full'] ?? 0));
        $usedCount = max(0, (int) ($entrySummary[$gasType]['used'] ?? 0));
        $groups[$gasType] = ['total' => 0, 'full' => 0, 'empty' => 0, 'in_use' => 0, 'damaged' => 0];
        foreach ($groupedUnits[$gasType] ?? [] as $unit) {
            $groups[$gasType]['total']++;
            $tankNo = (int) ($unit['tank_no'] ?? 0);
            $status = gasTankDisplayStatus(
                $tankNo,
                $fullCount,
                $usedCount,
                (string) ($unit['status'] ?? 'empty')
            );
            if (isset($groups[$gasType][$status])) {
                $groups[$gasType][$status]++;
            }
        }

        $dash[$gasType] = [
            'full' => max(0, (int) ($entrySummary[$gasType]['full'] ?? 0)),
            'used' => max(0, (int) ($entrySummary[$gasType]['used'] ?? 0)),
            'total' => max(0, (int) ($entrySummary[$gasType]['total'] ?? 0)),
        ];
        $accountTotals['total_tanks'] += $dash[$gasType]['total'];
        $accountTotals['full_tanks'] += $dash[$gasType]['full'];
        $accountTotals['empty_tanks'] += $dash[$gasType]['used'];
    }

    return [
        'totals' => $accountTotals,
        'physical' => $physicalTotals,
        'groups' => $groups,
        'dash' => $dash,
    ];
}

/** Dashboard AJAX payload — per-tank status for live board refresh. */
function gasTankBoardUnitsPayload(array $groupedUnits, ?string $yearMonth = null): array
{
    $yearMonth = $yearMonth ?? date('Y-m');
    $entrySummary = gasDashboardMonthSummary($yearMonth);
    $payload = [];

    foreach (array_keys(gasTypes()) as $gasType) {
        $fullCount = max(0, (int) ($entrySummary[$gasType]['full'] ?? 0));
        $usedCount = max(0, (int) ($entrySummary[$gasType]['used'] ?? 0));
        $payload[$gasType] = [];

        foreach ($groupedUnits[$gasType] ?? [] as $unit) {
            $tankNo = (int) ($unit['tank_no'] ?? 0);
            $status = (string) ($unit['status'] ?? 'empty');
            $displayStatus = gasTankDisplayStatus($tankNo, $fullCount, $usedCount, $status);
            $payload[$gasType][] = [
                'id' => (int) ($unit['id'] ?? 0),
                'tank_no' => $tankNo,
                'status' => $displayStatus,
                'slot' => gasTankSlotRole($tankNo, $fullCount, $usedCount, $displayStatus),
                'locked' => gasTankIsDashboardLocked($tankNo, $fullCount, $usedCount, $displayStatus),
                'machine_code' => gasTankDashboardMachineLabel($unit, $displayStatus),
            ];
        }
    }

    return $payload;
}

function gasTankTotalsFromUnits(array $groupedUnits, ?string $yearMonth = null): array
{
    $yearMonth = $yearMonth ?? date('Y-m');
    $entrySummary = gasDashboardMonthSummary($yearMonth);
    $totals = ['total_tanks' => 0, 'full_tanks' => 0, 'empty_tanks' => 0, 'in_use_tanks' => 0, 'damaged_tanks' => 0];
    foreach ($groupedUnits as $gasType => $units) {
        $fullCount = max(0, (int) ($entrySummary[$gasType]['full'] ?? 0));
        $usedCount = max(0, (int) ($entrySummary[$gasType]['used'] ?? 0));
        foreach ($units as $unit) {
            $totals['total_tanks']++;
            $tankNo = (int) ($unit['tank_no'] ?? 0);
            $status = gasTankDisplayStatus(
                $tankNo,
                $fullCount,
                $usedCount,
                (string) ($unit['status'] ?? 'empty')
            );
            if ($status === 'full') {
                $totals['full_tanks']++;
            } elseif ($status === 'empty') {
                $totals['empty_tanks']++;
            } elseif ($status === 'in_use') {
                $totals['in_use_tanks']++;
            } elseif ($status === 'damaged') {
                $totals['damaged_tanks']++;
            }
        }
    }

    return $totals;
}

function gasYearlyUsageSummary(int $year): array
{
    $summary = [];
    foreach (array_keys(gasTypes()) as $gasType) {
        $summary[$gasType] = array_fill(1, 12, 0);
    }

    $stmt = db()->prepare(
        'SELECT gas_type, strftime("%m", record_date) AS month_num, COALESCE(SUM(quantity), 0) AS total_usage
         FROM gas_entries
         WHERE entry_type = "usage" AND record_date LIKE :year_prefix
         GROUP BY gas_type, month_num'
    );
    $stmt->execute([':year_prefix' => $year . '-%']);

    foreach ($stmt->fetchAll() as $row) {
        $month = (int) $row['month_num'];
        $gasType = (string) $row['gas_type'];
        if (isset($summary[$gasType][$month])) {
            $summary[$gasType][$month] = (int) $row['total_usage'];
        }
    }

    return $summary;
}

function gasYearlyAddingSummary(int $year): array
{
    $summary = [];
    foreach (array_keys(gasTypes()) as $gasType) {
        $summary[$gasType] = array_fill(1, 12, 0);
    }

    $stmt = db()->prepare(
        'SELECT gas_type, strftime("%m", record_date) AS month_num, COALESCE(SUM(quantity), 0) AS total_adding
         FROM gas_entries
         WHERE entry_type = "adding" AND record_date LIKE :year_prefix
         GROUP BY gas_type, month_num'
    );
    $stmt->execute([':year_prefix' => $year . '-%']);

    foreach ($stmt->fetchAll() as $row) {
        $month = (int) $row['month_num'];
        $gasType = (string) $row['gas_type'];
        if (isset($summary[$gasType][$month])) {
            $summary[$gasType][$month] = (int) $row['total_adding'];
        }
    }

    return $summary;
}

function gasSubTypeStockBalancesBefore(string $beforeDate): array
{
    $balances = [];
    foreach (gasTypes() as $gasKey => $meta) {
        foreach ($meta['sub_types'] as $sub) {
            $balances[$gasKey][$sub] = 0;
        }
    }

    $stmt = db()->prepare(
        'SELECT gas_type, sub_type, entry_type, COALESCE(SUM(quantity), 0) AS total
         FROM gas_entries
         WHERE record_date < :before_date
         GROUP BY gas_type, sub_type, entry_type'
    );
    $stmt->execute([':before_date' => $beforeDate]);

    foreach ($stmt->fetchAll() as $row) {
        $gasType = (string) $row['gas_type'];
        $subType = (string) $row['sub_type'];
        if (!isset($balances[$gasType][$subType])) {
            continue;
        }
        $total = (int) $row['total'];
        if ((string) $row['entry_type'] === 'adding') {
            $balances[$gasType][$subType] += $total;
        } elseif ((string) $row['entry_type'] === 'usage') {
            $balances[$gasType][$subType] -= $total;
        }
    }

    return $balances;
}

function gasYearlyStockByType(int $year): array
{
    $opening = gasOpeningBalanceByType($year . '-01');
    $summary = [];

    foreach (array_keys(gasTypes()) as $gasType) {
        $summary[$gasType] = [
            'usage' => 0,
            'adding' => 0,
            'opening' => (int) ($opening[$gasType] ?? 0),
            'remaining' => (int) ($opening[$gasType] ?? 0),
        ];
    }

    $stmt = db()->prepare(
        'SELECT gas_type, entry_type, COALESCE(SUM(quantity), 0) AS total
         FROM gas_entries
         WHERE record_date LIKE :year_prefix
         GROUP BY gas_type, entry_type'
    );
    $stmt->execute([':year_prefix' => $year . '-%']);

    foreach ($stmt->fetchAll() as $row) {
        $gasType = (string) $row['gas_type'];
        $entryType = (string) $row['entry_type'];
        if (!isset($summary[$gasType][$entryType])) {
            continue;
        }
        $summary[$gasType][$entryType] = (int) $row['total'];
    }

    foreach (array_keys(gasTypes()) as $gasType) {
        $summary[$gasType]['remaining'] =
            $summary[$gasType]['opening']
            + $summary[$gasType]['adding']
            - $summary[$gasType]['usage'];
    }

    return $summary;
}

function gasYearlyDetailSummary(int $year): array
{
    $typeOpening = gasOpeningBalanceByType($year . '-01');
    $usageMonths = [];
    $yearAddingByGas = [];

    foreach (gasTypes() as $gasKey => $meta) {
        $yearAddingByGas[$gasKey] = 0;
        foreach ($meta['sub_types'] as $sub) {
            $usageMonths[$gasKey][$sub] = array_fill(1, 12, 0);
        }
    }

    $usageStmt = db()->prepare(
        'SELECT gas_type, sub_type, CAST(strftime("%m", record_date) AS INTEGER) AS month_num,
                COALESCE(SUM(quantity), 0) AS total
         FROM gas_entries
         WHERE entry_type = "usage" AND record_date LIKE :year_prefix
         GROUP BY gas_type, sub_type, month_num'
    );
    $usageStmt->execute([':year_prefix' => $year . '-%']);
    foreach ($usageStmt->fetchAll() as $row) {
        $gasType = (string) $row['gas_type'];
        $subType = (string) $row['sub_type'];
        $month = (int) $row['month_num'];
        if (isset($usageMonths[$gasType][$subType][$month])) {
            $usageMonths[$gasType][$subType][$month] = (int) $row['total'];
        }
    }

    $addingStmt = db()->prepare(
        'SELECT gas_type, COALESCE(SUM(quantity), 0) AS total
         FROM gas_entries
         WHERE entry_type = "adding" AND record_date LIKE :year_prefix
         GROUP BY gas_type'
    );
    $addingStmt->execute([':year_prefix' => $year . '-%']);
    foreach ($addingStmt->fetchAll() as $row) {
        $gasType = (string) $row['gas_type'];
        if (isset($yearAddingByGas[$gasType])) {
            $yearAddingByGas[$gasType] = (int) $row['total'];
        }
    }

    $result = [];
    foreach (gasTypes() as $gasKey => $meta) {
        $rows = [];
        $groupMonths = array_fill(1, 12, 0);
        $groupTotalUsage = 0;
        $pool = (int) ($typeOpening[$gasKey] ?? 0) + (int) ($yearAddingByGas[$gasKey] ?? 0);

        foreach (gasYearlySummarySubTypes($gasKey) as $sub) {
            $months = $usageMonths[$gasKey][$sub] ?? array_fill(1, 12, 0);
            $totalUsage = array_sum($months);
            $pool -= $totalUsage;
            $filledMonths = array_filter($months);
            $average = $filledMonths ? round($totalUsage / count($filledMonths), 1) : 0.0;

            $rows[$sub] = [
                'months' => $months,
                'total_usage' => $totalUsage,
                'remaining' => null,
                'average' => $average,
            ];

            for ($m = 1; $m <= 12; $m++) {
                $groupMonths[$m] += (int) ($months[$m] ?? 0);
            }
            $groupTotalUsage += $totalUsage;
        }

        $groupFilled = array_filter($groupMonths);
        $groupAverage = $groupFilled ? round($groupTotalUsage / count($groupFilled), 1) : 0.0;

        $result[$gasKey] = [
            'label' => $meta['label'],
            'rows' => $rows,
            'total' => [
                'months' => $groupMonths,
                'total_usage' => $groupTotalUsage,
                'remaining' => $pool,
                'average' => $groupAverage,
            ],
        ];
    }

    return $result;
}

function gasSundayDaysInMonth(string $yearMonth): array
{
    $daysInMonth = gasDaysInMonth($yearMonth);
    $sundays = [];
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dt = DateTime::createFromFormat('Y-m-d', $yearMonth . '-' . sprintf('%02d', $day));
        if ($dt && (int) $dt->format('w') === 0) {
            $sundays[] = $day;
        }
    }
    return $sundays;
}

function gasMonthlyDailyDetail(string $yearMonth): array
{
    $daysInMonth = gasDaysInMonth($yearMonth);
    $typeOpening = gasOpeningBalanceByType($yearMonth);
    $usageDays = [];
    $addingDays = [];

    foreach (gasTypes() as $gasKey => $meta) {
        $addingDays[$gasKey] = array_fill(1, 31, 0);
        foreach ($meta['sub_types'] as $sub) {
            $usageDays[$gasKey][$sub] = array_fill(1, 31, 0);
        }
    }

    $usageStmt = db()->prepare(
        'SELECT gas_type, sub_type, CAST(strftime("%d", record_date) AS INTEGER) AS day_num,
                COALESCE(SUM(quantity), 0) AS total
         FROM gas_entries
         WHERE entry_type = "usage" AND record_date LIKE :month_prefix
         GROUP BY gas_type, sub_type, day_num'
    );
    $usageStmt->execute([':month_prefix' => $yearMonth . '-%']);
    foreach ($usageStmt->fetchAll() as $row) {
        $gasType = (string) $row['gas_type'];
        $subType = (string) $row['sub_type'];
        $day = (int) $row['day_num'];
        if (isset($usageDays[$gasType][$subType][$day])) {
            $usageDays[$gasType][$subType][$day] = (int) $row['total'];
        }
    }

    $addingDailyStmt = db()->prepare(
        'SELECT gas_type, CAST(strftime("%d", record_date) AS INTEGER) AS day_num,
                COALESCE(SUM(quantity), 0) AS total
         FROM gas_entries
         WHERE entry_type = "adding" AND record_date LIKE :month_prefix
         GROUP BY gas_type, day_num'
    );
    $addingDailyStmt->execute([':month_prefix' => $yearMonth . '-%']);
    foreach ($addingDailyStmt->fetchAll() as $row) {
        $gasType = (string) $row['gas_type'];
        $day = (int) $row['day_num'];
        if (isset($addingDays[$gasType][$day])) {
            $addingDays[$gasType][$day] = (int) $row['total'];
        }
    }

    $groups = [];
    foreach (gasTypes() as $gasKey => $meta) {
        $rows = [];
        $groupDays = array_fill(1, 31, 0);
        $groupTotalUsage = 0;
        $groupOpening = (int) ($typeOpening[$gasKey] ?? 0);

        $addingDayData = $addingDays[$gasKey] ?? array_fill(1, 31, 0);
        $addingTotal = 0;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $addingTotal += (int) ($addingDayData[$d] ?? 0);
        }

        $rows[] = [
            'kind' => 'adding',
            'description' => $meta['label'],
            'opening' => null,
            'days' => $addingDayData,
            'total' => $addingTotal,
            'remaining' => null,
            'usage_category_rowspan' => 0,
        ];

        $usageSubs = gasUsageSubTypes($gasKey);
        $usageRowCount = count($usageSubs);
        foreach ($usageSubs as $idx => $sub) {
            $days = $usageDays[$gasKey][$sub] ?? array_fill(1, 31, 0);
            $totalUsage = 0;
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $totalUsage += (int) ($days[$d] ?? 0);
            }

            $rows[] = [
                'kind' => 'usage',
                'description' => $sub,
                'opening' => null,
                'days' => $days,
                'total' => $totalUsage,
                'remaining' => null,
                'usage_category_rowspan' => $idx === 0 ? $usageRowCount : 0,
            ];

            for ($d = 1; $d <= 31; $d++) {
                if ($d <= $daysInMonth) {
                    $groupDays[$d] += (int) ($days[$d] ?? 0);
                }
            }
            $groupTotalUsage += $totalUsage;
        }

        $groupRemaining = $groupOpening + $addingTotal - $groupTotalUsage;

        $groups[$gasKey] = [
            'label' => $meta['label'],
            'rows' => $rows,
            'total' => [
                'opening' => $groupOpening,
                'days' => $groupDays,
                'total_usage' => $groupTotalUsage,
                'remaining' => $groupRemaining,
            ],
        ];
    }

    return [
        'year_month' => $yearMonth,
        'days_in_month' => $daysInMonth,
        'sundays' => gasSundayDaysInMonth($yearMonth),
        'groups' => $groups,
    ];
}

function gasEquipmentYearlySummary(int $year, string $gasType): array
{
    return [];
}

function gasUsageOnDate(string $date): int
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(quantity), 0) FROM gas_entries WHERE entry_type = "usage" AND record_date = :record_date'
    );
    $stmt->execute([':record_date' => $date]);
    return (int) $stmt->fetchColumn();
}

function gasUsageInMonth(string $yearMonth): int
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(quantity), 0) FROM gas_entries WHERE entry_type = "usage" AND record_date LIKE :prefix'
    );
    $stmt->execute([':prefix' => $yearMonth . '%']);
    return (int) $stmt->fetchColumn();
}

function gasLatestPoStatus(): array
{
    return [];
}

function gasTankTotals(array $inventory): array
{
    $totals = ['total_tanks' => 0, 'full_tanks' => 0, 'empty_tanks' => 0, 'in_use_tanks' => 0];
    foreach ($inventory as $row) {
        $totals['total_tanks'] += (int) ($row['total_tanks'] ?? 0);
        $totals['full_tanks'] += (int) ($row['full_tanks'] ?? 0);
        $totals['empty_tanks'] += (int) ($row['empty_tanks'] ?? 0);
        $totals['in_use_tanks'] += (int) ($row['in_use_tanks'] ?? 0);
    }
    return $totals;
}

function gasExportYearlyExcel(int $year): void
{
    $detail = gasYearlyDetailSummary($year);
    $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="gas_usage_summary_Y' . $year . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }

    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Gas Usage Summary — Y' . $year]);
    fputcsv($out, array_merge(['Type', 'Description'], $monthNames, ['Total', 'Remain', 'Average']));

    foreach ($detail as $group) {
        $rowIndex = 0;
        $rowCount = count($group['rows']);
        $showGroupTotal = $rowCount > 1;
        $groupRemaining = (int) ($group['total']['remaining'] ?? 0);
        foreach ($group['rows'] as $sub => $rowData) {
            $rowIndex++;
            $line = [$group['label'], $sub];
            for ($m = 1; $m <= 12; $m++) {
                $val = (int) ($rowData['months'][$m] ?? 0);
                $line[] = $val > 0 ? $val : '';
            }
            $line[] = (int) $rowData['total_usage'];
            $line[] = $rowIndex === 1 ? $groupRemaining : '';
            $line[] = $rowData['average'] > 0 ? $rowData['average'] : '';
            fputcsv($out, $line);
        }

        if (!$showGroupTotal) {
            continue;
        }

        $total = $group['total'];
        $line = [$group['label'], 'Total'];
        for ($m = 1; $m <= 12; $m++) {
            $val = (int) ($total['months'][$m] ?? 0);
            $line[] = $val > 0 ? $val : '';
        }
        $line[] = (int) $total['total_usage'];
        $line[] = $groupRemaining;
        $line[] = $total['average'] > 0 ? $total['average'] : '';
        fputcsv($out, $line);
    }

    fclose($out);
}

function gasExportMonthlyDailyExcel(string $yearMonth): void
{
    $data = gasMonthlyDailyDetail($yearMonth);
    $daysInMonth = (int) ($data['days_in_month'] ?? 31);
    $label = gasMonthLabel($yearMonth);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="gas_usage_' . $yearMonth . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }

    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Gas Usage summary on ' . $label]);

    $header = ['Type', 'Adding / Usage', 'Description', 'Previous Month Remaining'];
    for ($d = 1; $d <= 31; $d++) {
        $header[] = (string) $d;
    }
    $header[] = 'Total';
    $header[] = 'Remain';
    fputcsv($out, $header);

    foreach ($data['groups'] as $group) {
        $rowIndex = 0;
        $groupOpening = (int) ($group['total']['opening'] ?? 0);
        $groupRemaining = (int) ($group['total']['remaining'] ?? 0);
        foreach ($group['rows'] as $rowData) {
            $rowIndex++;
            $isAdding = ($rowData['kind'] ?? 'usage') === 'adding';
            $line = [
                $group['label'],
                $isAdding ? 'Adding' : 'Usage',
                (string) $rowData['description'],
                $rowIndex === 1 ? $groupOpening : '',
            ];
            for ($d = 1; $d <= 31; $d++) {
                if ($d > $daysInMonth) {
                    $line[] = '';
                    continue;
                }
                $val = (int) ($rowData['days'][$d] ?? 0);
                $line[] = $val > 0 ? $val : '';
            }
            $line[] = (int) $rowData['total'];
            $line[] = $rowIndex === 1 ? $groupRemaining : '';
            fputcsv($out, $line);
        }
    }

    fclose($out);
}
