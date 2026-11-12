<?php

declare(strict_types=1);

namespace LiveScoreTracker;

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use LiveScoreTracker\Action\AccountAction;
use LiveScoreTracker\Action\Admin\AuditAction;
use LiveScoreTracker\Action\Admin\PhaseAction;
use LiveScoreTracker\Action\Admin\RefereeAccountAction;
use LiveScoreTracker\Action\Admin\TestDataAction;
use LiveScoreTracker\Action\Admin\UserCreateAction;
use LiveScoreTracker\Action\Admin\UserEditAction;
use LiveScoreTracker\Action\Admin\UsersAction;
use LiveScoreTracker\Action\Auth\ChangePasswordAction;
use LiveScoreTracker\Action\Auth\ForgotPasswordAction;
use LiveScoreTracker\Action\Auth\LoginAction;
use LiveScoreTracker\Action\Auth\LogoutAction;
use LiveScoreTracker\Action\Auth\RefereeNameAction;
use LiveScoreTracker\Action\Auth\ResetPasswordAction;
use LiveScoreTracker\Action\Auth\SetupAction;
use LiveScoreTracker\Action\Auth\TwoFactorChallengeAction;
use LiveScoreTracker\Action\Auth\TwoFactorSetupAction;
use LiveScoreTracker\Action\DashboardAction;
use LiveScoreTracker\Action\Entry\EntryBibAction;
use LiveScoreTracker\Action\Entry\EntryChildAction;
use LiveScoreTracker\Action\Entry\RaceAction;
use LiveScoreTracker\Action\Entry\TrackingAction;
use LiveScoreTracker\Action\Entry\WorkshopChoiceAction;
use LiveScoreTracker\Action\Entry\WorkshopHelpAction;
use LiveScoreTracker\Action\HealthAction;
use LiveScoreTracker\Action\HomeAction;
use LiveScoreTracker\Action\ImportSampleAction;
use LiveScoreTracker\Action\MigrateAction;
use LiveScoreTracker\Action\Reception\CheckInAction;
use LiveScoreTracker\Action\Reception\CheckInCardAction;
use LiveScoreTracker\Action\Reception\ReceptionListAction;
use LiveScoreTracker\Action\Registration\RegistrationCardAction;
use LiveScoreTracker\Action\Registration\RegistrationExportAction;
use LiveScoreTracker\Action\Registration\RegistrationImportAction;
use LiveScoreTracker\Action\Registration\RegistrationNewAction;
use LiveScoreTracker\Action\Registration\RegistrationsAction;
use LiveScoreTracker\Action\ScoringReportAction;
use LiveScoreTracker\Action\StyleguideAction;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Config\InvalidConfig;
use LiveScoreTracker\Domain\Registration\ValueMatcher;
use LiveScoreTracker\Domain\Security\Permission;
use LiveScoreTracker\Http\AppErrorHandler;
use LiveScoreTracker\Http\AuthState;
use LiveScoreTracker\Http\HtmlErrorRenderer;
use LiveScoreTracker\Http\Middleware\AuthenticationMiddleware;
use LiveScoreTracker\Http\Middleware\Authorize;
use LiveScoreTracker\Http\Middleware\CsrfMiddleware;
use LiveScoreTracker\Http\Middleware\SecurityHeadersMiddleware;
use LiveScoreTracker\Http\Middleware\SessionMiddleware;
use LiveScoreTracker\Http\RequestContext;
use LiveScoreTracker\Infrastructure\BuildInfo;
use LiveScoreTracker\Infrastructure\Clock;
use LiveScoreTracker\Infrastructure\Config\ConfigRepository;
use LiveScoreTracker\Infrastructure\Database\ConnectionFactory;
use LiveScoreTracker\Infrastructure\Database\Migrator;
use LiveScoreTracker\Infrastructure\Help\HelpPages;
use LiveScoreTracker\Infrastructure\I18n\CatalogLoader;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use LiveScoreTracker\Infrastructure\Mail\LogMailer;
use LiveScoreTracker\Infrastructure\Mail\Mailer;
use LiveScoreTracker\Infrastructure\Mail\SmtpMailer;
use LiveScoreTracker\Infrastructure\Pdf\PdfRenderer;
use LiveScoreTracker\Infrastructure\Session\SessionStore;
use LiveScoreTracker\Infrastructure\Settings;
use LiveScoreTracker\Presentation\ResultFormatter;
use LiveScoreTracker\Presentation\TwigExtension;
use PDO;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteCollectorProxyInterface;
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
     * @param array<string, mixed> $overrides container entries replaced (tests)
     *
     * @return App<ContainerInterface>
     */
    public static function createApp(Settings $settings, array $overrides = []): App
    {
        $container = self::createContainer($settings, $overrides);
        $app = AppFactory::createFromContainer($container);
        $context = self::service($container, RequestContext::class);
        $twig = self::service($container, Twig::class);

        // Middleware: the last added runs first. Request order:
        // security headers → session → Twig → errors → authentication → body parsing → CSRF → routing.
        $app->addRoutingMiddleware();
        $app->add(new CsrfMiddleware($context));
        $app->addBodyParsingMiddleware();
        $app->add(self::service($container, AuthenticationMiddleware::class));
        $errors = $app->addErrorMiddleware($settings->debug, true, $settings->debug);
        $handler = new AppErrorHandler($app->getCallableResolver(), $app->getResponseFactory());
        $renderer = new HtmlErrorRenderer($twig);
        $handler->registerErrorRenderer('text/html', $renderer);
        $handler->setDefaultErrorRenderer('text/html', $renderer);
        $errors->setDefaultErrorHandler($handler);
        $app->add(TwigMiddleware::create($app, $twig));
        $app->add(self::service($container, SessionMiddleware::class));
        $app->add(new SecurityHeadersMiddleware($context));

        self::routes($app, $context);

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private static function routes(App $app, RequestContext $context): void
    {
        $guard = static fn(?Permission $permission = null, AuthState ...$states): Authorize => new Authorize(
            $context,
            $permission,
            $states === [] ? [AuthState::Authenticated] : array_values($states),
        );

        $app->get('/', HomeAction::class)->setName('home');
        $app->get('/health', HealthAction::class)->setName('health');
        $app->post('/internal/migrate', MigrateAction::class)->setName('migrate');
        $app->get('/demo/report', ScoringReportAction::class)->setName('demo-report');
        $app->get('/demo/styleguide', StyleguideAction::class)->setName('demo-styleguide');

        // Login and passwords
        $app->map(['GET', 'POST'], '/setup', SetupAction::class)->setName('setup');
        $app->map(['GET', 'POST'], '/login', LoginAction::class)->setName('login');
        $app->map(['GET', 'POST'], '/login/2fa', TwoFactorChallengeAction::class)->setName('login-2fa')
            ->add($guard(null, AuthState::TwoFactorChallenge));
        $app->post('/logout', LogoutAction::class)->setName('logout');
        $app->map(['GET', 'POST'], '/referee/name', RefereeNameAction::class)->setName('referee-name')
            ->add($guard(null, AuthState::RefereeNameRequired, AuthState::Authenticated));
        $app->map(['GET', 'POST'], '/password/forgot', ForgotPasswordAction::class)->setName('password-forgot');
        $app->map(['GET', 'POST'], '/password/reset/{token}', ResetPasswordAction::class)->setName('password-reset');

        // Own account
        $app->get('/dashboard', DashboardAction::class)->setName('dashboard')->add($guard());
        $app->map(['GET', 'POST'], '/account', AccountAction::class)->setName('account')->add($guard());
        $app->map(['GET', 'POST'], '/account/password', ChangePasswordAction::class)->setName('account-password')
            ->add($guard(null, AuthState::Authenticated, AuthState::MustChangePassword));
        $app->map(['GET', 'POST'], '/account/2fa', TwoFactorSetupAction::class)->setName('account-2fa')
            ->add($guard(null, AuthState::TwoFactorSetup, AuthState::Authenticated));

        // Registrations (spec § 5)
        $app->get('/registrations', RegistrationsAction::class)->setName('registrations')->add($guard(Permission::ViewRegistrations));
        $app->map(['GET', 'POST'], '/registrations/new', RegistrationNewAction::class)->setName('registration-new')->add($guard(Permission::AddOnSite));
        $app->map(['GET', 'POST'], '/registrations/import', RegistrationImportAction::class)->setName('registration-import')->add($guard(Permission::ImportSpreadsheets));
        $app->get('/registrations/export', RegistrationExportAction::class)->setName('registration-export')->add($guard(Permission::ViewRegistrations));
        $app->map(['GET', 'POST'], '/registrations/{id:[0-9]+}', RegistrationCardAction::class)->setName('registration')->add($guard(Permission::ViewRegistrations));
        $app->get('/demo/import-sample.xlsx', ImportSampleAction::class)->setName('demo-import-sample')->add($guard(Permission::ImportSpreadsheets));

        // Reception on the competition day (spec § 6)
        $app->get('/reception-list.pdf', ReceptionListAction::class)->setName('reception-list')->add($guard(Permission::PrintDocuments));
        $app->get('/check-in', CheckInAction::class)->setName('check-in')->add($guard(Permission::CheckInOut));
        $app->map(['GET', 'POST'], '/check-in/{id:[0-9]+}', CheckInCardAction::class)->setName('check-in-card')->add($guard(Permission::CheckInOut));

        // Result entry at the workshops (spec § 8)
        $app->get('/entry', WorkshopChoiceAction::class)->setName('entry')->add($guard(Permission::EnterResultsChosenEvent));
        $app->get('/entry/{workshop:[a-z0-9_]+}', EntryBibAction::class)->setName('entry-workshop')->add($guard(Permission::EnterResultsChosenEvent));
        $app->get('/entry/{workshop:[a-z0-9_]+}/tracking', TrackingAction::class)->setName('entry-tracking')->add($guard(Permission::ViewRemainingBibs));
        $app->get('/entry/{workshop:[a-z0-9_]+}/help', WorkshopHelpAction::class)->setName('entry-help')->add($guard(Permission::ViewWorkshopHelp));
        $app->map(['GET', 'POST'], '/entry/{workshop:[a-z0-9_]+}/race', RaceAction::class)->setName('entry-race')->add($guard(Permission::EnterResultsChosenEvent));
        $app->map(['GET', 'POST'], '/entry/{workshop:[a-z0-9_]+}/{bib:[0-9]+}', EntryChildAction::class)->setName('entry-child')->add($guard(Permission::EnterResultsChosenEvent));

        // Administration
        // Slim binds group callbacks to itself: the closure must not be static.
        $app->group('/admin', function (RouteCollectorProxyInterface $admin) use ($guard): void {
            $admin->get('/users', UsersAction::class)->setName('admin-users')->add($guard(Permission::ManageAccounts));
            $admin->map(['GET', 'POST'], '/users/new', UserCreateAction::class)->setName('admin-user-new')->add($guard(Permission::ManageAccounts));
            $admin->map(['GET', 'POST'], '/users/{id:[0-9]+}', UserEditAction::class)->setName('admin-user')->add($guard(Permission::ManageAccounts));
            $admin->map(['GET', 'POST'], '/referee', RefereeAccountAction::class)->setName('admin-referee')->add($guard(Permission::ManageAccounts));
            $admin->map(['GET', 'POST'], '/test-data', TestDataAction::class)->setName('admin-test-data')->add($guard(Permission::RestoreAndPurge));
            $admin->map(['GET', 'POST'], '/phase', PhaseAction::class)->setName('admin-phase')->add($guard(Permission::ChangePhase));
            $admin->get('/audit', AuditAction::class)->setName('admin-audit')->add($guard(Permission::ViewAuditLog));
        });
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function createContainer(Settings $settings, array $overrides = []): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            Settings::class => $settings,
            Clock::class => static fn(): Clock => new Clock(),
            RequestContext::class => static fn(): RequestContext => new RequestContext(),
            BuildInfo::class => static fn(): BuildInfo => BuildInfo::fromRoot($settings->rootDir),
            PDO::class => static fn(): PDO => ConnectionFactory::create($settings),
            Migrator::class => static fn(ContainerInterface $c): Migrator => new Migrator(
                self::service($c, PDO::class),
                $settings->rootDir . '/migrations',
            ),
            SessionStore::class => static fn(ContainerInterface $c): SessionStore => new SessionStore(
                self::service($c, PDO::class),
                self::service($c, Clock::class),
                $settings->sessionIdleMinutes,
            ),
            HelpPages::class => static fn(): HelpPages => new HelpPages($settings->dataDir, $settings->rootDir . '/config.example'),
            PdfRenderer::class => static fn(): PdfRenderer => new PdfRenderer(sys_get_temp_dir()),
            Mailer::class => static fn(): Mailer => $settings->mailTransport === 'smtp'
                ? new SmtpMailer($settings)
                : new LogMailer($settings->dataDir . '/logs/mail.log'),
            ConfigRepository::class => static fn(): ConfigRepository => new ConfigRepository(
                $settings->dataDir,
                $settings->rootDir . '/config.example',
            ),
            Translator::class => static function (ContainerInterface $c) use ($settings): Translator {
                try {
                    $locale = self::service($c, ConfigRepository::class)->get()->edition->defaultLocale;
                } catch (InvalidConfig) {
                    $locale = 'fr';
                }
                $loader = new CatalogLoader([
                    $settings->rootDir . '/config.example/i18n',
                    $settings->dataDir . '/i18n',
                ]);

                return $loader->translator($locale);
            },
            CompetitionConfig::class => static fn(ContainerInterface $c): CompetitionConfig => self::service($c, ConfigRepository::class)->get(),
            ValueMatcher::class => static function (ContainerInterface $c): ValueMatcher {
                $config = self::service($c, CompetitionConfig::class);
                $translator = self::service($c, Translator::class);
                $labels = [];
                foreach ($config->genders as $gender) {
                    $labels[$gender] = [$translator->trans("gender.{$gender}.one"), $translator->trans("gender.{$gender}.many")];
                }

                return new ValueMatcher($config, $labels);
            },
            ResultFormatter::class => static fn(ContainerInterface $c): ResultFormatter => new ResultFormatter(
                self::service($c, Translator::class),
            ),
            Twig::class => static function (ContainerInterface $c) use ($settings): Twig {
                $twig = Twig::create($settings->rootDir . '/templates', [
                    'cache' => $settings->debug ? false : $settings->dataDir . '/cache/twig',
                    // Recompile templates changed by a deployment (the cache lives in the data folder).
                    'auto_reload' => true,
                    'strict_variables' => $settings->debug,
                ]);
                $twig->addExtension(new TwigExtension(
                    self::service($c, Translator::class),
                    self::service($c, ResultFormatter::class),
                    self::service($c, RequestContext::class),
                    $settings->timezone,
                ));

                return $twig;
            },
        ]);
        if ($overrides !== []) {
            $builder->addDefinitions($overrides);
        }

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
