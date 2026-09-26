<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Forms;

/**
 * What a form's questions and answers come to (the original's lib/forms.ts).
 *
 * The questions are rows somebody adds, not code somebody deploys, and two
 * consequences are the design: renaming a question can't detach its
 * answers, because an answer points at the question rather than at the
 * words it was asked in; and deleting one is replaced by a retirement,
 * because a hard delete would cascade a year of answers away, or leave
 * them under a column nobody can name.
 */
final class Forms
{
    public const TEXT = 'TEXT';
    public const TEXTAREA = 'TEXTAREA';
    public const EMAIL = 'EMAIL';
    public const PHONE = 'PHONE';
    public const NUMBER = 'NUMBER';
    public const DATE = 'DATE';
    public const SELECT = 'SELECT';
    public const RADIO = 'RADIO';
    public const CHECKBOX = 'CHECKBOX';
    public const CHECKBOXES = 'CHECKBOXES';

    /** The ten kinds of question. */
    public const TYPES = [self::TEXT, self::TEXTAREA, self::EMAIL, self::PHONE, self::NUMBER, self::DATE, self::SELECT, self::RADIO, self::CHECKBOX, self::CHECKBOXES];
    /** The ones that offer a choice, whose options are one per line. */
    public const CHOICES = [self::SELECT, self::RADIO, self::CHECKBOXES];
    public const MAX_ANSWER = 5000;

    /**
     * A field's options: one per line, blank lines ignored.
     *
     * @param array<string, mixed> $field
     * @return list<string>
     */
    public static function optionsOf(array $field): array
    {
        if (!in_array((string) ($field['type'] ?? self::TEXT), self::CHOICES, true)) {
            return [];
        }
        $lines = preg_split('/\r\n|\r|\n/', (string) ($field['options'] ?? '')) ?: [];
        return array_values(array_filter(array_map('trim', $lines), fn (string $line) => $line !== ''));
    }

    /**
     * What will be stored, and what to ask again for. The server has the
     * last word on what a valid answer is: a crafted request can't invent a
     * fourth answer to a three-way question.
     *
     * @param list<array<string, mixed>> $fields the live questions, in order
     * @param array<string, mixed> $input what was sent, by field id
     * @return array{answers: array<string, string>, problems: array<string, string>}
     */
    public static function validateSubmission(array $fields, array $input): array
    {
        $answers = [];
        $problems = [];
        foreach ($fields as $field) {
            $id = (string) $field['id'];
            $type = (string) ($field['type'] ?? self::TEXT);
            $label = (string) ($field['label'] ?? 'This');
            $required = (bool) ($field['required'] ?? false);
            $given = $input[$id] ?? null;
            $options = self::optionsOf($field);
            if ($type === self::CHECKBOX) {
                // A lone tick box is an answer either way: "no" is not silence.
                $ticked = in_array($given, [true, 1, '1', 'true', 'on', 'yes'], true);
                if ($required && !$ticked) {
                    $problems[$id] = "$label is needed.";
                    continue;
                }
                $answers[$id] = $ticked ? 'Yes' : 'No';
                continue;
            }
            if ($type === self::CHECKBOXES) {
                $chosen = [];
                foreach (is_array($given) ? $given : ($given === null ? [] : [$given]) as $one) {
                    // Uninvited options are dropped rather than stored.
                    if (is_string($one) && in_array($one, $options, true)) {
                        $chosen[] = $one;
                    }
                }
                $chosen = array_values(array_unique($chosen));
                if ($chosen === []) {
                    if ($required) {
                        $problems[$id] = "$label is needed.";
                    }
                    continue;
                }
                $answers[$id] = implode("\n", $chosen);
                continue;
            }
            $value = is_string($given) ? trim($given) : (is_int($given) || is_float($given) ? (string) $given : '');
            if ($value === '') {
                // An optional blank is left out rather than stored as an empty answer.
                if ($required) {
                    $problems[$id] = "$label is needed.";
                }
                continue;
            }
            if (mb_strlen($value) > self::MAX_ANSWER) {
                $problems[$id] = "$label is too long.";
                continue;
            }
            $problem = match ($type) {
                self::EMAIL => filter_var($value, FILTER_VALIDATE_EMAIL) === false ? "$label must be an email address." : null,
                self::NUMBER => !is_numeric($value) ? "$label must be a number." : null,
                self::DATE => !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? "$label must be a date (YYYY-MM-DD)." : null,
                self::SELECT, self::RADIO => !in_array($value, $options, true) ? "$label is not one of the choices." : null,
                default => null,
            };
            if ($problem !== null) {
                $problems[$id] = $problem;
                continue;
            }
            $answers[$id] = $value;
        }
        // Anything sent for a field the form doesn't ask is ignored, not stored.
        return ['answers' => $answers, 'problems' => $problems];
    }

    /**
     * The columns an export or a table has: the live questions in order,
     * then the retired ones, so an export never silently drops what
     * somebody actually said.
     *
     * @param list<array<string, mixed>> $fields every field, retired ones included
     * @return list<array{id: string, label: string, retired: bool}>
     */
    public static function columnsFor(array $fields): array
    {
        $live = [];
        $retired = [];
        foreach ($fields as $field) {
            $column = ['id' => (string) $field['id'], 'label' => (string) $field['label'], 'retired' => ($field['deleted_at'] ?? null) !== null];
            if ($column['retired']) {
                $retired[] = $column;
            } else {
                $live[] = $column;
            }
        }
        return [...$live, ...$retired];
    }

    /**
     * One submission laid out under those columns.
     *
     * @param list<array{id: string, label: string, retired: bool}> $columns
     * @param array<string, string> $answers by field id
     * @return list<array{label: string, value: string, retired: bool}>
     */
    public static function submissionRow(array $columns, array $answers): array
    {
        return array_map(fn (array $column) => [
            'label' => $column['label'],
            'value' => $answers[$column['id']] ?? '',
            'retired' => $column['retired'],
        ], $columns);
    }
}
