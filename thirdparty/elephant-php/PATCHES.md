# Local patches to elephant-php

Upstream: <https://github.com/endless-creativity/elephant-php>, **v0.4.1**, BSD-2-Clause.
Only `src/` is vendored — no `bin/`, no tests, no Composer machinery. `autoload.php` is ours
(Moodle gives plugins no Composer autoloader), as is this file and `apply-php81-patch.php`.

## The one patch: PHP 8.2 → 8.1

Upstream declares `"php": "^8.2"`. It needs 8.2 for exactly one construct: **`readonly class`**,
the 8.2 shorthand that marks every instance property of a class readonly. A survey of all 72
source files found nothing else 8.2-only — no standalone `true`/`false`/`null` types, no DNF types,
no trait constants (there are no traits), no `#[\SensitiveParameter]`, no 8.2 functions. Everything
else it uses is 8.1: enums, `readonly` properties, `never`, new-in-initializers, first-class
callables, `array_is_list`.

Moodle 4.5 supports PHP 8.1–8.3, and the site this plugin was written for runs 8.1, so shipping an
8.2 floor would have made the built-in DOCX converter dead code on the only server that matters.
`readonly class` is pure shorthand, so it is written out longhand instead:

```diff
-final readonly class Paragraph implements HasChildren
+final class Paragraph implements HasChildren
     public function __construct(
-        public array $children = [],
+        public readonly array $children = [],
```

That is semantically identical — verified on PHP 8.1 by asserting that writing to a patched
property still throws `Error`, not just that the file parses.

Scope, as measured against v0.4.1: **42 class declarations** and **101 property declarations**
(97 promoted constructor parameters, 4 class-body properties in `Document/Comments.php`,
`Document/Notes.php` and `Reader/Relationships.php`). `Document/Tab.php` and `Html/ForceWrite.php`
have no properties and only lose the class-level keyword.

Note this is a *load-time* constraint, not a runtime one: `readonly class` is a parse error before
8.2, and a parse error in an autoloaded file is fatal and uncatchable. That is why
`docx_converter::is_available()` is checked *before* `autoload.php` is ever required, and why that
ordering must survive any refactor even now that the patch exists.

## Do not hand-edit `src/`

Change `apply-php81-patch.php` and re-run it. A hand-edit is invisible the moment someone drops a
new upstream release in, and the failure it causes — a fatal parse error on a file nobody happens
to load today — surfaces in production weeks later.

`tests/extractor_test.php` has a guard test asserting no file under `src/` contains
`readonly class`, so an unpatched re-vendor fails the test suite rather than the site.

## Re-vendoring a new upstream release

```bash
git clone --depth 1 --branch vX.Y.Z https://github.com/endless-creativity/elephant-php.git /tmp/elephant
rm -rf thirdparty/elephant-php/src
cp -r /tmp/elephant/src thirdparty/elephant-php/src
cp /tmp/elephant/LICENSE /tmp/elephant/README.md /tmp/elephant/CHANGELOG.md thirdparty/elephant-php/

php thirdparty/elephant-php/apply-php81-patch.php
```

The script asserts it found exactly 42 classes and 101 properties and **exits non-zero otherwise**,
because a half-patched tree is worse than an unpatched one. If a new release trips that check,
restore `src/` from upstream, re-measure, update the `EXPECTED_*` constants, and re-read this file
in case upstream has adopted some other 8.2+ feature that needs handling too.

Then confirm, from the Moodle root:

```bash
# Parses on the oldest PHP Moodle 4.5 supports.
docker exec stream-massey-405-webserver-1 sh -c \
  'find /var/www/html/mod/assign/submission/refchecker/thirdparty/elephant-php/src -name "*.php" \
   -exec php -l {} \; | grep -v "No syntax errors"'

# And still actually works.
vendor/bin/phpunit mod/assign/submission/refchecker/tests/extractor_test.php
```

Finally update `<version>` in `../../thirdpartylibs.xml`.
