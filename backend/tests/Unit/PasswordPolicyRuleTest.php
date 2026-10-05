<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Rules\PasswordPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The shared password policy: >= 12 characters, <= 200, at least one letter and
 * at least one digit. Enforced on the SERVER for every password-setting path.
 */
final class PasswordPolicyRuleTest extends TestCase
{
    /** @return array{0: bool, 1: string|null} */
    private function check(mixed $value): array
    {
        $failed = null;

        (new PasswordPolicy())->validate('password', $value, function (string $message) use (&$failed): void {
            $failed ??= $message;
        });

        return [$failed === null, $failed];
    }

    public function test_a_conforming_password_passes(): void
    {
        [$passed] = $this->check('CorrectHorse42Battery');

        $this->assertTrue($passed);
    }

    public function test_eleven_characters_is_too_short(): void
    {
        [$passed, $message] = $this->check('Abcdefghij1'); // 11

        $this->assertFalse($passed);
        $this->assertSame(PasswordPolicy::describe(), $message);
    }

    public function test_exactly_twelve_characters_passes(): void
    {
        [$passed] = $this->check('Abcdefghijk1'); // 12

        $this->assertTrue($passed);
    }

    /** Length only. "aaaaaaaaaaaaaa" is the degenerate case this floor exists for. */
    public function test_letters_without_a_digit_fail(): void
    {
        [$passed] = $this->check('abcdefghijklmno');

        $this->assertFalse($passed);
    }

    public function test_digits_without_a_letter_fail(): void
    {
        [$passed] = $this->check('121212121212');

        $this->assertFalse($passed);
    }

    public function test_over_two_hundred_characters_fails(): void
    {
        [$passed] = $this->check(str_repeat('a', 200) . '1');

        $this->assertFalse($passed);
    }

    public function test_exactly_two_hundred_characters_passes(): void
    {
        [$passed] = $this->check(str_repeat('a', 199) . '1');

        $this->assertTrue($passed);
    }

    /**
     * mb_strlen, not strlen. A 12-CHARACTER Arabic passphrase is 24 bytes in
     * UTF-8; counting bytes would wrongly accept a 6-character one.
     */
    public function test_arabic_is_measured_in_characters_not_bytes(): void
    {
        // 12 Arabic letters + one digit = 13 characters, 25 bytes.
        [$passed] = $this->check('كلمةالمرورالسر1');
        $this->assertTrue($passed);

        // 6 Arabic letters + a digit = 7 characters but 13 bytes: must FAIL.
        [$shortPassed] = $this->check('كلمةال1');
        $this->assertFalse($shortPassed, 'A short Arabic passphrase must not pass on byte length.');
    }

    /** Spaces are legal: long passphrases are the shape we want to encourage. */
    public function test_spaces_are_allowed(): void
    {
        [$passed] = $this->check('a long pass phrase 7');

        $this->assertTrue($passed);
    }

    public function test_empty_and_non_string_values_report_the_required_message(): void
    {
        [$emptyPassed, $emptyMessage] = $this->check('');
        $this->assertFalse($emptyPassed);
        $this->assertSame('كلمة المرور مطلوبة', $emptyMessage);

        [$nullPassed] = $this->check(null);
        $this->assertFalse($nullPassed);
    }

    /** The describe() text is an EXACT contract string. */
    public function test_describe_is_the_exact_contract_text(): void
    {
        $this->assertSame(
            'يجب أن تتكون كلمة المرور من 12 حرفاً على الأقل (وبحد أقصى 200 حرفاً) وأن تحتوي على حرف ورقم على الأقل',
            PasswordPolicy::describe()
        );
    }
}
