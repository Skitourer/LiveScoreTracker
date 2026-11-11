<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Registration;

use LiveScoreTracker\Application\ReceptionList;
use LiveScoreTracker\Domain\Registration\ChildData;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\RegistrationSource;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use PHPUnit\Framework\TestCase;

final class ReceptionListTest extends TestCase
{
    public function testSortedByFirstNameIgnoringAccentsAndGroupedByInitial(): void
    {
        $groups = ReceptionList::byFirstName([
            self::child(1, 'Zoé', 'Martin'),
            self::child(2, 'Émile', 'Durand'),
            self::child(3, 'Eva', 'Petit'),
            self::child(4, 'Ali', 'Roux'),
            self::child(5, 'Eva', 'Blanc'),
            self::child(6, 'Eva', 'Blanc'),
        ], 'fr');

        self::assertSame(['A', 'E', 'Z'], array_keys($groups));
        $names = array_map(static fn(Registration $r): string => $r->fullName() . ' ' . $r->bib, $groups['E']);
        // "Émile" sorts with the E's; same names are ordered by bib.
        self::assertSame(['Émile Durand 2', 'Eva Blanc 5', 'Eva Blanc 6', 'Eva Petit 3'], $names);
    }

    private static function child(int $bib, string $firstName, string $lastName): Registration
    {
        return new Registration(
            id: $bib,
            bib: $bib,
            status: RegistrationStatus::Registered,
            source: RegistrationSource::Manual,
            child: new ChildData($lastName, $firstName, '2017-01-01', 'girl', 'School', 'CM1', 'Adult', '0600000000', 'a@example.org', false),
            createdAt: '2027-01-01 00:00:00',
            registeredAt: '2027-01-01 00:00:00',
            withdrawnAt: null,
        );
    }
}
