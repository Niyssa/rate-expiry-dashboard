<?php
require_once __DIR__ . '/../includes/functions.php';

$message = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $db     = getDB();

    if ($action === 'delete') {
        $id   = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM rates WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute() ? $message = 'Rate deleted successfully.' : $error = $db->error;

    } elseif (in_array($action, ['add', 'edit'], true)) {
        $rateCode    = trim($_POST['rate_code']           ?? '');
        $rateDesc    = trim($_POST['rate_description']    ?? '');
        $ccId        = intval($_POST['customer_carrier_id'] ?? 0);
        $type        = trim($_POST['type']                ?? '');
        $effFrom     = trim($_POST['effective_from']      ?? '');
        $effThrough  = trim($_POST['effective_through']   ?? '');
        $notes       = trim($_POST['notes']               ?? '');

        if (!$rateCode || !$rateDesc || !$ccId || !$type || !$effFrom || !$effThrough) {
            $error = 'All required fields must be filled.';
        } elseif ($effThrough < $effFrom) {
            $error = 'Effective Through date must be on or after Effective From date.';
        } else {
            if ($action === 'add') {
                $stmt = $db->prepare(
                    "INSERT INTO rates (rate_code,rate_description,customer_carrier_id,type,effective_from,effective_through,notes)
                     VALUES (?,?,?,?,?,?,?)"
                );
                $stmt->bind_param('ssissss', $rateCode, $rateDesc, $ccId, $type, $effFrom, $effThrough, $notes);
            } else {
                $id   = intval($_POST['id'] ?? 0);
                $stmt = $db->prepare(
                    "UPDATE rates
                     SET rate_code=?,rate_description=?,customer_carrier_id=?,type=?,
                         effective_from=?,effective_through=?,notes=?
                     WHERE id=?"
                );
                $stmt->bind_param('ssissssi', $rateCode, $rateDesc, $ccId, $type, $effFrom, $effThrough, $notes, $id);
            }
            if ($stmt->execute()) {
                syncRateStatuses();
                $message = $action === 'add' ? 'Rate added successfully.' : 'Rate updated successfully.';
            } else {
                $error = 'Database error: ' . $db->error;
            }
        }
    }
}

syncRateStatuses();
$pageTitle         = 'Rates Management';
$rates             = getRates([]);
$customersCarriers = getCustomersCarriers();
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Rates Management</h1>
        <p class="page-subtitle">View, add, edit, and remove rate cards</p>
    </div>
    <div class="page-actions">
        <a href="/rate-expiry-dashboard/pages/upload.php" class="btn btn-outline">&#8679; Upload CSV</a>
        <button class="btn btn-primary" onclick="openModal()">+ Add New Rate</button>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="table-wrapper">
    <table class="rates-table">
        <thead>
            <tr>
                <th>Rate Code</th>
                <th>Description</th>
                <th>Customer / Carrier</th>
                <th>Type</th>
                <th>Effective From</th>
                <th>Effective Through</th>
                <th>Days to Expiry</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rates)): ?>
            <tr><td colspan="9" class="empty-state">No rates found. Add your first rate above.</td></tr>
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
                <td class="actions-cell">
                    <button class="btn-action btn-edit"
                        onclick='editRate(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'>Edit</button>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Delete rate <?= htmlspecialchars($r['rate_code'], ENT_QUOTES) ?>? This cannot be undone.')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id"     value="<?= $r['id'] ?>">
                        <button type="submit" class="btn-action btn-delete">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Add / Edit Modal -->
<div id="rateModal" class="modal-overlay" style="display:none">
    <div class="modal-dialog">
        <div class="modal-header">
            <h2 class="modal-title" id="modalTitle">Add New Rate</h2>
            <button class="modal-close" onclick="closeModal()" type="button">&times;</button>
        </div>
        <form method="POST" id="rateForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id"     id="formId"     value="">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Rate Code <span class="required">*</span></label>
                        <input type="text" name="rate_code" id="fRateCode" class="form-control"
                               required placeholder="e.g. RC-2024-007">
                    </div>
                    <div class="form-group">
                        <label>Type <span class="required">*</span></label>
                        <select name="type" id="fType" class="form-control" required>
                            <option value="">Select type</option>
                            <option value="Customer">Customer</option>
                            <option value="Carrier">Carrier</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Rate Description <span class="required">*</span></label>
                    <input type="text" name="rate_description" id="fDesc" class="form-control"
                           required placeholder="e.g. Standard Freight - West Coast">
                </div>
                <div class="form-group">
                    <label>Customer / Carrier <span class="required">*</span></label>
                    <select name="customer_carrier_id" id="fCC" class="form-control" required>
                        <option value="">Select customer/carrier</option>
                        <?php foreach ($customersCarriers as $cc): ?>
                        <option value="<?= $cc['id'] ?>">
                            <?= htmlspecialchars($cc['name']) ?> (<?= $cc['type'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Effective From <span class="required">*</span></label>
                        <input type="date" name="effective_from" id="fFrom" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Effective Through <span class="required">*</span></label>
                        <input type="date" name="effective_through" id="fThrough" class="form-control" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" id="fNotes" class="form-control" rows="3"
                              placeholder="Optional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="submitBtn">Add Rate</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(title, action) {
    title  = title  || 'Add New Rate';
    action = action || 'add';
    document.getElementById('modalTitle').textContent    = title;
    document.getElementById('formAction').value          = action;
    document.getElementById('submitBtn').textContent     = action === 'add' ? 'Add Rate' : 'Save Changes';
    document.getElementById('rateModal').style.display   = 'flex';
}

function closeModal() {
    document.getElementById('rateModal').style.display = 'none';
    document.getElementById('rateForm').reset();
    document.getElementById('formId').value = '';
}

function editRate(r) {
    document.getElementById('formId').value    = r.id;
    document.getElementById('fRateCode').value = r.rate_code;
    document.getElementById('fDesc').value     = r.rate_description;
    document.getElementById('fType').value     = r.type;
    document.getElementById('fCC').value       = r.customer_carrier_id;
    document.getElementById('fFrom').value     = r.effective_from;
    document.getElementById('fThrough').value  = r.effective_through;
    document.getElementById('fNotes').value    = r.notes || '';
    openModal('Edit Rate', 'edit');
}

document.getElementById('rateModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
