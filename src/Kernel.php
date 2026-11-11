<?php

declare(strict_types=1);

namespace LiveScoreTracker;

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use LiveScoreTracker\Action\HealthAction;
use LiveScoreTracker\Action\HomeAction;
use LiveScoreTracker\Action\MigrateAction;
use LiveScoreTracker\Infrastructure\BuildInfo;
use LiveScoreTracker\Infrastructure\Database\ConnectionFactory;
use LiveScoreTracker\Infrastructure\Database\Migrator;
use LiveScoreTracker\Infrastructure\Settings;
use PDO;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

/**
 * Builds the Slim application: settings, container, middleware and routes.
 */
final class Kernel
{
    /**
     * Loads `.env` (if present) from the project root and builds the settings.
     */
    public static function loadSettings(string $rootDir): Settings
    {
        if (is_file($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->safeLoad();
        }

        return Settings::fromEnvironment($rootDir, $_ENV);
    }

    /**
     * @return App<ContainerInterface>
     */
    public static function createApp(Settings $settings): App
    {
        $container = self::createContainer($settings);
        $app = AppFactory::createFromContainer($container);

        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();
        $app->add(TwigMiddleware::create($app, self::service($container, Twig::class)));
        $app->addErrorMiddleware($settings->debug, true, true);

        $app->get('/', HomeAction::class)->setName('home');
        $app->get('/health', HealthAction::class)->setName('health');
        $app->post('/internal/migrate', MigrateAction::class)->setName('migrate');

        return $app;
    }

    public static function createContainer(Settings $settings): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            Settings::class => $settings,
            BuildInfo::class => static fn(): BuildInfo => BuildInfo::fromRoot($settings->rootDir),
            PDO::class => static fn(): PDO => ConnectionFactory::create($settings),
            Migrator::class => static fn(ContainerInterface $c): Migrator => new Migrator(
                self::service($c, PDO::class),
                $settings->rootDir . '/migrations',
            ),
            Twig::class => static fn(): Twig => Twig::create($settings->rootDir . '/templates', [
                'cache' => $settings->debug ? false : $settings->dataDir . '/cache/twig',
                'strict_variables' => $settings->debug,
            ]),
        ]);

        return $builder->build();
    }

    /**
     * Typed container lookup.
     *
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    public static function service(ContainerInterface $container, string $id): object
    {
        $service = $container->get($id);
        if (!$service instanceof $id) {
            throw new \LogicException(\sprintf('Service "%s" has an unexpected type.', $id));
        }

        return $service;
    }
}
