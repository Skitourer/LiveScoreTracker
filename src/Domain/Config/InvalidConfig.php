<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

use RuntimeException;

/**
 * The configuration file has errors; all of them are listed, each with its location.
 */
final class InvalidConfig extends RuntimeException
{
    /** @var list<string> English texts, e.g. 'workshops[2].variants[0].measure: must be one of time, distance, score, counter' */
    public readonly array $errors;

    /**
     * @param list<ConfigError> $issues
     */
    public function __construct(public readonly array $issues)
    {
        $this->errors = array_map(static fn(ConfigError $e): string => $e->english(), $issues);
        parent::__construct("Invalid configuration:\n- " . implode("\n- ", $this->errors));
    }
}
