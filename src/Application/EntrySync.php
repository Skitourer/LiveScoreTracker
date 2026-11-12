<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use LiveScoreTracker\Domain\Config\Measure;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Config\Workshop;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Results\CounterTally;
use LiveScoreTracker\Domain\Results\EntryChecks;
use LiveScoreTracker\Domain\Security\Phase;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use LiveScoreTracker\Infrastructure\Persistence\Author;
use LiveScoreTracker\Infrastructure\Persistence\LateEntry;
use LiveScoreTracker\Infrastructure\Persistence\LateEntryRepository;

/**
 * Entries sent by a phone, possibly long after they were made offline (spec § 8.8).
 *
 * Each entry carries an id made on the phone: an entry received twice is
 * stored once. The server checks everything again; its warnings are added to
 * those the referee confirmed, so an attempt that conflicts with another
 * phone's entry (e.g. one attempt too many) is kept and marked "to check".
 *
 * After the competition is closed, entries are kept aside ("late"), not
 * counted, until an admin or organizer accepts or refuses them.
 */
final class EntrySync
{
    public const SAVED = 'saved';
    public const REJECTED = 'rejected';
    public const CLOSED = 'closed';
    public const LATE = 'late';

    /** Entries made more than this long before they arrive keep the time they were made on the phone. */
    private const OFFLINE_DELAY = 60;

    private const WARNINGS = [
        EntryChecks::NOT_PRESENT, EntryChecks::ATTEMPTS_USED, EntryChecks::IMPLAUSIBLE,
        EntryChecks::ABOVE_MAX, EntryChecks::DUPLICATE, EntryChecks::TOO_MANY_SHOTS,
    ];

    public function __construct(
        private readonly EntryDesk $desk,
        private readonly AttemptRepository $attempts,
        private readonly LateEntryRepository $lateEntries,
    ) {}

    /**
     * @param list<mixed> $entries as sent by the phone
     *
     * @return list<array{id: string, status: string, reason?: string}>
     */
    public function apply(Workshop $workshop, array $entries, Phase $phase, Author $author, int $now): array
    {
        $results = [];
        foreach ($entries as $entry) {
            if (!\is_array($entry) || !\is_string($entry['id'] ?? null) || preg_match('/^[a-f0-9]{32}$/', $entry['id']) !== 1) {
                continue;
            }
            $id = $entry['id'];
            $outcome = match (true) {
                // Already stored (the answer to an earlier send was lost).
                $this->attempts->hasClient($id) => ['status' => self::SAVED],
                $phase === Phase::Running => $this->one($workshop, $id, $entry, $author, $now),
                $phase === Phase::Finished => $this->late($workshop, $id, $entry, $author),
                default => ['status' => self::CLOSED],
            };
            $results[] = ['id' => $id, ...$outcome];
        }

        return $results;
    }

    /**
     * Records a late entry as a normal one (accepted on the control board).
     *
     * @return array{status: string, reason?: string}
     */
    public function accept(Workshop $workshop, LateEntry $late, int $now): array
    {
        return $this->one($workshop, $late->clientId, ['bib' => $late->bib, ...$late->payload], $late->author, $now, true);
    }

    /**
     * Keeps an entry arrived after the closing aside, once the blocking checks pass.
     *
     * @param array<mixed> $entry
     *
     * @return array{status: string, reason?: string}
     */
    private function late(Workshop $workshop, string $id, array $entry, Author $author): array
    {
        $resolved = $this->validate($workshop, $entry);
        if (\is_string($resolved)) {
            return ['status' => self::REJECTED, 'reason' => $resolved];
        }
        $bib = \is_int($entry['bib'] ?? null) ? $entry['bib'] : 0;
        $variant = \is_string($entry['variant'] ?? null) ? $entry['variant'] : '';
        $this->lateEntries->store($id, $workshop->id, $bib, $variant, $entry, $author);

        return ['status' => self::LATE];
    }

    /**
     * Blocking checks (spec § 10.1): known bib, event of the child's level group, readable value.
     *
     * @param array<mixed> $entry
     *
     * @return array{0: Registration, 1: Variant}|string the child and the event, or the reason of the refusal
     */
    private function validate(Workshop $workshop, array $entry): array|string
    {
        $bib = $entry['bib'] ?? null;
        $resolved = \is_int($bib) ? $this->desk->resolve($workshop, $bib) : EntryDesk::UNKNOWN_BIB;
        if (\is_string($resolved)) {
            return $resolved;
        }
        if (($entry['variant'] ?? null) !== $resolved[1]->id()) {
            return EntryDesk::OTHER_GROUP;
        }
        $measured = $entry['measured'] ?? null;
        if ($measured !== null && (!\is_int($measured) || $measured < 0 || $measured > 9_999_999)) {
            return 'entry.error.invalid_value';
        }

        return $resolved;
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array{status: string, reason?: string}
     */
    private function one(Workshop $workshop, string $id, array $entry, Author $author, int $now, bool $late = false): array
    {
        $resolved = $this->validate($workshop, $entry);
        if (\is_string($resolved)) {
            return ['status' => self::REJECTED, 'reason' => $resolved];
        }
        [$child, $variant] = $resolved;
        $measured = \is_int($entry['measured'] ?? null) ? $entry['measured'] : null;
        $details = [];
        $tally = null;
        $sent = \is_array($entry['details'] ?? null) ? $entry['details'] : [];
        if ($measured !== null && $variant->measure === Measure::Counter && \is_array($sent['actions'] ?? null)) {
            // Counter: the total is recomputed from the actions.
            $tally = $this->tally($variant, $sent);
            $measured = $tally->total($variant);
            $details = $tally->toDetails();
        } elseif ($measured !== null && \in_array($sent['source'] ?? null, ['timer', 'timer_edited'], true)) {
            $details = ['source' => $sent['source']];
        }
        $at = $entry['at'] ?? null;
        if (\is_int($at) && $now - intdiv($at, 1000) > self::OFFLINE_DELAY) {
            $details['entered_on_phone'] = gmdate('Y-m-d H:i:s', intdiv($at, 1000));
        }
        if ($late) {
            $details['late'] = true;
        }

        $modifiers = $measured === null ? [] : $this->modifiers($variant, $entry['modifiers'] ?? null);
        $total = 0;
        foreach ($variant->modifiers as $modifier) {
            $total += $modifier->value * ($modifiers[$modifier->id] ?? 0);
        }

        $sentWarnings = \is_array($entry['warnings'] ?? null) ? array_filter($entry['warnings'], \is_string(...)) : [];
        $confirmed = array_intersect(self::WARNINGS, $sentWarnings);
        $checked = EntryChecks::check($this->desk->progress($child, $variant), $measured, $child->isPresent(), true, $now);
        if ($tally !== null) {
            $checked = [...$checked, ...$tally->warnings($variant)];
        }
        $warnings = array_values(array_unique([...$confirmed, ...$checked]));

        $this->attempts->record($id, $child->id, (int) $child->bib, $variant->id(), $measured, $modifiers, $total, $warnings, $author, $details);

        return ['status' => self::SAVED];
    }

    /**
     * @return array<string, int>
     */
    private function modifiers(Variant $variant, mixed $sent): array
    {
        $applied = [];
        foreach ($variant->modifiers as $modifier) {
            $count = \is_array($sent) && \is_int($sent[$modifier->id] ?? null) ? $sent[$modifier->id] : 0;
            $count = max(0, min($count, $modifier->repeatable ? 99 : 1));
            if ($count > 0) {
                $applied[$modifier->id] = $count;
            }
        }

        return $applied;
    }

    /**
     * @param array<mixed> $sent
     */
    private function tally(Variant $variant, array $sent): CounterTally
    {
        $actions = \is_array($sent['actions'] ?? null) ? $sent['actions'] : [];
        $counts = [];
        foreach ($variant->actions as $action) {
            $count = \is_int($actions[$action->id] ?? null) ? min(max(0, $actions[$action->id]), CounterTally::MAX_COUNT) : 0;
            if ($count > 0) {
                $counts[$action->id] = $count;
            }
        }
        $missed = \is_int($sent['missed'] ?? null) ? min(max(0, $sent['missed']), CounterTally::MAX_COUNT) : 0;

        return new CounterTally($counts, $missed);
    }
}
