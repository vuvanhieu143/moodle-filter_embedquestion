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

namespace filter_embedquestion;

use filter_embedquestion\output\error_message;
use filter_embedquestion\output\renderer;
use core_question\local\bank\question_version_status;

/**
 * Helper functions for filter_embedquestion.
 *
 * @package   filter_embedquestion
 * @copyright 2018 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class utils {

    /**
     * Are we running in a Moodle version with question versionning.
     *
     * @return bool true if the questoin versions exist.
     */
    public static function has_question_versionning(): bool {
        global $DB;
        static $hasversionning = null;
        if ($hasversionning === null) {
            $hasversionning = $DB->get_manager()->table_exists('question_bank_entries');
        }
        return $hasversionning;
    }

    /**
     * Display a warning notification if the filter is not enabled in this context.
     *
     * @param \context $context the context to check.
     */
    public static function warn_if_filter_disabled(\context $context): void {
        global $OUTPUT;
        if (!filter_is_enabled('embedquestion')) {
            echo $OUTPUT->notification(get_string('warningfilteroffglobally', 'filter_embedquestion'));
        } else {
            $activefilters = filter_get_active_in_context($context);
            if (!isset($activefilters['embedquestion'])) {
                echo $OUTPUT->notification(get_string('warningfilteroffhere', 'filter_embedquestion'));
            }
        }
    }

    /**
     * If the attempt is in an error state, report the error appropriately and die.
     *
     * Otherwise returns so processing can continue.
     *
     * @param attempt $attempt the attempt
     * @param \context $context the context we are in.
     */
    public static function report_if_error(attempt $attempt, \context $context): void {
        if ($attempt->is_valid()) {
            return;
        }
        if (has_capability('moodle/question:useall', $context)) {
            self::filter_error($attempt->get_problem_description());
        } else {
            self::filter_error(get_string('invalidtoken', 'filter_embedquestion'));
        }
    }

    /**
     * Display an error inside the filter iframe. Does not return.
     *
     * @param string $message the error message to display.
     */
    public static function filter_error(string $message): void {
        global $PAGE;

        /** @var renderer $renderer */
        $renderer = $PAGE->get_renderer('filter_embedquestion');
        echo $renderer->header();
        echo $renderer->render(new error_message($message));
        echo $renderer->footer();

        // In a unit test, throw an exception instead of terminating.
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            throw new \coding_exception('Filter error');
        } else {
            die;
        }
    }

    /**
     * Given any context, find the associated course from which to embed questions.
     *
     * Anywhere inside a course, that is the id of that course. Outside of
     * a particular course, it is the front page course id.
     *
     * @param \context $context the current context.
     * @return int the course id to use the question bank of.
     */
    public static function get_relevant_courseid(\context $context): int {
        $coursecontext = $context->get_course_context(false);
        if ($coursecontext) {
            return $coursecontext->instanceid;
        } else {
            return SITEID;
        }
    }

    /**
     * Get the URL for showing this question in the iframe.
     *
     * @param embed_id $embedid identifies the question being embedded.
     * @param embed_location $embedlocation identifies where it is being embedded.
     * @param question_options $options the options for how it is displayed.
     * @return \moodle_url the URL to access the question.
     */
    public static function get_show_url(embed_id $embedid, embed_location $embedlocation,
            question_options $options): \moodle_url {
        $url = new \moodle_url('/filter/embedquestion/showquestion.php');
        $embedid->add_params_to_url($url);
        $embedlocation->add_params_to_url($url);
        $options->add_params_to_url($url);
        token::add_iframe_token_to_url($url);
        return $url;
    }

    /**
     * Find a question bank with a given idnumber in a given course.
     *
     * @param int $courseid the id of the course to look in.
     * @param string|null $qbankidnumber the idnumber of the question bank to look for.
     * @param int|null $userid if set, only count question banks created by this user.
     * @return int|null cmid or null if not found.
     *                  If there are multiple question banks in the course, and no idnumber is given, return -1 only if there is no
     *                  question bank with no idnumber created by system.
     */
    public static function get_qbank_by_idnumber(int $courseid, ?string $qbankidnumber = null, ?int $userid = null): ?int {
        $qbanks = self::get_shareable_question_banks($courseid, $userid, $qbankidnumber);
        if (empty($qbanks)) {
            return null;
        } else if (count($qbanks) === 1) {
            $cmid = reset($qbanks)->cmid;
        } else {
            if (!$qbankidnumber || $qbankidnumber === '*') {
                // Multiple qbanks in this course.
                $qbankswithoutidnumber = array_filter($qbanks, function($qbank) {
                    return empty($qbank->qbankidnumber);
                });
                if (count($qbankswithoutidnumber) === 1) {
                    $cmid = reset($qbankswithoutidnumber)->cmid;
                } else {
                    // There are multiple question banks without id number and we can't determine which one to use.
                    return -1;
                }
            } else {
                // We have a qbankidnumber, so we can filter the list.
                $match = array_filter($qbanks, fn($q) => $q->qbankidnumber === $qbankidnumber);
                if (count($match) === 1) {
                    $cmid = reset($match)->cmid;
                } else {
                    // There are multiple question banks with id number and we can't determine which one to use.
                    return -1;
                }
            }
        }

        return $cmid;
    }

    /**
     * Find a category with a given idnumber in a given context.
     *
     * @param \context $context a context.
     * @param string $idnumber the idnumber to look for.
     * @return \stdClass|null row from the question_categories table, or false if none.
     */
    public static function get_category_by_idnumber(\context $context, string $idnumber): ?\stdClass {
        global $DB;

        $category = $DB->get_record_select('question_categories',
                'contextid = ? AND idnumber = ?',
                [$context->id, $idnumber]) ?? null;
        if (!$category) {
            return null;
        }
        return $category;
    }

    /**
     * Get a list of the question banks that have sharable questions in the specific course.
     *
     * The list is returned in a form suitable for using in a select menu.
     *
     * @param int $courseid the id of the course to look in.
     * @param int|null $userid if set, only count question created by this user.
     * @param string|null $qbankidnumber if set, only count question banks with this idnumber.
     * @return array course module id => object with fields cmid, qbankidnumber, courseid, qbankid.
     */
    public static function get_shareable_question_banks(int $courseid,
            ?int $userid = null, ?string $qbankidnumber = null): array {
        global $DB;
        $params = [
            'modulename' => 'qbank',
            'courseid' => $courseid,
            'contextlevel' => CONTEXT_MODULE,
            'ready' => question_version_status::QUESTION_STATUS_READY,
        ];

        $creatortest = '';
        if ($userid) {
            $creatortest = 'AND qbe.ownerid = :userid';
            $params['userid'] = $userid;
        }

        $idnumber = '';
        if ($qbankidnumber && $qbankidnumber !== '*') {
            $idnumber = 'AND cm.idnumber = :qbankidnumber';
            $params['qbankidnumber'] = $qbankidnumber;
        }

        $sql = "SELECT cm.id AS cmid,
                       cm.idnumber AS qbankidnumber,
                       qbank.id AS qbankid,
                       qbank.name,
                       qbank.type
                  FROM {course} c
                  JOIN {course_modules} cm ON cm.course = c.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {qbank} qbank ON qbank.id = cm.instance
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                  JOIN {question_categories} qc ON qc.contextid = ctx.id
                  JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = qc.id
                       AND qbe.idnumber IS NOT NULL
                       $creatortest
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                                    AND qv.version = (SELECT MAX(qv2.version)
                                                           FROM {question_versions} qv2
                                                          WHERE qv2.questionbankentryid = qbe.id
                                                                AND qv2.status = :ready
                                                     )
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE c.id = :courseid
                       AND qc.idnumber IS NOT NULL
                       AND qc.idnumber <> ''
                       $idnumber
              GROUP BY cm.id, cm.idnumber, qbank.id, qbank.name
                       HAVING COUNT(q.id) > 0
              ORDER BY cm.id";
        $qbanks = $DB->get_records_sql($sql, $params);
        return $qbanks;
    }

    /**
     * Create a list of question banks in a form suitable for using in a select menu.
     *
     * @param array $qbanks the question banks, as returned by {@see get_shareable_question_banks()}.
     * @return array course module id => question bank name (and idnumber if set).
     */
    public static function create_select_qbank_choices(array $qbanks): array {
        $choices = ['' => get_string('choosedots')];
        foreach ($qbanks as $cmid => $qbank) {
            if ($qbank->qbankidnumber) {
                $choices[$cmid] = get_string('nameandidnumber', 'filter_embedquestion',
                        ['name' => format_string($qbank->name), 'idnumber' => s($qbank->qbankidnumber)]);
            } else {
                $choices[$cmid] = format_string($qbank->name);
            }
        }
        return $choices;
    }

    /**
     * Find a question with a given idnumber in a given context.
     *
     * @param int $categoryid id of the question category to look in.
     * @param string $idnumber the idnumber to look for.
     * @return \stdClass|null row from the question table, or false if none.
     */
    public static function get_question_by_idnumber(int $categoryid, string $idnumber): ?\stdClass {
        global $DB;

        $question = $DB->get_record_sql('
            SELECT q.*, qbe.idnumber, qbe.questioncategoryid AS category,
                   qv.id AS versionid, qv.version, qv.questionbankentryid
              FROM {question_bank_entries} qbe
              JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id AND qv.version = (
                              SELECT MAX(version)
                                FROM {question_versions}
                               WHERE questionbankentryid = qbe.id AND status = :ready
                          )
              JOIN {question} q ON q.id = qv.questionid
             WHERE qbe.questioncategoryid = :category AND qbe.idnumber = :idnumber',
            [
                'ready' => question_version_status::QUESTION_STATUS_READY,
                'category' => $categoryid, 'idnumber' => $idnumber,
            ],
        );
        if (!$question) {
            return null;
        }
        return $question;
    }

    /**
     * Is a particular question the latest version of that question bank entry.
     *
     * This method can only be called if you have already verified that
     * {@see has_question_versionning()} returns true.
     *
     * @param \question_definition $question the question.
     * @return bool is this the latest ready version of this question?
     */
    public static function is_latest_version(\question_definition $question): bool {
        global $DB;

        $latestversion = $DB->get_field(
            'question_versions',
            'MAX(version)',
            [
                'questionbankentryid' => $question->questionbankentryid,
                'status' => question_version_status::QUESTION_STATUS_READY,
            ]
        );

        return $question->version == $latestversion;
    }

    /**
     * Get a list of the question categories in a particular context that
     * contain sharable questions (and which have an idnumber set).
     *
     * The list is returned in a form suitable for using in a select menu.
     *
     * If a userid is given, then only questions created by that user
     * are considered.
     *
     * @param \context $context a context.
     * @param int|null $userid (optional) if set, only count questions created by this user.
     * @return array category idnumber => Category name (question count).
     */
    public static function get_categories_with_sharable_question_choices(\context $context,
            int|null $userid = null): array {
        global $DB;
        $params = [];
        $creatortest = '';
        if ($userid) {
            $creatortest = 'AND qbe.ownerid = :userid';
            $params['userid'] = $userid;
        }
        $params['status'] = question_version_status::QUESTION_STATUS_READY;
        $params['modulename'] = 'qbank';
        $params['contextid'] = $context->id;

        $categories = $DB->get_records_sql("
                SELECT qc.id, qc.name, qc.idnumber, COUNT(q.id) AS count

                  FROM {question_categories} qc
                  JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = qc.id
                       AND qbe.idnumber IS NOT NULL $creatortest
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id AND qv.version = (
                                SELECT MAX(version)
                                  FROM {question_versions}
                                 WHERE questionbankentryid = qbe.id AND status = :status
                                )
                  JOIN {question} q ON q.id = qv.questionid

                 WHERE qc.contextid = :contextid
                       AND qc.idnumber IS NOT NULL
                       AND qc.idnumber <> ''
              GROUP BY qc.id, qc.name, qc.idnumber
                       HAVING COUNT(q.id) > 0
              ORDER BY qc.name
                ", $params);

        $choices = ['' => get_string('choosedots')];
        foreach ($categories as $category) {
            $choices[$category->idnumber] = get_string('nameidnumberandcount', 'filter_embedquestion',
                    ['name' => format_string($category->name), 'idnumber' => s($category->idnumber), 'count' => $category->count]);
        }
        return $choices;
    }

    /**
     * Get the ids of shareable questions from a category (those which have an idnumber set).
     *
     * If a userid is given, then only questions created by that user
     * are considered.
     *
     * @param int $categoryid id of a question category.
     * @param int|null $userid (optional) if set, only count questions created by this user.
     * @return \stdClass[] question id => object with fields question id, name and idnumber.
     */
    public static function get_sharable_question_ids(int $categoryid, int|null $userid = null): array {
        global $DB;

        $params = [];
        $params[] = question_version_status::QUESTION_STATUS_READY;
        $params[] = $categoryid;
        $creatortest = '';
        if ($userid) {
            $creatortest = 'AND qbe.ownerid = ?';
            $params[] = $userid;
        }

        return $DB->get_records_sql("
                SELECT q.id, q.name, qbe.idnumber

                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id AND qv.version = (
                                SELECT MAX(version)
                                  FROM {question_versions}
                                 WHERE questionbankentryid = qbe.id AND status = ?
                                )
                  JOIN {question} q ON q.id = qv.questionid

                 WHERE qbe.questioncategoryid = ?
                   AND qbe.idnumber IS NOT NULL
                   $creatortest

              ORDER BY q.name
                ", $params);
    }

    /**
     * Get shareable questions from a category (those which have an idnumber set).
     *
     * The list is returned in a form suitable for using in a select menu.
     *
     * If a userid is given, then only questions created by that user
     * are considered.
     *
     * @param int $categoryid id of a question category.
     * @param int|null $userid (optional) if set, only count questions created by this user.
     * @return array question idnumber => question name.
     */
    public static function get_sharable_question_choices(int $categoryid, int|null $userid = null): array {
        $questions = self::get_sharable_question_ids($categoryid, $userid);

        $choices = ['' => get_string('choosedots')];
        foreach ($questions as $question) {
            $choices[$question->idnumber] = get_string('nameandidnumber', 'filter_embedquestion',
                    ['name' => format_string($question->name), 'idnumber' => s($question->idnumber)]);
        }

        // When we are not restricting by user, and there are at least 2 questions in the category,
        // allow random choice. > 2 because of the 'Choose ...' option.
        if (!$userid && count($choices) > 2) {
            $choices['*'] = get_string('chooserandomly', 'filter_embedquestion');
        }
        return $choices;
    }

    /**
     * Get the behaviours that can be used with this filter.
     *
     * @return array behaviour name => lang string for this behaviour name.
     */
    public static function behaviour_choices(): array {
        $behaviours = [];
        foreach (\question_engine::get_archetypal_behaviours() as $behaviour => $name) {
            $unusedoptions = \question_engine::get_behaviour_unused_display_options($behaviour);
            // Apologies for the double-negative here.
            // A behaviour is suitable if specific feedback is relevant during the attempt.
            if (!in_array('specificfeedback', $unusedoptions)) {
                $behaviours[$behaviour] = $name;
            }
        }
        return $behaviours;
    }

    /**
     * Get the list of languages installed on this system, if there is more than one.
     *
     * @return array|null null if only one language is installed, else array lang code => name,
     *     including a 'Do not force' option.
     */
    public static function get_installed_language_choices(): ?array {
        $languages = get_string_manager()->get_list_of_translations();
        if (count($languages) == 1) {
            return null;
        }

        return ['' => get_string('forceno')] + $languages;
    }

    /** @var int Use to create unique iframe names. */
    protected static $untitilediframecounter = 0;

    /**
     * Make a unique name, for anonymous iframes.
     *
     * @return string Iframe description.
     */
    public static function make_unique_iframe_description(): string {
        self::$untitilediframecounter += 1;
        return get_string('iframetitleauto', 'filter_embedquestion', self::$untitilediframecounter);
    }

    /**
     * Get the URL of this question in the question bank.
     *
     * @param \question_definition $question
     * @return \moodle_url
     */
    public static function get_question_bank_url(\question_definition $question): \moodle_url {
        global $CFG, $DB;
        // To get MAXIMUM_QUESTIONS_PER_PAGE.
        require_once($CFG->dirroot . '/question/editlib.php');

        $context = \context::instance_by_id($question->contextid);

        $latestquestionid = $DB->get_field_sql("
                SELECT qv.questionid
                 FROM {question_versions} qv
                WHERE qv.questionbankentryid = ?
                  AND qv.version = (
                        SELECT MAX(v.version)
                          FROM {question_versions} v
                         WHERE v.questionbankentryid = ?
                  )
                ", [$question->questionbankentryid, $question->questionbankentryid]);

        return new \moodle_url('/question/edit.php', [
                'cmid' => $context->instanceid,
                'cat' => $question->category . ',' . $question->contextid,
                'qperpage' => MAXIMUM_QUESTIONS_PER_PAGE,
                'lastchanged' => $latestquestionid,
            ]);
    }

    /**
     * For unit tests only, reset the counter between tests.
     */
    public static function unit_test_reset() {
        self::$untitilediframecounter = 0;
    }

    /** @var string - Less than operator */
    const OP_LT = "<";
    /** @var string - equal operator */
    const OP_E = "=";
    /** @var string - greater than operator */
    const OP_GT = ">";

    /**
     * Conveniently compare the current moodle version to a provided version in branch format. This function will
     * inflate version numbers to a three digit number before comparing them. This way moodle minor versions greater
     * than 9 can be correctly and easily compared.
     *
     * Examples:
     *   utils::moodle_version_is("<", "39");
     *   utils::moodle_version_is("<=", "310");
     *   utils::moodle_version_is(">", "39");
     *   utils::moodle_version_is(">=", "38");
     *   utils::moodle_version_is("=", "41");
     *
     * CFG reference:
     * $CFG->branch = "311", "310", "39", "38", ...
     * $CFG->release = "3.11+ (Build: 20210604)", ...
     * $CFG->version = "2021051700.04", ...
     *
     * @param string $operator for the comparison
     * @param string $version to compare to
     * @return boolean
     */
    public static function moodle_version_is(string $operator, string $version): bool {
        global $CFG;

        if (strlen($version) == 2) {
            $version = $version[0]."0".$version[1];
        }

        $current = $CFG->branch;
        if (strlen($current) == 2) {
            $current = $current[0]."0".$current[1];
        }

        $from = intval($current);
        $to = intval($version);
        $ops = str_split($operator);

        foreach ($ops as $op) {
            switch ($op) {
                case self::OP_LT:
                    if ($from < $to) {
                        return true;
                    }
                    break;
                case self::OP_E:
                    if ($from == $to) {
                        return true;
                    }
                    break;
                case self::OP_GT:
                    if ($from > $to) {
                        return true;
                    }
                    break;
                default:
                    throw new \coding_exception('invalid operator '.$op);
            }
        }

        return false;
    }

    /**
     * Get course id by course shortname.
     *
     * @param string $courseshortname
     * @return int
     */
    public static function get_courseid_by_course_shortname(string $courseshortname): int {
        global $DB;
        return $DB->get_field('course', 'id', ['shortname' => $courseshortname]);
    }

    /**
     * Check if the current user has permission to embed questions in this context.
     *
     * @param \context $context the context to check.
     * @return bool true if the user has permission, false if not.
     */
    public static function has_permission(\context $context): bool {
        if (has_capability('moodle/question:useall', $context)) {
            return true;
        } else if (has_capability('moodle/question:usemine', $context)) {
            return true;
        } else {
            return false;
        }
    }
}
