<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The port of the zod schemas: every POST and PATCH body is read through an
 * explicit field list. Unknown fields are dropped, never spread into an
 * update; a field that is present but wrong is an error naming it.
 *
 *   $data = Validator::check($request->input(), [
 *       'title'     => ['string', 'required', 'max' => 200],
 *       'published' => ['bool'],
 *       'publishAt' => ['datetime', 'nullable'],
 *       'categoryId'=> ['id', 'nullable'],
 *       'kind'      => ['enum' => ['A', 'B']],
 *   ]);
 *
 * With 'partial' => true (a PATCH) absent fields are left out of the result;
 * otherwise an absent optional field is also left out, and a required one is
 * an error.
 */
final class Validator
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, array<int|string, mixed>> $rules
     * @return array<string, mixed>
     */
    public static function check(array $input, array $rules, bool $partial = false): array
    {
        $out = [];
        $errors = [];
        foreach ($rules as $field => $rule) {
            $flags = array_filter($rule, 'is_int', ARRAY_FILTER_USE_KEY);
            $type = (string) ($flags[0] ?? 'string');
            $required = in_array('required', $flags, true);
            $nullable = in_array('nullable', $flags, true);
            $label = $rule['label'] ?? $field;

            if (!array_key_exists($field, $input)) {
                if ($required && !$partial) {
                    $errors[$field] = "$label is required.";
                }
                continue;
            }
            $value = $input[$field];
            if ($value === null || ($value === '' && $nullable && $type !== 'string')) {
                if ($nullable) {
                    $out[$field] = null;
                } elseif ($required) {
                    $errors[$field] = "$label is required.";
                }
                continue;
            }
            try {
                $out[$field] = self::coerce($type, $value, $rule, $label);
                if ($required && ($out[$field] === '' || $out[$field] === [])) {
                    $errors[$field] = "$label is required.";
                }
                if ($out[$field] === '' && $nullable && !in_array('keepEmpty', $flags, true)) {
                    $out[$field] = null;
                }
            } catch (\InvalidArgumentException $e) {
                $errors[$field] = $e->getMessage();
            }
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
        return $out;
    }

    /** @param array<int|string, mixed> $rule */
    private static function coerce(string $type, mixed $value, array $rule, string $label): mixed
    {
        switch ($type) {
            case 'string':
            case 'text':
                if (!is_string($value) && !is_int($value) && !is_float($value)) {
                    throw new \InvalidArgumentException("$label must be text.");
                }
                $value = self::cleanText((string) $value, $type === 'text');
                $max = (int) ($rule['max'] ?? ($type === 'text' ? 100_000 : 500));
                if (mb_strlen($value) > $max) {
                    throw new \InvalidArgumentException("$label must be at most $max characters.");
                }
                if (isset($rule['min']) && mb_strlen($value) < (int) $rule['min']) {
                    throw new \InvalidArgumentException("$label must be at least {$rule['min']} characters.");
                }
                if (isset($rule['pattern']) && $value !== '' && !preg_match((string) $rule['pattern'], $value)) {
                    throw new \InvalidArgumentException("$label is not in the expected format.");
                }
                return $value;
            case 'bool':
                if (is_bool($value)) {
                    return $value;
                }
                if (in_array($value, [1, '1', 'true', 'on', 'yes'], true)) {
                    return true;
                }
                if (in_array($value, [0, '0', 'false', 'off', 'no', ''], true)) {
                    return false;
                }
                throw new \InvalidArgumentException("$label must be true or false.");
            case 'int':
                if (is_int($value) || (is_string($value) && preg_match('/^-?\d{1,15}$/', trim($value))) || (is_float($value) && floor($value) === $value)) {
                    $n = (int) $value;
                    if (isset($rule['min']) && $n < (int) $rule['min']) {
                        throw new \InvalidArgumentException("$label must be at least {$rule['min']}.");
                    }
                    if (isset($rule['max']) && $n > (int) $rule['max']) {
                        throw new \InvalidArgumentException("$label must be at most {$rule['max']}.");
                    }
                    return $n;
                }
                throw new \InvalidArgumentException("$label must be a whole number.");
            case 'id':
                if (!Id::isValid($value)) {
                    throw new \InvalidArgumentException("$label is not a valid id.");
                }
                return $value;
            case 'email':
                if (!is_string($value) || !self::isEmail($value)) {
                    throw new \InvalidArgumentException("$label must be an email address.");
                }
                return self::normalizeEmail($value);
            case 'datetime':
                if (!is_string($value)) {
                    throw new \InvalidArgumentException("$label must be a date and time.");
                }
                try {
                    $at = new \DateTimeImmutable($value);
                } catch (\Exception) {
                    throw new \InvalidArgumentException("$label must be a date and time.");
                }
                return $at->setTimezone(new \DateTimeZone('UTC'));
            case 'date':
                if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                    throw new \InvalidArgumentException("$label must be a date (YYYY-MM-DD).");
                }
                return $value;
            case 'enum':
                $allowed = (array) ($rule['enum'] ?? []);
                if (!in_array($value, $allowed, true)) {
                    throw new \InvalidArgumentException("$label must be one of: " . implode(', ', $allowed) . '.');
                }
                return $value;
            case 'url':
                if (!is_string($value) || !preg_match('#^https?://#i', $value) || filter_var($value, FILTER_VALIDATE_URL) === false || mb_strlen($value) > 2000) {
                    throw new \InvalidArgumentException("$label must be a web address.");
                }
                return $value;
            case 'hex':
                if (!is_string($value) || !preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value)) {
                    throw new \InvalidArgumentException("$label must be a colour like #1a8fd1.");
                }
                return strtolower($value);
            case 'array':
                if (!is_array($value) || !array_is_list($value)) {
                    throw new \InvalidArgumentException("$label must be a list.");
                }
                $max = (int) ($rule['max'] ?? 500);
                if (count($value) > $max) {
                    throw new \InvalidArgumentException("$label may have at most $max entries.");
                }
                $of = $rule['of'] ?? null;
                return $of === null ? $value : array_map(fn ($item) => self::coerce((string) $of, $item, $rule['each'] ?? [], $label), $value);
            case 'json':
                return $value;
            default:
                throw new \LogicException("Unknown rule type $type");
        }
    }

    /**
     * Strips control characters (keeping newlines and tabs in long text) and
     * trims. Markup is stored verbatim: escaping is the template's job.
     */
    public static function cleanText(string $value, bool $multiline = false): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $pattern = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
        $value = (string) preg_replace($pattern, '', $multiline ? str_replace("\r\n", "\n", $value) : $value);
        return trim($value);
    }

    /** Trimmed and lower-cased: the only way an address is compared or written. */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function isEmail(string $email): bool
    {
        $email = trim($email);
        if ($email === '' || strlen($email) > 254 || preg_match('/[\s<>()\[\],;:"\\\\\'`]/', $email)) {
            return false;
        }
        return preg_match('/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $email) === 1
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
