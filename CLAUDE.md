# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Where you are

`assignsubmission_refchecker` is an **assign submission subplugin for Moodle 4.5 LTS**. It extracts a
student's reference list from their submission and looks each reference up in public bibliographic
databases (CrossRef, OpenAlex, arXiv, DBLP, Semantic Scholar) in cron.

This git repo is checked out **inside a Moodle core checkout**:

| Path | What it is |
|---|---|
| `/home/arowatt/moodle405_assignsubmission_refchecker` | Moodle 4.5 core (`MOODLE_405_STABLE`), a separate git repo. Referred to below as `$MOODLE`. |
| `$MOODLE/mod/assign/submission/refchecker` | **This repo.** `origin` is `andrewrowatt-masseyuni/moodle-assignsubmission_refchecker`, branch `main`. |
| `/home/arowatt/moodle-docker` | moodle-docker; the site runs in containers. |
| `/home/arowatt/moodle-plugin-ci` | moodle-plugin-ci v4, run from `$MOODLE`. |

Only ever commit into this plugin repo. Changes to files under `$MOODLE` outside this directory
belong to core and normally should not be made — the one legitimate exception is noted under
[Linting](#linting) below.

[docs/architecture.md](docs/architecture.md) is the authoritative design document (~1150 lines) and
explains the *why* behind almost every decision. Read the relevant section before changing the
pipeline, the sources, the rate limiting, or the display levels.

## Commands

All docker commands need these in the environment:

```bash
export COMPOSE_PROJECT_NAME=moodle405_assignsubmission_refchecker
export MOODLE_DOCKER_WWWROOT=/home/arowatt/moodle405_assignsubmission_refchecker
export MOODLE_DOCKER_DB=pgsql
DC=/home/arowatt/moodle-docker/bin/moodle-docker-compose
```

`.vscode/tasks.json` holds every command below as a VS Code task; "Run all tests" chains them in the
order CI uses.

### Tests

```bash
# One-time init (also after a DB reset)
$DC exec webserver php admin/tool/phpunit/cli/init.php
$DC exec webserver php admin/tool/behat/cli/init.php

# Whole PHPUnit suite for the plugin
$DC exec webserver vendor/bin/phpunit mod/assign/submission/refchecker/tests/. --test-suffix=_test.php

# One file
$DC exec webserver vendor/bin/phpunit mod/assign/submission/refchecker/tests/matcher_test.php

# One test
$DC exec webserver vendor/bin/phpunit mod/assign/submission/refchecker/tests/task_test.php --filter test_the_pacer_pauses_without_touching_the_references

# Core privacy provider test — this plugin's provider must not break it, and CI runs it separately
$DC exec webserver vendor/bin/phpunit privacy/tests/privacy/provider_test.php

# Behat
$DC exec -u www-data webserver php admin/tool/behat/cli/run.php --tags=@assignsubmission_refchecker --format progress
```

### Linting

Run the moodle-plugin-ci checks from `$MOODLE`:

```bash
cd $MOODLE
../moodle-plugin-ci/bin/moodle-plugin-ci phplint  ./mod/assign/submission/refchecker
../moodle-plugin-ci/bin/moodle-plugin-ci phpcs    ./mod/assign/submission/refchecker
../moodle-plugin-ci/bin/moodle-plugin-ci phpcbf   ./mod/assign/submission/refchecker   # autofix
../moodle-plugin-ci/bin/moodle-plugin-ci phpmd    ./mod/assign/submission/refchecker
../moodle-plugin-ci/bin/moodle-plugin-ci mustache ./mod/assign/submission/refchecker
```

PHPDoc check runs in the container and must exclude the vendored library:

```bash
$DC exec webserver php local/moodlecheck/cli/moodlecheck.php \
  -e=mod/assign/submission/refchecker/thirdparty -p=mod/assign/submission/refchecker -f=text
```

Grunt runs **from this plugin directory** and uses core's config:

```bash
grunt --max-lint-warnings=0 amd        # rebuilds amd/build/ from amd/src/ — commit both
grunt --max-lint-warnings=0 yui
grunt --max-lint-warnings=0 stylelint
grunt --max-lint-warnings=0 gherkinlint
```

`thirdparty/elephant-php/` is excluded from phpcs/eslint/stylelint by entries in **core's**
`$MOODLE/phpcs.xml`, `.eslintignore` and `.stylelintignore`. Those edits live outside this repo and
are lost if core is re-cloned; re-add them rather than touching the vendored sources. In CI the
exclusion comes from `thirdpartylibs.xml` instead, which moodle-plugin-ci reads.

### Site operations

```bash
$DC exec webserver php admin/cli/upgrade.php --non-interactive --verbose-settings   # install/upgrade
$DC exec webserver php admin/cli/purge_caches.php                                    # after template/AMD/lang edits
$DC exec webserver php admin/cli/uninstall_plugins.php --plugins=assignsubmission_refchecker --run

# Configuration + reachability probe, and one live end-to-end lookup
$DC exec webserver php mod/assign/submission/refchecker/cli/check_sources.php
$DC exec webserver php mod/assign/submission/refchecker/cli/check_sources.php -r="Smith, J. (2023). A paper. Nature, 999."
```

CI (`.github/workflows/moodle-ci.yml`) runs PHP 8.1 / `MOODLE_405_STABLE` / pgsql with
`phpcs --max-warnings 0`, `phpdoc --max-warnings 0`, `grunt --max-lint-warnings 3`, and
`phpunit --fail-on-warning`. PHP 8.1 is the floor — the target site runs it.

## Architecture

### The pipeline

```
mod_assign event  →  classes/event/observer.php
                     → job_manager::reset_for_submission()   (bump generation, clear counters)
                     → adhoc task extract_references          (files/pasted text → reference rows)
                     → adhoc task check_references            (chunked; re-queues itself)
                        → source\chain::check() → matcher::score() → job_manager::record_result()
                     scheduled task reconcile_jobs (every 2h) revives stalled jobs, purges cache
```

Nothing happens in the student's request. `action.php` (teacher "Re-run", needs `mod/assign:grade`)
is the same two steps as the observer.

### Load-bearing invariants

These are enforced by tests and are easy to break by accident:

- **`job_manager` owns every read and write of the plugin's tables.** No other class touches them.
  That is what makes the generation invariant enforceable.
- **Generation guard.** Every task run re-reads its job from the DB and stands down unless
  `generation` still matches the value in its custom data (`classes/task/job_task.php`). A
  resubmission mid-check bumps the generation so the in-flight task cannot overwrite newer results.
- **Observers must never throw; tasks must.** An observer runs inside the request that saved the
  submission, so a throw fails the student's submission. A throw in a task is how a retry is asked
  for. `observer.php` wraps its whole body in `try/catch`.
- **`rate_limited_exception` and `service_refused_exception` both extend `transient_exception`, so
  they must be caught before the parent.** They mean opposite things: the first is *our* pacer
  declining to send (pause and re-queue the task, spend no reference attempt); the second is *one
  database* turning us away (stand that source down, carry on down the chain). Getting this wrong is
  silent and records a real work as "not found". See architecture §4.6.
- **A "not found" is never written to the shared cache if any source could not be consulted**
  (`degraded`). One outage must not teach the whole site that a real work does not exist.
- **Nothing student-authored goes in `assignsubmission_refchecker_cache`.** It is site-wide, keyed on
  a one-way hash; `raw`, `rawref` and `query` are stripped before storing.
- **The display level is re-derived wherever the report renders**, never inherited from how the page
  was reached — `view()` is reachable directly by URL. `display_level::NONE` (0) is a suppression
  switch, not just a smaller report, and unrecognised values sanitise *down* to `NONE`.
- **Error codes and messages are teachers-only.** Students never see operational detail.
- **A confident wrong answer is worse than no answer.** Hence the 70% title-similarity floor, and
  requiring year agreement before the source chain stops early.

### Key files

| Concern | File |
|---|---|
| The subplugin class (all mod_assign hooks) | `locallib.php` |
| All DB access, counters, cache, generation | `classes/local/job_manager.php` |
| Source ordering, early stop, best-answer ranking | `classes/local/source/chain.php` |
| HTTP + status-code → exception mapping, pacing hook | `classes/local/source/http_source.php` |
| Scoring and issue detection | `classes/local/matcher.php` |
| Reference-list detection, splitting, metadata parsing | `classes/local/reference_parser.php` |
| Site-wide request pacing / per-source backoff | `classes/local/rate_limiter.php`, `circuit_breaker.php` |
| Optional activity log (off by default, writes to dataroot) | `classes/local/debug_log.php`, `logs.php` |

Five tables, all `assignsubmission_refchecker*`: the job (one per submission), refs, cache, the
pasted text, and the per-source rate/health row. Schema table-by-table in architecture §7.

### Conventions

- Namespaced classes under `classes/`; internals in `\assignsubmission_refchecker\local\`. Statuses
  and levels are const-holding classes (`job_status`, `match_status`, `display_level`,
  `check_timing`, `text_mode`), not enums.
- Tests are `final class …_test extends \advanced_testcase` with `@covers` on the class docblock and
  a docblock on every test method (the PHPDoc check enforces this). Test names read as sentences —
  `test_a_persistently_refused_source_still_finishes_the_job()`.
- Source tests never touch the network: `$this->get_mocked_http_client()` + queued
  `GuzzleHttp\Psr7\Response`s, with `rateinterval_*` set to 0. Fixtures build data through
  `tests/generator/lib.php`.
- Comments explain *why*, at some length, and the codebase is consistent about it. Match that
  density; a change that removes the reasoning behind a threshold or an ordering is a regression.

### Do not hand-edit `thirdparty/elephant-php/src/`

It is elephant-php v0.4.1 (BSD-2-Clause) mechanically rewritten from PHP 8.2 `readonly class` to 8.1
longhand by `thirdparty/elephant-php/apply-php81-patch.php`. Change the script and re-run it; it
asserts it found 42 classes and 101 properties and exits non-zero otherwise, and
`test_vendored_library_carries_the_php81_patch()` fails the suite on unpatched code. Full procedure
in `thirdparty/elephant-php/PATCHES.md`.

`readonly class` is a *parse* error before 8.2 and a parse error in an autoloaded file is fatal and
uncatchable — which is why `docx_converter::is_available()` is checked **before** `autoload.php` is
required. Keep that ordering.
