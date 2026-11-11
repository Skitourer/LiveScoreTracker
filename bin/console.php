<?php

declare(strict_types=1);

/*
 * Command-line tools for development and servers with shell access.
 *
 * Usage: php bin/console.php <command>
 *   migrate   apply pending database migrations
 *   status    show applied and pending migrations
 *   config:check [file]   validate a competition.toml (default: the one in use)
 */

use LiveScoreTracker\Domain\Config\InvalidConfig;
use LiveScoreTracker\Infrastructure\Config\ConfigRepository;
use LiveScoreTracker\Infrastructure\Database\ConnectionFactory;
use LiveScoreTracker\Infrastructure\Database\Migrator;
use LiveScoreTracker\Kernel;

$rootDir = dirname(__DIR__);

require $rootDir . '/vendor/autoload.php';

$settings = Kernel::loadSettings($rootDir);
$command = $argv[1] ?? 'help';

if ($command === 'config:check') {
    $repository = new ConfigRepository($settings->dataDir, $rootDir . '/config.example');
    $file = $argv[2] ?? $repository->activePath();

    try {
        $config = ConfigRepository::loadFile($file);
    } catch (InvalidConfig $exception) {
        fwrite(STDERR, $file . "\n- " . implode("\n- ", $exception->errors) . "\n");
        exit(1);
    }
    printf("%s: valid (%d events, %d categories)\n", $file, count($config->variants()), count($config->categories()));
    exit(0);
}

$migrator = new Migrator(ConnectionFactory::create($settings), $rootDir . '/migrations');

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
        echo "Usage: php bin/console.php <migrate|status|config:check [file]>\n";
        exit($command === 'help' ? 0 : 1);
}
