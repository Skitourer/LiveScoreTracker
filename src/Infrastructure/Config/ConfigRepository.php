<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Config;

use Internal\Toml\Toml;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Config\ConfigParser;
use LiveScoreTracker\Domain\Config\InvalidConfig;
use Throwable;

/**
 * Loads `competition.toml` (spec § 12.1).
 *
 * The installation's own file lives in the private data folder. Until it
 * exists, the fictitious example shipped with the code is used, so a fresh
 * installation (or the test site) works out of the box.
 */
final class ConfigRepository
{
    public const FILE_NAME = 'competition.toml';

    private ?CompetitionConfig $config = null;

    public function __construct(
        private readonly string $dataDir,
        private readonly string $exampleDir,
    ) {}

    /**
     * @throws InvalidConfig
     */
    public function get(): CompetitionConfig
    {
        return $this->config ??= self::loadFile($this->activePath());
    }

    /**
     * Path of the file in use: the installation's own file, or the example.
     */
    public function activePath(): string
    {
        $own = $this->dataDir . '/' . self::FILE_NAME;

        return is_file($own) ? $own : $this->exampleDir . '/' . self::FILE_NAME;
    }

    public function usesExample(): bool
    {
        return !is_file($this->dataDir . '/' . self::FILE_NAME);
    }

    /**
     * @throws InvalidConfig
     */
    public static function loadFile(string $path): CompetitionConfig
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new InvalidConfig([\sprintf('%s: file not found or not readable', basename($path))]);
        }

        try {
            $data = Toml::parseToArray($content);
        } catch (Throwable $exception) {
            throw new InvalidConfig([\sprintf('%s: TOML syntax error: %s', basename($path), $exception->getMessage())]);
        }

        return ConfigParser::parse($data);
    }
}
