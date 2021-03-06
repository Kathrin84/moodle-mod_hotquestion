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
 * Internal library of functions for module hotquestion.
 *
 * All the hotquestion specific functions, needed to implement the module
 * logic, should go here. Never include this file from your lib.php!
 *
 * @package   mod_hotquestion
 * @copyright 2011 Sun Zhigang
 * @copyright 2016 onwards AL Rachels drachels@drachels.com
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class mod_hotquestion {
    public $instance;
    public $cm;
    public $course;

    protected $current_round;
    protected $prev_round;
    protected $next_round;

    public function __construct($cmid, $roundid = -1) {
        global $DB;
        $this->cm        = get_coursemodule_from_id('hotquestion', $cmid, 0, false, MUST_EXIST);
        $this->course    = $DB->get_record('course', array('id' => $this->cm->course), '*', MUST_EXIST);
        $this->instance  = $DB->get_record('hotquestion', array('id' => $this->cm->instance), '*', MUST_EXIST);
        $this->set_current_round($roundid);
    }

    /**
     * Return whether the user has voted on specified question.
     *
     * @param int $question question id
     * @param int $user user id. -1 means current user
     * @return boolean
     */
    public function has_voted($question, $user = -1) {
        global $USER, $DB;
        if ($user == -1) {
            $user = $USER->id;
        }
        return $DB->record_exists('hotquestion_votes', array('question' => $question, 'voter' => $user));
    }

    /**
     * Add a new question to current round.
     *
     * @global object
     * @global object
     * @global object
     * @param object $fromform from ask form
     */
    public function add_new_question($fromform) {
        global $USER, $CFG, $DB;
        $data = new StdClass();
        $data->hotquestion = $this->instance->id;
        $data->content = trim($fromform->question);
        $data->userid = $USER->id;
        $data->time = time();
        $context = context_module::instance($this->cm->id);
        if (isset($fromform->anonymous) && $fromform->anonymous && $this->instance->anonymouspost) {
            $data->anonymous = $fromform->anonymous;
            // Assume this user is guest.
            $data->userid = $CFG->siteguest;
        }
        if (!empty($data->content)) {
            $DB->insert_record('hotquestion_questions', $data);
            if ($CFG->version > 2014051200) { // Moodle 2.7+.
                $params = array(
                    'objectid' => $this->cm->id,
                    'context' => $context,
                );
                $event = \mod_hotquestion\event\add_question::create($params);
                $event->trigger();
            } else {
                add_to_log($this->course->id, "hotquestion", "add question"
                    , "view.php?id={$this->cm->id}", $data->content, $this->cm->id);
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * Vote on question.
     *
     * @global object
     * @global object
     * @param int $question the question id
     */
    public function vote_on($question) {
        global $CFG, $DB, $USER;
        $votes = new StdClass();
        $context = context_module::instance($this->cm->id);
        $question = $DB->get_record('hotquestion_questions', array('id' => $question));
        if ($question && $this->can_vote_on($question)) {

            if ($CFG->version > 2014051200) { // Moodle 2.7+.
                $params = array(
                    'objectid' => $this->cm->id,
                    'context' => $context,
                );
                $event = \mod_hotquestion\event\update_vote::create($params);
                $event->trigger();
            } else {
                add_to_log($this->course->id, 'hotquestion', 'update vote'
                    , "view.php?id={$this->cm->id}", $question->id, $this->cm->id);
            }

            if (!$this->has_voted($question->id)) {
                $votes->question = $question->id;
                $votes->voter = $USER->id;
                $DB->insert_record('hotquestion_votes', $votes);
            } else {
                $DB->delete_records('hotquestion_votes', array('question' => $question->id, 'voter' => $USER->id));
            }
        }
    }

    /**
     * Whether can vote on the question.
     *
     * @param object or int $question
     * @param object $user null means current user
     */
    public function can_vote_on($question, $user = null) {
        global $USER, $DB;

        if (is_int($question)) {
            $question = $DB->get_record('hotquestion_questions', array('id' => $question));
        }
        if (empty($user)) {
            $user = $USER;
        }

        // Is this question in last round?
        $rounds = $DB->get_records('hotquestion_rounds', array('hotquestion' => $this->instance->id), 'id DESC', '*', 0, 1);
        $lastround = reset($rounds);
        $inlastround = $question->time >= $lastround->starttime;

        return $question->userid != $user->id && $inlastround;
    }

    /**
     * Open a new round and close the old one.
     *
     * @global object
     */
    public function add_new_round() {
        global $USER, $CFG, $DB;

        // Close the latest round.
        $rounds = $DB->get_records('hotquestion_rounds', array('hotquestion' => $this->instance->id), 'id DESC', '*', 0, 1);
        $old = array_pop($rounds);
        $old->endtime = time();
        $DB->update_record('hotquestion_rounds', $old);

        // Open a new round.
        $new = new StdClass();
        $new->hotquestion = $this->instance->id;
        $new->starttime = time();
        $new->endtime = 0;
        $context = context_module::instance($this->cm->id);
        $rid = $DB->insert_record('hotquestion_rounds', $new);

        if ($CFG->version > 2014051200) { // Moodle 2.7+.
            $params = array(
                'objectid' => $this->cm->id,
                'context' => $context,
            );

            $event = \mod_hotquestion\event\add_round::create($params);
            $event->trigger();
        } else {
            add_to_log($this->course->id, 'hotquestion', 'add round', "view.php?id={$this->cm->id}&round=$rid", $rid, $this->cm->id);
        }
    }

    /**
     * Set current round to show.
     *
     * @global object
     * @param int $roundid
     */
    public function set_current_round($roundid = -1) {
        global $DB;

        $rounds = $DB->get_records('hotquestion_rounds', array('hotquestion' => $this->instance->id), 'id ASC');
        if (empty($rounds)) {
            // Create the first round.
            $round = new StdClass();
            $round->starttime = time();
            $round->endtime = 0;
            $round->hotquestion = $this->instance->id;
            $round->id = $DB->insert_record('hotquestion_rounds', $round);
            $rounds[] = $round;
        }

        if ($roundid != -1 && array_key_exists($roundid, $rounds)) {
            $this->current_round = $rounds[$roundid];

            $ids = array_keys($rounds);
            // Search previous round.
            $currentkey = array_search($roundid, $ids);
            if (array_key_exists($currentkey - 1, $ids)) {
                $this->prev_round = $rounds[$ids[$currentkey - 1]];
            } else {
                $this->prev_round = null;
            }
            // Search next round.
            if (array_key_exists($currentkey + 1, $ids)) {
                $this->next_round = $rounds[$ids[$currentkey + 1]];
            } else {
                $this->next_round = null;
            }
        } else {
            // Use the last round.
            $this->current_round = array_pop($rounds);
            $this->prev_round = array_pop($rounds);
            $this->next_round = null;
        }
        return $roundid;
    }

    /**
     * Return current round.
     *
     * @return object
     */
    public function get_current_round() {
        return $this->current_round;
    }

    /**
     * Return previous round.
     *
     * @return object
     */
    public function get_prev_round() {
        return $this->prev_round;
    }

    /**
     * Return next round.
     *
     * @return object
     */
    public function get_next_round() {
        return $this->next_round;
    }

    /**
     * Return questions according to $current_round.
     *
     * @global object
     * @return all questions with vote count in current round.
     */
    public function get_questions() {
        global $DB;
        if ($this->current_round->endtime == 0) {
            $this->current_round->endtime = 0xFFFFFFFF;  // Hack.
        }
        $params = array($this->instance->id, $this->current_round->starttime, $this->current_round->endtime);
        return $DB->get_records_sql('SELECT q.*, count(v.voter) as votecount
                                     FROM {hotquestion_questions} q
                                         LEFT JOIN {hotquestion_votes} v
                                         ON v.question = q.id
                                     WHERE q.hotquestion = ?
                                        AND q.time >= ?
                                        AND q.time <= ?
                                     GROUP BY q.id
                                     ORDER BY votecount DESC, q.time DESC', $params);
    }

    /**
     * Remove selected question and any votes that it might have.
     *
     * @return object
     */
    public function remove_question() {
        global $DB;
        if (isset($_GET['q'])) {
            $questionid = $_GET['q'];
            $dbquestion = $DB->get_record('hotquestion_questions', array('id' => $questionid));
            $DB->delete_records('hotquestion_questions', array('id' => $dbquestion->id));
            // Get an array of all votes on the question that was just deleted, then delete them.
            $dbvote = $DB->get_records('hotquestion_votes', array('question' => $questionid));
            $DB->delete_records('hotquestion_votes', array('question' => $dbquestion->id));
        }
        return $this->current_round;
    }

    /**
     * If the currently being viewed round is empty, delete it.
     * Otherwise, remove any questions in the round currently being viewed,
     * remove any votes for each question being removed,
     * then remove the currently being viewed round.
     * @return nothing
     */
    public function remove_round() {
        global $DB;

        $data = new StdClass();
        $data->hotquestion = $this->instance->id;
        $context = context_module::instance($this->cm->id);
        // Trigger remove_round event.
        $event = \mod_hotquestion\event\remove_round::create(array(
            'objectid' => $data->hotquestion,
            'context' => $context
        ));
        $event->trigger();

        $roundid = $_GET['round'];
        if ($this->current_round->endtime == 0) {
            $this->current_round->endtime = 0xFFFFFFFF;  // Hack.
        }
        $params = array($this->instance->id, $this->current_round->starttime, $this->current_round->endtime);
        $questions = $DB->get_records_sql('SELECT q.*, count(v.voter) as votecount
                                     FROM {hotquestion_questions} q
                                         LEFT JOIN {hotquestion_votes} v
                                         ON v.question = q.id
                                     WHERE q.hotquestion = ?
                                         AND q.time >= ?
                                         AND q.time <= ?
                                     GROUP BY q.id
                                     ORDER BY votecount DESC, q.time DESC', $params);

        if ($questions) {
            foreach ($questions as $q) {
                $questionid = $q->id; // Get id of first question on the page to delete.
                $dbquestion = $DB->get_record('hotquestion_questions', array('id' => $questionid));
                $DB->delete_records('hotquestion_questions', array('id' => $dbquestion->id));
                // Get an array of all votes on the question that was just deleted, then delete them.
                $dbvote = $DB->get_records('hotquestion_votes', array('question' => $questionid));
                $DB->delete_records('hotquestion_votes', array('question' => $dbquestion->id));
            }
            // Now that all questions and votes are gone, remove the round.
            $dbround = $DB->get_record('hotquestion_rounds', array('id' => $roundid));
            $DB->delete_records('hotquestion_rounds', array('id' => $dbround->id));
        } else {
            // This round is empty so delete without having to remove questions and votes.
            $dbround = $DB->get_record('hotquestion_rounds', array('id' => $roundid));
            $DB->delete_records('hotquestion_rounds', array('id' => $dbround->id));
        }
        // Now we need to see if we need a new round or have one we can still use.
        $rounds = $DB->get_records('hotquestion_rounds', array('hotquestion' => $this->instance->id), 'id DESC');

        foreach ($rounds as $rnd) {
            if ($rnd->endtime == 0) {
                // Deleted a closed round so just return.
                return;
            } else {
                // Deleted our open round so create a new round.
                $round = new StdClass();
                $round->starttime = time();
                $round->endtime = 0;
                $round->hotquestion = $this->instance->id;
                $round->id = $DB->insert_record('hotquestion_rounds', $round);
                $rounds[] = $round;
                return;
            }
        }
        return $this->current_round;
    }

    /**
     * Download questions.
     *
     * @return nothing
     */
    public function download_questions($array, $filename = "export.csv", $delimiter=";") {
        global $CFG, $DB, $USER;
        $data = new StdClass();
        $data->hotquestion = $this->instance->id;
        $context = context_module::instance($this->cm->id);
        // Trigger download_questions event.
        $event = \mod_hotquestion\event\download_questions::create(array(
            'objectid' => $data->hotquestion,
            'context' => $context
        ));
        $event->trigger();

        // Construct sql query and filename based on admin or teacher.
        // Add filename details based on course and HQ activity name.
        if (is_siteadmin($USER->id)) {
            $whichhqs = ('AND hq.hotquestion > 0');
            $filename = get_string('exportfilenamep1', 'hotquestion');
        } else {
            $whichhqs = ('AND hq.hotquestion = ');
            $whichhqs .= ($this->instance->id);
            $filename = ($this->course->shortname).'_';
            $filename .= ($this->instance->name);
        }
        $filename .= get_string('exportfilenamep2', 'hotquestion').gmdate("Ymd_Hi").'GMT.csv';

        $params = array();

        header('Content-Type: text/csv');
        header('Content-Disposition: attachement; filename="'.$filename.'";');
        header("Pragma: no-cache");
        header("Expires: 0");

        $file = fopen('php://output', 'w');
        $params = array(get_string('id', 'hotquestion'),
                        get_string('firstname'),
                        get_string('lastname'),
                        get_string('hotquestion', 'hotquestion'),
                        get_string('content', 'hotquestion'),
                        get_string('userid', 'hotquestion'),
                        get_string('time', 'hotquestion'),
                        get_string('anonymous', 'hotquestion'));
        fputcsv($file, $params, $delimiter);

        $sql = "SELECT hq.id id,
                CASE
                    WHEN u.firstname = 'Guest user'
                    THEN CONCAT(u.lastname, 'Anonymous')
                    ELSE u.firstname
                END AS 'firstname',
                        u.lastname AS 'lastname', hq.hotquestion hotquestion, hq.content content, hq.userid userid,
                FROM_UNIXTIME(hq.time) AS TIME, hq.anonymous anonymous
                FROM {hotquestion_questions} hq
                JOIN {user} u ON u.id = hq.userid
                WHERE hq.userid > 0 ";
        $sql .= ($whichhqs);
        $sql .= " ORDER BY hq.hotquestion, u.id";
        if ($hqs = $DB->get_records_sql($sql, $params)) {
            foreach ($hqs as $q) {
                $fields = array($q->id, $q->firstname, $q->lastname, $q->hotquestion, $q->content, $q->userid, $q->time, $q->anonymous);
                fputcsv($file, $fields, $delimiter);
            }
        }
        fclose($file);
        exit;
    }
}

/**
 * Count questions in current rounds.
 * Counts all the hotquestion entries (optionally in a given group)
 * @return nothing
 */
function hotquestion_count_entries($hotquestion, $groupid = 0) {

    global $DB;

    $cm = hotquestion_get_coursemodule($hotquestion->id);
    $context = context_module::instance($cm->id);
    // Currently, groups are not being used by Hot Question.
    if ($groupid) {     // How many in a particular group?

        $sql = "SELECT DISTINCT u.id FROM {hotquestion_questions} hq
                JOIN {groups_members} g ON g.userid = hq.userid
                JOIN {user} u ON u.id = g.userid
                WHERE hq.hotquestion = $hotquestion->id AND g.groupid = '$groupid'";
        $hotquestions = $DB->get_records_sql($sql);

    } else { // Count all the entries from the whole course.

        $sql = "SELECT COUNT(DISTINCT hq.userid) AS ucount, COUNT(DISTINCT hq.content) AS qcount FROM {hotquestion_questions} hq
                JOIN {user} u ON u.id = hq.userid
                LEFT JOIN {hotquestion_rounds} hr ON hr.hotquestion=hq.hotquestion
                WHERE hq.hotquestion = '$hotquestion->id' AND
                hr.endtime=0 AND
                hq.time>=hr.starttime AND
                hq.userid>0";

        $hotquestions = $DB->get_records_sql($sql);
    }

    if (!$hotquestions) {
        return 0;
    }

    $canadd = get_users_by_capability($context, 'mod/hotquestion:ask', 'u.id');
    $entriesmanager = get_users_by_capability($context, 'mod/hotquestion:manageentries', 'u.id');

    return ($hotquestions);
}

/**
 * Returns the hotquestion instance course_module id
 *
 * @param integer $hotquestion
 * @return object
 */
function hotquestion_get_coursemodule($hotquestionid) {

    global $DB;

    return $DB->get_record_sql("SELECT cm.id FROM {course_modules} cm
								JOIN {modules} m ON m.id = cm.module
								WHERE cm.instance = '$hotquestionid' AND m.name = 'hotquestion'");
}
