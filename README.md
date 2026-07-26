# Reference Checker (assignsubmission_refchecker)

An assignment submission plugin for Moodle that extracts the reference list from a student's
submission and checks each reference against public bibliographic databases in the background.

Students get early feedback on references that could not be found, or that do not match what was
cited. Teachers get a per-reference report alongside the submission when grading.

## Features

- Reads the reference list from the student's uploaded file (PDF, DOCX, DOC, ODT, RTF, TXT), or from
  a plain-text **References** box that can be shown on the submission form.
- Detects the bibliography section, splits it into individual references, and parses APA, Vancouver
  and generic citation formats.
- Looks each reference up in **CrossRef**, **OpenAlex**, **arXiv**, **DBLP** and
  **Semantic Scholar**, stopping early on a confident, year-consistent match.
- Classifies each reference as **verified**, **partial**, **mismatch** or **not found**, with a
  confidence score and separate title, author and journal sub-scores.
- Flags actionable discrepancies: author mismatch, cited year differing from the found year, and a
  cited DOI that differs from the found DOI.
- Flags **retracted** works (authoritatively from CrossRef and OpenAlex) and journals appearing on a
  bundled **predatory publisher** list.
- Reports temporal statistics for the reference list: oldest, newest and average year, plus total and
  average citation counts.
- Checking runs entirely in Moodle cron as adhoc tasks — the student's submission is never delayed.
- Site-wide result cache, per-source rate limiting, circuit breakers and a task concurrency ceiling,
  so a whole cohort submitting at a deadline stays inside the external services' limits.
- Per-assignment control of how much students see: status only, summary, or the full report.
- Teachers can re-run a check from the report.
- Full Moodle integration: capabilities, privacy provider, backup and restore, event logging, a
  system status check and a CLI diagnostic.

## Requirements

- Moodle 4.5 (2024100700)
- Cron running regularly — all checking happens in scheduled and adhoc tasks
- Outbound HTTPS access to the enabled bibliographic databases
- **Optional:** `pdftotext` (poppler-utils) for PDF submissions
- **Optional:** Moodle's document converter (normally LibreOffice) for DOCX, DOC, ODT and RTF
  submissions

By default the plugin reads the files submitted through the **File submissions** plugin, so that
plugin normally needs to be enabled on the assignment too. The exception is when **Require text
references** is set to Yes, in which case the pasted reference list is used and File submissions is
not needed.

## Installation

1. Copy the `refchecker` folder to `mod/assign/submission/refchecker` in your Moodle installation.
2. Visit **Site administration > Notifications** to complete the installation.
3. Enable the plugin in **Site administration > Plugins > Assignment > Submission plugins > Manage
   assignment submission plugins**.
4. Set a contact email (see below) and check **Site administration > Reports > System status** for
   the plugin's health check.

## Configuration

Site-level settings are available under **Site administration > Plugins > Assignment > Submission
plugins > Reference Checker**:

### External databases

- **Contact email** - Identifies your site to CrossRef and OpenAlex, which moves requests into their
  "polite pools" with far more generous rate limits. This is the highest-impact setting on the page.
- **Sources** - Which databases to query. Default: CrossRef, OpenAlex, arXiv, DBLP.
- **Semantic Scholar API key** - Required before enabling Semantic Scholar; without a key the service
  places every anonymous caller in one shared pool and refuses requests made seconds apart.
- **Minimum interval** (per source) - Minimum gap between requests. Defaults: CrossRef 100 ms,
  OpenAlex 100 ms, arXiv 3000 ms, DBLP 1000 ms, Semantic Scholar 3000 ms.

### Text extraction

- **Path to pdftotext** - Default `/usr/bin/pdftotext`.
- **Use LibreOffice** - Convert Word, ODT and RTF submissions via Moodle's document converter.
- **Supported file types** - Default PDF, DOCX, ODT, RTF, TXT.
- **Maximum file size** - Default 20 MB.

### Throughput

- **Chunk size** - References checked per task run (default 10).
- **Maximum concurrency** - Concurrent checking tasks site-wide (default 2). This is the hard ceiling
  on how fast the site talks to the external services.
- **Delay between chunks** - Default 0.
- **Request timeout** - Default 30 seconds.
- **Maximum references per submission** - Default 200; students are told when their list was
  truncated.
- **Cache lifetime** - How long a shared lookup result is reused (default 30 days).
- **Stale job timeout** - How long before an in-progress job is considered stalled (default 6 hours).
- **Retention period** - Optionally blank stored reference text after this many days, keeping the
  result (default 0, keep indefinitely).

### What students are shown

- **Student information** - HTML shown on the assignment page explaining what the check does.
- **Privacy notice** - Optional HTML notice, shown on the assignment page.
- **Default student display** - Status only (default), Summary, or Full report.
- **Default check timing** - On submit (default) or on every save.
- **Default require text references** - No (default), Yes - Optional, or Yes - Required.

Per-assignment settings are available when editing an assignment under the **Submission types**
section:

- **Show students** - Status only, Summary, or Full report.
- **Check references** - On submit for grading, or on every save including drafts.
- **Maximum references per submission**.
- **Require text references** - No, Yes - Optional, or Yes - Required. When enabled, students paste
  their reference list into a plain-text box, which is considerably more reliable than extracting it
  from a file.

## Usage

1. Enable **Reference Checker** on an assignment, alongside **File submissions** (or set **Require
   text references** to Yes).
2. A student submits. On the next cron run the reference list is extracted and each reference is
   looked up.
3. The status line appears on the assignment page and in the grading table, showing progress while
   the check runs.
4. Teachers open the full report from the grading view: a dashboard of counts, a filter bar, and a
   card per reference showing what was cited, what was found, the confidence, any issues, and a
   Google Scholar search link.

Note that "not found" is frequently a coverage gap rather than a fabrication — a correctly cited book
or report may simply not be indexed by any of these databases. The report is designed to be read that
way, and each reference carries a search link so it can be checked directly.

## Capabilities

- `assignsubmission/refchecker:viewfullreport` - Always see the full per-reference report, regardless
  of the assignment's student display setting. Granted to teacher, editingteacher and manager by
  archetype, and deliberately not to students.

Re-running a check requires `mod/assign:grade`.

## Scheduled tasks

- `\assignsubmission_refchecker\task\reconcile_jobs` - Runs every 2 hours. Purges expired cache
  entries, applies the retention period, and revives or fails jobs that have stalled.

Checking itself uses adhoc tasks (`extract_references`, `check_references`), queued in response to a
submission.

## Diagnostics

A system status check is registered under **Site administration > Reports > System status**. It warns
about a missing contact email, a missing or non-executable `pdftotext`, no sources enabled, Semantic
Scholar enabled without an API key, and any enabled source that fails its availability probe.

A CLI probe checks configuration and reachability, and resolves a single reference end to end:

```bash
php mod/assign/submission/refchecker/cli/check_sources.php
php mod/assign/submission/refchecker/cli/check_sources.php -r="Smith, J. (2023). A paper that does not exist. Nature, 999."
```

## Privacy

The plugin stores the extracted references and the results of checking them, plus the reference list
a student pasted in. All of it is declared to the privacy API, exported under the submission's
subcontext, and deleted with the submission, user or assignment.

Reference text is sent to the enabled external databases in order to look it up; each is declared as
an external location in the privacy provider. The site-wide result cache is keyed on a one-way hash
and deliberately holds no student-authored text.

## Documentation

[docs/architecture.md](docs/architecture.md) documents the pipeline, per-source behaviour, rate
limiting and caching, the database schema, and the design rules behind the matching thresholds.

## Credits

The concepts and several algorithms — reference-list detection and splitting, the similarity
measures, the match classification thresholds and the predatory publisher list — are ported from
**References-Validation** ("CheckIfExist") by Diletta Abbonato, MIT licensed:
<https://github.com/zabbonat/References-Validation>. That project is a browser-based tool; this
plugin reimplements the approach server-side for a whole cohort, with shared request pacing, caching
and durable background jobs. See [docs/architecture.md](docs/architecture.md) section 2 for what was
ported and what was changed.

## License

This plugin is licensed under the [GNU GPL v3 or later](https://www.gnu.org/copyleft/gpl.html).

## Author

Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
