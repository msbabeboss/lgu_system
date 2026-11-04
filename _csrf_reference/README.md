# CSRF sa LGU System

Gamitin ang gabay na ito para malaman kung ano ang CSRF protection sa system,
saan ito ginagamit, at paano patakbuhin ang kasamang checks.

- [Detalyadong paliwanag at manual test procedure](./CSRF_DOCUMENTATION.md)
- [Mga code pattern](./PATTERNS.md)
- [Static inventory ng POST-related PHP files](./COVERAGE.md)
- Automated helper checks: `tests/csrf_check.php`

## Mabilis na patakbuhin

Mula sa project root sa terminal:

```powershell
php tests/csrf_check.php
```

Kapag pumasa ang helper checks, ibig sabihin gumagana ang mga token behavior na
sinubukan. Ipinapakita rin ng script ang static inventory; kailangan pa ring
suriin nang mano-mano ang bawat route at subukan sa local/staging environment.
Hindi ito end-to-end HTTP test at hindi nito binabago ang database.
