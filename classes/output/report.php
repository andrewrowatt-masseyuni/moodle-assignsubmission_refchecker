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

namespace assignsubmission_refchecker\output;

use assignsubmission_refchecker\local\display_level;
use assignsubmission_refchecker\local\issue;
use assignsubmission_refchecker\local\job_status;
use assignsubmission_refchecker\local\json_columns;
use assignsubmission_refchecker\local\match_status;
use assignsubmission_refchecker\local\source\chain;
use core_text;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * The expanded reference check report: the dashboard, and at full level the individual references.
 *
 * The dashboard is built from the counters denormalised onto the job row, so rendering it costs no
 * extra queries. Individual reference rows are only loaded and decoded when the viewer is actually
 * entitled to see them.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param stdClass $job The job record.
     * @param stdClass[] $references The reference rows, empty unless the viewer may see them.
     * @param int $level The viewer's effective display level.
     * @param bool $isteacher Whether the viewer may see operational detail.
     * @param bool $canrerun Whether the viewer may spend API quota re-running the check.
     */
    public function __construct(
        /** @var stdClass The job record. */
        protected stdClass $job,
        /** @var stdClass[] The reference rows. */
        protected array $references,
        /** @var int The viewer's effective display level. */
        protected int $level,
        /** @var bool Whether the viewer may see operational detail. */
        protected bool $isteacher,
        /** @var bool Whether the viewer may re-run the check. */
        protected bool $canrerun = false,
    ) {
    }

    /**
     * Export for the report template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $job = $this->job;
        $showreferences = $this->level >= display_level::FULL;
        $hasreferences = $showreferences && !empty($this->references);

        return [
            'heading' => get_string('report_heading', 'assignsubmission_refchecker'),
            'iscomplete' => $job->status === job_status::COMPLETE,
            'statuslabel' => job_status::label(
                $job->status,
                (int) $job->checkedrefs,
                (int) $job->totalrefs,
            ),
            'dashboard' => $this->export_dashboard(),
        ] + $this->export_notices() + [
            'showreferences' => $showreferences,
            'hasreferences' => $hasreferences,
            'references' => $showreferences ? $this->export_references() : [],
            'filters' => $showreferences ? $this->export_filters() : [],
            'showdiagnostics' => $this->isteacher,
            'errorcode' => $this->isteacher ? (string) $job->errorcode : '',
            'errormessage' => $this->isteacher ? (string) $job->errormessage : '',
            'timecompleted' => $this->isteacher && $job->timecompleted
                ? userdate($job->timecompleted)
                : '',
            // Anyone reading a non-empty full report may take a copy of it away, which is exactly
            // the condition under which the references are on screen. export.php re-derives the
            // entitlement for itself, for the reason given on view() in locallib.php.
            'canexport' => $hasreferences,
            'exportheading' => get_string('export_heading', 'assignsubmission_refchecker'),
            'exportformats' => $hasreferences ? $this->export_formats() : [],
            // Re-running spends external API quota, so it is offered only to graders.
            'canrerun' => $this->canrerun,
            'rerunurl' => $this->canrerun
                ? (new moodle_url('/mod/assign/submission/refchecker/action.php', [
                    'id' => (int) $job->submission,
                    'sesskey' => sesskey(),
                ]))->out(false)
                : '',
            'hasactions' => $hasreferences || $this->canrerun,
        ];
    }

    /**
     * The download controls, one per offered format.
     *
     * A list rather than three url keys, so the template stays one loop and offering another
     * format is a one line change. The submission id comes off the job, which is what scopes each
     * report's controls to its own submission when several are expanded on a grading page.
     *
     * @return array<int, array{key: string, label: string, arialabel: string, url: string}>
     */
    protected function export_formats(): array {
        $out = [];

        foreach (['pdf', 'excel', 'csv'] as $key) {
            // Core already translates the format names: "PDF", "Excel", "CSV".
            $label = get_string('shortname', 'dataformat_' . $key);

            $out[] = [
                'key' => $key,
                'label' => $label,
                // Three bare format names in a row say nothing to a screen reader.
                'arialabel' => get_string('export_as', 'assignsubmission_refchecker', $label),
                'url' => (new moodle_url('/mod/assign/submission/refchecker/export.php', [
                    'id' => (int) $this->job->submission,
                    'format' => $key,
                    'sesskey' => sesskey(),
                ]))->out(false),
            ];
        }

        return $out;
    }

    /**
     * Notices that qualify what the report actually covers.
     *
     * Naming the section that was read, and saying when the list was cut short, is what lets a
     * reader tell "16 references, all fine" from "we only managed to read 16 of your references".
     *
     * @return array
     */
    protected function export_notices(): array {
        $job = $this->job;
        $hassectionheading = !empty($job->sectionheading);
        $istruncated = !empty($job->truncated);

        return [
            'hassectionheading' => $hassectionheading,
            'sectionheading' => $hassectionheading
                ? get_string('sectionheading_found', 'assignsubmission_refchecker', $job->sectionheading)
                : '',
            'istruncated' => $istruncated,
            'truncatednotice' => $istruncated
                ? get_string('truncated_notice', 'assignsubmission_refchecker', (int) $job->totalrefs)
                : '',
        ];
    }

    /**
     * The aggregate counts and temporal statistics.
     *
     * Every value is read straight off the counters denormalised onto the job row, so building
     * this costs no extra queries however many submissions are on screen.
     *
     * @return array
     */
    protected function export_dashboard(): array {
        $job = $this->job;

        return [
            'total' => (int) $job->totalrefs,
            'verified' => (int) $job->verifiedrefs,
            'partial' => (int) $job->partialrefs,
            'mismatch' => (int) $job->mismatchrefs,
            'notfound' => (int) $job->notfoundrefs,
            'withissues' => (int) $job->issuerefs,
            'retracted' => (int) $job->retractedrefs,
            'predatory' => (int) $job->predatoryrefs,
            'hasretracted' => (int) $job->retractedrefs > 0,
            'haspredatory' => (int) $job->predatoryrefs > 0,
            'hastemporal' => $job->oldestyear !== null && $job->newestyear !== null,
            'oldestyear' => $job->oldestyear,
            'newestyear' => $job->newestyear,
            'avgyear' => $job->avgyear !== null ? (int) round((float) $job->avgyear) : null,
            'totalcitations' => (int) $job->totalcitations,
            'avgcitations' => $job->avgcitations !== null ? (int) round((float) $job->avgcitations) : null,
            'hascitations' => (int) $job->totalcitations > 0,
        ];
    }

    /**
     * Build the filter bar counts.
     *
     * @return array
     */
    protected function export_filters(): array {
        $job = $this->job;

        return [
            ['key' => 'all', 'label' => get_string('filter_all', 'assignsubmission_refchecker'),
                'count' => (int) $job->totalrefs, 'active' => true],
            ['key' => match_status::VERIFIED, 'label' => get_string('filter_verified', 'assignsubmission_refchecker'),
                'count' => (int) $job->verifiedrefs, 'active' => false, 'disabled' => (int) $job->verifiedrefs === 0],
            ['key' => match_status::PARTIAL, 'label' => get_string('filter_partial', 'assignsubmission_refchecker'),
                'count' => (int) $job->partialrefs, 'active' => false, 'disabled' => (int) $job->partialrefs === 0],
            ['key' => match_status::MISMATCH, 'label' => get_string('filter_mismatch', 'assignsubmission_refchecker'),
                'count' => (int) $job->mismatchrefs, 'active' => false, 'disabled' => (int) $job->mismatchrefs === 0],
            ['key' => match_status::NOTFOUND, 'label' => get_string('filter_notfound', 'assignsubmission_refchecker'),
                'count' => (int) $job->notfoundrefs, 'active' => false, 'disabled' => (int) $job->notfoundrefs === 0],
            ['key' => 'issues', 'label' => get_string('filter_issues', 'assignsubmission_refchecker'),
                'count' => (int) $job->issuerefs, 'active' => false, 'disabled' => (int) $job->issuerefs === 0],
        ];
    }

    /**
     * Flatten the reference rows for the template.
     *
     * @return array
     */
    protected function export_references(): array {
        $out = [];

        foreach ($this->references as $reference) {
            $matchstatus = (string) ($reference->matchstatus ?? match_status::NOTFOUND);
            $issues = issue::format_all(json_columns::decode_records($reference->issues));
            $authors = $this->decode_list($reference->foundauthors);
            $formatted = $this->decode_map($reference->formatted);
            $isnotfound = $matchstatus === match_status::NOTFOUND;

            $out[] = [
                'id' => (int) $reference->id,
                'sortorder' => (int) $reference->sortorder,
                'rawref' => (string) $reference->rawref,
                'sourcefile' => (string) $reference->sourcefile,
                // Operational text, and the plugin's rule is that those are for teachers. It is raw
                // internal English naming the databases, so it is also the one place a student could
                // read which services the site consults.
                'haserror' => $this->isteacher && $reference->status === job_status::REF_ERROR,
                'errormessage' => $this->isteacher ? (string) $reference->errormessage : '',
                'matchstatus' => $matchstatus,
                'matchstatuslabel' => match_status::label($matchstatus),
                'badgeclass' => match_status::badge_class($matchstatus),
                'hasconfidence' => $reference->matchconfidence !== null && $matchstatus !== match_status::NOTFOUND,
                'matchconfidence' => (int) $reference->matchconfidence,
                'isnotfound' => $isnotfound,
                'notfounddetail' => $isnotfound ? match_status::notfound_detail(
                    chain::display_name_list($reference->sourcesconsulted),
                    $this->isteacher,
                ) : '',
                'notfounddetailnote' => $isnotfound ? match_status::notfound_note(
                    chain::display_name_list($reference->sourcesunavailable),
                    $this->isteacher,
                ) : '',
                'foundtitle' => (string) $reference->foundtitle,
                'hasfoundtitle' => !empty($reference->foundtitle),
                'foundauthors' => implode(', ', $authors),
                'hasfoundauthors' => !empty($authors),
                'foundyear' => $reference->foundyear,
                'foundjournal' => (string) $reference->foundjournal,
                'hasfoundjournal' => !empty($reference->foundjournal),
                'doi' => (string) $reference->doi,
                'hasdoi' => !empty($reference->doi),
                'doiurl' => !empty($reference->doi)
                    ? 'https://doi.org/' . ltrim((string) $reference->doi, '/')
                    : '',
                'url' => (string) $reference->url,
                'citations' => $reference->citations,
                'hascitations' => $reference->citations !== null,
                'retracted' => !empty($reference->retracted),
                'predatory' => !empty($reference->predatory),
                'hasissues' => !empty($issues),
                'issues' => $issues,
                // The machine name for styling, and the readable one for people to read.
                'source' => (string) $reference->source,
                'sourcelabel' => chain::display_name($reference->source),
                'sourcecolor' => chain::brand_color($reference->source),
                'scholarurl' => $this->scholar_url((string) $reference->rawref),
                'scores' => [
                    ['label' => get_string('report_titlescore', 'assignsubmission_refchecker'),
                        'value' => $reference->titlescore],
                    ['label' => get_string('report_authorscore', 'assignsubmission_refchecker'),
                        'value' => $reference->authorscore],
                    ['label' => get_string('report_journalscore', 'assignsubmission_refchecker'),
                        'value' => $reference->journalscore],
                ],
                'hasscores' => $reference->titlescore !== null,
                'formatted' => [
                    ['label' => get_string('report_apa', 'assignsubmission_refchecker'),
                        'value' => $formatted['apa'] ?? ''],
                    ['label' => get_string('report_mla', 'assignsubmission_refchecker'),
                        'value' => $formatted['mla'] ?? ''],
                    ['label' => get_string('report_iso690', 'assignsubmission_refchecker'),
                        'value' => $formatted['iso690'] ?? ''],
                    ['label' => get_string('report_bibtex', 'assignsubmission_refchecker'),
                        'value' => $formatted['bibtex'] ?? ''],
                ],
                'hasformatted' => !empty(array_filter($formatted)),
                'showsource' => $this->isteacher && !empty($reference->source),
            ];
        }

        return $out;
    }

    /**
     * A Google Scholar search for a reference, offered as a fallback when nothing was found.
     *
     * Not-found is frequently a coverage gap rather than a fabrication, so students need somewhere
     * obvious to go and check for themselves.
     *
     * @param string $rawref
     * @return string
     */
    protected function scholar_url(string $rawref): string {
        $query = trim(preg_replace('/\s+/u', ' ', $rawref));
        // Scholar ignores very long queries; the first couple of hundred characters of a reference
        // is the author/title/year part that actually identifies the work.
        $query = core_text::substr($query, 0, 250);

        return (new moodle_url('https://scholar.google.com/scholar', ['q' => $query]))->out(false);
    }

    /**
     * Decode a JSON array column, tolerating null and malformed values.
     *
     * @param string|null $json
     * @return string[]
     */
    protected function decode_list(?string $json): array {
        return json_columns::decode_list($json);
    }

    /**
     * Decode a JSON object column, tolerating null and malformed values.
     *
     * @param string|null $json
     * @return array<string, string>
     */
    protected function decode_map(?string $json): array {
        return json_columns::decode_map($json);
    }
}
