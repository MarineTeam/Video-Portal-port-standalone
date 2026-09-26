<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Validator;
use App\Core\ValidationError;
use PHPUnit\Framework\TestCase;

/**
 * The allowlist every write goes through (lib/validation/schemas.ts).
 *
 * The first test is the one the security review leans on: what comes back is
 * built from the rules, never from the input, so a field nobody asked for
 * cannot reach a query no matter what a caller sends.
 */
final class ValidatorTest extends TestCase
{
    public function test_1_a_field_nobody_asked_for_does_not_come_back(): void
    {
        $out = Validator::check(
            ['title' => 'Romans 1', 'role' => 'ADMIN', 'id' => 'anything', 'deleted_at' => null],
            ['title' => ['string']],
        );
        self::assertSame(['title' => 'Romans 1'], $out);
    }

    public function test_2_a_required_field_that_is_absent_is_an_error_and_a_patch_forgives_it(): void
    {
        try {
            Validator::check([], ['title' => ['string', 'required']]);
            self::fail('an absent required field should throw');
        } catch (ValidationError $e) {
            self::assertArrayHasKey('title', $e->fields);
        }
        self::assertSame([], Validator::check([], ['title' => ['string', 'required']], partial: true));
    }

    public function test_3_an_absent_optional_field_is_left_out_rather_than_nulled(): void
    {
        // Left out, not null: a PATCH that does not mention a column must not
        // blank it.
        self::assertSame([], Validator::check([], ['note' => ['string', 'nullable']]));
        self::assertSame(['note' => null], Validator::check(['note' => null], ['note' => ['string', 'nullable']]));
    }

    public function test_4_an_empty_string_is_null_where_null_is_allowed(): void
    {
        self::assertSame(['note' => null], Validator::check(['note' => ''], ['note' => ['string', 'nullable']]));
        self::assertSame(['note' => ''], Validator::check(['note' => ''], ['note' => ['string', 'nullable', 'keepEmpty']]));
        self::assertSame(['note' => ''], Validator::check(['note' => ''], ['note' => ['string']]));
    }

    public function test_5_text_keeps_its_newlines_and_a_string_does_not(): void
    {
        self::assertSame("one\n\ntwo", Validator::check(['v' => "one\n\ntwo"], ['v' => ['text']])['v']);
        self::assertSame('onetwo', Validator::check(['v' => "one\ntwo"], ['v' => ['string']])['v']);
    }

    public function test_6_control_characters_are_stripped_and_the_value_trimmed(): void
    {
        $out = Validator::check(['v' => "  Romans\x00\x07 1  "], ['v' => ['string']])['v'];
        self::assertSame('Romans 1', $out);
    }

    public function test_7_markup_is_kept_verbatim_because_escaping_is_the_templates_job(): void
    {
        // Stripping it here would be the wrong place and would corrupt a
        // legitimate title; every template escapes on the way out instead.
        $out = Validator::check(['v' => '<script>alert(1)</script>'], ['v' => ['string']])['v'];
        self::assertSame('<script>alert(1)</script>', $out);
    }

    public function test_8_a_length_is_a_refusal_not_a_truncation(): void
    {
        $this->expectException(ValidationError::class);
        Validator::check(['v' => str_repeat('x', 51)], ['v' => ['string', 'max' => 50]]);
    }

    public function test_9_a_number_takes_the_forms_a_form_sends_and_refuses_the_rest(): void
    {
        self::assertSame(7, Validator::check(['n' => '7'], ['n' => ['int']])['n']);
        self::assertSame(-7, Validator::check(['n' => -7], ['n' => ['int']])['n']);
        self::assertSame(7, Validator::check(['n' => 7.0], ['n' => ['int']])['n']);
        foreach (['7.5', '7abc', 'abc', '', '1e3', str_repeat('9', 16)] as $bad) {
            try {
                Validator::check(['n' => $bad], ['n' => ['int']]);
                self::fail("$bad should not be a whole number");
            } catch (ValidationError) {
                self::assertTrue(true);
            }
        }
    }

    public function test_10_a_number_out_of_range_is_refused_at_either_end(): void
    {
        self::assertSame(5, Validator::check(['n' => 5], ['n' => ['int', 'min' => 1, 'max' => 10]])['n']);
        $this->expectException(ValidationError::class);
        Validator::check(['n' => 11], ['n' => ['int', 'min' => 1, 'max' => 10]]);
    }

    public function test_11_a_checkbox_reads_as_true_or_false_and_nothing_else(): void
    {
        foreach ([true, 1, '1', 'true', 'on', 'yes'] as $yes) {
            self::assertTrue(Validator::check(['b' => $yes], ['b' => ['bool']])['b'], var_export($yes, true));
        }
        foreach ([false, 0, '0', 'false', 'off', 'no', ''] as $no) {
            self::assertFalse(Validator::check(['b' => $no], ['b' => ['bool']])['b'], var_export($no, true));
        }
        $this->expectException(ValidationError::class);
        Validator::check(['b' => 'maybe'], ['b' => ['bool']]);
    }

    public function test_12_an_enum_takes_only_what_is_listed_and_compares_strictly(): void
    {
        $rule = ['s' => ['enum', 'enum' => ['GOING', 'MAYBE']]];
        self::assertSame('GOING', Validator::check(['s' => 'GOING'], $rule)['s']);
        foreach (['going', 'OTHER', 0, true] as $bad) {
            try {
                Validator::check(['s' => $bad], $rule);
                self::fail(var_export($bad, true) . ' is not one of the listed values');
            } catch (ValidationError) {
                self::assertTrue(true);
            }
        }
    }

    public function test_13_a_web_address_must_be_one_and_must_be_http(): void
    {
        self::assertSame('https://example.org/a', Validator::check(['u' => 'https://example.org/a'], ['u' => ['url']])['u']);
        foreach (['javascript:alert(1)', 'data:text/html,<script>', 'file:///etc/passwd', 'example.org', '//example.org'] as $bad) {
            try {
                Validator::check(['u' => $bad], ['u' => ['url']]);
                self::fail("$bad is not a web address");
            } catch (ValidationError) {
                self::assertTrue(true);
            }
        }
    }

    public function test_14_a_date_must_be_a_date_that_exists(): void
    {
        self::assertSame('2026-02-28', Validator::check(['d' => '2026-02-28'], ['d' => ['date']])['d']);
        foreach (['2026-02-30', '2026-13-01', '28/02/2026', '2026-2-8', ''] as $bad) {
            try {
                Validator::check(['d' => $bad], ['d' => ['date']]);
                self::fail("$bad is not a date");
            } catch (ValidationError) {
                self::assertTrue(true);
            }
        }
    }

    public function test_15_a_datetime_comes_back_in_utc_whatever_zone_it_was_given_in(): void
    {
        $at = Validator::check(['t' => '2026-03-01T09:30:00+05:30'], ['t' => ['datetime']])['t'];
        self::assertInstanceOf(\DateTimeImmutable::class, $at);
        self::assertSame('2026-03-01 04:00:00', $at->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $at->getTimezone()->getName());
    }

    public function test_16_an_email_is_lower_cased_and_trimmed(): void
    {
        self::assertSame('ruth@example.org', Validator::check(['e' => '  Ruth@Example.ORG '], ['e' => ['email']])['e']);
        $this->expectException(ValidationError::class);
        Validator::check(['e' => 'ruth@'], ['e' => ['email']]);
    }

    public function test_17_a_colour_is_a_hex_colour(): void
    {
        self::assertSame('#1a8fd1', Validator::check(['c' => '#1A8FD1'], ['c' => ['hex']])['c']);
        $this->expectException(ValidationError::class);
        Validator::check(['c' => 'red'], ['c' => ['hex']]);
    }

    public function test_18_a_list_must_be_a_list_and_its_items_are_checked_too(): void
    {
        self::assertSame(['a', 'b'], Validator::check(['l' => ['a', 'b']], ['l' => ['array']])['l']);
        self::assertSame([1, 2], Validator::check(['l' => ['1', '2']], ['l' => ['array', 'of' => 'int']])['l']);
        foreach ([['a' => 'b'], 'a,b', 5] as $bad) {
            try {
                Validator::check(['l' => $bad], ['l' => ['array']]);
                self::fail('not a list: ' . var_export($bad, true));
            } catch (ValidationError) {
                self::assertTrue(true);
            }
        }
    }

    public function test_19_a_list_too_long_is_refused(): void
    {
        $this->expectException(ValidationError::class);
        Validator::check(['l' => range(1, 4)], ['l' => ['array', 'max' => 3]]);
    }

    public function test_20_every_field_that_is_wrong_is_reported_at_once(): void
    {
        try {
            Validator::check(
                ['title' => str_repeat('x', 600), 'count' => 'lots'],
                ['title' => ['string'], 'count' => ['int'], 'slug' => ['string', 'required']],
            );
            self::fail('should throw');
        } catch (ValidationError $e) {
            // All three, not just the first: a form that reports one error
            // per submission takes three submissions to fill in.
            self::assertSame(['title', 'count', 'slug'], array_keys($e->fields));
        }
    }

    public function test_21_an_unknown_rule_is_a_mistake_in_our_code_not_the_callers(): void
    {
        $this->expectException(\LogicException::class);
        Validator::check(['v' => 'x'], ['v' => ['telephone']]);
    }
}
