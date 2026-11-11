<?php

declare(strict_types=1);

namespace LiveScoreTracker\Presentation;

use LiveScoreTracker\Domain\Config\Measure;
use LiveScoreTracker\Domain\Config\TimeFormat;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Infrastructure\I18n\Translator;

/**
 * Displays stored values with their unit (spec § 7.3): "12,25 s", "1 min 23,24 s", "12,34 m", "22 pts".
 */
final class ResultFormatter
{
    public function __construct(private readonly Translator $translator) {}

    public function format(int $value, Variant $variant): string
    {
        return match ($variant->measure) {
            Measure::Time => $this->time($value, $variant->timeFormat ?? TimeFormat::Seconds),
            Measure::Distance => $this->translator->trans('unit.metres', ['value' => $this->hundredths($value)]),
            Measure::Score, Measure::Counter => $this->translator->trans('unit.points', ['value' => $value]),
        };
    }

    /**
     * @param int $value hundredths of a second
     */
    public function time(int $value, TimeFormat $format): string
    {
        if ($format === TimeFormat::Minutes && $value >= 6000) {
            $seconds = $this->hundredths($value % 6000);

            return $this->translator->trans('unit.minutes_seconds', [
                'minutes' => intdiv($value, 6000),
                'seconds' => str_pad($seconds, 5, '0', STR_PAD_LEFT),
            ]);
        }

        return $this->translator->trans('unit.seconds', ['value' => $this->hundredths($value)]);
    }

    /**
     * 1225 → "12,25" (with the locale's decimal separator).
     */
    public function hundredths(int $value): string
    {
        $separator = $this->translator->has('format.decimal_separator') ? $this->translator->trans('format.decimal_separator') : '.';
        $sign = $value < 0 ? '-' : '';
        $value = abs($value);

        return $sign . intdiv($value, 100) . $separator . str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT);
    }
}
