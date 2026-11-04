# CSRF protection: implementation at testing

## Ano ang CSRF?

Ang Cross-Site Request Forgery (CSRF) ay pag-udyok sa naka-login na browser
na magpadala ng request na nagpapalit ng data nang hindi sinasadya ng user.
Ang token dito ay isang secret value na nakaimbak sa PHP session. Kailangang
isama ito sa request na nagpapalit ng data; ihahambing ito ng server sa token
ng session bago magpatuloy.

CSRF token ay hindi kapalit ng login, role/permission checks, input validation,
o HTTPS. Dapat pa ring gamitin ang mga iyon.

## Eksaktong implementation sa system

Nasa `helpers/functions.php` ang helpers na ito:

| Helper | Ginagawa |
|---|---|
| `generateCsrfToken()` | Gumagawa ng 32 random bytes (`random_bytes(32)`) at kinokonvert sa 64-character hexadecimal string. Ibinabalik ang kasalukuyang `$_SESSION['csrf_token']` kung mayroon na. |
| `csrfField()` | Gumagawa ng hidden `<input name="csrf_token">` para sa HTML form; ine-escape ang value. |
| `csrfMeta()` | Nagbibigay ng token para sa `<meta name="csrf-token">` at JavaScript/AJAX. |
| `verifyCsrfToken()` | Tinitingnan ang `$_POST['csrf_token']`; kung walang laman, ginagamit ang `X-CSRF-Token` request header. Inihahambing sa `hash_equals()`. |
| `verifyCsrfOrAbort()` | Kapag invalid o nawawala ang token, nagbabalik ng HTTP 403, JSON para sa AJAX o HTML para sa browser, at agad na humihinto. |

Hindi iniikot ang token sa bawat request. Napananatili ito sa kasalukuyang
session para hindi mapawalang-bisa ang token ng sabay-sabay na AJAX at form
requests. Pagkatapos ng login, tinatawag ng `loginUser()` ang
`session_regenerate_id(true)`; sa logout, sinisira at sinisimulan muli ang
session.

## Aling bahagi ang nilalagyan?

1. **Bawat request na nagbabago ng data:** ilagay ang
   `verifyCsrfOrAbort()` sa server-side handler bago basahin o gamitin ang
   anumang input at bago gumawa ng pagbabago sa database.
2. **Bawat HTML POST form:** ilagay ang `<?= csrfField() ?>` sa loob ng form.
3. **AJAX/fetch POST:** ipadala ang token sa `csrf_token` body field o
   `X-CSRF-Token` header. Dapat pa ring tumawag sa
   `verifyCsrfOrAbort()` ang server endpoint.
4. **Read-only GET:** hindi nilalagyan ng CSRF token kung talagang walang
   pagbabago ng state. Huwag gumamit ng GET para sa delete/update/action.

## Halimbawa: login form ng system

Ang login page at handler ay nasa `home.php`. Ang form ay naglalaman ng:

```php
<form method="POST" id="loginForm" onsubmit="submitLogin(event)">
    <?= csrfField() ?>
    <!-- username, password, at role fields -->
</form>
```

Karaniwang fallback POST handler:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action'])) {
    verifyCsrfOrAbort();
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    // Login logic
}
```

Sa normal na browser flow, pinipigilan ng JavaScript ang default form submit at
nagpapadala ng AJAX POST. Kinukuha nito ang token mula sa
`<meta name="csrf-token">` (na ginagawa sa parehong session) at ipinapadala
bilang `csrf_token` sa request body. Ang hidden field ay ginagamit ng normal
form/fallback POST. Vine-verify ng `home.php` ang token sa AJAX login path bago
iproseso ang login. Kaya parehong may proteksiyon ang normal fallback at AJAX
path.

## Halimbawa: ibang POST endpoint

```php
<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();

    $name = sanitize($_POST['name'] ?? '');
    // Validate input, then save it.
}
```

Form:

```php
<form method="POST">
    <?= csrfField() ?>
    <input name="name" required>
    <button type="submit">Save</button>
</form>
```

AJAX alternative:

```php
<meta name="csrf-token" content="<?= htmlspecialchars(csrfMeta(), ENT_QUOTES, 'UTF-8') ?>">
```

```js
const token = document.querySelector('meta[name="csrf-token"]').content;
fetch('/ajax/update.php', {
    method: 'POST',
    headers: { 'X-CSRF-Token': token },
    body: new URLSearchParams({ name: 'Sample' })
});
```

Kung JSON ang body, gumamit ng header dahil ang helper ay nagbabasa ng
`$_POST` o `X-CSRF-Token`, hindi ng raw JSON body.

## Automated helper checks

Patakbuhin sa project root:

```powershell
php tests/csrf_check.php
```

Sinusubukan nito ang:

- format at pagiging stable ng token sa parehong session;
- pagtanggap sa tamang token sa form at header;
- pagtanggi sa nawawala, maling, o malformed na token;
- pag-uuna ng form token kapag may laman ito (hindi ito dapat ma-override ng
  header);
- pag-render ng `csrfField()` bilang hidden input.

Hindi kailangan ng database o PHPUnit. Ang exit code na `0` ay nangangahulugang
pumasa ang mga helper checks; `1` ay may pumalyang check.

## Static inventory at manual validation

Kasabay ng unit checks, ini-scan ng script ang project PHP files (hindi kasama
ang `vendor/` at `uploads/`) at inililista ang mga file na may POST-related
source, kung may CSRF helper reference, at kung ang literal na POST forms ay
may token reference.

Ang scan ay **hindi** PHP parser, browser, o HTTP integration test. Hindi nito
mapatutunayan na lahat ng conditional/action branch ay protektado, na ang
JavaScript ay talagang nagpapadala ng token, o na walang database write bago
ang validation. Ang “guard helper found” ay paunang palatandaan lamang. Suriin
ang bawat route sa output lalo na ang dynamic forms at AJAX.

Para sa end-to-end verification sa local o staging copy:

1. Gumamit ng test account at test records; huwag gumamit ng production data.
2. Mag-login at buksan ang bawat screen na may create/edit/delete/approve o
   katulad na action.
3. Sa browser Developer Tools → **Network**, magsagawa ng isang normal na
   action. Tiyaking POST request ay may `csrf_token` body field o
   `X-CSRF-Token` header at ang action ay matagumpay.
4. Ulitin ang request gamit ang browser Network tool (Resend/Edit and Resend):
   alisin ang token, saka subukan ulit na pinalitan ito ng random na value.
   Gawin lamang ito sa test environment. Dapat 403 ang sagot ng server at
   walang magbago sa data.
5. Suriin sa UI o test database na walang nagbago pagkatapos ng mga request na
   walang token o may maling token. Dapat manatiling valid ang token sa parehong
   session pagkatapos ng normal na request; hindi ito iniikot kada request.
6. Ulitin ito sa lahat ng state-changing route at role na naaangkop.

Ang `csrf_check.php` ay hindi nagpapadala ng HTTP request at hindi nagbabago
ng data. Para makumpirmang protektado ang lahat ng login at data-changing
route, kailangan pa rin ang staging HTTP tests at source code review.
