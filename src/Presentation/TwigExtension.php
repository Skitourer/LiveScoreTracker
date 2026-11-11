<?php

declare(strict_types=1);

namespace LiveScoreTracker\Presentation;

use LiveScoreTracker\Domain\Config\Category;
use LiveScoreTracker\Domain\Config\TieBreaker;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Template helpers: translations and display of configuration items.
 * Templates never contain texts directly (spec § 12.4).
 */
final class TwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly Translator $translator,
        private readonly ResultFormatter $formatter,
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
}
