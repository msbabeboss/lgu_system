<?php if (empty($barangays)): ?>
    <tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:10px;"></i>No barangays found.</td></tr>
<?php else: ?>
    <?php foreach ($barangays as $b): ?>
    <tr style="border-bottom:1px solid #e8f4fb;transition:background 0.15s;<?= $b['status'] === 'inactive' ? 'background:#f8fafc;' : '' ?>" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='<?= $b['status'] === 'inactive' ? '#f8fafc' : '' ?>'">

        <td class="ps-4 py-3" style="border:none;"><input type="checkbox" class="form-check-input row-check" value="<?= $b['id'] ?>"></td>

        <!-- Barangay ID -->
        <td class="py-3" style="border:none;"><code class="small">#<?= str_pad($b['id'], 4, '0', STR_PAD_LEFT) ?></code></td>

        <!-- Barangay Name -->
        <td class="py-3" style="border:none;">
            <div class="fw-semibold"><?= htmlspecialchars($b['barangay_name']) ?></div>
            <div class="text-muted" style="font-size:0.7rem;">
                <i class="bi bi-person-badge me-1"></i><?= $b['active_users'] ?> admin<?= $b['active_users'] != 1 ? 's' : '' ?>
            </div>
        </td>

        <!-- Municipality -->
        <td class="py-3 small" style="border:none;"><?= htmlspecialchars($b['municipality']) ?></td>

        <!-- Barangay Captain -->
        <td class="py-3" style="border:none;">
            <?php if ($b['captain_name']): ?>
                <div class="fw-semibold small"><?= htmlspecialchars($b['captain_name']) ?></div>
                <?php if ($b['captain_term_end']): ?>
                    <div class="text-muted" style="font-size:0.7rem;">
                        <i class="bi bi-calendar me-1"></i>Until <?= date('M Y', strtotime($b['captain_term_end'])) ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <span class="text-muted small fst-italic">Not assigned</span>
            <?php endif; ?>
        </td>

        <!-- Status -->
        <td class="py-3" style="border:none;">
            <span class="badge <?= $b['status']==='active' ? 'bg-success' : 'bg-secondary' ?>">
                <?= ucfirst($b['status']) ?>
            </span>
        </td>

        <!-- Date Registered -->
        <td class="py-3 small text-nowrap" style="border:none;">
            <?= date('M d, Y', strtotime($b['created_at'])) ?>
            <div class="text-muted" style="font-size:0.7rem;"><?= date('h:i A', strtotime($b['created_at'])) ?></div>
        </td>

        <!-- Actions -->
        <td class="pe-4 py-3" style="border:none;">
            <div class="d-flex gap-1">
                <button class="btn btn-xs btn-outline-info"
                        onclick="viewBrgy(<?= htmlspecialchars(json_encode($b)) ?>)"
                        title="View Details">
                    <i class="bi bi-eye"></i>
                </button>
                <button class="btn btn-xs btn-outline-primary"
                        onclick="editBrgy(<?= htmlspecialchars(json_encode($b)) ?>)"
                        title="Edit">
                    <i class="bi bi-pencil"></i>
                </button>
                <?php if ($b['status'] === 'active'): ?>
                <button class="btn btn-xs btn-outline-warning"
                        onclick="openArchiveBrgy(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes($b['barangay_name'])) ?>')"
                        title="Archive">
                    <i class="bi bi-archive"></i>
                </button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
<?php endif; ?>
