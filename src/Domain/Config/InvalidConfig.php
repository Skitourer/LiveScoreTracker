<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

use RuntimeException;

/**
 * The configuration is not valid; holds every problem found, with its location.
 */
final class InvalidConfig extends RuntimeException
{
    /**
     * @param list<string> $errors e.g. 'workshops[2].variants[0].measure: must be one of time, distance, score, counter'
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct("Invalid configuration:\n- " . implode("\n- ", $errors));
    }
}
