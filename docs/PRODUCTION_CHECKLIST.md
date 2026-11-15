# Production readiness checklist

Internal checklist for maintainers (excluded from Packagist dist via `.gitattributes`).

## Must before tagging a release

- [ ] `composer validate --strict`
- [ ] `composer test` / `./vendor/bin/pest` green
- [ ] CI on `main` green (PHP × Laravel × MoonShine matrix)
- [ ] `composer.json` authors / homepage / support filled (no placeholders)
- [ ] `.gitattributes` `export-ignore` present; dry-run archive has no `tests/`, `docs/`, `.github/`, but **does** include `skills/`
- [ ] README matches shipped features (tabs, Webmaster, CSV, registry)
- [ ] `CHANGELOG.md` has an entry for the version being tagged
- [ ] Tag `vX.Y.Z` and ensure Packagist updates (or webhook)

## Dist should contain

- `src/`, `config/`, `resources/`, `routes/`, `skills/`, `composer.json`, `LICENSE`, `README.md`, `CHANGELOG.md`

## Dist should NOT contain

- `tests/`, `docs/`, `.github/`, `phpunit.xml.dist`, `.env.example`, local `vendor/`, `composer.lock`

## Host-app smoke (FamFi or similar)

- [ ] Menu page loads Metrika + Webmaster tabs
- [ ] Period filter works on Metrika; queries period on Webmaster
- [ ] CSV downloads under MoonShine auth
- [ ] Invalid OAuth → alert on Yandex tabs only; third-party tabs still usable if registered
