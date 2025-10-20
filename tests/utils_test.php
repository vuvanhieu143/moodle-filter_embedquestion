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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/editlib.php');

use core_question\local\bank\question_version_status;

/**
 * Unit tests for the util methods.
 *
 * @package   filter_embedquestion
 * @copyright 2018 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \filter_embedquestion\utils
 */
final class utils_test extends \advanced_testcase {

    public function test_get_category_by_idnumber(): void {
        $this->resetAfterTest();

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $catwithidnumber = $questiongenerator->create_question_category(
                ['name' => 'Category with idnumber', 'idnumber' => 'abc123']);
        $questiongenerator->create_question_category();
        $context = \context::instance_by_id($catwithidnumber->contextid);

        $this->assertEquals($catwithidnumber->id,
                utils::get_category_by_idnumber(
                    $context, 'abc123')->id);
    }

    public function test_get_category_by_idnumber_not_existing(): void {

        $this->assertSame(null,
                utils::get_category_by_idnumber(
                        \context_system::instance(), 'abc123'));
    }

    public function test_get_question_by_idnumber(): void {
        $this->resetAfterTest();

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $catwithidnumber = $questiongenerator->create_question_category(
                ['name' => 'Category with idnumber', 'idnumber' => 'abc123']);
        $q = $questiongenerator->create_question('shortanswer', null,
                ['category' => $catwithidnumber->id, 'name' => 'Question', 'idnumber' => 'frog']);
        $questiongenerator->create_question('shortanswer', null,
                ['category' => $catwithidnumber->id]);

        $this->assertEquals($q->id,
                utils::get_question_by_idnumber(
                        $catwithidnumber->id, 'frog')->id);
    }

    public function test_get_question_by_idnumber_not_existing(): void {
        $this->resetAfterTest();

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $catwithidnumber = $questiongenerator->create_question_category(
                ['name' => 'Category with idnumber', 'idnumber' => 'abc123']);

        $this->assertSame(null,
                utils::get_question_by_idnumber(
                        $catwithidnumber->id, 'frog'));
    }

    public function test_get_categories_with_sharable_question_choices(): void {
        $this->resetAfterTest();

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $questiongenerator->create_question_category(
                ['name' => 'Category with idnumber', 'idnumber' => 'abc123']);
        $catwithid2 = $questiongenerator->create_question_category(
                ['name' => 'Second category', 'idnumber' => 'pqr789']);
        $questiongenerator->create_question_category();

        $questiongenerator->create_question('shortanswer', null,
                ['category' => $catwithid2->id, 'name' => 'Question', 'idnumber' => 'frog']);
        $context = \context::instance_by_id($catwithid2->contextid);

        $this->assertEquals(
                ['' => 'Choose...', 'pqr789' => 'Second category [pqr789] (1)'],
                utils::get_categories_with_sharable_question_choices($context));
    }

    public function test_get_categories_with_sharable_question_choices_only_user(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $catwithid1 = $questiongenerator->create_question_category(
                ['name' => 'Category with idnumber', 'idnumber' => 'abc123']);
        $catwithid2 = $questiongenerator->create_question_category(
                ['name' => 'Second category with', 'idnumber' => 'pqr789']);

        $questiongenerator->create_question('shortanswer', null,
                ['category' => $catwithid1->id, 'name' => 'Question', 'idnumber' => 'toad']);
        $this->setGuestUser();
        $questiongenerator->create_question('shortanswer', null,
                ['category' => $catwithid2->id, 'name' => 'Question', 'idnumber' => 'frog']);
        $this->setAdminUser();
        $context = \context::instance_by_id($catwithid1->contextid);
        $this->assertEquals([
                '' => 'Choose...',
                'abc123' => 'Category with idnumber [abc123] (1)',
            ], utils::get_categories_with_sharable_question_choices($context, $USER->id));
    }

    public function test_get_sharable_question_choices(): void {
        $this->resetAfterTest();

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(
                ['name' => 'Category with idnumber', 'idnumber' => 'abc123']);

        $questiongenerator->create_question('shortanswer', null,
                ['category' => $category->id, 'name' => 'Question 2', 'idnumber' => 'toad']);
        $questiongenerator->create_question('shortanswer', null,
                ['category' => $category->id, 'name' => 'Question 1', 'idnumber' => 'frog']);
        $questiongenerator->create_question('shortanswer', null,
                ['category' => $category->id]);

        $this->assertEquals([
                '' => 'Choose...',
                'frog' => 'Question 1 [frog]',
                'toad' => 'Question 2 [toad]',
                '*' => get_string('chooserandomly', 'filter_embedquestion'),
            ], utils::get_sharable_question_choices($category->id));
    }

    public function test_get_sharable_question_choices_only_user(): void {
        global $USER;

        $this->resetAfterTest();

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(
                ['name' => 'Category with idnumber', 'idnumber' => 'abc123']);

        $this->setGuestUser();
        $questiongenerator->create_question('shortanswer', null,
                ['category' => $category->id, 'name' => 'Question 2', 'idnumber' => 'toad']);
        $this->setAdminUser();
        $questiongenerator->create_question('shortanswer', null,
                ['category' => $category->id, 'name' => 'Question 1', 'idnumber' => 'frog']);
        $questiongenerator->create_question('shortanswer', null,
                ['category' => $category->id]);

        $this->assertEquals([
                '' => 'Choose...',
                'frog' => 'Question 1 [frog]',
            ], utils::get_sharable_question_choices($category->id, $USER->id));
    }

    public function test_get_sharable_question_choices_should_not_include_random(): void {
        $this->resetAfterTest();

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(
                ['name' => 'Category with idnumber', 'idnumber' => 'abc123']);

        $questiongenerator->create_question('shortanswer', null,
                ['category' => $category->id, 'name' => 'Question 1', 'idnumber' => 'frog']);
        $questiongenerator->create_question('shortanswer', null,
                ['category' => $category->id]);

        // Now create a random question in that category.
        $form = new \stdClass();
        $form->category = $category->id . ',' . $category->contextid;
        $form->includesubcategories = false;
        $form->questiontext = ['text' => '0', 'format' => 0]; // Needed for the test to pass in Moodle 3.4.
        $form->defaultmark = 1;
        $form->hidden = 1;
        $form->stamp = make_unique_id_code(); // Set the unique code (not to be changed).
        $question = new \stdClass();
        $question->qtype = 'random';
        \question_bank::get_qtype('random')->save_question($question, $form);

        // The random question should not appear in the list.
        $this->assertEquals([
                '' => 'Choose...',
                'frog' => 'Question 1 [frog]',
            ], utils::get_sharable_question_choices($category->id));
    }

    public function test_get_categories_with_sharable_question_choices_should_not_include_random(): void {
        $this->resetAfterTest();

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $questiongenerator->create_question_category(
                ['name' => 'Category with idnumber', 'idnumber' => 'abc123']);
        $catwithid2 = $questiongenerator->create_question_category(
                ['name' => 'Second category with', 'idnumber' => 'pqr789']);
        $questiongenerator->create_question_category();

        // Now create a random question in that category.
        $form = new \stdClass();
        $form->category = $catwithid2->id . ',' . $catwithid2->contextid;
        $form->includesubcategories = false;
        $form->questiontext = ['text' => '0', 'format' => 0]; // Needed for the test to pass in Moodle 3.4.
        $form->defaultmark = 1;
        $form->hidden = 1;
        $form->stamp = make_unique_id_code(); // Set the unique code (not to be changed).
        $question = new \stdClass();
        $question->qtype = 'random';
        \question_bank::get_qtype('random')->save_question($question, $form);

        $questiongenerator->create_question('shortanswer', null,
                ['category' => $catwithid2->id, 'name' => 'Question', 'idnumber' => 'frog']);
        $context = \context::instance_by_id($catwithid2->contextid);

        // The random question should not appear in the counts.
        $this->assertEquals([
                '' => 'Choose...',
                'pqr789' => 'Second category with [pqr789] (1)',
            ], utils::get_categories_with_sharable_question_choices($context));
    }

    /**
     * Crete a question, like $questiongenerator->create_question does, but make it hidden/draft.
     *
     * @param string $qtype the question type to create an example of.
     * @param string|null $which as for the corresponding argument of
     *      {@see question_test_helper::get_question_form_data}. null for the default one.
     * @param array|null $overrides any fields that should be different from the base example.
     * @return \stdClass the question data.
     */
    protected function create_hidden_question(string $qtype, string|null $which = null,
            array|null $overrides = null): \stdClass {
        global $DB;

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $hiddenq = $questiongenerator->create_question($qtype, $which, $overrides);

        if (utils::has_question_versionning()) {
            $DB->set_field('question_versions', 'status', question_version_status::QUESTION_STATUS_DRAFT,
                    ['questionid' => $hiddenq->id]);
        } else {
            $DB->set_field('question', 'hidden', 1, ['id' => $hiddenq->id]);
        }

        return $hiddenq;
    }

    public function test_get_sharable_question_choices_should_not_include_hidden(): void {
        $this->resetAfterTest();

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(
                ['name' => 'Category with idnumber', 'idnumber' => 'abc123']);

        $questiongenerator->create_question('shortanswer', null,
                ['category' => $category->id, 'name' => 'Question', 'idnumber' => 'frog']);
        $this->create_hidden_question('shortanswer', null,
                ['category' => $category->id, 'name' => 'Question (hidden)', 'idnumber' => 'toad']);

        // The hidden question should not appear in the list.
        $this->assertEquals([
                '' => 'Choose...',
                'frog' => 'Question [frog]',
            ], utils::get_sharable_question_choices($category->id));
    }

    public function test_get_categories_with_sharable_question_choices_should_not_include_hidden(): void {
        $this->resetAfterTest();

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $questiongenerator->create_question_category(
                ['name' => 'Category with idnumber', 'idnumber' => 'abc123']);
        $catwithid2 = $questiongenerator->create_question_category(
                ['name' => 'Second category with', 'idnumber' => 'pqr789']);
        $questiongenerator->create_question_category();
        $questiongenerator->create_question('shortanswer', null,
                ['category' => $catwithid2->id, 'name' => 'Question', 'idnumber' => 'frog']);
        $this->create_hidden_question('shortanswer', null,
                ['category' => $catwithid2->id, 'name' => 'Question (hidden)', 'idnumber' => 'toad']);
        $context = \context::instance_by_id($catwithid2->contextid);

        // The hidden question should not appear in the counts.
        $this->assertEquals([
                '' => 'Choose...',
                'pqr789' => 'Second category with [pqr789] (1)',
            ], utils::get_categories_with_sharable_question_choices($context));
    }

    public function test_behaviour_choices(): void {
        // This test is written in a way that will work even if extra behaviours are installed.
        $choices = utils::behaviour_choices();
        $this->assertArrayHasKey('interactive', $choices);
        $this->assertArrayHasKey('adaptive', $choices);
        $this->assertArrayHasKey('immediatefeedback', $choices);
        $this->assertArrayHasKey('immediatecbm', $choices);
        $this->assertArrayNotHasKey('deferredfeedback', $choices);
    }

    /**
     * Test create_attempt_at_embedded_question
     *
     */
    public function test_create_attempt_at_embedded_question(): void {
        global $COURSE;
        $this->setAdminUser();
        $this->resetAfterTest();

        // Get the generators.
        $generator = $this->getDataGenerator();
        /** @var \filter_embedquestion_generator $attemptgenerator */
        $attemptgenerator = $generator->get_plugin_generator('filter_embedquestion');

        // Create course.
        $course = $generator->create_course(['fullname' => 'Course 1', 'shortname' => 'C1']);
        // In unit test, the global $COURSE is always set to SITE, so we need to set it to the course we created.
        $COURSE = $course;
        $qbank = $generator->create_module('qbank', ['course' => $course->id], ['idnumber' => 'qbank1']);
        $context = \context_module::instance($qbank->cmid);
        // Create embed question.
        $question = $attemptgenerator->create_embeddable_question('truefalse', null, [], ['contextid' => $context->id]);
        // Create page page that embeds a question.
        $page = $generator->create_module('page', [
            'course' => $course->id,
            'content' => '<p>Try this question: ' . $attemptgenerator->get_embed_code($question) . '</p>',
        ]);
        $pagecontext = \context_module::instance($page->cmid);

        // Create a student and enroll to the course.
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');
        // Create attempt at that question for created student.
        $attempt = $attemptgenerator->create_attempt_at_embedded_question($question, $user, 'True', $pagecontext);
        // Verify that the question attempt step information is correct.
        $this->assertEquals($user->id,
                $attempt->get_question_usage()->get_question_attempt($attempt->get_slot())->get_last_step()->get_user_id());
    }

    public function test_get_question_bank_url(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $context = \context_module::instance($qbank->cmid);

        // Create a question with two versions.
        $cat = $questiongenerator->create_question_category(['contextid' => $context->id]);

        $saq = $questiongenerator->create_question('shortanswer', null, ['category' => $cat->id]);
        $firstversion = \question_bank::load_question($saq->id);

        $saqv2 = $questiongenerator->update_question($saq);
        $secondversion = \question_bank::load_question($saqv2->id);

        // Prepare the expected result.
        $expectedurl = new \moodle_url('/question/edit.php', [
                'cmid' => $context->instanceid,
                'cat' => $secondversion->category . ',' . $secondversion->contextid,
                'qperpage' => MAXIMUM_QUESTIONS_PER_PAGE,
                'lastchanged' => $secondversion->id,
            ]);

        // Check the URL using the first question id.
        $this->assertEquals($expectedurl, utils::get_question_bank_url($firstversion));

        // Check the URL using the second question id.
        $this->assertEquals($expectedurl, utils::get_question_bank_url($secondversion));
    }

    /**
     * Test getting shareable question banks.
     */
    public function test_get_shareable_question_banks(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Course 1', 'shortname' => 'C1']);
        // Create a question bank.
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id], ['idnumber' => '']);
        $qbank2 = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id], ['idnumber' => 'qbank2']);
        $generator = $this->getDataGenerator();
        $attemptgenerator = $generator->get_plugin_generator('filter_embedquestion');
        $attemptgenerator->create_embeddable_question('truefalse', null, [],
            ['contextid' => \context_module::instance($qbank->cmid)->id]);
        $attemptgenerator->create_embeddable_question('truefalse', null, [],
            ['contextid' => \context_module::instance($qbank2->cmid)->id]);

        $banks = utils::get_shareable_question_banks($course->id);
        $this->assertArrayHasKey($qbank->cmid, $banks);
        $this->assertArrayHasKey($qbank2->cmid, $banks);

        $banks = utils::get_shareable_question_banks($course->id, $course->shortname, null, 'qbank2');
        $this->assertArrayHasKey($qbank2->cmid, $banks);
        $this->assertArrayNotHasKey($qbank->cmid, $banks);
    }
    /**
     * Test getting a question bank by idnumber.
     */
    public function test_get_qbank_by_idnumber(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Course 1', 'shortname' => 'C1']);
        // Create a question bank.
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id], ['idnumber' => '']);
        $qbank2 = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id], ['idnumber' => 'abcd1234']);

        $generator = $this->getDataGenerator();
        $attemptgenerator = $generator->get_plugin_generator('filter_embedquestion');
        $attemptgenerator->create_embeddable_question('truefalse', null, [],
            ['contextid' => \context_module::instance($qbank->cmid)->id]);
        $attemptgenerator->create_embeddable_question('truefalse', null, [],
            ['contextid' => \context_module::instance($qbank2->cmid)->id]);

        // Check that we can get the question bank.
        $this->assertEquals($qbank->cmid, utils::get_qbank_by_idnumber($course->id));
        $this->assertEquals($qbank2->cmid, utils::get_qbank_by_idnumber($course->id, '', 'abcd1234'));
        // We can get the question bank by with idnumber using course short name.
        $this->assertEquals($qbank2->cmid, utils::get_qbank_by_idnumber(SITEID, 'C1', 'abcd1234'));

        $qbank3 = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id], ['idnumber' => '']);
        $attemptgenerator->create_embeddable_question('truefalse', null, [],
            ['contextid' => \context_module::instance($qbank3->cmid)->id]);
        // Can't get the correct question bank if there are multiple banks without idnumber.
        $this->assertEquals(-1, utils::get_qbank_by_idnumber($course->id));
        // Can't get a question bank doesn't exist in the course.
        $this->assertEquals(null, utils::get_qbank_by_idnumber($course->id, 'C2'));
        // Can't get a question bank with an idnumber that does not exist.
        $this->assertEquals(null, utils::get_qbank_by_idnumber($course->id, '', 'randomidnumber'));
    }
}
