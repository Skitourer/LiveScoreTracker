<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Config;

use DateTimeImmutable;
use Internal\Toml\Toml;
use LiveScoreTracker\Domain\Config\ConfigError;
use LiveScoreTracker\Domain\Config\ConfigParser;
use LiveScoreTracker\Domain\Config\InvalidConfig;
use RuntimeException;
use Throwable;

/**
 * Reads and writes `competition.toml` for the configuration screen (spec § 12.3).
 *
 * Every save is validated first. The previous version of the installation's
 * own file is kept in `config-history/` (the last {@see self::HISTORY_SIZE}),
 * so the admin can go back to it. The first save copies the example into the
 * data folder.
 */
final class ConfigStore
{
    public const HISTORY_SIZE = 20;
    private const HISTORY_DIR = 'config-history';
    private const HISTORY_PATTERN = '/^competition-\d{8}-\d{6}(-\d+)?\.toml$/';

    public function __construct(
        private readonly ConfigRepository $repository,
        private readonly string $dataDir,
    ) {}

    public function usesExample(): bool
    {
        return $this->repository->usesExample();
    }

    /**
     * Text of the file in use.
     */
    public function toml(): string
    {
        $content = @file_get_contents($this->repository->activePath());

        return $content === false ? '' : $content;
    }

    /**
     * Decoded file in use (what the forms edit).
     *
     * @return array<mixed>
     */
    public function data(): array
    {
        try {
            return Toml::parseToArray($this->toml());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Errors of a TOML text, empty when it is a valid configuration.
     *
     * @return list<ConfigError>
     */
    public static function check(string $toml): array
    {
        try {
            ConfigParser::parse(Toml::parseToArray($toml));
        } catch (InvalidConfig $exception) {
            return $exception->issues;
        } catch (Throwable $exception) {
            return [new ConfigError('', 'toml_syntax', ['detail' => $exception->getMessage()])];
        }

        return [];
    }

    /**
     * Saves decoded data (from the forms); the file is rewritten, so its comments are lost.
     *
     * @param array<mixed> $data
     *
     * @throws InvalidConfig
     */
    public function save(array $data): void
    {
        $this->saveToml(self::encode($data));
    }

    /**
     * Saves a TOML text as is (advanced edit, import, restore).
     *
     * @throws InvalidConfig
     */
    public function saveToml(string $toml): void
    {
        $errors = self::check($toml);
        if ($errors !== []) {
            throw new InvalidConfig($errors);
        }
        $this->archive();
        $path = $this->dataDir . '/' . ConfigRepository::FILE_NAME;
        $temp = $path . '.tmp';
        if (@file_put_contents($temp, $toml) === false || !@rename($temp, $path)) {
            throw new RuntimeException('Cannot write ' . ConfigRepository::FILE_NAME);
        }
    }

    /**
     * @param array<mixed> $data
     */
    public static function encode(array $data): string
    {
        $toml = (string) Toml::encode($data);
        // The encoder writes single-line texts as literal strings ('…'), which
        // cannot hold an apostrophe ("Nom de l'enfant"): use a basic string instead.
        foreach (self::apostropheTexts($data) as $text) {
            $basic = '"' . strtr($text, ['\\' => '\\\\', '"' => '\\"']) . '"';
            $toml = str_replace("'" . $text . "'", $basic, $toml);
        }

        return "# Fichier écrit par l'écran de configuration.\n\n" . $toml;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function apostropheTexts(array $data): array
    {
        $texts = [];
        array_walk_recursive($data, static function (mixed $value) use (&$texts): void {
            if (\is_string($value) && str_contains($value, "'") && !str_contains($value, "\n")) {
                $texts[] = $value;
            }
        });

        return array_values(array_unique($texts));
    }

    /**
     * Previous versions, newest first.
     *
     * @return list<array{file: string, saved_at: DateTimeImmutable}>
     */
    public function history(): array
    {
        $files = glob($this->historyDir() . '/competition-*.toml') ?: [];
        rsort($files);
        $list = [];
        foreach ($files as $file) {
            $name = basename($file);
            $date = DateTimeImmutable::createFromFormat('Ymd-His', substr($name, 12, 15));
            if (preg_match(self::HISTORY_PATTERN, $name) === 1 && $date !== false) {
                $list[] = ['file' => $name, 'saved_at' => $date];
            }
        }

        return $list;
    }

    /**
     * Text of a previous version, or null when the name is unknown.
     */
    public function historyToml(string $file): ?string
    {
        if (preg_match(self::HISTORY_PATTERN, $file) !== 1) {
            return null;
        }
        $content = @file_get_contents($this->historyDir() . '/' . $file);

        return $content === false ? null : $content;
    }

    private function archive(): void
    {
        $own = $this->dataDir . '/' . ConfigRepository::FILE_NAME;
        if (!is_file($own)) {
            return;
        }
        $dir = $this->historyDir();
        if (!is_dir($dir) && !@mkdir($dir, 0o770, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create ' . self::HISTORY_DIR);
        }
        $base = $dir . '/competition-' . date('Ymd-His');
        $target = $base . '.toml';
        for ($i = 2; is_file($target); ++$i) {
            $target = $base . '-' . $i . '.toml';
        }
        copy($own, $target);

        $files = glob($dir . '/competition-*.toml') ?: [];
        rsort($files);
        foreach (\array_slice($files, self::HISTORY_SIZE) as $old) {
            @unlink($old);
        }
    }

    private function historyDir(): string
    {
        return $this->dataDir . '/' . self::HISTORY_DIR;
    }
}
