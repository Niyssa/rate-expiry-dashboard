<?php
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'Upload Rates';
$message   = $error = '';
$rowErrors = [];
$inserted  = $skipped = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'File upload failed (error code ' . $file['error'] . '). Please try again.';
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $error = 'Only .csv files are accepted.';
    } else {
        $handle  = fopen($file['tmp_name'], 'r');
        fgetcsv($handle); // skip header row
        $db      = getDB();
        $rowNum  = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count($row) < 6) {
                $rowErrors[] = "Row $rowNum: Not enough columns — expected at least 6.";
                $skipped++;
                continue;
            }

            [$rateCode, $rateDesc, $ccName, $type, $effFrom, $effThrough] = $row;
            $notes = $row[6] ?? '';

            $rateCode   = trim($rateCode);
            $rateDesc   = trim($rateDesc);
            $ccName     = trim($ccName);
            $type       = trim($type);
            $effFrom    = trim($effFrom);
            $effThrough = trim($effThrough);

            if (!$rateCode || !$rateDesc || !$ccName || !$type || !$effFrom || !$effThrough) {
                $rowErrors[] = "Row $rowNum: Missing required field(s). Skipped.";
                $skipped++;
                continue;
            }

            if (!in_array($type, ['Customer', 'Carrier'], true)) {
                $rowErrors[] = "Row $rowNum: Type must be 'Customer' or 'Carrier'. Got '$type'. Skipped.";
                $skipped++;
                continue;
            }

            // Resolve customer/carrier name → id
            $s = $db->prepare("SELECT id FROM customers_carriers WHERE name = ? AND is_active = 1 LIMIT 1");
            $s->bind_param('s', $ccName);
            $s->execute();
            $cc = $s->get_result()->fetch_assoc();

            if (!$cc) {
                $rowErrors[] = "Row $rowNum: Customer/Carrier '$ccName' not found in the system. Skipped.";
                $skipped++;
                continue;
            }

            $ccId = $cc['id'];
            $ins  = $db->prepare(
                "INSERT IGNORE INTO rates
                 (rate_code,rate_description,customer_carrier_id,type,effective_from,effective_through,notes)
                 VALUES (?,?,?,?,?,?,?)"
            );
            $ins->bind_param('ssissss', $rateCode, $rateDesc, $ccId, $type, $effFrom, $effThrough, $notes);

            if ($ins->execute() && $ins->affected_rows > 0) {
                $inserted++;
            } else {
                $rowErrors[] = "Row $rowNum: Rate code '$rateCode' already exists. Skipped.";
                $skipped++;
            }
        }

        fclose($handle);
        syncRateStatuses();

        $message = "$inserted rate(s) imported successfully."
                 . ($skipped > 0 ? " $skipped row(s) were skipped." : '');
    }
}

$customersCarriers = getCustomersCarriers();
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Upload Rates</h1>
        <p class="page-subtitle">Bulk-import rate cards from a CSV file</p>
    </div>
    <div class="page-actions">
        <a href="/rate-expiry-dashboard/assets/templates/rate_upload_template.csv"
           class="btn btn-outline" download>&#8681; Download Template</a>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (!empty($rowErrors)): ?>
<div class="alert alert-warning">
    <strong>Some rows were skipped:</strong>
    <ul style="margin-top:6px;padding-left:20px;line-height:1.8">
        <?php foreach ($rowErrors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Upload form -->
<div class="section-card" style="margin-bottom:24px">
    <div class="section-title">Import from CSV</div>
    <form method="POST" enctype="multipart/form-data" class="upload-form">
        <div class="form-group">
            <label>Select CSV File <span class="required">*</span></label>
            <input type="file" name="csv_file" accept=".csv" class="form-control" required>
            <p class="form-hint">
                File must follow the template format. Download the template above for the correct column order.
                Maximum upload size: <?= ini_get('upload_max_filesize') ?>.
            </p>
        </div>
        <div>
            <button type="submit" class="btn btn-primary">&#8679; Import Rates</button>
        </div>
    </form>
</div>

<!-- CSV format reference -->
<div class="section-card">
    <div class="section-title">CSV Column Reference</div>
    <div class="table-wrapper" style="margin-top:0;box-shadow:none;border:1px solid var(--color-border)">
        <table class="rates-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Column Name</th>
                    <th>Required</th>
                    <th>Example Value</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <td class="rate-code">rate_code</td>
                    <td><span class="badge badge-critical">Yes</span></td>
                    <td>RC-2024-007</td>
                    <td>Must be unique across all rates</td>
                </tr>
                <tr>
                    <td>2</td>
                    <td class="rate-code">rate_description</td>
                    <td><span class="badge badge-critical">Yes</span></td>
                    <td>Standard Freight - West Coast</td>
                    <td></td>
                </tr>
                <tr>
                    <td>3</td>
                    <td class="rate-code">customer_carrier_name</td>
                    <td><span class="badge badge-critical">Yes</span></td>
                    <td>ABC Logistics</td>
                    <td>Must exactly match an existing active record</td>
                </tr>
                <tr>
                    <td>4</td>
                    <td class="rate-code">type</td>
                    <td><span class="badge badge-critical">Yes</span></td>
                    <td>Customer</td>
                    <td>Must be <strong>Customer</strong> or <strong>Carrier</strong></td>
                </tr>
                <tr>
                    <td>5</td>
                    <td class="rate-code">effective_from</td>
                    <td><span class="badge badge-critical">Yes</span></td>
                    <td>2024-01-01</td>
                    <td>Format: YYYY-MM-DD</td>
                </tr>
                <tr>
                    <td>6</td>
                    <td class="rate-code">effective_through</td>
                    <td><span class="badge badge-critical">Yes</span></td>
                    <td>2027-01-15</td>
                    <td>Format: YYYY-MM-DD</td>
                </tr>
                <tr>
                    <td>7</td>
                    <td class="rate-code">notes</td>
                    <td><span class="badge badge-active">Optional</span></td>
                    <td>Annual renewal</td>
                    <td>Free text; leave blank if not needed</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Known customers/carriers hint -->
    <div style="margin-top:16px">
        <p style="font-size:12px;font-weight:600;color:var(--color-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px">
            Active Customers &amp; Carriers (use exact names in column 3)
        </p>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
            <?php foreach ($customersCarriers as $cc): ?>
            <span class="type-badge type-<?= strtolower($cc['type']) ?>">
                <?= htmlspecialchars($cc['name']) ?>
            </span>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
