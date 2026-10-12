/**
 * Shared autocomplete for search bars across barangay_admin pages.
 *
 * Usage:
 *   initAutocomplete(inputId, listId, ajaxUrl, clearBtnId, onSelect)
 *
 * onSelect(item) — called when user picks a suggestion.
 *   item = { id, text }
 *   Default behaviour: sets input value to item.id and submits the form.
 */
function initAutocomplete(inputId, listId, ajaxUrl, clearBtnId, onSelect) {
    const input = document.getElementById(inputId);
    const list  = document.getElementById(listId);
    if (!input || !list) return;

    // Move list to <body> so position:fixed escapes all overflow/transform ancestors
    document.body.appendChild(list);

    const wrapper = input.closest('.ac-wrapper') || input.parentElement;
    const form    = input.closest('form');
    let items     = [];
    let activeIdx = -1;
    let timer;

    function positionList() {
        const rect = wrapper.getBoundingClientRect();
        list.style.left  = rect.left  + 'px';
        list.style.top   = rect.bottom + 'px';
        list.style.width = rect.width + 'px';
    }

    function openList() {
        if (!list.children.length) return;
        positionList();
        list.style.display = 'block';
        wrapper.classList.add('ac-open');
    }

    function closeList() {
        list.style.display = 'none';
        list.innerHTML = '';
        wrapper.classList.remove('ac-open');
        items = [];
        activeIdx = -1;
    }

    function setActive(idx) {
        items.forEach((el, i) => el.classList.toggle('ac-active', i === idx));
        activeIdx = idx;
    }

    function renderList(data) {
        list.innerHTML = '';
        items = [];
        activeIdx = -1;
        if (!data.length) { closeList(); return; }

        data.forEach(function(r) {
            const div = document.createElement('div');
            div.className = 'ac-item';
            div.textContent = r.text;
            div.dataset.id  = r.id;
            div.dataset.text = r.text;
            div.addEventListener('mousedown', function(e) {
                e.preventDefault();
                if (onSelect) {
                    onSelect(r);
                } else {
                    input.value = r.id;
                    closeList();
                    if (clearBtnId) {
                        const btn = document.getElementById(clearBtnId);
                        if (btn) btn.style.visibility = 'visible';
                    }
                    if (form) form.submit();
                }
            });
            list.appendChild(div);
            items.push(div);
        });
        openList();
    }

    input.addEventListener('input', function() {
        const q = input.value.trim();
        if (clearBtnId) {
            const btn = document.getElementById(clearBtnId);
            if (btn) btn.style.visibility = q ? 'visible' : 'hidden';
        }
        clearTimeout(timer);
        if (!q) { closeList(); return; }
        timer = setTimeout(function() {
            fetch(ajaxUrl + '?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => renderList(data.results || []))
                .catch(() => closeList());
        }, 200);
    });

    input.addEventListener('keydown', function(e) {
        if (!items.length) {
            if (e.key === 'Enter' && form) { closeList(); form.submit(); }
            return;
        }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive(Math.min(activeIdx + 1, items.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(Math.max(activeIdx - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIdx >= 0) {
                const chosen = { id: items[activeIdx].dataset.id, text: items[activeIdx].dataset.text };
                if (onSelect) {
                    onSelect(chosen);
                } else {
                    input.value = chosen.id;
                    closeList();
                    if (form) form.submit();
                }
            } else if (form) {
                form.submit();
            }
        } else if (e.key === 'Escape') {
            closeList();
        }
    });

    window.addEventListener('scroll', function() {
        if (list.style.display === 'block') positionList();
    }, true);
    window.addEventListener('resize', function() {
        if (list.style.display === 'block') positionList();
    });
    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !list.contains(e.target)) closeList();
    });

    if (clearBtnId) {
        const btn = document.getElementById(clearBtnId);
        if (btn) {
            btn.addEventListener('click', function() {
                input.value = '';
                btn.style.visibility = 'hidden';
                closeList();
                if (form) form.submit();
            });
        }
    }
}
