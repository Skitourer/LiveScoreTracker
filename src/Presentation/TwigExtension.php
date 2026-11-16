<?php

declare(strict_types=1);

namespace LiveScoreTracker\Presentation;

use Closure;
use LiveScoreTracker\Domain\Config\Category;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Config\Theme;
use LiveScoreTracker\Domain\Config\TieBreaker;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Http\Middleware\CsrfMiddleware;
use LiveScoreTracker\Http\RequestContext;
use LiveScoreTracker\Infrastructure\Config\Branding;
use LiveScoreTracker\Infrastructure\Help\MediaStore;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
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
        /** @var (Closure(): ?CompetitionConfig)|null */
        private readonly ?Closure $config = null,
        private readonly ?Branding $branding = null,
        private readonly ?MediaStore $media = null,
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
            new TwigFunction('long_date', $this->longDate(...)),
            new TwigFunction('referee_name', $this->context->refereeName(...)),
            new TwigFunction('theme', $this->theme(...)),
            new TwigFunction('contacts', fn(): array => $this->competition()->contacts ?? []),
            new TwigFunction('logo_data_uri', fn(): ?string => $this->branding?->logoDataUri($this->theme())),
            new TwigFunction('child_name', fn(Registration $child): string => $child->nameFor($this->theme()->refereeNames)),
            new TwigFunction('participant_name', fn(Registration $child): string => $child->nameFor($this->theme()->participantNames)),
            new TwigFunction('flashes', fn(): array => $this->context->hasSession() ? $this->context->session()->takeFlashes() : []),
        ];
    }

    public function getFilters(): array
    {
        return [
            // Images of the edited pages are written "image:plan.png" (spec § 12.3).
            new TwigFilter('media', static fn(string $html, string $basePath): string => str_replace(['src="image:', 'href="image:'], ['src="' . $basePath . '/media/', 'href="' . $basePath . '/media/'], $html)),
            // PDF documents: the images are embedded (Dompdf does not fetch URLs).
            new TwigFilter('media_pdf', $this->mediaPdf(...)),
        ];
    }

    private function mediaPdf(string $html): string
    {
        return (string) preg_replace_callback('/src="image:([a-z0-9-]+\\.(?:png|jpg|webp))"/', function (array $m): string {
            $path = $this->media?->path($m[1]);
            $content = $path === null ? false : file_get_contents($path);

            return $content === false ? 'src=""' : 'src="data:' . Branding::mimeType($path) . ';base64,' . base64_encode($content) . '"';
        }, $html);
    }

    public function theme(): Theme
    {
        return $this->competition()->theme ?? new Theme();
    }

    private function competition(): ?CompetitionConfig
    {
        return $this->config === null ? null : ($this->config)();
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
    /**
     * "dimanche 6 juin 2027" (with the time: "… à 08:00") in the page's language,
     * in the date's own time zone; dd/mm/yyyy when the intl extension is missing.
     */
    public function longDate(?\DateTimeImmutable $date, bool $withTime = false): string
    {
        if ($date === null) {
            return '';
        }
        if (!class_exists(\IntlDateFormatter::class)) {
            return $date->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
        }
        $formatter = new \IntlDateFormatter(
            $this->translator->locale(),
            \IntlDateFormatter::FULL,
            $withTime ? \IntlDateFormatter::SHORT : \IntlDateFormatter::NONE,
            $date->getTimezone(),
        );
        $text = $formatter->format($date);

        return \is_string($text) ? $text : $date->format('d/m/Y');
    }

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
