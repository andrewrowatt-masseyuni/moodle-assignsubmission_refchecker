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
 * Settings for Reference Checker assign submission plugin
 *
 * Note there is deliberately no site-wide "disable" setting here: mod_assign already owns the
 * assignsubmission_refchecker/disabled key through the submission plugin management page, and
 * assign_plugin::is_visible() reads it.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use assignsubmission_refchecker\local\check_timing;
use assignsubmission_refchecker\local\debug_log;
use assignsubmission_refchecker\local\display_level;
use assignsubmission_refchecker\local\text_mode;

defined('MOODLE_INTERNAL') || die();

// Point administrators at the health check rather than duplicating it as a bespoke test page:
// the plugin registers a core status check, which is where a broken configuration will be noticed.
$settings->add(new admin_setting_heading(
    'assignsubmission_refchecker/checkstatus',
    '',
    new lang_string(
        'checkstatuslink',
        'assignsubmission_refchecker',
        (new moodle_url('/report/status/index.php'))->out()
    )
));

$settings->add(new admin_setting_configcheckbox(
    'assignsubmission_refchecker/default',
    new lang_string('default', 'assignsubmission_refchecker'),
    new lang_string('default_help', 'assignsubmission_refchecker'),
    0
));

// Identifying the site to CrossRef and OpenAlex moves requests into their "polite pools", which
// carry far more generous rate limits. This is the highest impact setting on the page.
$settings->add(new admin_setting_configtext(
    'assignsubmission_refchecker/contactemail',
    new lang_string('contactemail', 'assignsubmission_refchecker'),
    new lang_string('contactemail_help', 'assignsubmission_refchecker'),
    '',
    PARAM_EMAIL
));

$settings->add(new admin_setting_configmultiselect(
    'assignsubmission_refchecker/sources',
    new lang_string('sources', 'assignsubmission_refchecker'),
    new lang_string('sources_help', 'assignsubmission_refchecker'),
    \assignsubmission_refchecker\local\source\chain::DEFAULT_SOURCES,
    [
        'crossref' => 'CrossRef',
        'openalex' => 'OpenAlex',
        'arxiv' => 'arXiv',
        'dblp' => 'DBLP',
        'semanticscholar' => 'Semantic Scholar',
    ]
));

// Semantic Scholar puts every anonymous caller in one shared pool and refuses requests within
// seconds of each other, so it is off by default and only worth enabling once a key is here.
$settings->add(new admin_setting_configpasswordunmask(
    'assignsubmission_refchecker/semanticscholarkey',
    new lang_string('semanticscholarkey', 'assignsubmission_refchecker'),
    new lang_string('semanticscholarkey_help', 'assignsubmission_refchecker'),
    ''
));

// How closely requests to each database may be spaced. These exist because the services differ by
// more than an order of magnitude in what they will tolerate.
foreach (
    [
        'crossref' => 'CrossRef',
        'openalex' => 'OpenAlex',
        'arxiv' => 'arXiv',
        'dblp' => 'DBLP',
        'semanticscholar' => 'Semantic Scholar',
    ] as $sourcename => $sourcelabel
) {
    $settings->add(new admin_setting_configtext(
        'assignsubmission_refchecker/rateinterval_' . $sourcename,
        new lang_string('rateinterval', 'assignsubmission_refchecker', $sourcelabel),
        new lang_string('rateinterval_help', 'assignsubmission_refchecker', $sourcelabel),
        \assignsubmission_refchecker\local\rate_limiter::default_interval($sourcename),
        PARAM_INT
    ));
}

// Text extraction.
$settings->add(new admin_setting_configexecutable(
    'assignsubmission_refchecker/pathtopdftotext',
    new lang_string('pathtopdftotext', 'assignsubmission_refchecker'),
    new lang_string('pathtopdftotext_help', 'assignsubmission_refchecker'),
    '/usr/bin/pdftotext'
));

// Listed above the site converter because that is the order the two are tried in.
$settings->add(new admin_setting_configcheckbox(
    'assignsubmission_refchecker/usebuiltinconverter',
    new lang_string('usebuiltinconverter', 'assignsubmission_refchecker'),
    new lang_string('usebuiltinconverter_help', 'assignsubmission_refchecker'),
    1
));

$settings->add(new admin_setting_configcheckbox(
    'assignsubmission_refchecker/uselibreoffice',
    new lang_string('uselibreoffice', 'assignsubmission_refchecker'),
    new lang_string('uselibreoffice_help', 'assignsubmission_refchecker'),
    1
));

$settings->add(new admin_setting_configmultiselect(
    'assignsubmission_refchecker/supportedtypes',
    new lang_string('supportedtypes', 'assignsubmission_refchecker'),
    new lang_string('supportedtypes_help', 'assignsubmission_refchecker'),
    ['pdf', 'docx', 'odt', 'rtf', 'txt'],
    [
        'pdf' => 'PDF (.pdf)',
        'docx' => 'Word (.docx)',
        'doc' => 'Word 97-2003 (.doc)',
        'odt' => 'OpenDocument (.odt)',
        'rtf' => 'Rich text (.rtf)',
        'txt' => 'Plain text (.txt)',
    ]
));

$filesizes = [];
foreach ([1, 2, 5, 10, 20, 50, 100] as $mb) {
    $filesizes[$mb] = $mb . ' MB';
}
$settings->add(new admin_setting_configselect(
    'assignsubmission_refchecker/maxfilesizemb',
    new lang_string('maxfilesizemb', 'assignsubmission_refchecker'),
    new lang_string('maxfilesizemb_help', 'assignsubmission_refchecker'),
    20,
    $filesizes
));

// Throughput. These settings together are the hard ceiling on how fast the site talks to the
// external databases.
$chunksizes = array_combine(range(1, 50), range(1, 50));
$settings->add(new admin_setting_configselect(
    'assignsubmission_refchecker/chunksize',
    new lang_string('chunksize', 'assignsubmission_refchecker'),
    new lang_string('chunksize_help', 'assignsubmission_refchecker'),
    10,
    $chunksizes
));

$concurrency = array_combine(range(1, 8), range(1, 8));
$settings->add(new admin_setting_configselect(
    'assignsubmission_refchecker/maxconcurrency',
    new lang_string('maxconcurrency', 'assignsubmission_refchecker'),
    new lang_string('maxconcurrency_help', 'assignsubmission_refchecker'),
    2,
    $concurrency
));

$settings->add(new admin_setting_configduration(
    'assignsubmission_refchecker/interchunkdelay',
    new lang_string('interchunkdelay', 'assignsubmission_refchecker'),
    new lang_string('interchunkdelay_help', 'assignsubmission_refchecker'),
    0
));

$settings->add(new admin_setting_configduration(
    'assignsubmission_refchecker/requesttimeout',
    new lang_string('requesttimeout', 'assignsubmission_refchecker'),
    new lang_string('requesttimeout_help', 'assignsubmission_refchecker'),
    30
));

$settings->add(new admin_setting_configtext(
    'assignsubmission_refchecker/maxreferences',
    new lang_string('maxreferences', 'assignsubmission_refchecker'),
    new lang_string('maxreferences_help', 'assignsubmission_refchecker'),
    200,
    PARAM_INT
));

$settings->add(new admin_setting_configduration(
    'assignsubmission_refchecker/cachettl',
    new lang_string('cachettl', 'assignsubmission_refchecker'),
    new lang_string('cachettl_help', 'assignsubmission_refchecker'),
    30 * DAYSECS
));

$settings->add(new admin_setting_configduration(
    'assignsubmission_refchecker/staletimeout',
    new lang_string('staletimeout', 'assignsubmission_refchecker'),
    new lang_string('staletimeout_help', 'assignsubmission_refchecker'),
    6 * HOURSECS
));

$settings->add(new admin_setting_configtext(
    'assignsubmission_refchecker/retaindays',
    new lang_string('retaindays', 'assignsubmission_refchecker'),
    new lang_string('retaindays_help', 'assignsubmission_refchecker'),
    0,
    PARAM_INT
));

// What students are told and shown.
$settings->add(new admin_setting_confightmleditor(
    'assignsubmission_refchecker/studentinformation',
    new lang_string('studentinformation', 'assignsubmission_refchecker'),
    new lang_string('studentinformation_help', 'assignsubmission_refchecker'),
    '',
    PARAM_RAW,
    '',
    '15'
));

$settings->add(new admin_setting_confightmleditor(
    'assignsubmission_refchecker/privacynotice',
    new lang_string('privacynotice', 'assignsubmission_refchecker'),
    new lang_string('privacynotice_help', 'assignsubmission_refchecker'),
    '',
    PARAM_RAW,
    '',
    '10'
));

$settings->add(new admin_setting_configselect(
    'assignsubmission_refchecker/defaultstudentdisplay',
    new lang_string('defaultstudentdisplay', 'assignsubmission_refchecker'),
    new lang_string('defaultstudentdisplay_help', 'assignsubmission_refchecker'),
    display_level::STATUS_ONLY,
    display_level::menu()
));

$settings->add(new admin_setting_configselect(
    'assignsubmission_refchecker/defaultchecktiming',
    new lang_string('defaultchecktiming', 'assignsubmission_refchecker'),
    new lang_string('defaultchecktiming_help', 'assignsubmission_refchecker'),
    check_timing::SUBMIT,
    check_timing::menu()
));

$settings->add(new admin_setting_configselect(
    'assignsubmission_refchecker/defaultrequiretext',
    new lang_string('defaultrequiretext', 'assignsubmission_refchecker'),
    new lang_string('defaultrequiretext_help', 'assignsubmission_refchecker'),
    text_mode::NONE,
    text_mode::menu()
));

// Diagnostics. Off by default and meant to be switched on only while a specific problem is being
// chased, because the log records the text of students' references.
$settings->add(new admin_setting_configcheckbox(
    'assignsubmission_refchecker/debuglog',
    new lang_string('debuglog', 'assignsubmission_refchecker'),
    new lang_string('debuglog_help', 'assignsubmission_refchecker'),
    0
));

$settings->add(new admin_setting_configselect(
    'assignsubmission_refchecker/debuglogretention',
    new lang_string('debuglogretention', 'assignsubmission_refchecker'),
    new lang_string('debuglogretention_help', 'assignsubmission_refchecker'),
    debug_log::DEFAULT_RETENTION_HOURS,
    debug_log::retention_options()
));

$settings->add(new admin_setting_heading(
    'assignsubmission_refchecker/debugloglink',
    '',
    new lang_string(
        'debugloglink',
        'assignsubmission_refchecker',
        (new moodle_url('/mod/assign/submission/refchecker/logs.php'))->out()
    )
));
