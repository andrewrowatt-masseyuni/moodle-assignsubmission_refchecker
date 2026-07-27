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
 * English language pack for Reference Checker
 *
 * @package    assignsubmission_refchecker
 * @category   string
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['cachettl'] = 'Cache lifetime';
$string['cachettl_help'] = 'How long a looked-up reference stays cached. Students on the same course cite much the same literature, so the cache is the main reason checking stays within the external services\' rate limits.';
$string['check_builtinconverter'] = 'Word (.docx) files are read by the plugin\'s built-in converter.';
$string['check_builtinconverterunavailable'] = 'The built-in document converter is enabled but cannot run on this server: it needs PHP\'s zip extension, which Moodle requires in any case. Word files will fall back to the site\'s document converter.';
$string['check_filetypes'] = 'File types being scanned: {$a}.';
$string['check_nocontactemail'] = 'No contact email address is set. CrossRef and OpenAlex apply much tighter rate limits to requests that do not identify themselves, so checking will be slow.';
$string['check_nopdftotext'] = 'pdftotext was not found, so PDF submissions cannot be read. It is part of the poppler-utils package.';
$string['check_nosources'] = 'No databases are enabled, so nothing can be checked.';
$string['check_notinstalled'] = 'Reference Checker is not installed.';
$string['check_ok'] = 'Reference checking is ready.';
$string['check_pdftotextfound'] = 'PDF text extraction is available at {$a}.';
$string['check_problems'] = 'Reference checking has {$a} configuration problem(s).';
$string['check_s2nokey'] = 'Semantic Scholar is enabled but no API key is set. Without one it shares a rate limit with every other anonymous caller and will refuse most requests.';
$string['check_sourceok'] = '{$a} is reachable.';
$string['check_sources'] = 'Reference Checker';
$string['check_sources_action'] = 'Reference Checker settings';
$string['check_sourceunreachable'] = '{$a} could not be reached. References will not be checked until it is available again.';
$string['checkstatuslink'] = 'Reference checking reports its configuration and the reachability of the external databases under <a href="{$a}">Site administration &rsaquo; Reports &rsaquo; System status</a>. For a deeper probe, including resolving a sample reference, run <code>php mod/assign/submission/refchecker/cli/check_sources.php</code>.';
$string['checktiming'] = 'Check references';
$string['checktiming_help'] = 'When to check a student\'s references.

Checking on submit for grading runs once per attempt. Checking on every save gives students feedback while they are still drafting, but multiplies use of the external databases and is only suitable for small classes.';
$string['checktiming_save'] = 'On every save, including drafts';
$string['checktiming_submit'] = 'On submit for grading';
$string['chunksize'] = 'References per run';
$string['chunksize_help'] = 'How many references are checked each time the background task runs. Lower values spread the work out and keep each task run short.';
$string['contactemail'] = 'Contact email address';
$string['contactemail_help'] = 'Sent to CrossRef and OpenAlex with every request. Both services give much better rate limits to requests that identify themselves, so setting this is the single most effective thing you can do for throughput. Use a role address rather than a personal one.';
$string['dashboard_avgcitations'] = 'Average citations';
$string['dashboard_avgyear'] = 'Average year';
$string['dashboard_filterby'] = 'Filter by';
$string['dashboard_heading'] = 'Summary';
$string['dashboard_mismatch'] = 'Mismatch';
$string['dashboard_newestyear'] = 'Newest';
$string['dashboard_notfound'] = 'Not found';
$string['dashboard_oldestyear'] = 'Oldest';
$string['dashboard_partial'] = 'Partial match';
$string['dashboard_predatory'] = 'Possibly predatory';
$string['dashboard_retracted'] = 'Retracted';
$string['dashboard_total'] = 'References';
$string['dashboard_totalcitations'] = 'Total citations';
$string['dashboard_verified'] = 'Verified';
$string['dashboard_withissues'] = 'With issues';
$string['default'] = 'Enabled by default';
$string['default_help'] = 'If set, this submission method will be enabled by default for all new assignments.';
$string['defaultchecktiming'] = 'Default check timing';
$string['defaultchecktiming_help'] = 'The value new assignments start with for when references are checked.';
$string['defaultrequiretext'] = 'Default require text references';
$string['defaultrequiretext_help'] = 'The value new assignments start with for whether students paste their reference list in as text.';
$string['defaultstudentdisplay'] = 'Default student display';
$string['defaultstudentdisplay_help'] = 'The value new assignments start with for how much of the result students see.';
$string['enabled'] = 'Reference Checker';
$string['enabled_help'] = 'If enabled, a student\'s reference list is looked up in public academic databases after they submit. Students and teachers see a progress status, and optionally a summary or a full report.

References are read out of the files submitted through File submissions, so that type must also be enabled. Alternatively, set "Require text references" and the student pastes their reference list in as plain text, in which case File submissions is not needed at all.';
$string['error_abandoned'] = 'Checking was abandoned after repeated failures.';
$string['error_extractfailed'] = 'The text of this file could not be read.';
$string['error_noextractabletext'] = 'Almost no text could be read from this file. It may be a scanned image rather than a text document.';
$string['error_nopdftotext'] = 'PDF text extraction is not configured on this site.';
$string['event_check_completed'] = 'Reference check completed';
$string['export_as'] = 'Download this report as {$a}';
$string['export_col_error'] = 'Check error';
$string['export_col_number'] = 'No.';
$string['export_col_reference'] = 'Submitted reference';
$string['export_col_sourcefile'] = 'Source file';
$string['export_col_status'] = 'Result';
$string['export_col_url'] = 'Link';
$string['export_heading'] = 'Download';
$string['export_nojob'] = 'This submission has not been checked, so there is nothing to download.';
$string['export_pdf_assignment'] = 'Assignment';
$string['export_pdf_checked'] = 'Checked';
$string['export_pdf_downloaded'] = 'Downloaded';
$string['export_pdf_group'] = 'Group';
$string['export_pdf_reference'] = 'Reference {$a}';
$string['export_pdf_student'] = 'Student';
$string['export_unknownformat'] = 'That download format is not available.';
$string['filter_all'] = 'All';
$string['filter_issues'] = 'Issues';
$string['filter_mismatch'] = 'Mismatch';
$string['filter_notfound'] = 'Not found';
$string['filter_partial'] = 'Partial';
$string['filter_verified'] = 'Verified';
$string['interchunkdelay'] = 'Delay between runs';
$string['interchunkdelay_help'] = 'How long to wait before the background task processes the next batch of references. Increase this to spread load if you are approaching an external service\'s rate limit.';
$string['issue_authors'] = 'The authors do not match the work that was found.';
$string['issue_doi'] = 'The DOI points to a different work.';
$string['issue_year'] = 'You cited {$a->cited}, but the work that was found was published in {$a->found}.';
$string['match_mismatch'] = 'Mismatch';
$string['match_notfound'] = 'Not found';
$string['match_partial'] = 'Partial match';
$string['match_verified'] = 'Verified';
$string['maxconcurrency'] = 'Maximum concurrent checks';
$string['maxconcurrency_help'] = 'How many checking tasks may run at once across the whole site. This is the hard ceiling on how fast requests are sent to the external databases.';
$string['maxfilesizemb'] = 'Maximum file size to scan';
$string['maxfilesizemb_help'] = 'Submitted files larger than this are skipped.';
$string['maxreferences'] = 'Maximum references per submission';
$string['maxreferences_help'] = 'Checking stops after this many references. Students are told when their list was truncated.';
$string['nofileplugin'] = 'Reference Checker is enabled but File submissions is not. Nothing will be checked until File submissions is turned on for this assignment.';
$string['norefs_guidance'] = 'No reference list could be found in the submitted files. This usually means the reference list has no recognisable heading, or the file is a scanned image rather than a text document.';
$string['norefs_guidance_text'] = 'No references could be recognised in the reference list that was pasted in. Check that each reference is on its own line, or separated from the next by a blank line.';
$string['notconfigured'] = 'Reference Checker is not fully configured. Ask your site administrator to check the plugin settings.';
$string['pathtopdftotext'] = 'Path to pdftotext';
$string['pathtopdftotext_help'] = 'Full path to the pdftotext program, which is part of the poppler-utils package. Without it, PDF submissions cannot be read and only Word and plain text files will be checked.';
$string['pluginname'] = 'Reference Checker';
$string['privacy:metadata:arxiv'] = 'Reference details are sent to arXiv to find out whether the work exists and whether the details match.';
$string['privacy:metadata:arxiv:reference'] = 'The title of a single reference from the reference list.';
$string['privacy:metadata:cache'] = 'A shared cache of results already looked up, so the same reference is not requested from the external services repeatedly. It stores only a one-way hash of the reference and the bibliographic record that was found, never the text a student wrote.';
$string['privacy:metadata:cache:payload'] = 'The bibliographic record that was found.';
$string['privacy:metadata:cache:refhash'] = 'A one-way hash of the normalised reference.';
$string['privacy:metadata:crossref'] = 'Reference details are sent to CrossRef to find out whether the work exists and whether the details match.';
$string['privacy:metadata:crossref:reference'] = 'The text of a single reference from the reference list.';
$string['privacy:metadata:dblp'] = 'Reference details are sent to DBLP to find out whether the work exists and whether the details match.';
$string['privacy:metadata:dblp:reference'] = 'The title of a single reference from the reference list.';
$string['privacy:metadata:job'] = 'Information about the reference check carried out on a submission.';
$string['privacy:metadata:job:sectionheading'] = 'The reference list heading that was found in the submission.';
$string['privacy:metadata:job:status'] = 'How far the check has progressed.';
$string['privacy:metadata:job:timecompleted'] = 'When the check finished.';
$string['privacy:metadata:job:totalrefs'] = 'How many references were found in the submission.';
$string['privacy:metadata:openalex'] = 'Reference details are sent to OpenAlex to find out whether the work exists and whether the details match.';
$string['privacy:metadata:openalex:reference'] = 'The text of a single reference from the reference list.';
$string['privacy:metadata:refs'] = 'The individual references found in a submission and the result of checking each one.';
$string['privacy:metadata:refs:foundtitle'] = 'The title of the work that was matched.';
$string['privacy:metadata:refs:matchstatus'] = 'Whether the reference was verified, partially matched, mismatched or not found.';
$string['privacy:metadata:refs:rawref'] = 'A single reference, exactly as it appeared in the submission.';
$string['privacy:metadata:refs:timechecked'] = 'When this reference was checked.';
$string['privacy:metadata:semanticscholar'] = 'Reference details are sent to Semantic Scholar to find out whether the work exists and whether the details match.';
$string['privacy:metadata:semanticscholar:reference'] = 'The title of a single reference from the reference list.';
$string['privacy:metadata:text'] = 'The reference list a student pasted into the submission form.';
$string['privacy:metadata:text:referencetext'] = 'The reference list, exactly as the student pasted it in.';
$string['privacy:metadata:text:timemodified'] = 'When the reference list was last changed.';
$string['privacy:path'] = 'Reference check';
$string['privacynotice'] = 'Privacy notice';
$string['privacynotice_help'] = 'Shown to students alongside the explanatory text. Use it to say where reference data is sent and why.';
$string['rateinterval'] = 'Minimum gap between {$a} requests';
$string['rateinterval_help'] = 'The shortest time, in milliseconds, allowed between two requests to {$a}. Requests are paced across all background tasks, so this is a site-wide limit rather than a per-task one.

The defaults follow each service\'s own published guidance. Lowering them risks having the site\'s address throttled or blocked; raising them slows checking down.';
$string['refchecker:viewfullreport'] = 'View the full reference checking report';
$string['references'] = 'References';
$string['references_hint'] = 'Paste in your reference list here.';
$string['references_required'] = 'Paste in your reference list.';
$string['references_submitted'] = 'A reference list has been submitted.';
$string['report_apa'] = 'APA';
$string['report_authorscore'] = 'Authors';
$string['report_bibtex'] = 'BibTeX';
$string['report_citations'] = 'Citations';
$string['report_confidence'] = 'Confidence';
$string['report_doi'] = 'DOI';
$string['report_formatted'] = 'Formatted citation';
$string['report_foundauthors'] = 'Authors';
$string['report_foundjournal'] = 'Published in';
$string['report_foundtitle'] = 'Matched title';
$string['report_foundyear'] = 'Year';
$string['report_heading'] = 'Reference check report';
$string['report_iso690'] = 'ISO 690';
$string['report_issues'] = 'Issues';
$string['report_journalscore'] = 'Journal';
$string['report_mla'] = 'MLA';
$string['report_predatory_warning'] = 'This journal or publisher appears on a list of possibly predatory publishers. Check it carefully before relying on it.';
$string['report_rerun'] = 'Re-run checks';
$string['report_retracted_warning'] = 'This work has been retracted or withdrawn.';
$string['report_scholarlink'] = 'Search Google Scholar';
$string['report_source'] = 'Found in';
$string['report_submittedref'] = 'Your reference';
$string['report_titlescore'] = 'Title';
$string['requesttimeout'] = 'Request timeout';
$string['requesttimeout_help'] = 'How long to wait for a reply from an external database before giving up on a request.';
$string['requiretext'] = 'Require text references';
$string['requiretext_help'] = 'If enabled, students are required to submit their reference list as plain text. This should improve the accuracy of reference detection. If disabled, references will be detected within the submitted file.

* **No** - references are detected within the submitted file.
* **Yes - Optional** - a References box is shown on the submission form. If the student uses it, that text is checked and the submitted file is not read. If they leave it empty, references are detected within the submitted file.
* **Yes - Required** - a References box is shown and must be filled in. The submitted file is never read.

In both of the Yes settings, File submissions is optional: the assignment can be set up for the reference checker alone.';
$string['requiretext_no'] = 'No';
$string['requiretext_optional'] = 'Yes - Optional';
$string['requiretext_required'] = 'Yes - Required';
$string['rerunqueued'] = 'The references will be checked again shortly.';
$string['retaindays'] = 'Days to keep reference text';
$string['retaindays_help'] = 'After this many days, the stored copy of each reference is blanked while the result of the check is kept. Set to 0 to keep the reference text indefinitely.';
$string['sectionheading_found'] = 'References were read from the section headed "{$a}". Automatic reference extraction from PDF/DOCX files is experimental and might contain errors or miss citations. Always double check flagged references manually.';
$string['semanticscholarkey'] = 'Semantic Scholar API key';
$string['semanticscholarkey_help'] = 'Semantic Scholar shares one rate limit between every caller who does not identify themselves, which in practice means requests are refused within seconds of each other. Semantic Scholar is therefore treated as unavailable until a key is entered here. Keys are free and requested from the Semantic Scholar website.';
$string['sources'] = 'Databases to search';
$string['sources_help'] = 'Which databases to look references up in. They are always searched in the order shown, which puts the broadest coverage first.

* **CrossRef** covers journal articles, books, chapters, conference proceedings and standards, and carries retraction data.
* **OpenAlex** adds preprints and much conference material that CrossRef indexes patchily.
* **arXiv** covers preprints in computing, physics, mathematics and related fields, and is often the only source holding the version a student actually read.
* **DBLP** covers computer science conference proceedings thoroughly.
* **Semantic Scholar** needs an API key to be usable; without one it will refuse most requests.';
$string['staletimeout'] = 'Abandoned job timeout';
$string['staletimeout_help'] = 'A check that has made no progress for this long is treated as abandoned. It is retried once, then marked as failed so it does not sit showing progress forever.';
$string['status_cancelled'] = 'Superseded by a newer submission';
$string['status_complete'] = 'Complete';
$string['status_extracting'] = 'Reading your references';
$string['status_failed'] = 'Checking could not be completed';
$string['status_norefs'] = 'No references found';
$string['status_notapplicable'] = 'Nothing to check';
$string['status_pending'] = 'Pending checks';
$string['status_progress'] = '{$a->done} / {$a->total} ({$a->percent}%) checks complete';
$string['studentdisplay'] = 'Show students';
$string['studentdisplay_full'] = 'Full report';
$string['studentdisplay_help'] = 'How much of the result students see. Teachers always see the full report.

* **No information** &mdash; students see only whether checking has finished.
* **Summary** &mdash; students also see how many references were verified, partially matched or not found.
* **Full report** &mdash; students see the result for each individual reference.';
$string['studentdisplay_status'] = 'No information (status only)';
$string['studentdisplay_summary'] = 'Summary';
$string['studentinformation'] = 'Information for students';
$string['studentinformation_help'] = 'Shown on the assignment page whenever this plugin is enabled. Leave empty to use the default wording, which explains what the check does and its limits.';
$string['submittedreferences'] = 'Submitted reference list';
$string['supportedtypes'] = 'File types to scan';
$string['supportedtypes_help'] = 'Which submitted file types to read references from. PDF needs pdftotext; .docx is read by the built-in converter, and the other Word, OpenDocument and rich text formats need the site\'s document converter.';
$string['task_check'] = 'Check submitted references';
$string['task_extract'] = 'Read references from submitted files';
$string['task_reconcile'] = 'Reference checking housekeeping';
$string['truncated_notice'] = 'Only the first {$a} references were checked.';
$string['usebuiltinconverter'] = 'Use the built-in document converter';
$string['usebuiltinconverter_help'] = 'Convert Word (docx only) documents to text using the plugin\'s built-in document converter. If enabled, this will take priority over the site\'s document converter.';
$string['uselibreoffice'] = 'Use the document converter';
$string['uselibreoffice_help'] = 'Convert Word, OpenDocument and RTF files to text using the site\'s document converter. Turn this off if the converter is unavailable; only PDF and plain text will then be scanned.';
$string['viewheader_default'] = 'Your reference list will be checked automatically. After you submit, this assignment looks up each reference in public academic databases to see whether it exists and whether the details match. Checking happens in the background and can take several minutes, so refresh this page to see progress.

The check is a guide only. It can be wrong, especially for books, reports, websites and other sources that are not indexed in those databases. It is not a plagiarism check and it does not decide your grade.';
$string['viewheader_level_full'] = 'You will see the result for each of your references once checking has finished.';
$string['viewheader_level_status'] = 'You will see when checking has finished, but not the detailed results.';
$string['viewheader_level_summary'] = 'You will see a summary of how many of your references were verified once checking has finished.';
$string['viewheader_teacher_level'] = 'Students on this assignment see: {$a}';
