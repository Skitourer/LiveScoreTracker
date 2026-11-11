<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Registration;

use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\ChildValidator;
use LiveScoreTracker\Domain\Registration\TextCleaner;
use LiveScoreTracker\Domain\Registration\ValueMatcher;
use LiveScoreTracker\Infrastructure\Config\ConfigRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistrationRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function dates(): iterable
    {
        yield 'ISO' => ['2019-05-31', '2019-05-31'];
        yield 'French' => ['31/05/2019', '2019-05-31'];
        yield 'dashes' => ['31-05-2019', '2019-05-31'];
        yield 'dots and short year' => ['1.5.19', '2019-05-01'];
        yield 'with time' => ['2019-05-31 00:00:00', '2019-05-31'];
        yield 'impossible day' => ['31/02/2019', null];
        yield 'text' => ['last spring', null];
    }

    #[DataProvider('dates')]
    public function testDates(string $input, ?string $expected): void
    {
        self::assertSame($expected, TextCleaner::date($input));
    }

    public function testCleaning(): void
    {
        self::assertSame('Jean-Pierre', TextCleaner::firstName("  jean-PIERRE\u{00A0} "));
        self::assertSame('DE LA FONTAINE', TextCleaner::lastName('de  la fontaine'));
        self::assertSame('06 12 34 56 78', TextCleaner::phone('612345678'), 'Leading zero lost by a spreadsheet');
        self::assertSame('06 12 34 56 78', TextCleaner::phone('06.12.34.56.78'));
        self::assertSame('+33 6 12 34 56 78', TextCleaner::phone('+33612345678'));
        self::assertSame('+33 6 12 34 56 78', TextCleaner::phone('+33 (0)6 12 34 56 78'));
        self::assertSame('+33 6 12 34 56 78', TextCleaner::phone('0033 6 12 34 56 78'));
        self::assertTrue(TextCleaner::isPhone(TextCleaner::phone('+1 555 0100 222')));
        self::assertFalse(TextCleaner::isPhone(TextCleaner::phone('06 12 34 56')), 'Too short');
        self::assertFalse(TextCleaner::isPhone(TextCleaner::phone('1234567890')), 'French numbers start with 0');
        self::assertTrue(TextCleaner::yes('Oui'));
        self::assertTrue(TextCleaner::yes('x'));
        self::assertFalse(TextCleaner::yes('non'));
        self::assertSame('elodie', TextCleaner::key(' Élodie '));
    }

    public function testValueVariants(): void
    {
        $matcher = new ValueMatcher(self::config(), ['girl' => ['Fille', 'Filles'], 'boy' => ['Garçon', 'Garçons']]);

        foreach (['Garçon', 'garcon', 'G', 'boy', 'GARÇONS'] as $boy) {
            self::assertSame('boy', $matcher->gender($boy), $boy);
        }
        foreach (['Fille', 'f', 'girl'] as $girl) {
            self::assertSame('girl', $matcher->gender($girl), $girl);
        }
        self::assertNull($matcher->gender('?'));
        self::assertSame('CM1', $matcher->level('cm 1'));
        self::assertSame('CE2', $matcher->level('Ce2'));
        self::assertNull($matcher->level('6e'));
        self::assertSame('Springfield Elementary', $matcher->school('springfield  elementary'));
        self::assertNull($matcher->school('Unknown School'));
    }

    public function testValidChild(): void
    {
        $result = self::validator()->validate(self::input());

        self::assertTrue($result->isValid(), print_r($result->errors, true));
        self::assertNotNull($result->data);
        self::assertSame('MARTIN', $result->data->lastName);
        self::assertSame('Zoé', $result->data->firstName);
        self::assertSame('2017-03-14', $result->data->birthDate);
        self::assertSame('girl', $result->data->gender);
        self::assertSame('CM1', $result->data->level);
        self::assertSame('06 11 22 33 44', $result->data->guardianPhone);
        self::assertTrue($result->data->volunteer);
        self::assertSame([], $result->warnings);
        self::assertSame('martin|zoe|cm1', $result->data->matchKey());
    }

    public function testErrorsAndWarnings(): void
    {
        $result = self::validator()->validate(self::input(['first_name' => '', 'gender' => 'x', 'guardian_email' => 'nope', 'school' => 'Elsewhere']));
        self::assertFalse($result->isValid());
        self::assertArrayHasKey('registration.required.first_name', $result->errors);
        self::assertArrayHasKey('registration.gender_unknown', $result->errors);
        self::assertArrayHasKey('registration.email_invalid', $result->errors);
        self::assertArrayHasKey('registration.school_unknown', $result->errors);

        // A CM1 child is usually born 7 + 3 years before the 2027 edition: 2017.
        $unusual = self::validator()->validate(self::input(['birth_date' => '2019-01-01']));
        self::assertTrue($unusual->isValid());
        self::assertSame(['level' => 'CM1', 'year' => 2017], $unusual->warnings['registration.age_unusual']);

        // Imports keep an unknown school as a warning.
        $import = self::validator()->validate(self::input(['school' => 'Elsewhere']), strictSchool: false);
        self::assertTrue($import->isValid());
        self::assertArrayHasKey('registration.school_unknown', $import->warnings);

        self::assertArrayHasKey('registration.required.birth_date', self::validator()->validate(self::input(['birth_date' => '']))->errors);
        self::assertTrue(self::validator()->validate(self::input(['birth_date' => '']), birthDateRequired: false)->isValid(), 'On-site additions');
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    public static function input(array $overrides = []): array
    {
        return [
            'last_name' => 'martin',
            'first_name' => 'zoé',
            'birth_date' => '14/03/2017',
            'gender' => 'Fille',
            'school' => 'Springfield Elementary',
            'level' => 'cm1',
            'guardian_name' => 'Anne Martin',
            'guardian_phone' => '611223344',
            'guardian_email' => 'Anne@Example.org',
            'volunteer' => 'oui',
            ...$overrides,
        ];
    }

    private static function validator(): ChildValidator
    {
        $config = self::config();

        return new ChildValidator($config, new ValueMatcher($config, ['girl' => ['Fille'], 'boy' => ['Garçon']]));
    }

    private static function config(): CompetitionConfig
    {
        return ConfigRepository::loadFile(\dirname(__DIR__, 3) . '/config.example/competition.toml');
    }
}
