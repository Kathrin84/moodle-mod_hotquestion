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
 * Administration settings definitions for the quiz module.
 *
 * @package    mod
 * @subpackage hotquestion
 * @copyright  2016 onwards AL Rachels (drachels@drachels.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later.
 */


defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    require_once($CFG->dirroot.'/mod/hotquestion/lib.php');
    require_once($CFG->dirroot.'/mod/hotquestion/locallib.php');

    // Recent activity setting.
    $name = new lang_string('showrecentactivity', 'hotquestion');
    $description = new lang_string('showrecentactivityconfig', 'hotquestion');
    $settings->add(new admin_setting_configcheckbox('hotquestion/showrecentactivity',
                                                    $name,
                                                    $description,
                                                    0));
}
