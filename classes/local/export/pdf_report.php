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

use assign;
use assignsubmission_refchecker\local\job_status;
use assignsubmission_refchecker\local\json_columns;
use assignsubmission_refchecker\local\match_status;
use assignsubmission_refchecker\local\source\chain;
use pdf;
use stdClass;

/**
 * The report as a document somebody can keep.
 *
 * Built as a designed document rather than through the pdf dataformat writer, because the report
 * is a dashboard plus a card per reference, and flattening that to a landscape grid of seventeen
 * equal width columns produces something nobody can read.
 *
 * The layout is mustache rendered into TCPDF's writeHTML(), one call per reference. That keeps the
 * layout in the same language as the on screen report instead of in MultiCell coordinate
 * arithmetic, and chunking it stops a long reference list going through TCPDF's HTML parser as one
 * enormous string.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_report {
    /**
     * How close to the top margin, in millimetres, still counts as "the top of the page".
     *
     * Only ever compared against a freshly added page, where the cursor sits exactly on the top
     * margin; the tolerance is there so that floating point drift cannot turn that into a false
     * negative and send every block down the expensive path.
     *
     * @var float
     */
    protected const PAGE_TOP_TOLERANCE = 1.0;

    /**
     * Constructor.
     *
     * @param assign $assign
     * @param stdClass $submission An assign_submission record.
     * @param stdClass $job The job record.
     * @param stdClass[] $references The reference rows, in bibliography order.
     * @param bool $isteacher Whether the viewer may see operational detail.
     */
    public function __construct(
        /** @var assign The assignment. */
        protected assign $assign,
        /** @var stdClass The submission. */
        protected stdClass $submission,
        /** @var stdClass The job record. */
        protected stdClass $job,
        /** @var stdClass[] The reference rows. */
        protected array $references,
        /** @var bool Whether the viewer may see operational detail. */
        protected bool $isteacher,
    ) {
    }

    /**
     * Build the document.
     *
     * Separated from sending it so that tests can generate a document without a browser.
     *
     * @return pdf
     */
    public function build(): pdf {
        global $CFG, $OUTPUT;
        require_once($CFG->libdir . '/pdflib.php');

        $pdf = new pdf();

        // Whatever the site configured for PDF export, which is freesans by default. Never
        // helvetica: it has no Unicode coverage, and would mangle the accented author names that
        // come back from the external databases.
        $fontlist = $pdf->get_export_fontlist();
        $font = (string) array_key_first($fontlist);

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->SetCreator('Moodle');
        $pdf->SetTitle($this->document_title());
        $pdf->SetSubject(get_string('report_heading', 'assignsubmission_refchecker'));
        $pdf->SetFont($font, '', 10);
        $pdf->AddPage();

        // The cover is allowed to flow across a page break; it reads as continuous prose.
        $this->write_html(
            $pdf,
            $OUTPUT->render_from_template('assignsubmission_refchecker/export_pdf_header', $this->header_context()),
        );

        foreach ($this->references as $reference) {
            $this->write_together(
                $pdf,
                $OUTPUT->render_from_template(
                    'assignsubmission_refchecker/export_pdf_reference',
                    $this->reference_context($reference),
                ),
            );
        }

        return $pdf;
    }

    /**
     * Write a block, moving it to the next page rather than letting it straddle a page break.
     *
     * A reference split across a page boundary is genuinely hard to read: the submitted text ends
     * up separated from the verdict on it, which is the one comparison the whole document exists
     * to support.
     *
     * TCPDF cannot be asked to avoid this in advance — there is no way to measure arbitrary HTML
     * without laying it out — so the block is written speculatively inside a transaction and put
     * back if it turns out to have spilled.
     *
     * @param pdf $pdf
     * @param string $html
     */
    protected function write_together(pdf $pdf, string $html): void {
        $margins = $pdf->getMargins();

        // Already at the top of a page: a block that does not fit here does not fit anywhere, so
        // retrying could only produce the same split having paid for a clone of the document.
        if ($pdf->GetY() <= $margins['top'] + self::PAGE_TOP_TOLERANCE) {
            $this->write_html($pdf, $html);

            return;
        }

        $startpage = $pdf->getPage();

        // Opening a transaction clones the whole document, which is why the guard above matters:
        // it keeps the expensive path off blocks that could not have benefited from it. Only one
        // clone is ever alive, because each is committed or rolled back before the next reference.
        $pdf->startTransaction();
        $this->write_html($pdf, $html);

        if ($pdf->getPage() === $startpage) {
            $pdf->commitTransaction();

            return;
        }

        // It spilled. Put the document back, then start the block at the top of a fresh page.
        // Passing true restores this object in place, so the caller keeps its own reference; the
        // default form returns a different object and would silently leave the caller on the old
        // one. A block taller than a whole page still splits, and is not retried again.
        $pdf->rollbackTransaction(true);
        $pdf->AddPage();
        $this->write_html($pdf, $html);
    }

    /**
     * Write a rendered template into the document.
     *
     * @param pdf $pdf
     * @param string $html
     */
    protected function write_html(pdf $pdf, string $html): void {
        $pdf->writeHTML($html, true, false, true, false, '');
    }

    /**
     * Send the document to the browser.
     *
     * The caller must die() afterwards: TCPDF's Output() returns rather than exiting.
     *
     * @param string $stem The filename without an extension. TCPDF, unlike \core\dataformat,
     *      does not append one.
     */
    public function download(string $stem): void {
        $this->build()->Output($stem . '.pdf', 'D');
    }

    /**
     * The document as a string, for tests.
     *
     * @param string $stem
     * @return string
     */
    public function to_string(string $stem = 'report'): string {
        return (string) $this->build()->Output($stem . '.pdf', 'S');
    }

    /**
     * The cover block: what this report is about, and the aggregate counts.
     *
     * Public so that tests can assert on the data rather than trying to grep a compressed PDF
     * stream.
     *
     * @return array
     */
    public function header_context(): array {
        $job = $this->job;
        $hastemporal = $job->oldestyear !== null && $job->newestyear !== null;

        return [
            'heading' => get_string('report_heading', 'assignsubmission_refchecker'),
            'assignmentlabel' => get_string('export_pdf_assignment', 'assignsubmission_refchecker'),
            'assignment' => format_string(
                $this->assign->get_instance()->name,
                true,
                ['context' => $this->assign->get_context()],
            ),
            'subjectlabel' => naming::subject_label($this->submission),
            'subject' => naming::subject($this->assign, $this->submission),
            'checkedlabel' => get_string('export_pdf_checked', 'assignsubmission_refchecker'),
            'checked' => $job->timecompleted ? userdate($job->timecompleted) : '',
            'downloadedlabel' => get_string('export_pdf_downloaded', 'assignsubmission_refchecker'),
            'downloaded' => userdate(time()),
            'iscomplete' => $job->status === job_status::COMPLETE,
            'statuslabel' => job_status::label(
                $job->status,
                (int) $job->checkedrefs,
                (int) $job->totalrefs,
            ),
            'hassectionheading' => !empty($job->sectionheading),
            'sectionheading' => !empty($job->sectionheading)
                ? get_string('sectionheading_found', 'assignsubmission_refchecker', $job->sectionheading)
                : '',
            'istruncated' => !empty($job->truncated),
            'truncatednotice' => !empty($job->truncated)
                ? get_string('truncated_notice', 'assignsubmission_refchecker', (int) $job->totalrefs)
                : '',
            'stats' => [
                ['label' => get_string('dashboard_total', 'assignsubmission_refchecker'),
                    'value' => (int) $job->totalrefs],
                ['label' => get_string('dashboard_verified', 'assignsubmission_refchecker'),
                    'value' => (int) $job->verifiedrefs],
                ['label' => get_string('dashboard_partial', 'assignsubmission_refchecker'),
                    'value' => (int) $job->partialrefs],
                ['label' => get_string('dashboard_mismatch', 'assignsubmission_refchecker'),
                    'value' => (int) $job->mismatchrefs],
                ['label' => get_string('dashboard_notfound', 'assignsubmission_refchecker'),
                    'value' => (int) $job->notfoundrefs],
                ['label' => get_string('dashboard_withissues', 'assignsubmission_refchecker'),
                    'value' => (int) $job->issuerefs],
            ],
            'hasretracted' => (int) $job->retractedrefs > 0,
            'retractedlabel' => get_string('dashboard_retracted', 'assignsubmission_refchecker'),
            'retracted' => (int) $job->retractedrefs,
            'haspredatory' => (int) $job->predatoryrefs > 0,
            'predatorylabel' => get_string('dashboard_predatory', 'assignsubmission_refchecker'),
            'predatory' => (int) $job->predatoryrefs,
            'hastemporal' => $hastemporal,
            'temporal' => $hastemporal ? $this->temporal_stats() : [],
        ];
    }

    /**
     * One reference's block.
     *
     * Public for the same reason header_context() is.
     *
     * @param stdClass $reference
     * @return array
     */
    public function reference_context(stdClass $reference): array {
        $matchstatus = (string) ($reference->matchstatus ?? match_status::NOTFOUND);
        $issues = json_columns::decode_list($reference->issues);
        $hasconfidence = $reference->matchconfidence !== null && $matchstatus !== match_status::NOTFOUND;
        $details = $this->reference_details($reference);

        return [
            'heading' => get_string(
                'export_pdf_reference',
                'assignsubmission_refchecker',
                (int) $reference->sortorder + 1,
            ),
            'rawref' => (string) $reference->rawref,
            'matchstatuslabel' => match_status::label($matchstatus),
            'statuscolor' => $this->status_color($matchstatus),
            'hasconfidence' => $hasconfidence,
            'matchconfidence' => $hasconfidence ? (int) $reference->matchconfidence : null,
            'confidencelabel' => get_string('report_confidence', 'assignsubmission_refchecker'),
            'retracted' => !empty($reference->retracted),
            'retractedwarning' => get_string('report_retracted_warning', 'assignsubmission_refchecker'),
            'predatory' => !empty($reference->predatory),
            'predatorywarning' => get_string('report_predatory_warning', 'assignsubmission_refchecker'),
            'haserror' => $reference->status === job_status::REF_ERROR,
            'errormessage' => (string) $reference->errormessage,
            'hasdetails' => !empty($details),
            'details' => $details,
            'hasissues' => !empty($issues),
            'issueslabel' => get_string('report_issues', 'assignsubmission_refchecker'),
            'issues' => $issues,
        ];
    }

    /**
     * The matched metadata for one reference, as label and value pairs.
     *
     * Built by filtering a declared list rather than by a chain of conditionals, so that adding a
     * field is one line and the rows always come out in a fixed order. A row whose value is empty
     * is dropped, mirroring reference_card.mustache: an absent journal should leave no trace,
     * rather than printing a label against a blank.
     *
     * @param stdClass $reference
     * @return array<int, array{label: string, value: string}>
     */
    protected function reference_details(stdClass $reference): array {
        // Nothing was matched, so there is no metadata to describe. The submitted text and the
        // status still appear; they come from elsewhere in the context.
        if (empty($reference->foundtitle)) {
            return $this->operational_details($reference);
        }

        $candidates = [
            'report_foundtitle' => (string) $reference->foundtitle,
            'report_foundauthors' => implode(', ', json_columns::decode_list($reference->foundauthors)),
            'report_foundyear' => $reference->foundyear !== null ? (string) (int) $reference->foundyear : '',
            'report_foundjournal' => (string) $reference->foundjournal,
            'report_doi' => (string) $reference->doi,
            'report_citations' => $reference->citations !== null ? (string) (int) $reference->citations : '',
        ];

        $details = [];
        foreach ($candidates as $key => $value) {
            if ($value !== '') {
                $details[] = [
                    'label' => get_string($key, 'assignsubmission_refchecker'),
                    'value' => $value,
                ];
            }
        }

        return array_merge($details, $this->operational_details($reference));
    }

    /**
     * The rows only a teacher sees: which database answered, and which file the reference came from.
     *
     * Offered on the same terms as the source pill on screen.
     *
     * @param stdClass $reference
     * @return array<int, array{label: string, value: string}>
     */
    protected function operational_details(stdClass $reference): array {
        if (!$this->isteacher) {
            return [];
        }

        $candidates = [
            'report_source' => chain::display_name($reference->source),
            'export_col_sourcefile' => (string) $reference->sourcefile,
        ];

        $details = [];
        foreach ($candidates as $key => $value) {
            if ($value !== '') {
                $details[] = [
                    'label' => get_string($key, 'assignsubmission_refchecker'),
                    'value' => $value,
                ];
            }
        }

        return $details;
    }

    /**
     * The temporal and citation statistics, as label and value pairs.
     *
     * @return array<int, array{label: string, value: string}>
     */
    protected function temporal_stats(): array {
        $job = $this->job;

        $stats = [
            ['label' => get_string('dashboard_oldestyear', 'assignsubmission_refchecker'),
                'value' => (string) (int) $job->oldestyear],
            ['label' => get_string('dashboard_newestyear', 'assignsubmission_refchecker'),
                'value' => (string) (int) $job->newestyear],
        ];

        if ($job->avgyear !== null) {
            $stats[] = ['label' => get_string('dashboard_avgyear', 'assignsubmission_refchecker'),
                'value' => (string) (int) round((float) $job->avgyear)];
        }

        if ((int) $job->totalcitations > 0) {
            $stats[] = ['label' => get_string('dashboard_totalcitations', 'assignsubmission_refchecker'),
                'value' => (string) (int) $job->totalcitations];
            if ($job->avgcitations !== null) {
                $stats[] = ['label' => get_string('dashboard_avgcitations', 'assignsubmission_refchecker'),
                    'value' => (string) (int) round((float) $job->avgcitations)];
            }
        }

        return $stats;
    }

    /**
     * The colour for a match status.
     *
     * Hex rather than the Bootstrap contextual name match_status::badge_class() returns, because
     * TCPDF loads no stylesheet and a class name means nothing to it.
     *
     * @param string $status
     * @return string
     */
    protected function status_color(string $status): string {
        return match ($status) {
            match_status::VERIFIED => '#146c43',
            match_status::PARTIAL => '#997404',
            match_status::MISMATCH => '#b02a37',
            default => '#5c636a',
        };
    }

    /**
     * The document's title, as recorded in the PDF metadata.
     *
     * @return string
     */
    protected function document_title(): string {
        return get_string('report_heading', 'assignsubmission_refchecker')
            . ' - ' . naming::subject($this->assign, $this->submission);
    }
}
