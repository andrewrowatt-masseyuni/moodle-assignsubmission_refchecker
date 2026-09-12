# Upstream sync

This plugin draws on two upstream projects, in two different ways:

| Upstream | Relationship | Design record |
|---|---|---|
| [References-Validation](https://github.com/zabbonat/References-Validation) ("CheckIfExist", MIT, Diletta Abbonato) | Concepts and algorithms **reimplemented** server-side. No upstream code is copied. | [architecture.md §2](architecture.md#2-relationship-to-the-upstream-project) |
| [elephant-php](https://github.com/endless-creativity/elephant-php) (BSD-2-Clause, Endless Creativity) | Source is **vendored** under `thirdparty/elephant-php/`, locally patched from PHP 8.2 to 8.1. | [PATCHES.md](../thirdparty/elephant-php/PATCHES.md) |

Those two documents explain *what* was taken and *why it diverges*. This file is the maintenance
side: **when each upstream was last reviewed, against which revision, and how to review it again.**

## Sync log

Newest first. **The next check starts from the "Newest upstream ref" of the most recent row for
that upstream** — that column is the baseline, so a check only ever reads what is genuinely new.
Append a row after every check, *including when nothing was ported*: a "no change" row is what
makes the following check cheap.

| Checked | Upstream | Baseline at check | Newest upstream ref | Outcome |
|---|---|---|---|---|
| 2026-09-13 | References-Validation | `103c9f4` (2026-07-05) | `e460565` (2026-08-23) | Nothing ported. The only two commits since the baseline add, then delete, `reviewers-copilot/` — a research replication package (LaTeX manuscript and Python analysis scripts) for a Scientometrics submission. No application code changed. |
| 2026-09-13 | elephant-php | v0.4.1 (`a85ad27`, 2026-06-29) | `d913102` (2026-06-30) | No re-vendor. v0.4.1 is still the newest tag; the commits after it touch only `README.md`, `composer.json` (Packagist description) and a logo asset. Vendored `src/` verified byte-identical to upstream v0.4.1 with the 8.1 patch applied. |

The References-Validation baseline in that first row is **derived, not recorded**: the port was made
at the plugin's initial commit (2026-07-25), and `103c9f4` was upstream's newest commit at that
date. It is written down here so nobody has to re-derive it. Every later row records a ref that was
actually checked.

## Running a check

Requires the GitHub CLI (`gh auth login` once). Substitute the baseline ref and date from the top
log row for that upstream.

### References-Validation

Upstream ships no tags or releases, so the baseline is a commit.

```bash
# New commits since the baseline. GitHub's `since` is inclusive, so the baseline commit itself
# appears as the last row — ignore it; anything above it is new.
gh api 'repos/zabbonat/References-Validation/commits?since=2026-08-23' \
  --jq '.[] | "\(.sha[0:7]) \(.commit.author.date[0:10]) \(.commit.message|split("\n")[0])"'

# For anything that looks relevant, check what it actually touched before reading the diff.
gh api repos/zabbonat/References-Validation/commits/<sha> --jq '.files[].filename'
```

### elephant-php

The baseline is a tag, so compare tag to tag.

```bash
# Is there a newer tag than the vendored one? (`thirdpartylibs.xml` records what is vendored.)
gh api repos/endless-creativity/elephant-php/tags --jq '.[].name'

# What has changed since the vendored tag. If no path under src/ appears, there is nothing to
# re-vendor however many commits there are.
gh api repos/endless-creativity/elephant-php/compare/v0.4.1...HEAD \
  --jq '.files[] | "\(.status) \(.filename)"'
```

If a newer tag exists, follow the re-vendoring recipe in
[PATCHES.md](../thirdparty/elephant-php/PATCHES.md), which also covers re-measuring
`EXPECTED_CLASSES` / `EXPECTED_PROPERTIES` and re-checking for newly adopted PHP 8.2+ constructs.

To confirm the vendored tree has not drifted from the tag it claims (it should be byte-identical
once patched):

```bash
git clone --depth 1 --branch v0.4.1 \
  https://github.com/endless-creativity/elephant-php.git /tmp/elephant
cp thirdparty/elephant-php/apply-php81-patch.php /tmp/elephant/
php /tmp/elephant/apply-php81-patch.php
diff -ru /tmp/elephant/src thirdparty/elephant-php/src   # expect no output
```

## Triage: what counts as significant

### References-Validation — port these

| Upstream change | Lands in |
|---|---|
| Reference-list detection or splitting heuristics, the multilingual heading vocabulary, the APA / Vancouver / generic metadata parsers | [reference_parser.php](../classes/local/reference_parser.php) |
| Similarity measures, or the 70% minimum title similarity floor | [matcher.php](../classes/local/matcher.php) |
| The `verified` ≥ 80 / `partial` ≥ 50 classification thresholds | [match_status.php](../classes/local/match_status.php) |
| Additions or removals in the predatory publisher / journal list | [data/predatorylist.json](../data/predatorylist.json) |
| API query construction, requested field lists, or fallback conditions for CrossRef, OpenAlex, arXiv, DBLP or Semantic Scholar | `classes/local/source/*` |

Thresholds and measures are kept identical to upstream on purpose, so results stay comparable
between the two tools. A change to any of them upstream is worth porting even when it looks minor —
but it will move results for existing submissions, so re-read the pinned expectations in
`tests/matcher_test.php` and `tests/source_test.php` before changing a number.

### References-Validation — ignore

Browser and UI work, the MCP server, badges and visitor counters, README and site copy, and
anything under `reviewers-copilot/` (the research replication package).

Some divergences are **deliberate and must not be "re-synced" back** — see architecture.md §2:

- the public CORS proxy upstream uses for arXiv (a browser restriction that does not apply here);
- upstream's "ask every source and merge" versus this plugin's chain with an early-stop rule;
- the Semantic Scholar `isRetracted` field, which no longer exists in the Graph API and fails the
  whole call if requested (there is a regression test for this).

### elephant-php — critical

- **Security fixes.** Treat as critical and re-vendor promptly. This is a live category: v0.3.0
  closed XXE, `javascript:` hyperlink and `r:link` image vectors, all reachable from a
  student-supplied `.docx`.
- **DOCX parsing correctness fixes**, which change what text reaches the reference parser.
- **A raised PHP floor or a newly adopted 8.2+ construct.** The whole local patch exists because
  upstream requires PHP 8.2 only for `readonly class`; if a release adopts some other 8.2+ feature,
  `apply-php81-patch.php` will not catch it and the shape assertion is the only warning you get.

Documentation, tooling, PHPStan and CI changes upstream need nothing here — only `src/` is vendored.

## After a check

1. Append a row to the sync log, whether or not anything was ported.
2. If anything was ported, describe it in the Outcome column and let the commit carry the detail.
3. If a re-vendor happened, update `<version>` in [thirdpartylibs.xml](../thirdpartylibs.xml) and
   the upstream version line in [PATCHES.md](../thirdparty/elephant-php/PATCHES.md).
