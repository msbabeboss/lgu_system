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
 *   $paginationAnchor — fragment appended to each page link (string, optional)
 *   $paginationLinkClass — extra class applied to each page link (string, optional)
 *
 * Pages using $currentPage instead of $page should set: $page = $currentPage;
 */
$page       = max(1, (int)($page ?? 1));
$totalPages = max(1, (int)($totalPages ?? 1));
$page       = min($page, $totalPages);
$_pParam = $paginationParam ?? 'page';
$_pAnchor = $paginationAnchor ?? '';
$_pLinkClass = $paginationLinkClass ?? '';
$__paginationAjaxJson = !empty($paginationAjaxJson);
$__paginationContainerSelector = $paginationContainerSelector ?? '';
$paginationParam = null; // reset so it doesn't bleed into next include
$paginationAnchor = null;
$paginationLinkClass = null;
$paginationAjaxJson = null;
$paginationContainerSelector = null;
$__renderPaginationMarkup = empty($paginationScriptOnly);
$paginationScriptOnly = null;
?>
<?php
$__start = max(1, $page - 2);
$__end   = min($totalPages, $page + 2);
?>
<?php if ($__renderPaginationMarkup): ?>
<nav class="table-pagination mt-3 d-flex justify-content-center" aria-label="Pagination" data-page-param="<?= htmlspecialchars($_pParam, ENT_QUOTES, 'UTF-8') ?>"<?= $__paginationAjaxJson ? ' data-pagination-json="true"' : '' ?><?= $__paginationContainerSelector !== '' ? ' data-pagination-container="' . htmlspecialchars($__paginationContainerSelector, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
    <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link <?= $_pLinkClass ?>" href="<?= $paginationBase ?><?= $_pParam ?>=<?= max(1, $page - 1) ?><?= $_pAnchor ?>" aria-label="Previous page">
                <i class="bi bi-chevron-left"></i>
            </a>
        </li>
        <?php if ($__start > 1): ?>
            <li class="page-item">
                <a class="page-link <?= $_pLinkClass ?>" href="<?= $paginationBase ?><?= $_pParam ?>=1<?= $_pAnchor ?>">1</a>
            </li>
            <?php if ($__start > 2): ?>
                <li class="page-item disabled"><span class="page-link">…</span></li>
            <?php endif; ?>
        <?php endif; ?>
        <?php for ($__p = $__start; $__p <= $__end; $__p++): ?>
            <li class="page-item <?= $__p === $page ? 'active' : '' ?>">
                <a class="page-link <?= $_pLinkClass ?>" href="<?= $paginationBase ?><?= $_pParam ?>=<?= $__p ?><?= $_pAnchor ?>" <?= $__p === $page ? 'aria-current="page"' : '' ?>><?= $__p ?></a>
            </li>
        <?php endfor; ?>
        <?php if ($__end < $totalPages): ?>
            <?php if ($__end < $totalPages - 1): ?>
                <li class="page-item disabled"><span class="page-link">…</span></li>
            <?php endif; ?>
            <li class="page-item">
                <a class="page-link <?= $_pLinkClass ?>" href="<?= $paginationBase ?><?= $_pParam ?>=<?= $totalPages ?><?= $_pAnchor ?>"><?= $totalPages ?></a>
            </li>
        <?php endif; ?>
        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link <?= $_pLinkClass ?>" href="<?= $paginationBase ?><?= $_pParam ?>=<?= min($totalPages, $page + 1) ?><?= $_pAnchor ?>" aria-label="Next page">
                <i class="bi bi-chevron-right"></i>
            </a>
        </li>
    </ul>
</nav>
<div class="table-pagination-status small text-danger text-center" role="status" aria-live="polite"></div>
<?php endif; ?>
<script>
(function () {
    'use strict';

    if (window.sharedTablePaginationLoaded) return;
    window.sharedTablePaginationLoaded = true;

    function precedingTbody(root, nav) {
        var candidates = Array.from(root.querySelectorAll('.main-content table tbody'));
        return candidates.filter(function (tbody) {
            return tbody.compareDocumentPosition(nav) & Node.DOCUMENT_POSITION_FOLLOWING;
        }).pop() || null;
    }

    function matchingPagination(root, param) {
        return Array.from(root.querySelectorAll('.table-pagination[data-page-param]'))
            .find(function (nav) { return nav.dataset.pageParam === param; }) || null;
    }

    function paginationTarget(root, nav) {
        if (nav.dataset.paginationContainer) {
            return root.querySelector(nav.dataset.paginationContainer);
        }
        return precedingTbody(root, nav);
    }

    function showError(nav, message) {
        if (!nav.isConnected) nav = matchingPagination(document, nav.dataset.pageParam) || nav;
        var status = nav.nextElementSibling;
        if (status && status.classList.contains('table-pagination-status')) {
            status.textContent = message;
        }
    }

    async function refreshTables(url, sourceNavs) {
        var jsonRows = sourceNavs.length === 1 && sourceNavs[0].dataset.paginationJson === 'true';
        var headers = { 'Accept': jsonRows ? 'application/json' : 'text/html' };
        if (jsonRows) headers['X-Requested-With'] = 'XMLHttpRequest';
        var response = await fetch(url, { credentials: 'same-origin', headers: headers });
        if (!response.ok) throw new Error('Page request failed (' + response.status + ').');

        if (jsonRows) {
            var pageData = await response.json();
            if (typeof pageData.rowsHtml !== 'string' || typeof pageData.paginationHtml !== 'string') {
                throw new Error('The server response did not contain the requested table page.');
            }
            var paginationTemplate = document.createElement('template');
            paginationTemplate.innerHTML = pageData.paginationHtml;
            var nextNav = paginationTemplate.content.querySelector('.table-pagination[data-page-param="' + sourceNavs[0].dataset.pageParam + '"]');
            var sourceBody = precedingTbody(document, sourceNavs[0]);
            if (!nextNav || !sourceBody) throw new Error('The server response did not contain the requested table page.');
            sourceBody.innerHTML = pageData.rowsHtml;
            var selectAll = document.getElementById('selectAll');
            if (selectAll) selectAll.checked = false;
            var status = sourceNavs[0].nextElementSibling;
            sourceNavs[0].replaceWith(nextNav);
            if (status && status.classList.contains('table-pagination-status')) status.textContent = '';
            return;
        }

        var html = await response.text();
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        sourceNavs.forEach(function (nav) {
            var sourceTarget = paginationTarget(document, nav);
            var nextNav = matchingPagination(parsed, nav.dataset.pageParam);
            var sourceContainerSelector = nav.dataset.paginationContainer;
            var nextTarget = nextNav ? paginationTarget(parsed, nextNav) : null;
            if (!sourceTarget || !nextTarget || !nextNav ||
                Boolean(sourceContainerSelector) !== Boolean(nextNav.dataset.paginationContainer)) {
                throw new Error('The server response did not contain the requested table page.');
            }
        });

        sourceNavs.forEach(function (nav) {
            var sourceTarget = paginationTarget(document, nav);
            var nextNav = matchingPagination(parsed, nav.dataset.pageParam);
            var nextTarget = paginationTarget(parsed, nextNav);
            if (nav.dataset.paginationContainer) {
                sourceTarget.replaceWith(nextTarget);
            } else {
                sourceTarget.innerHTML = nextTarget.innerHTML;
            }
            if (!nav.dataset.paginationContainer && sourceTarget.closest('#brgyTable')) {
                var selectAll = document.getElementById('selectAll');
                if (selectAll) selectAll.checked = false;
            }
            var status = nav.nextElementSibling;
            nav.replaceWith(nextNav);
            if (status && status.classList.contains('table-pagination-status')) status.textContent = '';
        });
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('.table-pagination a.page-link[href]');
        if (!link || event.defaultPrevented || event.button !== 0 ||
            event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

        var nav = link.closest('.table-pagination[data-page-param]');
        if (!nav || link.closest('li').classList.contains('disabled') || !paginationTarget(document, nav)) return;

        var url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin) return;

        event.preventDefault();
        var target = paginationTarget(document, nav);
        target.setAttribute('aria-busy', 'true');
        showError(nav, '');
        refreshTables(url.href, [nav]).then(function () {
            history.pushState({}, '', url.href);
        }).catch(function (error) {
            console.error('Unable to load the requested table page:', error);
            showError(nav, 'Hindi na-load ang page. Subukan ulit.');
        }).finally(function () {
            target.removeAttribute('aria-busy');
        });
    });

    window.addEventListener('popstate', function () {
        var navs = Array.from(document.querySelectorAll('.table-pagination[data-page-param]'))
            .filter(function (nav) { return paginationTarget(document, nav); });
        if (!navs.length) return;
        var targets = navs.map(function (nav) { return paginationTarget(document, nav); });
        targets.forEach(function (target) { target.setAttribute('aria-busy', 'true'); });
        refreshTables(window.location.href, navs).catch(function (error) {
            console.error('Unable to restore the requested table page:', error);
            navs.forEach(function (nav) { showError(nav, 'Hindi na-load ang page. Subukan ulit.'); });
        }).finally(function () {
            targets.forEach(function (target) { target.removeAttribute('aria-busy'); });
        });
    });

    window.renderSharedPagination = function (container, page, totalPages, onPageChange) {
        page = Math.max(1, parseInt(page, 10) || 1);
        totalPages = Math.max(1, parseInt(totalPages, 10) || 1);
        page = Math.min(page, totalPages);
        var start = Math.max(1, page - 2);
        var end = Math.min(totalPages, page + 2);
        var nav = container.matches('nav') ? container : document.createElement('nav');
        nav.className = 'table-pagination mt-3 d-flex justify-content-center';
        nav.setAttribute('aria-label', 'Pagination');

        var list = document.createElement('ul');
        list.className = 'pagination pagination-sm mb-0';

        function addLink(targetPage, label, ariaLabel, disabled, active) {
            var item = document.createElement('li');
            item.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
            var link = document.createElement('a');
            link.className = 'page-link';
            link.href = '#';
            link.dataset.sharedPage = String(targetPage);
            if (ariaLabel) link.setAttribute('aria-label', ariaLabel);
            if (active) link.setAttribute('aria-current', 'page');
            if (label === 'previous') {
                link.innerHTML = '<i class="bi bi-chevron-left"></i>';
            } else if (label === 'next') {
                link.innerHTML = '<i class="bi bi-chevron-right"></i>';
            } else {
                link.textContent = label;
            }
            item.appendChild(link);
            list.appendChild(item);
        }

        addLink(page - 1, 'previous', 'Previous page', page <= 1, false);
        if (start > 1) {
            addLink(1, '1', null, false, false);
            if (start > 2) {
                var leftEllipsis = document.createElement('li');
                leftEllipsis.className = 'page-item disabled';
                leftEllipsis.innerHTML = '<span class="page-link">…</span>';
                list.appendChild(leftEllipsis);
            }
        }
        for (var number = start; number <= end; number++) {
            addLink(number, String(number), null, false, number === page);
        }
        if (end < totalPages) {
            if (end < totalPages - 1) {
                var rightEllipsis = document.createElement('li');
                rightEllipsis.className = 'page-item disabled';
                rightEllipsis.innerHTML = '<span class="page-link">…</span>';
                list.appendChild(rightEllipsis);
            }
            addLink(totalPages, String(totalPages), null, false, false);
        }
        addLink(page + 1, 'next', 'Next page', page >= totalPages, false);
        nav.replaceChildren(list);

        nav.onclick = function (event) {
            var link = event.target.closest('a[data-shared-page]');
            if (!link) return;
            event.preventDefault();
            if (link.closest('li').classList.contains('disabled')) return;
            var nextPage = parseInt(link.dataset.sharedPage, 10);
            if (nextPage >= 1 && nextPage <= totalPages) onPageChange(nextPage);
        };
        if (nav !== container) container.replaceChildren(nav);
    };
})();
</script>
