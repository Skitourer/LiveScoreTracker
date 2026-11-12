<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Config\Workshop;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use LiveScoreTracker\Presentation\ResultFormatter;

/**
 * Everything a phone needs to enter results at a workshop without network
 * (spec § 8.8): the events, the children with a bib (bib, first name and
 * initial, level, presence), their current attempts on this workshop, and the
 * texts of the entry screens. The phone keeps it and refreshes it regularly.
 */
final class EntryPack
{
    /** Texts used by the entry screens (placeholders such as {number} are filled on the phone). */
    private const TEXTS = [
        'entry.bib', 'entry.race_bib', 'entry.optional', 'entry.open', 'entry.mode_timer', 'entry.mode_manual',
        'entry.see_bib', 'entry.other_bib', 'entry.other_race', 'entry.see_attempts', 'entry.not_checked_in',
        'entry.different_events', 'entry.time', 'entry.result', 'entry.did_not_finish', 'entry.attempt_number',
        'entry.attempts_finished', 'entry.best', 'entry.save', 'entry.save_anyway', 'entry.foul_button', 'entry.foul',
        'entry.confirm_hint', 'entry.lenient_hint', 'entry.mark_present', 'entry.confirm_present', 'entry.recorded',
        'entry.recorded_foul', 'entry.race',
        'entry.error.unknown_bib', 'entry.error.other_group', 'entry.error.not_registered', 'entry.error.same_bib',
        'entry.error.no_bib', 'entry.error.invalid_value', 'entry.error.not_running', 'entry.error.finished',
        'entry.warning.not_present', 'entry.warning.attempts_used', 'entry.warning.implausible', 'entry.warning.above_max',
        'entry.warning.duplicate', 'entry.warning.too_many_shots',
        'entry.hint.time_sscc', 'entry.hint.time_msscc', 'entry.hint.distance', 'entry.hint.score', 'entry.hint.counter',
        'entry.timer.start', 'entry.timer.finish', 'entry.timer.place_first', 'entry.timer.place_other', 'entry.timer.dnf',
        'entry.timer.cancel', 'entry.timer.confirm_cancel', 'entry.timer.confirm_leave', 'entry.timer.swap',
        'entry.counter.start', 'entry.counter.shot', 'entry.counter.missed', 'entry.counter.undo', 'entry.counter.stop',
        'entry.counter.summary', 'entry.counter.limit_shots', 'entry.counter.limit_duration', 'entry.counter.confirm_leave',
        'entry.counter.direct', 'entry.counter.direct_total',
        'entry.offline.online', 'entry.offline.offline', 'entry.offline.pending', 'entry.offline.send_now',
        'entry.offline.sending', 'entry.offline.login_again', 'entry.offline.no_pack', 'entry.offline.queue_title',
        'entry.offline.status_pending', 'entry.offline.status_rejected', 'entry.offline.status_closed',
        'entry.offline.remove', 'entry.offline.confirm_remove', 'entry.offline.needs_network', 'entry.offline.updated_at',
        'unit.seconds', 'unit.minutes_seconds', 'unit.metres', 'unit.points', 'format.decimal_separator',
    ];

    public function __construct(
        private readonly CompetitionConfig $config,
        private readonly EntryDesk $desk,
        private readonly RegistrationRepository $registrations,
        private readonly AttemptRepository $attempts,
        private readonly Translator $translator,
        private readonly ResultFormatter $formatter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Workshop $workshop, string $phase, string $csrf): array
    {
        $variants = [];
        foreach ($workshop->variants as $variant) {
            $variants[$variant->id()] = $this->variant($variant);
        }
        $levels = [];
        foreach ($this->config->levels as $level) {
            $variant = $this->desk->variantFor($workshop, $level);
            $levels[$level] = $variant?->id();
        }

        $byChild = $this->attempts->currentByChild(array_keys($variants));
        $children = [];
        foreach ($this->registrations->search(['statuses' => [RegistrationStatus::Registered]]) as $child) {
            if ($child->bib === null) {
                continue;
            }
            $attempts = [];
            foreach ($byChild[$child->id] ?? [] as $attempt) {
                $attempts[] = [
                    'm' => $attempt->measured,
                    'r' => $attempt->result(),
                    'at' => (int) strtotime($attempt->enteredAt . ' UTC'),
                ];
            }
            $children[] = [
                'id' => $child->id,
                'bib' => $child->bib,
                'name' => $child->publicName(),
                'level' => $child->child->level,
                'levelLabel' => $this->translator->label('level.' . $child->child->level),
                'present' => $child->isPresent(),
                'attempts' => $attempts,
            ];
        }

        $texts = [];
        foreach (self::TEXTS as $key) {
            $texts[$key] = $this->translator->trans($key);
        }

        return [
            'workshop' => [
                'id' => $workshop->id,
                'name' => $this->translator->trans('workshop.' . $workshop->id . '.name'),
                'timed' => $this->desk->isTimed($workshop),
                'bibsPerRace' => $this->desk->bibsPerRace($workshop),
            ],
            'phase' => $phase,
            'csrf' => $csrf,
            'generatedAt' => time(),
            'variants' => $variants,
            'levels' => $levels,
            'children' => $children,
            'texts' => $texts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function variant(Variant $variant): array
    {
        $signed = fn(int $value): string => ($value > 0 ? '+' : '') . $this->formatter->format($value, $variant);

        return [
            'id' => $variant->id(),
            'label' => $this->eventLabel($variant),
            'measure' => $variant->measure->value,
            'timeFormat' => $variant->timeFormat?->value,
            'better' => $variant->better->value,
            'attempts' => $variant->attempts,
            'foulMode' => $variant->foulMode->value,
            'plausible' => $variant->plausibleRange,
            'maxValue' => $variant->maxValue,
            'limit' => $variant->limit === null ? null : ['type' => $variant->limit->type->value, 'value' => $variant->limit->value],
            'modifiers' => array_map(fn($m): array => [
                'id' => $m->id,
                'label' => $this->translator->trans('modifier.' . $m->id) . ' (' . $signed($m->value) . ')',
                'value' => $m->value,
                'repeatable' => $m->repeatable,
            ], $variant->modifiers),
            'actions' => array_map(fn($a): array => [
                'id' => $a->id,
                'label' => $this->translator->trans('action.' . $a->id),
                'points' => $a->points,
            ], $variant->actions),
        ];
    }

    /**
     * Same label as on the server pages: "Sprint 40 m".
     */
    private function eventLabel(Variant $variant): string
    {
        $name = $this->translator->trans("workshop.{$variant->workshopId}.name");

        return $variant->label === null ? $name : $name . ' ' . $this->translator->label($variant->label);
    }
}
