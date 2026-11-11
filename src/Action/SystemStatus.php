<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Infrastructure\Database\Migrator;
use LiveScoreTracker\Kernel;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks that the database is reachable and the schema is up to date.
 *
 * The database connection is resolved lazily so that a broken connection
 * shows up as a status instead of crashing the page.
 */
final class SystemStatus
{
    public function __construct(private readonly ContainerInterface $container) {}

    /**
     * @return array{database: bool, pendingMigrations: int|null, error: string|null}
     */
    public function check(): array
    {
        try {
            $migrator = Kernel::service($this->container, Migrator::class);

            return [
                'database' => true,
                'pendingMigrations' => \count($migrator->pending()),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            // The message may contain server details: callers decide whether to show it.
            return ['database' => false, 'pendingMigrations' => null, 'error' => $exception->getMessage()];
        }
    }
}
