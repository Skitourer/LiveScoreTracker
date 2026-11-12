<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Entry;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Results\CounterTally;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reading the fields of the entry forms (spec § 8.2, § 8.4, § 8.5), shared by
 * the one-child screen and the race screen. A prefix tells which child of the
 * race the field belongs to ("1_", "2_"); the one-child screen uses none.
 */
final class EntryFields
{
    /**
     * A new entry id, made with the form: sending it twice records it once (spec § 8.8).
     */
    public static function newEntryId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * The entry id sent back with the form, or a new one if it is missing or malformed.
     */
    public static function entryId(ServerRequestInterface $request, string $name): string
    {
        $value = Responder::field($request, $name);

        return preg_match('/^[a-f0-9]{32}$/', $value) === 1 ? $value : self::newEntryId();
    }

    /**
     * @return array<string, int> modifier id => times applied (only those applied)
     */
    public static function modifiers(ServerRequestInterface $request, Variant $variant, string $prefix = ''): array
    {
        $applied = [];
        foreach ($variant->modifiers as $modifier) {
            $raw = Responder::field($request, $prefix . 'mod_' . $modifier->id);
            $count = $modifier->repeatable ? (ctype_digit($raw) ? min((int) $raw, 99) : 0) : ($raw === '1' ? 1 : 0);
            if ($count > 0) {
                $applied[$modifier->id] = $count;
            }
        }

        return $applied;
    }

    /**
     * @param array<string, int> $applied
     */
    public static function modifierTotal(Variant $variant, array $applied): int
    {
        $total = 0;
        foreach ($variant->modifiers as $modifier) {
            $total += $modifier->value * ($applied[$modifier->id] ?? 0);
        }

        return $total;
    }

    /**
     * Counts of a counter event, as sent by the counter screen (spec § 8.5).
     */
    public static function tally(ServerRequestInterface $request, Variant $variant): CounterTally
    {
        $counts = [];
        foreach ($variant->actions as $action) {
            $count = self::count($request, 'act_' . $action->id);
            if ($count > 0) {
                $counts[$action->id] = $count;
            }
        }

        return new CounterTally($counts, self::count($request, 'missed'));
    }

    /**
     * How a time was obtained (spec § 8.4): $timed is the time measured by the
     * phone's timer, in hundredths, sent along with the (editable) value.
     *
     * @return array<string, string>
     */
    public static function timerDetails(string $timed, ?int $measured): array
    {
        if ($measured === null || !ctype_digit($timed)) {
            return [];
        }

        return ['source' => (int) $timed === $measured ? 'timer' : 'timer_edited'];
    }

    private static function count(ServerRequestInterface $request, string $name): int
    {
        $raw = Responder::field($request, $name);

        return ctype_digit($raw) ? min((int) $raw, CounterTally::MAX_COUNT) : 0;
    }
}
