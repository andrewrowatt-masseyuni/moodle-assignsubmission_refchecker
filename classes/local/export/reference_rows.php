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

namespace assignsubmission_refchecker\local\export;

use assignsubmission_refchecker\local\issue;
use assignsubmission_refchecker\local\json_columns;
use assignsubmission_refchecker\local\match_status;
use assignsubmission_refchecker\local\source\chain;
use Generator;
use stdClass;

/**
 * The reference rows flattened to plain scalars, for the spreadsheet downloads.
 *
 * Deliberately separate from {@see \assignsubmission_refchecker\output\report}, which builds
 * presentation: badge classes, brand colours, resolver links and nested score arrays. A file
 * somebody has archived and is comparing year on year must not change shape because a badge colour
 * did, so the dependency runs this way round rather than through a shared flattener.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reference_rows {
    /**
     * Constructor.
     *
     * @param stdClass[] $references Every reference on the job, in bibliography order.
     * @param bool $isteacher Whether the viewer may see which database answered, and which file
     *      the reference was read from.
     */
    public function __construct(
        /** @var stdClass[] The reference rows. */
        protected array $references,
        /** @var bool Whether the viewer may see operational detail. */
        protected bool $isteacher,
    ) {
    }

    /**
     * The column headings, keyed by column, in output order.
     *
     * @return array<string, string>
     */
    public function columns(): array {
        $columns = [
            'number' => get_string('export_col_number', 'assignsubmission_refchecker'),
            'rawref' => get_string('export_col_reference', 'assignsubmission_refchecker'),
            'matchstatus' => get_string('export_col_status', 'assignsubmission_refchecker'),
            'matchconfidence' => get_string('report_confidence', 'assignsubmission_refchecker'),
            'foundtitle' => get_string('report_foundtitle', 'assignsubmission_refchecker'),
            'foundauthors' => get_string('report_foundauthors', 'assignsubmission_refchecker'),
            'foundyear' => get_string('report_foundyear', 'assignsubmission_refchecker'),
            'foundjournal' => get_string('report_foundjournal', 'assignsubmission_refchecker'),
            'doi' => get_string('report_doi', 'assignsubmission_refchecker'),
            'url' => get_string('export_col_url', 'assignsubmission_refchecker'),
            'citations' => get_string('report_citations', 'assignsubmission_refchecker'),
            'retracted' => get_string('dashboard_retracted', 'assignsubmission_refchecker'),
            'predatory' => get_string('dashboard_predatory', 'assignsubmission_refchecker'),
            'issues' => get_string('report_issues', 'assignsubmission_refchecker'),
        ];

        // Appended rather than emitted empty: a student's download should not carry a column whose
        // existence tells them the site consults named databases. The check error belongs with them
        // for the same reason — it is raw internal text and it names the databases that were down.
        if ($this->isteacher) {
            $columns['errormessage'] = get_string('export_col_error', 'assignsubmission_refchecker');
            $columns['source'] = get_string('report_source', 'assignsubmission_refchecker');
            $columns['sourcefile'] = get_string('export_col_sourcefile', 'assignsubmission_refchecker');
        }

        return $columns;
    }

    /**
     * One row per reference, keyed identically to columns() and in the same order.
     *
     * A generator, so a job at the top of the maxreferences range never exists twice in memory and
     * so each call hands the writer a fresh iterator.
     *
     * Every row must carry every key. OpenSpout writes values positionally and ignores the keys,
     * so a row that omits one shifts the whole row left underneath correct looking headings.
     *
     * @return Generator<int, array<string, string|int|null>>
     */
    public function rows(): Generator {
        foreach ($this->references as $reference) {
            yield $this->row($reference);
        }
    }

    /**
     * Flatten a single reference.
     *
     * @param stdClass $reference
     * @return array<string, string|int|null>
     */
    protected function row(stdClass $reference): array {
        $matchstatus = (string) ($reference->matchstatus ?? match_status::NOTFOUND);

        $row = [
            // The report numbers references from one; the stored sortorder is zero based.
            'number' => (int) $reference->sortorder + 1,
            'rawref' => (string) $reference->rawref,
            'matchstatus' => match_status::label($matchstatus),
            // Left empty rather than zero when nothing was found, so that "not found" is never
            // read as "found, and we are nought per cent confident in it".
            'matchconfidence' => $reference->matchconfidence !== null && $matchstatus !== match_status::NOTFOUND
                ? (int) $reference->matchconfidence
                : null,
            'foundtitle' => (string) $reference->foundtitle,
            // Semicolons rather than commas: an author list separated by commas fragments visually
            // when a CSV is opened with the wrong delimiter.
            'foundauthors' => implode('; ', json_columns::decode_list($reference->foundauthors)),
            'foundyear' => $reference->foundyear !== null ? (int) $reference->foundyear : null,
            'foundjournal' => (string) $reference->foundjournal,
            // The bare DOI, not the resolver URL: it is an identifier here, not a link.
            'doi' => (string) $reference->doi,
            'url' => (string) $reference->url,
            'citations' => $reference->citations !== null ? (int) $reference->citations : null,
            'retracted' => $this->yesno(!empty($reference->retracted)),
            'predatory' => $this->yesno(!empty($reference->predatory)),
            'issues' => implode("\n", issue::format_all(json_columns::decode_records($reference->issues))),
        ];

        if ($this->isteacher) {
            $row['errormessage'] = (string) $reference->errormessage;
            $row['source'] = chain::display_name($reference->source);
            $row['sourcefile'] = (string) $reference->sourcefile;
        }

        return $row;
    }

    /**
     * Render a flag as a word.
     *
     * A raw boolean would reach the spreadsheet as a boolean cell reading TRUE or FALSE, and a 1 or
     * a 0 reads as a quantity. Both are worse than the word.
     *
     * @param bool $value
     * @return string
     */
    protected function yesno(bool $value): string {
        return $value ? get_string('yes') : get_string('no');
    }
}
