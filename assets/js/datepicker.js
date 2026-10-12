/**
 * LGU System — Typeable Date Input Enhancer  v2.0
 * =================================================
 * Wraps every input[type="date"] / input[type="datetime-local"] with a
 * visible MM/DD/YYYY text mask while keeping the native input hidden and
 * in sync for correct form submission.
 *
 * Public API:
 *   window.lguDatepickerInit(root?)  — (re-)enhance inputs inside root
 *   window.lguSetDate(idOrEl, isoValue) — programmatically set a date
 */
(function () {
    'use strict';

    /* ─── Format helpers ──────────────────────────────────────── */

    function isoToDisplay(v) {
        var m = v && v.match(/^(\d{4})-(\d{2})-(\d{2})/);
        return m ? m[2] + '/' + m[3] + '/' + m[1] : '';
    }

    function dtlToDisplay(v) {
        var m = v && v.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
        return m ? m[2] + '/' + m[3] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : '';
    }

    function displayToISO(s) {
        var d = s.replace(/\D/g, '');
        if (d.length < 8) return '';
        var mm = d.slice(0,2), dd = d.slice(2,4), yy = d.slice(4,8);
        var mo = +mm, da = +dd, yr = +yy;
        if (mo<1||mo>12||da<1||da>31||yr<1900||yr>2100) return '';
        return yy + '-' + mm + '-' + dd;
    }

    function displayToDTL(s) {
        var parts = s.split(' ');
        var iso = displayToISO(parts[0] || '');
        if (!iso) return '';
        var tp = (parts[1] || '00:00').match(/^(\d{1,2}):(\d{2})$/);
        if (!tp) return '';
        return iso + 'T' + tp[1].padStart(2,'0') + ':' + tp[2];
    }

    function applyMask(txt, isDTL) {
        var raw = txt.value.replace(/\D/g, '');
        var max = isDTL ? 12 : 8;
        if (raw.length > max) raw = raw.slice(0, max);
        var out = '';
        if (!isDTL) {
            /* MM/DD/YYYY */
            if (raw.length >= 1) {
                out = raw.slice(0,2);
                if (raw.length >= 3) {
                    out += '/' + raw.slice(2,4);
                    if (raw.length >= 5) out += '/' + raw.slice(4,8);
                }
            }
        } else {
            /* MM/DD/YYYY HH:MM */
            if (raw.length >= 1) {
                out = raw.slice(0,2);
                if (raw.length >= 3) {
                    out += '/' + raw.slice(2,4);
                    if (raw.length >= 5) {
                        out += '/' + raw.slice(4,8);
                        if (raw.length >= 9) {
                            out += ' ' + raw.slice(8,10);
                            if (raw.length >= 11) out += ':' + raw.slice(10,12);
                        }
                    }
                }
            }
        }
        txt.value = out;
    }

    /* ─── Core enhancer ───────────────────────────────────────── */

    function enhance(native) {
        if (native.dataset.lguDateWrapped) return;
        native.dataset.lguDateWrapped = '1';

        var isDTL = native.type === 'datetime-local';

        /* 1. Wrapper div */
        var wrap = document.createElement('div');
        wrap.style.cssText = 'position:relative;display:flex;align-items:center;';
        /* Store reference so lguSetDate can reach txt via wrap */
        wrap._lguNative = native;

        /* 2. Visible text input — clone classes & style from native */
        var txt = document.createElement('input');
        txt.type         = 'text';
        txt.placeholder  = isDTL ? 'MM/DD/YYYY HH:MM' : 'MM/DD/YYYY';
        txt.autocomplete = 'off';
        txt.className    = native.className;
        var origStyle    = native.getAttribute('style');
        if (origStyle) txt.setAttribute('style', origStyle);
        txt.style.paddingRight = '36px';
        txt.style.flex         = '1';
        txt.style.minWidth     = '0';

        /* Pre-fill from native value */
        if (native.value) {
            txt.value = isDTL ? dtlToDisplay(native.value) : isoToDisplay(native.value);
        }

        /* Mirror required/disabled/readonly at enhance time */
        txt.required = native.required;
        txt.disabled = native.disabled;
        txt.readOnly = native.readOnly;

        /* 3. Calendar icon button */
        var cal = document.createElement('button');
        cal.type      = 'button';
        cal.title     = 'Pick a date';
        cal.innerHTML = '<i class="bi bi-calendar3"></i>';
        cal.style.cssText = 'position:absolute;right:6px;top:50%;transform:translateY(-50%);'
            + 'background:none;border:none;padding:2px 4px;cursor:pointer;'
            + 'color:#5483B3;font-size:0.95rem;line-height:1;z-index:2;'
            + 'display:flex;align-items:center;justify-content:center;';

        /* 4. Hide native — keep in DOM for form value */
        native.style.cssText = 'position:absolute;opacity:0;pointer-events:none;'
            + 'width:1px;height:1px;overflow:hidden;';
        native.className = '';
        /* Remove required from native — we validate via txt instead */
        native.required  = false;

        /* 5. Assemble */
        native.parentNode.insertBefore(wrap, native);
        wrap.appendChild(txt);
        wrap.appendChild(cal);
        wrap.appendChild(native);

        /* Store txt reference on wrap for lguSetDate */
        wrap._lguTxt = txt;

        /* 6. Sync txt → native */
        function syncToNative() {
            var iso = isDTL ? displayToDTL(txt.value) : displayToISO(txt.value);
            if (native.value !== iso) {
                native.value = iso;
                native.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        /* 7. Input → mask + sync */
        txt.addEventListener('input', function () {
            var caretBefore   = txt.selectionStart || 0;
            var digitsBefore  = (txt.value.slice(0, caretBefore).match(/\d/g) || []).length;
            applyMask(txt, isDTL);
            syncToNative();
            /* Restore caret */
            var pos = 0, cnt = 0;
            for (var i = 0; i < txt.value.length; i++) {
                if (/\d/.test(txt.value[i])) cnt++;
                if (cnt >= digitsBefore) { pos = i + 1; break; }
            }
            try { txt.setSelectionRange(pos, pos); } catch(e) {}
        });

        /* 8. Keydown — digits only */
        txt.addEventListener('keydown', function (e) {
            var ok = ['Backspace','Delete','ArrowLeft','ArrowRight','ArrowUp','ArrowDown',
                      'Tab','Home','End','Enter'];
            if (ok.indexOf(e.key) !== -1 || e.ctrlKey || e.metaKey) return;
            if (/^\d$/.test(e.key)) return;
            e.preventDefault();
        });

        /* 9. Paste */
        txt.addEventListener('paste', function (e) {
            e.preventDefault();
            var p = (e.clipboardData || window.clipboardData).getData('text');
            if (/^\d{4}-\d{2}-\d{2}/.test(p)) {
                txt.value = isDTL ? dtlToDisplay(p) : isoToDisplay(p);
            } else {
                txt.value = p.replace(/\D/g, '');
                applyMask(txt, isDTL);
            }
            syncToNative();
        });

        /* 10. Blur */
        txt.addEventListener('blur', syncToNative);

        /* 11. Calendar button — open native picker */
        cal.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            native.style.cssText = 'position:absolute;opacity:0;pointer-events:all;'
                + 'width:1px;height:1px;overflow:hidden;';
            try { native.showPicker(); }
            catch (_) {
                native.focus();
                native.click();
            }
            native.style.cssText = 'position:absolute;opacity:0;pointer-events:none;'
                + 'width:1px;height:1px;overflow:hidden;';
        });

        /* 12. Native change (calendar pick / lguSetDate) → update txt */
        native.addEventListener('change', function () {
            var disp = isDTL ? dtlToDisplay(native.value) : isoToDisplay(native.value);
            if (txt.value !== disp) txt.value = disp;
        });

        /* 13. Form submit — final sync */
        var form = native.closest('form');
        if (form && !form._lguSubmitPatched) {
            form._lguSubmitPatched = true;
            form.addEventListener('submit', function () {
                form.querySelectorAll('[data-lgu-date-wrapped]').forEach(function (n) {
                    var w = n.parentNode;
                    if (!w || !w._lguTxt) return;
                    var isDtl = n.type === 'datetime-local';
                    var iso   = isDtl ? displayToDTL(w._lguTxt.value)
                                      : displayToISO(w._lguTxt.value);
                    if (iso) n.value = iso;
                });
            });
        }

        /* 14. Watch for required/disabled/readonly changes on native
               (e.g. editOfficial sets offBirthdate.required = false) */
        var attrObserver = new MutationObserver(function () {
            /* Only mirror required — disabled/readonly less likely to change */
            txt.required = native.required;
        });
        attrObserver.observe(native, { attributes: true, attributeFilter: ['required','disabled','readonly'] });
    }

    /* ─── Init all inputs in a root ───────────────────────────── */
    function initAll(root) {
        (root || document).querySelectorAll(
            'input[type="date"], input[type="datetime-local"]'
        ).forEach(enhance);
    }

    /* ─── Public API ──────────────────────────────────────────── */
    window.lguDatepickerInit = initAll;

    /**
     * Programmatically set a date input's value and update the visible field.
     * @param {string|HTMLInputElement} idOrEl   native input ID or element
     * @param {string}                  isoValue  YYYY-MM-DD  or  ''
     */
    window.lguSetDate = function (idOrEl, isoValue) {
        var native = typeof idOrEl === 'string'
            ? document.getElementById(idOrEl) : idOrEl;
        if (!native) return;
        native.value = isoValue || '';
        /* Fire change — the native listener on wrap._lguNative updates txt */
        native.dispatchEvent(new Event('change', { bubbles: true }));
        /* Belt-and-suspenders: also set txt directly via wrap reference */
        var wrap = native.parentNode;
        if (wrap && wrap._lguTxt) {
            var isDTL  = native.type === 'datetime-local';
            var disp   = isDTL ? dtlToDisplay(isoValue || '')
                               : isoToDisplay(isoValue || '');
            wrap._lguTxt.value = disp;
        }
    };

    /* ─── Initial run ─────────────────────────────────────────── */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initAll(); });
    } else {
        setTimeout(initAll, 0);
    }

    /* ─── MutationObserver — catch dynamically added inputs ───── */
    var domObserver = new MutationObserver(function (mutations) {
        mutations.forEach(function (mut) {
            mut.addedNodes.forEach(function (node) {
                if (node.nodeType !== 1) return;
                var sel = 'input[type="date"], input[type="datetime-local"]';
                if (node.matches && node.matches(sel)) {
                    setTimeout(function () { enhance(node); }, 0);
                }
                if (node.querySelectorAll) {
                    node.querySelectorAll(sel).forEach(function (inp) {
                        setTimeout(function () { enhance(inp); }, 0);
                    });
                }
            });
        });
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            domObserver.observe(document.body, { childList: true, subtree: true });
        });
    } else {
        domObserver.observe(document.body, { childList: true, subtree: true });
    }

})();
