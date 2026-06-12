<?php
require_once __DIR__ . '/includes/functions.php';

// Sync statuses on every page load (lightweight UPDATE)
syncRateStatuses();

$pageTitle = 'Rate Expiry Dashboard';

// Read filters from GET
$filters = [
    'search'     => trim($_GET['search']     ?? ''),
    'status'     => trim($_GET['status']     ?? ''),
    'cc_id'      => intval($_GET['cc_id']    ?? 0),
    'type'       => trim($_GET['type']       ?? ''),
    'expiry_from'=> trim($_GET['expiry_from']?? ''),
    'expiry_to'  => trim($_GET['expiry_to']  ?? ''),
];

$counts            = getStatusCounts();
$rates             = getRates($filters);
$customersCarriers = getCustomersCarriers();
$dashAlerts        = getDashboardAlerts();

include __DIR__ . '/includes/header.php';
?>

<?php if (!empty($dashAlerts)): ?>
<!-- Portal Dashboard Alert Banner — Channel 2 -->
<div class="dashboard-alert-banner" id="dashAlertBanner">
    <div class="alert-banner-inner">
        <span class="alert-banner-icon">&#9888;</span>
        <div class="alert-banner-text">
            <strong><?= count($dashAlerts) ?> active rate alert<?= count($dashAlerts) > 1 ? 's' : '' ?></strong>
            — <?php
                $codes = array_unique(array_column($dashAlerts, 'rate_code'));
                echo implode(', ', array_slice($codes, 0, 4));
                if (count($codes) > 4) echo ' +' . (count($codes) - 4) . ' more';
            ?> require<?= count($codes) === 1 ? 's' : '' ?> attention.
            <a href="/rate-expiry-dashboard/pages/notifications.php" class="alert-banner-link">View all &rarr;</a>
        </div>
        <button class="alert-banner-close" onclick="document.getElementById('dashAlertBanner').style.display='none'" title="Dismiss">&times;</button>
    </div>
</div>
<?php endif; ?>

<!-- Page heading -->
<div class="page-header">
    <div>
        <h1 class="page-title">Rate Expiry Dashboard</h1>
        <p class="page-subtitle">Monitor and manage rate expiration status with real-time updates</p>
    </div>
    <div class="page-actions">
        <a href="/rate-expiry-dashboard/assets/templates/rate_upload_template.csv" class="btn btn-outline" download>
            &#8681; Download Template
        </a>
        <a href="/rate-expiry-dashboard/pages/upload.php" class="btn btn-primary">
            &#8679; Upload Rates
        </a>
    </div>
</div>

<!-- Status summary cards -->
<div class="cards-grid">
    <div class="card card-active">
        <div class="card-count"><?= $counts['Active'] ?></div>
        <div class="card-label">Active</div>
        <div class="card-sublabel">&gt; 6 months remaining</div>
    </div>
    <div class="card card-expiring">
        <div class="card-count"><?= $counts['Expiring Soon'] ?></div>
        <div class="card-label">Expiring Soon</div>
        <div class="card-sublabel">3–6 months remaining</div>
    </div>
    <div class="card card-critical">
        <div class="card-count"><?= $counts['Critical Expiry'] ?></div>
        <div class="card-label">Critical Expiry</div>
        <div class="card-sublabel">1–3 months remaining</div>
    </div>
    <div class="card card-expired">
        <div class="card-count"><?= $counts['Expired'] ?></div>
        <div class="card-label">Expired</div>
        <div class="card-sublabel">Past expiry date</div>
    </div>
</div>

<!-- Filters bar -->
<form class="filters-bar" method="GET" action="">
    <div class="filter-row">
        <div class="filter-search">
            <span class="search-icon">&#128269;</span>
            <input type="text" name="search" placeholder="Search rates, customers, carriers..."
                   value="<?= htmlspecialchars($filters['search']) ?>">
        </div>

        <div class="filter-group">
            <label>Status Category</label>
            <select name="status">
                <option value="">All Statuses</option>
                <?php foreach (['Active','Expiring Soon','Critical Expiry','Expired'] as $s): ?>
                <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label>Customer/Carrier</label>
            <select name="cc_id">
                <option value="">All</option>
                <?php foreach ($customersCarriers as $cc): ?>
                <option value="<?= $cc['id'] ?>" <?= $filters['cc_id'] == $cc['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cc['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label>Type</label>
            <select name="type">
                <option value="">All Types</option>
                <option value="Customer" <?= $filters['type'] === 'Customer' ? 'selected' : '' ?>>Customer</option>
                <option value="Carrier"  <?= $filters['type'] === 'Carrier'  ? 'selected' : '' ?>>Carrier</option>
            </select>
        </div>

        <button type="submit" class="btn btn-outline btn-sm">&#8681; Export</button>
    </div>

    <div class="filter-row filter-row-dates">
        <div class="filter-group">
            <label>Expiry From</label>
            <input type="date" name="expiry_from" value="<?= htmlspecialchars($filters['expiry_from']) ?>">
        </div>
        <div class="filter-group">
            <label>Expiry To</label>
            <input type="date" name="expiry_to" value="<?= htmlspecialchars($filters['expiry_to']) ?>">
        </div>
        <a href="/rate-expiry-dashboard/index.php" class="btn-link">Clear All Filters</a>
    </div>
</form>

<!-- Rates table -->
<div class="table-wrapper">
    <table class="rates-table">
        <thead>
            <tr>
                <th>Rate Code</th>
                <th>Rate Description</th>
                <th>Customer / Carrier</th>
                <th>Type</th>
                <th>Effective From</th>
                <th>Effective Through</th>
                <th>Days to Expiry</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rates)): ?>
            <tr><td colspan="8" class="empty-state">No rates found matching your filters.</td></tr>
        <?php else: ?>
            <?php foreach ($rates as $r): ?>
            <tr>
                <td class="rate-code"><?= htmlspecialchars($r['rate_code']) ?></td>
                <td><?= htmlspecialchars($r['rate_description']) ?></td>
                <td><?= htmlspecialchars($r['customer_carrier']) ?></td>
                <td><span class="type-badge type-<?= strtolower($r['type']) ?>"><?= $r['type'] ?></span></td>
                <td><?= date('M d, Y', strtotime($r['effective_from'])) ?></td>
                <td><?= date('M d, Y', strtotime($r['effective_through'])) ?></td>
                <td class="days-cell <?= $r['days_to_expiry'] < 0 ? 'days-overdue' : '' ?>">
                    <?= formatDaysLabel((int)$r['days_to_expiry']) ?>
                </td>
                <td><span class="badge <?= statusBadgeClass($r['status']) ?>"><?= $r['status'] ?></span></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
