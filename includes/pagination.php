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
 *   $paginationParam — query param name for the page number (default: 'page') (string, optional)
 *
 * Pages using $currentPage instead of $page should set: $page = $currentPage;
 */
$page       = max(1, (int)($page ?? 1));
$totalPages = max(1, (int)($totalPages ?? 1));
$page       = min($page, $totalPages);
$_pParam = $paginationParam ?? 'page';
$paginationParam = null; // reset so it doesn't bleed into next include
?>
<?php
$__start = max(1, $page - 2);
$__end   = min($totalPages, $page + 2);
?>
<nav class="table-pagination mt-3 d-flex justify-content-center" aria-label="Pagination">
    <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $paginationBase ?><?= $_pParam ?>=<?= max(1, $page - 1) ?>" aria-label="Previous page">
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
                <a class="page-link" href="<?= $paginationBase ?><?= $_pParam ?>=<?= $__p ?>" <?= $__p === $page ? 'aria-current="page"' : '' ?>><?= $__p ?></a>
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
            <a class="page-link" href="<?= $paginationBase ?><?= $_pParam ?>=<?= min($totalPages, $page + 1) ?>" aria-label="Next page">
                <i class="bi bi-chevron-right"></i>
            </a>
        </li>
    </ul>
</nav>
