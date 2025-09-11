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

use report_embedquestion;

/**
 * Unit tests for the code for attempting questions.
 *
 * @package   filter_embedquestion
 * @copyright 2019 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \filter_embedquestion\attempt
 * @covers    \filter_embedquestion\attempt_storage
 */
final class attempt_test extends \advanced_testcase {

    public function test_start_new_attempt_at_question_will_select_an_unused_question(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create two sharable questions in the same category.
        /** @var \filter_embedquestion_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('filter_embedquestion');
        $q1 = $generator->create_embeddable_question('shortanswer');
        $q2 = $generator->create_embeddable_question('shortanswer', null, ['category' => $q1->category]);
        $category = $DB->get_record('question_categories', ['id' => $q1->category], '*', MUST_EXIST);

        // Start an attempt in the way that showattempt.php would.
        list(, $context) = $generator->get_embed_id_and_context($q1);
        $embedid = new embed_id($category->idnumber, '*'); // We actually want to embed a random selection.
        $embedlocation = embed_location::make_for_test($context, $context->get_url(), 'Test embed location');
        $options = new question_options();
        $options->behaviour = 'immediatefeedback';
        $attempt = new attempt($embedid, $embedlocation, $USER, $options);
        $this->verify_attempt_valid($attempt);
        $attempt->find_or_create_attempt();
        $this->verify_attempt_valid($attempt);

        // Verify that we started an attempt at one of our questions.
        $firstusedquestionid = $attempt->get_question_usage()->get_question($attempt->get_slot())->id;
        if (method_exists($this, 'assertContainsEquals')) {
            $this->assertContainsEquals($firstusedquestionid, [$q1->id, $q2->id]);
        } else {
            $this->assertContains($firstusedquestionid, [$q1->id, $q2->id]);
        }

        // Now start a second question attempt.
        $attempt->start_new_attempt_at_question();

        // Verify that it uses the other question.
        $secondusedquestionid = $attempt->get_question_usage()->get_question($attempt->get_slot())->id;
        if (method_exists($this, 'assertContainsEquals')) {
            $this->assertContainsEquals($secondusedquestionid, [$q1->id, $q2->id]);
        } else {
            $this->assertContains($secondusedquestionid, [$q1->id, $q2->id]);
        }
        $this->assertNotEquals($firstusedquestionid, $secondusedquestionid);
    }

    public function test_start_new_attempt_at_question_will_select_an_unused_variant(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create two sharable questions in the same category.
        /** @var \filter_embedquestion_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('filter_embedquestion');
        $question = $generator->create_embeddable_question('calculatedsimple', 'sumwithvariants');

        // Unfortunately, the standard generated question comes with lots of variants, but we only
        // want 2. Therefore, delete the extras.
        $DB->delete_records_select('question_dataset_items', 'itemnumber > 2');
        $DB->set_field('question_dataset_definitions', 'itemcount', 2);
        \question_bank::notify_question_edited($question->id);

        // Start an attempt in the way that showattempt.php would.
        [$embedid, $context] = $generator->get_embed_id_and_context($question);
        $embedlocation = embed_location::make_for_test($context, $context->get_url(), 'Test embed location');
        $options = new question_options();
        $options->behaviour = 'immediatefeedback';
        $attempt = new attempt($embedid, $embedlocation, $USER, $options);
        $this->verify_attempt_valid($attempt);
        $attempt->find_or_create_attempt();
        $this->verify_attempt_valid($attempt);

        // Verify that we started an attempt at one of our questions.
        $firstusedvariant = $attempt->get_question_usage()->get_variant($attempt->get_slot());
        $this->assertContains($firstusedvariant, [1, 2]);

        // Now start a second question attempt.
        $attempt->start_new_attempt_at_question();

        // Verify that it uses the other variant.
        $secondusedvariant = $attempt->get_question_usage()->get_variant($attempt->get_slot());
        $this->assertContains($secondusedvariant, [1, 2]);
        $this->assertNotEquals($firstusedvariant, $secondusedvariant);
    }

    public function test_start_new_attempt_at_question_reports_errors(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a sharable questions in a same category.
        /** @var \filter_embedquestion_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('filter_embedquestion');
        $q = $generator->create_embeddable_question('shortanswer');
        $sharedcategory = $DB->get_record('question_categories', ['id' => $q->category], '*', MUST_EXIST);

        // Make another category.
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $unsharedcategory = $questiongenerator->create_question_category(
                ['name' => 'Not shared category', 'contextid' => $sharedcategory->contextid]);

        // Start an attempt in the way that showattempt.php would.
        list($embedid, $context) = $generator->get_embed_id_and_context($q);
        $embedlocation = embed_location::make_for_test($context, $context->get_url(), 'Test embed location');
        $options = new question_options();
        $options->behaviour = 'immediatefeedback';
        $attempt = new attempt($embedid, $embedlocation, $USER, $options);
        $this->verify_attempt_valid($attempt);
        $attempt->find_or_create_attempt();
        $this->verify_attempt_valid($attempt);

        // Verify that we started an attempt at one of our questions.
        $question = $attempt->get_question_usage()->get_question($attempt->get_slot());
        $this->assertEquals($q->id, $question->id);

        // Now move the question to the other category.
        if (utils::has_question_versionning()) {
            $DB->set_field('question_bank_entries', 'questioncategoryid', $unsharedcategory->id,
                    ['id' => $question->questionbankentryid]);
        } else {
            $DB->set_field('question', 'category', $unsharedcategory->id, ['id' => $question->id]);
        }
        \question_bank::notify_question_edited($q->id);

        // And try to restart. Should give an error.
        $this->expectOutputRegex('~The question with idnumber "embeddableq\d+" ' .
                'does not exist in category "Test question category \d+ \[embeddablecat\d+\]"\.~');
        $this->expectException(\coding_exception::class);
        $attempt->start_new_attempt_at_question();
    }

    public function test_discard_broken_attempt_no_usage(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a sharable questions in a same category.
        /** @var \filter_embedquestion_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('filter_embedquestion');
        $q = $generator->create_embeddable_question('shortanswer');

        // Create the attempt.
        list($embedid, $context) = $generator->get_embed_id_and_context($q);
        $embedlocation = embed_location::make_for_test($context, $context->get_url(), 'Test embed location');
        $options = new question_options();
        $options->behaviour = 'immediatefeedback';
        $attempt = new attempt($embedid, $embedlocation, $USER, $options);

        // Calling discard_broken_attempt now should throw an exception.
        $this->expectException('coding_exception');
        $attempt->discard_broken_attempt();
    }

    public function test_discard_broken_attempt_one_qa(): void {
        global $CFG, $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a sharable questions in a same category.
        /** @var \filter_embedquestion_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('filter_embedquestion');
        $q = $generator->create_embeddable_question('shortanswer');

        // Create the attempt.
        list($embedid, $context) = $generator->get_embed_id_and_context($q);
        $embedlocation = embed_location::make_for_test($context, $context->get_url(), 'Test embed location');
        $options = new question_options();
        $options->behaviour = 'immediatefeedback';
        $attempt = new attempt($embedid, $embedlocation, $USER, $options);
        $this->verify_attempt_valid($attempt);
        $attempt->find_or_create_attempt();
        $this->verify_attempt_valid($attempt);

        // Calling discard_broken_attempt should delete the attempt and $quba.
        $qubaid = $attempt->get_question_usage()->get_id();
        $attempt->discard_broken_attempt();

        if (is_dir($CFG->dirroot . '/report/embedquestion/db')) {
            $this->assertFalse($DB->record_exists('question_usages', ['id' => $qubaid]));
            $this->assertFalse($DB->record_exists('report_embedquestion_attempt', ['questionusageid' => $qubaid]));
        } else {
            // Without the report installed delete not expected. A new attempt starts anyway.
            $this->assertTrue($DB->record_exists('question_usages', ['id' => $qubaid]));
        }
    }

    public function test_discard_broken_attempt_two_qas(): void {
        global $CFG, $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a sharable questions in a same category.
        /** @var \filter_embedquestion_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('filter_embedquestion');
        $q = $generator->create_embeddable_question('shortanswer');

        // Create the attempt with two tries at the question.
        list($embedid, $context) = $generator->get_embed_id_and_context($q);
        $embedlocation = embed_location::make_for_test($context, $context->get_url(), 'Test embed location');
        $options = new question_options();
        $options->behaviour = 'immediatefeedback';
        $attempt = new attempt($embedid, $embedlocation, $USER, $options);
        $this->verify_attempt_valid($attempt);
        $attempt->find_or_create_attempt();
        $this->verify_attempt_valid($attempt);
        $attempt->start_new_attempt_at_question();

        // Calling discard_broken_attempt should delete the attempt and $quba.
        $qubaid = $attempt->get_question_usage()->get_id();
        $attempt->discard_broken_attempt();

        // This time we have just added a new question_attempt to the usage.
        $this->assertTrue($DB->record_exists('question_usages', ['id' => $qubaid]));
        $this->assertEquals(3, $DB->count_records('question_attempts', ['questionusageid' => $qubaid]));
        if (is_dir($CFG->dirroot . '/report/embedquestion/db')) {
            $this->assertTrue($DB->record_exists('report_embedquestion_attempt', ['questionusageid' => $qubaid]));
        }
    }

    public function test_question_rendering(): void {
        global $PAGE, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a sharable questions in the same category.
        /** @var \filter_embedquestion_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('filter_embedquestion');
        $q1 = $generator->create_embeddable_question('shortanswer');

        // Start an attempt in the way that showattempt.php would.
        list($embedid, $context) = $generator->get_embed_id_and_context($q1);
        $embedlocation = embed_location::make_for_test($context, $context->get_url(), 'Test embed location');
        $options = new question_options();
        $options->behaviour = 'immediatefeedback';
        $attempt = new attempt($embedid, $embedlocation, $USER, $options);
        $this->verify_attempt_valid($attempt);
        $attempt->find_or_create_attempt();
        $this->verify_attempt_valid($attempt);

        // Render the question.
        /** @var output\renderer $renderer */
        $renderer = $PAGE->get_renderer('filter_embedquestion');
        $html = $attempt->render_question($renderer);

        $previousattemptlink = '';
        if (class_exists(report_embedquestion\attempt_summary_table::class)) {
            $previousattemptlink = '<div class="link-wrapper-class"><a target="_top" href="[^"]+">' .
                '<span>Previous attempts</span></a></div>';
        }
        $icon = '<i class="icon fa fa-pen fa-fw iconsmall"  title="Edit"[^>]*></i>\s*Edit question\s*</a>\s*</div>';

        // Verify that the edit question, question bank link and fill with correct links are present.
        $expectedregex = '~<div class="info"><h3 class="no">Question <span class="qno">[^<]+</span>' .
            '</h3><div class="state">Not complete</div><div class="grade">Marked out of 1.00</div>' .
            '<div class="editquestion"><a href="[^"]+">' .
            $icon .
            '(<span class="badge bg-primary text-light">v1 \(latest\)</span>)?' .
            '<div class="filter_embedquestion-viewquestionbank">' .
            '<a target="_top" href="[^"]+">' .
            '<img class="icon iconsmall" alt="" aria-hidden="true" src="[^"]+" />' .
            'Question bank</a></div>' .
            '<div class="filter_embedquestion-fill-link">' .
            '<button type="submit" name="fillwithcorrect" value="1" class="btn btn-link">' .
            '<i class="icon fa fa-check fa-fw iconsmall" aria-hidden="true" ></i>' .
            '<span>Fill with correct</span></button></div></div>~s';
        $this->assertMatchesRegularExpression($expectedregex, $html);
        // Create an authenticated user.
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        // Start an attempt in the way that showattempt.php would.
        [$embedid, $context] = $generator->get_embed_id_and_context($q1);
        $embedlocation = embed_location::make_for_test($context, $context->get_url(), 'Test embed location');
        $options = new question_options();
        $options->behaviour = 'immediatefeedback';
        $attempt = new attempt($embedid, $embedlocation, $USER, $options);
        $this->verify_attempt_valid($attempt);
        $attempt->find_or_create_attempt();
        $this->verify_attempt_valid($attempt);

        $html = $attempt->render_question($renderer);
        // Verify that the edit question, question bank link and fill with correct links are not present.
        $expectedregex = '~<div class="info"><h3 class="no">Question <span class="qno">[^<]+</span>' .
            '</h3><div class="state">Not complete</div><div class="grade">Marked out of 1.00</div>' .
            '</div>~';
        $this->assertMatchesRegularExpression($expectedregex, $html);

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $attempt->start_new_attempt_at_question();
        $postdata = $questiongenerator->get_simulated_post_data_for_questions_in_usage($attempt->get_question_usage(),
            [1 => 'Sample answer'], true);
        $attempt->process_submitted_actions($postdata);

        // Verify that the Previous attempts link is displayed for the second attempt.
        $html = $attempt->render_question($renderer);
        $expectedregex = '~<div class="info"><h3 class="no">Question <span class="qno">[^<]+</span>' .
            '</h3><div class="state">Not complete</div><div class="grade">Marked out of 1.00</div>' .
            $previousattemptlink .
            '</div>~';
        $this->assertMatchesRegularExpression($expectedregex, $html);
    }

    /**
     * Helper: throw an exception if attempt is not valid.
     *
     * @param attempt $attempt the attempt to check.
     */
    protected function verify_attempt_valid(attempt $attempt): void {
        if (!$attempt->is_valid()) {
            throw new \coding_exception($attempt->get_problem_description());
        }
    }
}
