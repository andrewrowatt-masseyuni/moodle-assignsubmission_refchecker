<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Rewrites the vendored elephant-php sources from PHP 8.2 to PHP 8.1 syntax.
 *
 * Upstream requires PHP 8.2 for exactly one reason: `readonly class`, the 8.2 shorthand for
 * marking every instance property readonly. Moodle 4.5 supports PHP 8.1, so this writes that
 * shorthand out longhand, which is semantically identical.
 *
 * Run this after re-vendoring a new upstream release, from anywhere:
 *
 *     php thirdparty/elephant-php/apply-php81-patch.php
 *
 * It is idempotent, and it asserts the shape of what it found rather than patching best-effort:
 * a half-patched tree is a parse error on some file nobody happens to load today, discovered in
 * production weeks later. Read PATCHES.md before changing anything here.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This is a standalone maintenance script, deliberately runnable without Moodle.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

// The shape of upstream v0.4.1. If a new release does not match these, stop and re-measure rather
// than trusting a partial result: see the "Re-vendoring" section of PATCHES.md.
const EXPECTED_CLASSES = 42;
const EXPECTED_PROPERTIES = 101;

$srcdir = __DIR__ . '/src';
if (!is_dir($srcdir)) {
    fwrite(STDERR, "No src/ directory at {$srcdir}.\n");
    exit(1);
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcdir));

$classcount = 0;
$propertycount = 0;
$changedfiles = 0;
$alreadypatched = 0;

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $original = file_get_contents($path);

    if (strpos($original, 'final readonly class') === false) {
        // Either not a readonly class, or already patched. Count the latter so a re-run over an
        // untouched tree still reports the expected total rather than looking like a no-op.
        if (preg_match('/^final class /m', $original) && strpos($original, ' readonly ') !== false) {
            $alreadypatched++;
        }
        continue;
    }

    $contents = str_replace('final readonly class', 'final class', $original);
    $classcount++;

    // Promoted constructor parameters, always at eight spaces of indent in this Pint-formatted
    // tree, and class-body property declarations, always at four. Both are matched explicitly
    // rather than with a loose \s+ so that a reformatted upstream fails the count check below
    // instead of being silently missed.
    $contents = preg_replace_callback(
        '/^(\x20{8}|\x20{4})(public|protected|private)(\x20+)(?!readonly\b)(?=[^\s(]*\x20*\$)/m',
        function (array $matches) use (&$propertycount): string {
            $propertycount++;
            return $matches[1] . $matches[2] . ' readonly' . $matches[3];
        },
        $contents,
    );

    if ($contents !== $original) {
        file_put_contents($path, $contents);
        $changedfiles++;
    }
}

printf("Patched %d file(s): %d class declarations, %d property declarations.\n",
    $changedfiles, $classcount, $propertycount);

if ($changedfiles === 0 && $alreadypatched > 0) {
    printf("Nothing to do: %d file(s) already carry the patch.\n", $alreadypatched);
    exit(0);
}

$problems = [];
if ($classcount !== EXPECTED_CLASSES) {
    $problems[] = sprintf('expected %d readonly classes, found %d', EXPECTED_CLASSES, $classcount);
}
if ($propertycount !== EXPECTED_PROPERTIES) {
    $problems[] = sprintf('expected %d properties, found %d', EXPECTED_PROPERTIES, $propertycount);
}

if ($problems) {
    fwrite(STDERR, "\nUpstream does not match what this script was written against:\n  - "
        . implode("\n  - ", $problems)
        . "\nThe tree may now be half-patched. Restore src/ from upstream, re-measure, and update"
        . " the EXPECTED_* constants. See PATCHES.md.\n");
    exit(1);
}

echo "Counts match the expected shape of upstream v0.4.1.\n";
exit(0);
