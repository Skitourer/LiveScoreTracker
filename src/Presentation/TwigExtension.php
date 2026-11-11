<?php

declare(strict_types=1);

namespace LiveScoreTracker\Presentation;

use LiveScoreTracker\Domain\Config\Category;
use LiveScoreTracker\Domain\Config\TieBreaker;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Http\Middleware\CsrfMiddleware;
use LiveScoreTracker\Http\RequestContext;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Template helpers: translations, display of configuration items, and the
 * current visitor (account, permissions, CSRF token, flash messages).
 * Templates never contain texts directly (spec § 12.4).
 */
final class TwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly Translator $translator,
        private readonly ResultFormatter $formatter,
        private readonly RequestContext $context,
        private readonly string $timezone = 'UTC',
    ) {}

    public function getGlobals(): array
    {
        return ['lang' => $this->translator->locale()];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', $this->translator->trans(...)),
            new TwigFunction('label', $this->translator->label(...)),
            new TwigFunction('result', $this->formatter->format(...)),
            new TwigFunction('category_label', $this->categoryLabel(...)),
            new TwigFunction('event_label', $this->eventLabel(...)),
            new TwigFunction('tie_breaker', $this->tieBreaker(...)),
            new TwigFunction('csrf_field', $this->csrfField(...), ['is_safe' => ['html']]),
            new TwigFunction('current_user', fn() => $this->context->isAuthenticated() ? $this->context->user : null),
            new TwigFunction('logged_in', fn(): bool => $this->context->user !== null),
            new TwigFunction('can', $this->context->can(...)),
            new TwigFunction('phase', fn(): string => $this->context->phase->value),
            new TwigFunction('local_time', $this->localTime(...)),
            new TwigFunction('flashes', fn(): array => $this->context->hasSession() ? $this->context->session()->takeFlashes() : []),
        ];
    }

    public function categoryLabel(Category $category): string
    {
        return $this->translator->trans('category.label', [
            'gender' => $this->translator->trans("gender.{$category->gender}.many"),
            'level' => $this->translator->label("level.{$category->level}"),
        ]);
    }

    /**
     * Workshop name, followed by the variant label when there is one: "Sprint 30 m".
     */
    public function eventLabel(Variant $variant): string
    {
        $name = $this->translator->trans("workshop.{$variant->workshopId}.name");

        return $variant->label === null ? $name : $name . ' ' . $this->translator->label($variant->label);
    }

    public function tieBreaker(TieBreaker $criterion): string
    {
        return $this->translator->trans('tie_breaker.' . $criterion->value);
    }

    /**
     * Stored UTC time ('YYYY-MM-DD HH:MM:SS') shown in the installation's time zone: "11/11/2026 17:40".
     */
    public function localTime(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '–';
        }
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone($this->timezone))
                ->format('d/m/Y H:i');
        } catch (\Exception) {
            return $utc;
        }
    }

    public function csrfField(): string
    {
        $token = $this->context->hasSession() ? $this->context->session()->csrfToken() : '';

        return '<input type="hidden" name="' . CsrfMiddleware::FIELD . '" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
    }
}
