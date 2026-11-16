<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Help;

use League\CommonMark\CommonMarkConverter;
use LiveScoreTracker\Domain\Config\Workshop;
use RuntimeException;

/**
 * Workshop help pages (spec § 12.6): one Markdown file per workshop and language,
 * taken from the installation's data folder, else from the fictitious examples.
 * The public pages (home presentation, rules) work the same way.
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
        $file = self::helpFile($workshop);
        if ($file === null) {
            return null;
        }
        foreach ([$this->dataDir . '/help', $this->exampleDir . '/help'] as $base) {
            foreach (array_unique([$locale, $defaultLocale, 'en']) as $lang) {
                $path = "{$base}/{$lang}/{$file}";
                if (is_file($path)) {
                    $markdown = file_get_contents($path);

                    return $markdown === false ? null : $this->convert($markdown);
                }
            }
        }

        return null;
    }

    /**
     * HTML of a public page of the installation ("home", "rules"), from
     * pages/<lang>/<name>.md, or null when there is none (spec § 13.0).
     */
    public function page(string $name, string $locale, string $defaultLocale): ?string
    {
        if (preg_match('/^[a-z0-9_-]+$/', $name) !== 1) {
            return null;
        }
        foreach ([$this->dataDir . '/pages', $this->exampleDir . '/pages'] as $base) {
            foreach (array_unique([$locale, $defaultLocale, 'en']) as $lang) {
                $path = "{$base}/{$lang}/{$name}.md";
                if (is_file($path)) {
                    $markdown = file_get_contents($path);

                    return $markdown === false ? null : $this->convert($markdown);
                }
            }
        }

        return null;
    }

    /**
     * Markdown file of a workshop help: the `help` key, or `<id>.md` once written from the screen.
     */
    public static function helpFile(Workshop $workshop): ?string
    {
        $file = $workshop->help ?? $workshop->id . '.md';

        return preg_match('/^[a-z0-9_-]+\.md$/', $file) === 1 ? $file : null;
    }

    // ------------------------------------------------- editing (spec § 12.3)

    /**
     * Text written for this page and language in the data folder, or null.
     *
     */
    public function written(string $kind, string $file, string $lang): ?string
    {
        $path = $this->path($this->dataDir, $kind, $file, $lang);
        $text = $path === null || !is_file($path) ? false : file_get_contents($path);

        return $text === false ? null : $text;
    }

    /**
     * Text given with the application (the fictitious example), or null.
     *
     */
    public function shipped(string $kind, string $file, string $lang): ?string
    {
        $path = $this->path($this->exampleDir, $kind, $file, $lang);
        $text = $path === null || !is_file($path) ? false : file_get_contents($path);

        return $text === false ? null : $text;
    }

    /**
     * Writes a page in the data folder; an empty text removes it (back to the default text).
     *
     */
    public function write(string $kind, string $file, string $lang, string $markdown): void
    {
        $path = $this->path($this->dataDir, $kind, $file, $lang) ?? throw new RuntimeException('Invalid page name.');
        if (trim($markdown) === '') {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true)) {
            throw new RuntimeException('Cannot create ' . $dir);
        }
        if (@file_put_contents($path, str_replace("\r\n", "\n", $markdown)) === false) {
            throw new RuntimeException('Cannot write ' . $path);
        }
    }

    private function path(string $base, string $kind, string $file, string $lang): ?string
    {
        if (!\in_array($kind, ['pages', 'help'], true) || preg_match('/^[a-z0-9_-]+\.md$/', $file) !== 1 || preg_match('/^[a-z]{2}$/', $lang) !== 1) {
            return null;
        }

        return "{$base}/{$kind}/{$lang}/{$file}";
    }

    public function convert(string $markdown): string
    {
        $converter = new CommonMarkConverter(['html_input' => 'escape', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]);

        return $converter->convert($markdown)->getContent();
    }
}
