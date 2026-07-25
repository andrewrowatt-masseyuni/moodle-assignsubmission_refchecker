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

namespace assignsubmission_refchecker\check;

use assignsubmission_refchecker\local\extractor;
use assignsubmission_refchecker\local\source\chain;
use core\check\check;
use core\check\result;
use html_writer;
use moodle_url;

/**
 * Reports whether reference checking can actually work on this site.
 *
 * Surfaced through Site administration ▸ Reports ▸ System status so that a silent failure — every
 * submission sitting at "Pending checks" because an address was never configured, or because
 * pdftotext is missing — is visible without anyone thinking to look at the plugin.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sources extends check {
    /**
     * The check's name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('check_sources', 'assignsubmission_refchecker');
    }

    /**
     * Link to the plugin's settings, which is where every problem here is fixed.
     *
     * @return \action_link|null
     */
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new moodle_url('/admin/settings.php', ['section' => 'assignsubmission_refchecker']),
            get_string('check_sources_action', 'assignsubmission_refchecker'),
        );
    }

    /**
     * Probe the configuration and the external services.
     *
     * @return result
     */
    public function get_result(): result {
        $plugin = \core_plugin_manager::instance()->get_plugin_info('assignsubmission_refchecker');
        if (!$plugin) {
            return new result(result::NA, get_string('check_notinstalled', 'assignsubmission_refchecker'));
        }

        $details = [];
        $problems = [];

        // A contact address is not required, but without it both services apply much tighter
        // rate limits, which at cohort scale is the difference between hours and days.
        if (trim((string) get_config('assignsubmission_refchecker', 'contactemail')) === '') {
            $problems[] = get_string('check_nocontactemail', 'assignsubmission_refchecker');
        }

        // PDF is the most common submission format, so losing it is close to losing the feature.
        $pdftotext = (string) get_config('assignsubmission_refchecker', 'pathtopdftotext');
        if ($pdftotext === '' || !is_executable($pdftotext)) {
            $problems[] = get_string('check_nopdftotext', 'assignsubmission_refchecker');
        } else {
            $details[] = get_string('check_pdftotextfound', 'assignsubmission_refchecker', $pdftotext);
        }

        $chain = chain::from_config();
        if (!$chain->get_sources()) {
            $problems[] = get_string('check_nosources', 'assignsubmission_refchecker');
        }

        foreach ($chain->get_sources() as $source) {
            if ($source->is_available()) {
                $details[] = get_string(
                    'check_sourceok',
                    'assignsubmission_refchecker',
                    $source->get_display_name(),
                );
            } else {
                $problems[] = get_string(
                    'check_sourceunreachable',
                    'assignsubmission_refchecker',
                    $source->get_display_name(),
                );
            }
        }

        $details[] = get_string(
            'check_filetypes',
            'assignsubmission_refchecker',
            implode(', ', extractor::supported_types()),
        );

        $summary = $problems
            ? get_string('check_problems', 'assignsubmission_refchecker', count($problems))
            : get_string('check_ok', 'assignsubmission_refchecker');

        $body = html_writer::alist(array_merge($problems, $details));

        return new result($problems ? result::WARNING : result::OK, $summary, $body);
    }
}
