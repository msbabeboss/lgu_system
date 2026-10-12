<?php
/**
 * Shared pagination include.
 *
 * Required variables before including:
 *   $page        — current page (int)
 *   $totalPages  — total number of pages (int)
 *   $totalCount  — total number of records (int)
 *   $offset      — current offset (int)
 *   $perPage     — items per page (int)
 *   $paginationBase — base URL string ending with '?' or '?foo=bar&' (string)
 *   $paginationLabel — singular label e.g. 'record', 'notification' (string, optional)
 *   $paginationParam — query param name for the page number (default: 'page') (string, optional)
 *
 * Pages using $currentPage instead of $page should set: $page = $currentPage;
 */
$_pLabel = $paginationLabel ?? 'record';
$_pParam = $paginationParam ?? 'page';
$paginationParam = null; // reset so it doesn't bleed into next include
$_pFrom  = $totalCount > 0 ? $offset + 1 : 0;
$_pTo    = min($offset + $perPage, $totalCount);
?>
<nav class="mt-3" aria-label="Pagination">
    <div class="d-flex flex-column align-items-center gap-1">
        <small class="text-muted">
            Showing <?= number_format($_pFrom) ?>–<?= number_format($_pTo) ?>
            of <?= number_format($totalCount) ?>
            <?= htmlspecialchars($_pLabel) ?><?= $totalCount !== 1 ? 's' : '' ?>
        </small>
        <?php if ($totalPages > 1):
            $__start = max(1, $page - 2);
            $__end   = min($totalPages, $page + 2);
        ?>
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $paginationBase ?><?= $_pParam ?>=<?= $page - 1 ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
            <?php if ($__start > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="<?= $paginationBase ?><?= $_pParam ?>=1">1</a>
                </li>
                <?php if ($__start > 2): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
            <?php endif; ?>
            <?php for ($__p = $__start; $__p <= $__end; $__p++): ?>
                <li class="page-item <?= $__p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= $paginationBase ?><?= $_pParam ?>=<?= $__p ?>"><?= $__p ?></a>
                </li>
            <?php endfor; ?>
            <?php if ($__end < $totalPages): ?>
                <?php if ($__end < $totalPages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
                <li class="page-item">
                    <a class="page-link" href="<?= $paginationBase ?><?= $_pParam ?>=<?= $totalPages ?>"><?= $totalPages ?></a>
                </li>
            <?php endif; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $paginationBase ?><?= $_pParam ?>=<?= $page + 1 ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        </ul>
        <?php endif; ?>
    </div>
</nav>
