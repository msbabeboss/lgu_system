# CSRF Reference — LGU System

Quick-reference folder so you can see **at a glance** how every form and
AJAX call in the project is protected.  Nothing in here is executable —
it is documentation only.

---

## How the CSRF system works

| Function | Where | What it does |
|---|---|---|
| `generateCsrfToken()` | `helpers/functions.php` | Creates (or reuses) a session-tied 64-char hex token |
| `csrfField()` | `helpers/functions.php` | Returns `<input type="hidden" name="csrf_token" value="...">` |
| `csrfMeta()` | `helpers/functions.php` | Returns the raw token string for `<meta name="csrf-token">` |
| `verifyCsrfToken()` | `helpers/functions.php` | Checks `$_POST['csrf_token']` **or** `X-CSRF-Token` header |
| `verifyCsrfOrAbort()` | `helpers/functions.php` | Calls verify; sends 403 + JSON/HTML error if invalid |

The token is **session-scoped and does NOT rotate** per-request (rotation caused stale-token 403s on parallel AJAX calls).  It is regenerated only on login (`session_regenerate_id`) and logout.

---

## Quick checklist — every form needs

```html
<form method="POST">
    <?= csrfField() ?>   <!-- ← this line -->
    ...
</form>
```

Every AJAX `fetch()` / `XMLHttpRequest` needs:

```js
headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' }
```

Every POST handler needs (first line inside the `if POST` block):

```php
verifyCsrfOrAbort();
```

---

## Files reference

See `COVERAGE.md` for the complete per-file breakdown.
See `PATTERNS.md` for copy-paste code snippets.
