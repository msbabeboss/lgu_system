# CSRF Patterns — copy-paste snippets

---

## 1. Standard HTML form (server-side POST)

```php
<form method="POST">
    <?= csrfField() ?>
    <!-- your fields -->
    <button type="submit">Save</button>
</form>
```

PHP handler (top of the `if POST` block):

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    // ... rest of handler
}
```

---

## 2. Multiple independent forms on one page

Each form gets its own `csrfField()`.
The token is the same value for the whole session, so duplication is safe.

```php
<!-- Form 1 -->
<form method="POST" id="mainForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="edit">
    ...
</form>

<!-- Form 2 — outside Form 1, never nested -->
<form method="POST" id="deleteForm" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="item_id" id="deleteItemId">
</form>
```

> ⚠️ **Never nest `<form>` inside another `<form>`.**  
> HTML ignores inner form tags; the outer form breaks silently.  
> Always place secondary forms as siblings at the bottom of the page.

---

## 3. AJAX / fetch() — meta tag pattern

In the `<head>` of the page:

```php
<meta name="csrf-token" content="<?= csrfMeta() ?>">
```

In JavaScript:

```js
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

fetch('/ajax/some_endpoint.php', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF
    },
    body: JSON.stringify({ key: 'value' })
});
```

PHP endpoint:

```php
verifyCsrfOrAbort();   // checks X-CSRF-Token header automatically
```

---

## 4. AJAX — FormData pattern (file uploads / multipart)

```js
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const fd   = new FormData();
fd.append('action', 'upload');
fd.append('csrf_token', CSRF);   // field in body, not header
fd.append('file', fileInput.files[0]);

fetch('/ajax/upload.php', { method: 'POST', body: fd });
```

---

## 5. Dynamically created forms in JavaScript

```js
function submitWithCsrf(action, extraFields) {
    const meta  = document.querySelector('meta[name="csrf-token"]');
    const form  = document.createElement('form');
    form.method = 'POST';
    form.action = '';   // same page

    const csrfInput   = document.createElement('input');
    csrfInput.type    = 'hidden';
    csrfInput.name    = 'csrf_token';
    csrfInput.value   = meta?.content || '';
    form.appendChild(csrfInput);

    const actionInput  = document.createElement('input');
    actionInput.type   = 'hidden';
    actionInput.name   = 'action';
    actionInput.value  = action;
    form.appendChild(actionInput);

    for (const [name, value] of Object.entries(extraFields)) {
        const inp   = document.createElement('input');
        inp.type    = 'hidden';
        inp.name    = name;
        inp.value   = value;
        form.appendChild(inp);
    }

    document.body.appendChild(form);
    form.submit();
}

// Usage:
submitWithCsrf('archive', { resident_id: 42, reason: 'Deceased' });
```

---

## 6. Token refresh after long sessions

Call this whenever the page may have been idle and the session token
could have been regenerated (e.g. after a login redirect):

```js
async function refreshCsrfToken() {
    const res  = await fetch('/ajax/get_csrf_token.php');
    const data = await res.json();
    if (data.csrf_token) {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.content = data.csrf_token;
        // Also update any hidden fields already in the DOM
        document.querySelectorAll('input[name="csrf_token"]').forEach(f => {
            f.value = data.csrf_token;
        });
    }
}
```

---

## 7. AJAX endpoint boilerplate

```php
<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');          // auth first
header('Content-Type: application/json');

verifyCsrfOrAbort();                    // CSRF second

$db = Database::getInstance()->getConnection();
// ... handler logic
echo json_encode(['ok' => true]);
```

---

## Common mistakes

| Mistake | Fix |
|---|---|
| Nested `<form>` tags | Move secondary forms outside the parent form |
| Forgetting `csrfField()` in a new form | Add `<?= csrfField() ?>` as the very first child of every `<form method="POST">` |
| AJAX without header | Add `'X-CSRF-Token': CSRF` to fetch headers |
| Using raw `$_SESSION['csrf_token']` compare | Replace with `verifyCsrfOrAbort()` |
| Calling `verifyCsrfOrAbort()` after reading POST data | Always call it **first**, before touching any `$_POST` values |
