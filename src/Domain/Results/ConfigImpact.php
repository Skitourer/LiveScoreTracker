<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Results;

use InvalidArgumentException;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Registration\Registration;

/**
 * Children affected by a change made while the competition is running
 * (spec § 10.4): number of attempts, foul mode, added or deactivated event,
 * level groups, or a child's level.
 *
 * For each child and workshop, the event state is computed with the old and
 * the new settings, then compared:
 * - "to_do": the child has an attempt to do (or redo) that they did not have;
 * - "excluded": fewer attempts count (later ones are kept but excluded);
 * - "restored": attempts that were excluded count again.
 */
final class ConfigImpact
{
    public const TO_DO = 'to_do';
    public const EXCLUDED = 'excluded';
    public const RESTORED = 'restored';

    /**
     * @param list<array{registration: Registration, before: string, after: string}> $children level before and after
     * @param array<int, list<StoredAttempt>>                                        $attempts registration id => current attempts, all events
     *
     * @return list<array{workshop: string, kind: string, children: list<Registration>}>
     */
    public static function compare(CompetitionConfig $old, CompetitionConfig $new, array $children, array $attempts): array
    {
        $workshops = [];
        foreach ([...$new->workshops, ...$old->workshops] as $workshop) {
            $workshops[$workshop->id] = true;
        }

        $found = [];
        foreach (array_keys($workshops) as $workshop) {
            foreach ($children as $child) {
                $id = $child['registration']->id;
                $list = $attempts[$id] ?? [];
                $oldVariant = self::variantOf($old, $workshop, $child['before']);
                $newVariant = self::variantOf($new, $workshop, $child['after']);
                // Results follow a child who changes level group when the event is measured the same way.
                $source = self::follows($oldVariant, $newVariant) ? $oldVariant : $newVariant;
                $before = self::state($old, $oldVariant, $oldVariant, $list);
                $after = self::state($new, $newVariant, $source, $list);

                if ($after['active'] && !$after['finished'] && (!$before['active'] || $before['finished'])) {
                    $found[$workshop][self::TO_DO][] = $child['registration'];
                }
                if ($after['retained'] < $before['retained']) {
                    $found[$workshop][self::EXCLUDED][] = $child['registration'];
                }
                if ($after['retained'] > $before['retained']) {
                    $found[$workshop][self::RESTORED][] = $child['registration'];
                }
            }
        }

        $impact = [];
        foreach ($found as $workshop => $kinds) {
            foreach ([self::TO_DO, self::EXCLUDED, self::RESTORED] as $kind) {
                if (isset($kinds[$kind])) {
                    $impact[] = ['workshop' => (string) $workshop, 'kind' => $kind, 'children' => $kinds[$kind]];
                }
            }
        }

        return $impact;
    }

    /**
     * Number of different children in an impact.
     *
     * @param list<array{workshop: string, kind: string, children: list<Registration>}> $impact
     */
    public static function childCount(array $impact): int
    {
        $ids = [];
        foreach ($impact as $line) {
            foreach ($line['children'] as $child) {
                $ids[$child->id] = true;
            }
        }

        return \count($ids);
    }

    /**
     * Whether the attempts of a child moving from one variant to another follow them.
     */
    public static function follows(?Variant $from, ?Variant $to): bool
    {
        return $from !== null && $to !== null && $from->id() !== $to->id() && $from->measure === $to->measure;
    }

    public static function variantOf(CompetitionConfig $config, string $workshopId, string $level): ?Variant
    {
        try {
            $group = $config->groupOf($level);
        } catch (InvalidArgumentException) {
            return null;
        }
        foreach ($config->workshops as $workshop) {
            if ($workshop->id === $workshopId) {
                foreach ($workshop->variants as $variant) {
                    if ($variant->group === $group) {
                        return $variant;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param list<StoredAttempt> $attempts
     *
     * @return array{active: bool, retained: int, finished: bool}
     */
    private static function state(CompetitionConfig $config, ?Variant $variant, ?Variant $source, array $attempts): array
    {
        if ($variant === null || $source === null || $config->isDisabled($variant->id())) {
            return ['active' => false, 'retained' => 0, 'finished' => true];
        }
        $own = array_values(array_filter($attempts, static fn(StoredAttempt $a): bool => $a->variantId === $source->id()));
        $progress = new AttemptProgress($variant, $own);

        return ['active' => true, 'retained' => \count($progress->retained()), 'finished' => $progress->isFinished()];
    }
}
