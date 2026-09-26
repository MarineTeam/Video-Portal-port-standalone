<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\Forms\Forms;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/forms/src/Forms.php';

/** Forms, case for case as the original's lib/forms.test.ts. */
final class FormsTest extends TestCase
{
    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function field(string $id, string $type, array $extra = []): array
    {
        return $extra + ['id' => $id, 'label' => ucfirst(strtolower($type)), 'type' => $type, 'required' => false, 'options' => null, 'deleted_at' => null];
    }

    // -- optionsOf --------------------------------------------------------

    public function test_options_reads_one_per_line_and_ignores_the_blank_ones(): void
    {
        $field = $this->field('f', Forms::RADIO, ['options' => "Morning\r\n\r\n  Afternoon  \nEvening\n"]);
        self::assertSame(['Morning', 'Afternoon', 'Evening'], Forms::optionsOf($field));
    }

    public function test_options_are_empty_for_a_field_that_offers_no_choices(): void
    {
        self::assertSame([], Forms::optionsOf($this->field('f', Forms::TEXT, ['options' => "One\nTwo"])));
        self::assertSame([], Forms::optionsOf($this->field('f', Forms::SELECT)));
    }

    // -- validateSubmission -----------------------------------------------

    /** @return list<array<string, mixed>> */
    private function fields(): array
    {
        return [
            $this->field('name', Forms::TEXT, ['label' => 'Your name', 'required' => true]),
            $this->field('email', Forms::EMAIL, ['label' => 'Email']),
            $this->field('age', Forms::NUMBER, ['label' => 'Age']),
            $this->field('when', Forms::DATE, ['label' => 'When']),
            $this->field('session', Forms::RADIO, ['label' => 'Session', 'options' => "Morning\nAfternoon"]),
            $this->field('extras', Forms::CHECKBOXES, ['label' => 'Extras', 'options' => "Lunch\nCrèche"]),
            $this->field('agree', Forms::CHECKBOX, ['label' => 'I agree']),
        ];
    }

    public function test_validate_keeps_what_was_given_and_trims_it(): void
    {
        $out = Forms::validateSubmission($this->fields(), ['name' => '  Ruth  ', 'email' => 'ruth@test.example']);
        self::assertSame('Ruth', $out['answers']['name']);
        self::assertSame('ruth@test.example', $out['answers']['email']);
        self::assertSame([], $out['problems']);
    }

    public function test_validate_asks_again_for_a_required_field_left_blank(): void
    {
        $out = Forms::validateSubmission($this->fields(), ['name' => '   ']);
        self::assertSame(['name' => 'Your name is needed.'], $out['problems']);
    }

    public function test_validate_leaves_an_optional_blank_out_rather_than_storing_an_empty_answer(): void
    {
        $out = Forms::validateSubmission($this->fields(), ['name' => 'Ruth', 'email' => '']);
        self::assertArrayNotHasKey('email', $out['answers']);
    }

    public function test_validate_checks_an_email_a_number_and_a_date(): void
    {
        $out = Forms::validateSubmission($this->fields(), ['name' => 'Ruth', 'email' => 'not-an-address', 'age' => 'forty', 'when' => 'tomorrow']);
        self::assertSame(['email', 'age', 'when'], array_keys($out['problems']));
        $good = Forms::validateSubmission($this->fields(), ['name' => 'Ruth', 'age' => '40', 'when' => '2026-03-03']);
        self::assertSame(['name' => 'Ruth', 'age' => '40', 'when' => '2026-03-03', 'agree' => 'No'], $good['answers']);
    }

    public function test_validate_refuses_an_answer_to_a_choice_question_that_was_never_offered(): void
    {
        $out = Forms::validateSubmission($this->fields(), ['name' => 'Ruth', 'session' => 'Midnight']);
        self::assertSame(['session' => 'Session is not one of the choices.'], $out['problems']);
    }

    public function test_validate_drops_uninvited_options_out_of_a_multi_choice_rather_than_storing_them(): void
    {
        $out = Forms::validateSubmission($this->fields(), ['name' => 'Ruth', 'extras' => ['Lunch', 'A pony', 'Lunch']]);
        self::assertSame('Lunch', $out['answers']['extras']);
        self::assertSame([], $out['problems']);
    }

    public function test_validate_treats_a_lone_unticked_checkbox_as_an_answer_not_as_silence(): void
    {
        $out = Forms::validateSubmission($this->fields(), ['name' => 'Ruth']);
        self::assertSame('No', $out['answers']['agree']);
        self::assertSame('Yes', Forms::validateSubmission($this->fields(), ['name' => 'Ruth', 'agree' => true])['answers']['agree']);
    }

    public function test_validate_will_not_let_a_required_checkbox_through_unticked(): void
    {
        $fields = [$this->field('agree', Forms::CHECKBOX, ['label' => 'I agree', 'required' => true])];
        self::assertSame(['agree' => 'I agree is needed.'], Forms::validateSubmission($fields, [])['problems']);
        self::assertSame(['agree' => 'Yes'], Forms::validateSubmission($fields, ['agree' => '1'])['answers']);
    }

    public function test_validate_ignores_anything_sent_for_a_field_the_form_does_not_ask(): void
    {
        $out = Forms::validateSubmission($this->fields(), ['name' => 'Ruth', 'smuggled' => 'value', 'id' => 'other']);
        self::assertSame(['name', 'agree'], array_keys($out['answers']));
    }

    public function test_validate_requires_at_least_one_box_of_a_required_multi_choice(): void
    {
        $fields = [$this->field('extras', Forms::CHECKBOXES, ['label' => 'Extras', 'required' => true, 'options' => "Lunch\nCrèche"])];
        self::assertSame(['extras' => 'Extras is needed.'], Forms::validateSubmission($fields, ['extras' => ['A pony']])['problems']);
        self::assertSame("Lunch\nCrèche", Forms::validateSubmission($fields, ['extras' => ['Lunch', 'Crèche']])['answers']['extras']);
    }

    // -- columnsFor / submissionRow ---------------------------------------

    public function test_columns_keep_a_retired_question_as_a_column_after_the_live_ones(): void
    {
        $fields = [
            $this->field('a', Forms::TEXT, ['label' => 'Name']),
            $this->field('gone', Forms::TEXT, ['label' => 'Fax number', 'deleted_at' => '2026-01-01 00:00:00']),
            $this->field('b', Forms::TEXT, ['label' => 'Phone']),
        ];
        self::assertSame(
            [['id' => 'a', 'label' => 'Name', 'retired' => false], ['id' => 'b', 'label' => 'Phone', 'retired' => false], ['id' => 'gone', 'label' => 'Fax number', 'retired' => true]],
            Forms::columnsFor($fields),
        );
    }

    public function test_submission_row_lays_the_answers_out_under_their_labels(): void
    {
        $columns = Forms::columnsFor([
            $this->field('a', Forms::TEXT, ['label' => 'Name']),
            $this->field('gone', Forms::TEXT, ['label' => 'Fax number', 'deleted_at' => '2026-01-01 00:00:00']),
        ]);
        self::assertSame([
            ['label' => 'Name', 'value' => 'Ruth', 'retired' => false],
            ['label' => 'Fax number', 'value' => '01234 567890', 'retired' => true],
        ], Forms::submissionRow($columns, ['a' => 'Ruth', 'gone' => '01234 567890']));
        self::assertSame('', Forms::submissionRow($columns, [])[0]['value'], 'a question nobody answered is blank, not missing');
    }
}
