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
 * Probe the reference checking configuration and resolve a reference from the command line.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use assignsubmission_refchecker\local\extractor;
use assignsubmission_refchecker\local\reference_parser;
use assignsubmission_refchecker\local\source\chain;

[$options, $unrecognised] = cli_get_params(
    [
        'help' => false,
        'reference' => null,
    ],
    [
        'h' => 'help',
        'r' => 'reference',
    ]
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help']) {
    echo <<<EOT
Probe the Reference Checker configuration and optionally resolve one reference.

Options:
  -h, --help                Show this help.
  -r, --reference=STRING    A reference to look up. Defaults to a known real paper.

Examples:
  # Configuration and reachability only, plus a known good lookup.
  php mod/assign/submission/refchecker/cli/check_sources.php

  # Check a reference you suspect is fabricated.
  php mod/assign/submission/refchecker/cli/check_sources.php \\
      -r="Smith, J. (2023). A paper that does not exist. Nature, 999."

EOT;
    exit(0);
}

cli_heading('Configuration');

$contact = trim((string) get_config('assignsubmission_refchecker', 'contactemail'));
cli_writeln('  Contact email  : ' . ($contact !== '' ? $contact : '(not set — rate limits will be tight)'));

$pdftotext = (string) get_config('assignsubmission_refchecker', 'pathtopdftotext');
$pdfstate = ($pdftotext !== '' && is_executable($pdftotext)) ? 'OK' : 'NOT USABLE';
cli_writeln('  pdftotext      : ' . ($pdftotext !== '' ? $pdftotext : '(not set)') . "  [{$pdfstate}]");

cli_writeln('  File types     : ' . implode(', ', extractor::supported_types()));

// Whether the converter can actually produce text depends on the remote LibreOffice server's
// advertised formats, which needs a real file to ask about, so only the setting is reported here.
cli_writeln('  Doc converter  : ' . (get_config('assignsubmission_refchecker', 'uselibreoffice')
    ? 'enabled'
    : 'disabled'));

cli_heading('Sources');

$chain = chain::from_config();
if (!$chain->get_sources()) {
    cli_error('No sources are enabled. Set at least one in the plugin settings.');
}

foreach ($chain->get_sources() as $source) {
    $interval = \assignsubmission_refchecker\local\rate_limiter::interval_for($source->get_name());

    if (
        $source instanceof \assignsubmission_refchecker\local\source\semanticscholar
            && $source->get_api_key() === ''
    ) {
        cli_writeln(sprintf(
            '  %-17s : NO API KEY (treated as unavailable; it rate limits within seconds without one)',
            $source->get_display_name()
        ));
        continue;
    }

    cli_writeln(sprintf(
        '  %-17s : %-12s (min %dms between requests)',
        $source->get_display_name(),
        $source->is_available() ? 'reachable' : 'UNREACHABLE',
        $interval
    ));
}

cli_heading('Lookup');

$reference = $options['reference']
    ?: 'Vaswani, A., Shazeer, N., & Parmar, N. (2017). Attention is all you need. '
        . 'Advances in Neural Information Processing Systems, 30.';

cli_writeln('  Reference : ' . $reference);

$parsed = array_merge(['raw' => $reference], reference_parser::parse_metadata($reference));
cli_writeln('  Parsed as : title=' . var_export($parsed['title'], true));
cli_writeln('              authors=' . var_export($parsed['authors'], true));
cli_writeln('              year=' . var_export($parsed['year'], true));
cli_writeln('              doi=' . var_export($parsed['doi'], true));
cli_writeln('');

$started = microtime(true);
try {
    $result = $chain->check($parsed);
} catch (Throwable $e) {
    cli_error('Lookup failed: ' . get_class($e) . ': ' . $e->getMessage());
}
$elapsed = round(microtime(true) - $started, 2);

cli_writeln('  Status     : ' . $result['matchstatus']);
cli_writeln('  Confidence : ' . $result['confidence'] . '%');
cli_writeln('  Source     : ' . ($result['source'] ?? '-'));
cli_writeln('  Scores     : title=' . $result['titlescore']
    . ' authors=' . var_export($result['authorscore'], true)
    . ' journal=' . var_export($result['journalscore'], true));

if ($result['record']) {
    cli_writeln('  Found      : ' . $result['record']['title']);
    cli_writeln('               ' . implode(', ', $result['record']['authors']));
    cli_writeln('               ' . $result['record']['journal'] . ' (' . $result['record']['year'] . ')');
    cli_writeln('               doi=' . $result['record']['doi']
        . ' citations=' . var_export($result['record']['citations'], true)
        . ' retracted=' . var_export($result['record']['retracted'], true));
}

if ($result['issues']) {
    cli_writeln('  Issues     : ' . implode(' | ', $result['issues']));
}

cli_writeln('  Took       : ' . $elapsed . 's');
cli_writeln('');
