<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Help;

use League\CommonMark\CommonMarkConverter;
use LiveScoreTracker\Domain\Config\Workshop;

/**
 * Workshop help pages (spec § 12.6): one Markdown file per workshop and language,
 * taken from the installation's data folder, else from the fictitious examples.
 *
 * The Markdown is converted safely: raw HTML is escaped and unsafe links
 * (javascript:, data:…) are dropped.
 */
final class HelpPages
{
    public function __construct(
        private readonly string $dataDir,
        private readonly string $exampleDir,
    ) {}

    /**
     * HTML of the help page, or null when the workshop has none.
     */
    public function html(Workshop $workshop, string $locale, string $defaultLocale): ?string
    {
        $file = $workshop->help;
        if ($file === null || preg_match('/^[a-z0-9_-]+\.md$/', $file) !== 1) {
            return null;
        }
        foreach ([$this->dataDir . '/help', $this->exampleDir . '/help'] as $base) {
            foreach (array_unique([$locale, $defaultLocale]) as $lang) {
                $path = "{$base}/{$lang}/{$file}";
                if (is_file($path)) {
                    $markdown = file_get_contents($path);

                    return $markdown === false ? null : $this->convert($markdown);
                }
            }
        }

        return null;
    }

    public function convert(string $markdown): string
    {
        $converter = new CommonMarkConverter(['html_input' => 'escape', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]);

        return $converter->convert($markdown)->getContent();
    }
}
