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
 * Capability definitions for Reference Checker
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Whether this submission type can be turned on and configured on an assignment at all. The
    // plugin is being rolled out to a small number of staff first, so it is granted to no archetype:
    // until somebody is given it explicitly, the assignment settings form does not offer Reference
    // Checker to anyone but a site administrator. Declared at module level so it can be granted
    // site-wide, per course, or on a single assignment.
    //
    // It gates the settings form only. Once an assignment has the plugin on, checking, the status
    // line and the report all behave the same for everyone, whoever holds this.
    'assignsubmission/refchecker:configure' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [],
    ],

    // Holders always see the full per-reference report, whatever the assignment's student display
    // setting says. Deliberately not granted to students: students reach the full report only
    // through the per-assignment setting, never through a capability.
    'assignsubmission/refchecker:viewfullreport' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
