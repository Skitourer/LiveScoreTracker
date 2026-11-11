<?php

declare(strict_types=1);

/*
 * Command-line tools for development and servers with shell access.
 *
 * Usage: php bin/console.php <command>
 *   migrate   apply pending database migrations
 *   status    show applied and pending migrations
 */

use LiveScoreTracker\Infrastructure\Database\ConnectionFactory;
use LiveScoreTracker\Infrastructure\Database\Migrator;
use LiveScoreTracker\Kernel;

$rootDir = dirname(__DIR__);

require $rootDir . '/vendor/autoload.php';

$settings = Kernel::loadSettings($rootDir);
$migrator = new Migrator(ConnectionFactory::create($settings), $rootDir . '/migrations');
$command = $argv[1] ?? 'help';

switch ($command) {
    case 'migrate':
        $applied = $migrator->migrate();
        echo $applied === [] ? "Nothing to migrate.\n" : 'Applied: ' . implode(', ', $applied) . "\n";
        break;

    case 'status':
        echo 'Applied: ' . (implode(', ', $migrator->appliedNames()) ?: 'none') . "\n";
        echo 'Pending: ' . (implode(', ', array_keys($migrator->pending())) ?: 'none') . "\n";
        break;

    default:
        echo "Usage: php bin/console.php <migrate|status>\n";
        exit($command === 'help' ? 0 : 1);
}
