# Traceo — Agent Guide

## First-time setup
- DB: `mysql -u root -p < backend/sql/schema.sql` (creates `traceo` DB + seeds 4 pays)
- Config: `backend/config/database.php` — change `APP_SECRET` before production; set `APP_TIMEZONE`/`APP_TIMEZONE_OFFSET` to local time (default Africa/Porto-Novo +01:00)
- App at `http://localhost/Traceo/` — first visit picks role on `index.php`, login for existing, `inscription.php` to create school + first censeur

## Architecture
- **Zero external PHP deps** — PHP native + MySQL. XLSX via `ZipArchive`+`SimpleXML`. SMS via `file_get_contents`+stream context.
- **Multi-tenant**: every query filters by `etablissement_id` from `$_SESSION`. Missing filter = data leak.
- **Dual auth**: session-based for web pages (stateful), JWT (custom HS256, no lib) for `backend/api/` endpoints (stateless, used by mobile).
- **No npm/Node/build tools** — pure PHP, CSS, JS. Design tokens in `assets/css/app.css` (light/dark via `[data-theme]`).

## Security conventions (MANDATORY)
- **CSRF**: every web page that handles POST must call `require_csrf()` right after `require_web_login()` (or `csrf_valide()` for public pages like `login.php`/`inscription.php`/`oubli.php`), and every POST form must include `<?= csrf_field() ?>`. Helpers live in `_session.php`.
- **Multi-tenant on web pages**: never trust IDs from POST/GET. Use `verifier_ressource($pdo, 'table', $id, $etab_id)` (helper in `_session.php`) before INSERT/UPDATE/DELETE. API endpoints use `assert_belongs_to_etablissement()` from `backend/helpers/tenant.php` instead.
- **Login**: `login.php` has anti-brute-force (5 échecs → verrouillage 15 min, session-based) and calls `session_regenerate_id(true)` after success. Don't remove.
- **Uploads** (`import.php`): max 5 Mo (`IMPORT_MAX_OCTETS`), MIME check via `finfo` when available, `is_uploaded_file()`. XLSX parsed with `LIBXML_NONET`.
- **EDT conflicts**: `emploi_du_temps.php` and `import.php` reject créneau overlapping prof OR classe on the same day; `heure_fin` must be > `heure_debut`.
- **Password reset**: `oubli.php` uses table `code_reset` (see `backend/sql/migrations/001_mot_de_passe_oublie.sql`), code 6 chiffres envoyé via `SmsGateway` (log provider en dev), 15 min validity, max 3 demandes/heure. Never reveal whether an identifiant exists.
- **Tests**: `php backend/tests/run.php` — zero-dep runner; must stay green. DB-dependent tests skip cleanly if MySQL is down.
- Migrations: new schema changes go in BOTH `backend/sql/schema.sql` and `backend/sql/migrations/NNN_*.sql`.

## Role structure
| Role | Login redirect | Portal dir | Auth gate |
|---|---|---|---|
| censeur | `dashboard.php` | root | `_session.php` with `require_web_login(['censeur'])` |
| prof | `prof/dashboard.php` | `prof/` | `prof/_session.php` with `['prof']` |
| responsable_classe | `responsable/dashboard.php` | `responsable/` | `responsable/_session.php` with `['responsable_classe']` |
| surveillant | `surveillant/dashboard.php` | `surveillant/` | `surveillant/_session.php` with `['surveillant']` |

- Censeur-only pages: `classes.php`, `utilisateurs.php`, `responsables.php`, `affectations.php`, `matieres.php`, `filieres.php`, `emploi_du_temps.php`, `import.php`, `transition.php`.
- Shared (censeur+surveillant): `punitions.php`, `rapports.php`.
- `profil.php` open to all roles.
- `login.php?role=X` pre-selects role; registration link only for censeur.

## Layout conventions
- Pages declare `$titre` then `require_once __DIR__ . '/inc/header.php'`.
- `$root_rel` auto-computes asset path (`../` in subdirs, else `''`). Missing it in asset links breaks CSS.
- Flash messages: `$_SESSION['_flash_succes']` or `$_SESSION['_flash_erreur']` auto-render in header, auto-dismiss after 5s.

## DB quirks
- Presence: only records `absent`/`retard`. Present = **no row**.
- `seance.creneau_id` → `creneau`, NOT `classe_id`. Join through `JOIN creneau cr ON cr.id = s.creneau_id` then `cr.classe_id` (alias `cr`, not `c`).
- `seance.statut_validation` ENUM: `en_attente`, `validee`, `forcee_censeur`.
- `eleve.statut` ENUM: `actif`, `passe`, `redouble`, `parti`.
- `utilisateur.statut` ENUM: `actif`, `inactif`.
- `responsable_classe.statut` ENUM: `actif`, `remplace`. Max 2 actifs per class (enforced in `responsables.php` AND via trigger `trg_check_max_responsables` in `schema.sql`/migration 002).
- `seance.valide_par` FK references `responsable_classe.id`, not `utilisateur.id`. Deleting a responsable requires NULLing `seance.valide_par` first.

## Key page specifics
- `prof/dashboard.php`: day navigation via `?date=Y-m-d`, inline lesson form submits to `save_seance.php`. Shows class stats, upcoming sessions, past history.
- `prof/appel.php`: only `absent`/`retard` checkboxes (mutually exclusive via JS). Motif input shown/hidden on check. Saving deletes old presences for that seance and re-inserts.
- `prof/save_seance.php`: `INSERT … ON DUPLICATE KEY UPDATE` on `(creneau_id, date)`.
- `punitions.php`: class filter via `?classe_id=`. Default lists only students with recent infractions (`?tous=1` for all). `?histo=ID` shows per-student infraction history.
- `surveillant/dashboard.php`: class filter via `?classe_id=`. Links to student history and punishment creation.
- `responsables.php`: has both "create from student" (`action=creer_affecter` — picks from `eleve`, creates `utilisateur`+`responsable_classe`) and "assign existing account" (`action=affecter`). Delete (`action=supprimer`) removes `responsable_classe` row AND `utilisateur` row (with FK cleanup).
- `utilisateurs.php`: delete sets `statut='inactif'` (login filters `statut='actif'`); cannot delete own censeur account.
- `login.php`: now checks inactive accounts and shows dedicated message "Compte inactif — faute de destitution…".

## Notifications
- Provider config: `backend/config/notifications.php` (`'log'` for dev, `'africas_talking'`, `'twilio'`).
- Cron: `php backend/cron/traitement_notifications.php` — run hourly.

## Common pitfalls
- Forgetting `etablissement_id` filter in SQL queries (multi-tenant requirement).
- Using `c.classe_id` instead of `cr.classe_id` when joining `creneau` (alias is `cr`).
- Missing `<?= $root_rel ?>` in asset links in `inc/header.php` → CSS broken in subdir pages.
- `import.php` row skip: `if ($i < 1) continue` skips only header; `if ($i < 3)` would skip data rows.
- `seance.valide_par` references `responsable_classe.id`, not `utilisateur.id`. When deleting a responsable, NULL `seance.valide_par` first or FK constraint fails.
