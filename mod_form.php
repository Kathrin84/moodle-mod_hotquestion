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
 * The main hotquestion configuration form.
 *
 * It uses the standard core Moodle formslib. For more info about them, please
 * visit: http://docs.moodle.org/en/Development:lib/formslib.php
 *
 * @package   mod_hotquestion
 * @copyright 2011 Sun Zhigang
 * @copyright 2016 onwards AL Rachels drachels@drachels.com
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/course/moodleform_mod.php');

class mod_hotquestion_mod_form extends moodleform_mod {

    public function definition() {

        global $COURSE, $CFG;
        $mform =& $this->_form;

        // Adding the "general" fieldset, where all the common settings are shown.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Adding the standard "name" field.
        $mform->addElement('text', 'name', get_string('hotquestionname', 'hotquestion'), array('size' => '64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEAN);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Adding the standard "intro" fields based on Moodle version.
        if ($CFG->branch < 29) {
            $this->add_intro_editor(true, get_string('description'));
        } else {
            $this->standard_intro_elements();
        }

        // Adding the rest of hotquestion settings, spreading them into this fieldset
        // or adding more fieldsets ('header' elements), if needed for better logic.
        // Adding 'anonymouspost' field.
        $mform->addElement('selectyesno', 'anonymouspost', get_string('allowanonymouspost', 'hotquestion'));
        $mform->setDefault('anonymouspost', '1');

        // Add standard elements, common to all modules.
        $this->standard_coursemodule_elements();

        // Add standard buttons, common to all modules.
        $this->add_action_buttons();

    }
}

// Form for submitting question.
class hotquestion_form extends moodleform {

    public function definition() {
        global $CFG;

        list($allowanonymous, $cm) = $this->_customdata;

        $mform =& $this->_form;
        $mform->addElement('textarea', 'question', get_string('inputquestion', 'hotquestion'), 'wrap="virtual" rows="3" cols="50"');
        // Next line is there for a possible config setting.
        // $mform->addElement('textarea', 'question', get_config('mod_hotquestion','inputquestion'), 'wrap="virtual" rows="3" cols="50"');
        $mform->setType('question', PARAM_TEXT);
        $mform->addElement('hidden', 'id', $cm->id, 'id="hotquestion_courseid"');
        $mform->setType('id', PARAM_INT);

        $submitgroup = array();
        $submitgroup[] =& $mform->createElement('submit', 'submitbutton', get_string('post'));
        if ($allowanonymous) {
            $submitgroup[] =& $mform->createElement('checkbox', 'anonymous', '', get_string('displayasanonymous', 'hotquestion'));
            $mform->setType('anonymous', PARAM_BOOL);
        }
        $mform->addGroup($submitgroup);

    }
}
