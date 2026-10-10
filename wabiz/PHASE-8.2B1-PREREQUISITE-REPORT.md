# WABiz Phase 8.2B.1 — Restore Test Prerequisites

**Date:** 2026-10-09 (Asia/Bangkok)  
**Result:** **PARTIAL — Composer install stopped on access/write failure**

## Scope and safety

Read-only inspection was followed by the one Composer install explicitly approved by the user. No source/config edit, permission change, cache clear, migration, test, database operation, or service startup was performed. No `.env` contents, credentials, callback URL, or secret values were read into output or included here. Phase 8.3 was not started.

## Findings

### PHP and Composer

- `php --version` — exit **0**; PHP CLI **8.5.11** (64-bit ZTS build).
- `where.exe php` — exit **0**; executable resolved to `C:\PHP\php-8.5.11-Win32-vs17-x64\php.exe`.
- `composer --version` — exit **0**; Composer **2.10.3**, running under PHP 8.5.11.
- `where.exe composer` — exit **0**; resolved to `C:\ProgramData\ComposerSetup\bin\composer` and `.bat` shim.

### Backend dependency files

| Path | Result |
| --- | --- |
| `C:\WABiz\wabiz-backend\composer.json` | Present; JSON read successfully. Root PHP requirement is `^8.3`. |
| `C:\WABiz\wabiz-backend\composer.lock` | Present; JSON read successfully; 77 production and 38 development packages locked. |
| `C:\WABiz\wabiz-backend\vendor\autoload.php` | Present (748 bytes by filesystem metadata), but PHP reports it is not readable. |
| `C:\WABiz\wabiz-backend\vendor` | Present but PHP reports the directory is not readable. |

The project requires PHP `^8.3`; installed PHP 8.5.11 satisfies that constraint. The lockfile has a platform requirement section and a content hash.

### Required extensions / platform requirements

`php -m` exited **0**. `composer check-platform-reqs --lock --no-plugins --no-interaction`, run from the backend directory, exited **0**. Composer reported all locked platform requirements successful, including PHP 8.5.11 and required extensions such as `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `openssl`, `pcre`, `phar`, `session`, `tokenizer`, `xml`, and `xmlwriter`. `ctype` and `mbstring` are satisfied through Symfony polyfills.

`composer validate --no-check-publish --no-interaction` exited **0** and reported `composer.json` valid. A first `composer check-platform-reqs` invocation from the Flutter workspace exited **1** because that directory has no `composer.json`; it made no changes. The correctly scoped backend invocation then passed.

### Test bootstrap status

`php -r` from the backend confirmed:

- `vendor/autoload.php` exists: **yes**
- `vendor/autoload.php` readable by PHP: **no**
- `vendor` directory readable by PHP: **no**

This is the concrete blocker from Phase 8.2B. It is a PHP-level access failure, not evidence that the lockfile is missing or the declared PHP/platform requirements are incompatible. No permission workaround was attempted. Since PHP cannot read the current vendor tree, tests and further Laravel runtime verification were not run. The previously inspected PHPUnit database isolation/provider configuration remains static evidence only; no test database was accessed in this phase.

## Commands and exit codes

| Command | Exit | Result |
| --- | ---: | --- |
| `php --version` | 0 | PHP 8.5.11. |
| `composer --version` | 0 | Composer 2.10.3 on PHP 8.5.11. |
| `where.exe php` | 0 | PHP executable resolved. |
| `where.exe composer` | 0 | Composer shims resolved. |
| Filesystem metadata inspection for `vendor/autoload.php`, `composer.json`, `composer.lock` | 0 | All three files exist. |
| `php -m` | 0 | Module inventory obtained. |
| `composer check-platform-reqs --lock --no-plugins --no-interaction` from Flutter workspace | 1 | No composer.json in that working directory; no change. |
| Same `composer check-platform-reqs` command from backend | 0 | Locked PHP/platform requirements pass. |
| `composer validate --no-check-publish --no-interaction` from backend | 0 | composer.json valid. |
| PHP `file_exists` / `is_readable` check for backend autoloader and vendor directory | 0 | Exists, but unreadable by PHP. |
| `composer install --no-interaction --prefer-dist --no-scripts --no-plugins` from exact backend directory | 1 | Access/write failure category; Composer and lock files unchanged. |
| Post-install PHP readability check | 0 | Autoloader still exists but is unreadable; vendor directory remains unreadable to PHP. |

No test, migration, or service command was run.

## Can dependencies be restored from the lockfile?

**The approved lockfile-based install was attempted but did not complete.** Both Composer files exist, Composer validation passes, and the locked platform requirements pass with the installed PHP/extensions. The command exited 1 with a redacted access/write failure category. Composer/lock files remained unchanged. The autoloader and vendor directory remain unreadable to PHP.

The project declares Composer scripts. In particular, normal `post-autoload-dump` invokes Laravel package discovery, which may write bootstrap cache files. The `setup` script is substantially broader and includes key generation, migration, npm install, and build. **Neither was run.** Any future install must explicitly avoid scripts and plugins if the goal is dependency restoration only, and must not invoke the project `setup` script.

### Installation result

Executed from the verified directory `C:\WABiz\wabiz-backend`:

```powershell
composer install --no-interaction --prefer-dist --no-scripts --no-plugins
```

Exit code: **1**. Output was captured in memory and only the safe failure category was emitted; raw Composer output was not retained or placed in this report. `composer.json` and `composer.lock` remained unchanged. Composer reported two safe operation-summary lines before failure, but their detailed package output was not retained. Thus, exact files written within `vendor` before failure cannot be established; treat `vendor` as potentially partially updated. No files were deleted or restored and no retry was attempted.

## Baseline and project state

- Baseline manifest was not changed.
- Its SHA-256 observed after inspection remains `827b71775cab29f3b6696650bb6e9f3b626b509088f17c5e4426d84a195e32b5`.
- No application source/config files or lock files were changed. Composer may have partially changed files under `vendor`; exact package-level file changes are unverified. This report is the only new project artifact.

## Next steps requiring approval

1. The approved install attempt failed on access/write constraints. A human must determine the supported access path for the backend vendor tree. No permission change, administrator elevation, vendor deletion, workaround, or retry was attempted; a separate approval is needed before another installation plan.
2. After PHP can read the autoloader, re-check `phpunit.xml`, test database isolation, and provider fakes read-only before asking to run any database-backed tests.
3. Keep service startup/restart behind separate explicit approval. Do not claim runtime verification from this prerequisite report.

**Runtime is not verified. Phase 8.3 was not started.**
