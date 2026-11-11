<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use LiveScoreTracker\Infrastructure\Database\ConnectionFactory;
use LiveScoreTracker\Infrastructure\Database\Migrator;
use LiveScoreTracker\Infrastructure\Mail\LogMailer;
use LiveScoreTracker\Infrastructure\Mail\Mailer;
use LiveScoreTracker\Infrastructure\Settings;
use LiveScoreTracker\Kernel;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Minimal browser for integration tests: keeps cookies between requests,
 * sends the CSRF token found in the last page, and captures emails.
 *
 * Each request builds a fresh application, as PHP does on a real server.
 */
final class WebClient
{
    /** @var array<string, string> */
    public array $cookies = [];
    public LogMailer $mailer;
    public string $ip = '192.0.2.10';
    private ?string $csrf = null;
    public string $lastBody = '';

    public function __construct(
        private readonly string $dataDir,
        private readonly string $environment = 'test',
    ) {
        $this->mailer = new LogMailer(null);
    }

    public static function migrated(string $dataDir): self
    {
        $client = new self($dataDir);
        (new Migrator(ConnectionFactory::create($client->settings()), \dirname(__DIR__, 2) . '/migrations'))->migrate();

        return $client;
    }

    /**
     * A second browser (other phone) sharing the same server and mailbox.
     */
    public function otherBrowser(): self
    {
        $other = new self($this->dataDir, $this->environment);
        $other->mailer = $this->mailer;

        return $other;
    }

    public function settings(): Settings
    {
        return Settings::fromEnvironment(\dirname(__DIR__, 2), [
            'APP_ENV' => $this->environment,
            'APP_DEBUG' => 'true',
            'APP_URL' => 'https://lst.example.test',
            'DATA_DIR' => $this->dataDir,
            'DB_DRIVER' => 'sqlite',
            'DEPLOY_TOKEN' => 'test-deploy-token',
        ]);
    }

    public function get(string $path): ResponseInterface
    {
        return $this->request('GET', $path);
    }

    /**
     * @param array<string, string> $form
     */
    public function post(string $path, array $form = [], bool $withCsrf = true): ResponseInterface
    {
        if ($withCsrf && !isset($form['_csrf'])) {
            if ($this->csrf === null) {
                $this->get($path); // load the form first, like a user would
            }
            $form['_csrf'] = (string) $this->csrf;
        }

        return $this->request('POST', $path, $form);
    }

    /**
     * Follows a redirect response with a GET.
     */
    public function follow(ResponseInterface $response): ResponseInterface
    {
        return $this->get($response->getHeaderLine('Location'));
    }

    /**
     * @param array<string, string> $form
     */
    public function request(string $method, string $path, array $form = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'https://lst.example.test' . $path, ['REMOTE_ADDR' => $this->ip]);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query)->withCookieParams($this->cookies)->withHeader('User-Agent', 'WebClient test');
        if ($method === 'POST') {
            $request = $request->withParsedBody($form)->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        }

        $response = Kernel::createApp($this->settings(), [Mailer::class => $this->mailer])->handle($request);

        foreach ($response->getHeader('Set-Cookie') as $header) {
            $parts = explode(';', $header);
            [$name, $value] = array_pad(explode('=', trim($parts[0]), 2), 2, '');
            if (str_contains(strtolower($header), 'max-age=0') || $value === '') {
                unset($this->cookies[$name]);
                if ($name === 'lst_session') {
                    $this->csrf = null; // session gone: the next form must be loaded again
                }
            } else {
                $this->cookies[$name] = $value;
            }
        }

        $this->lastBody = (string) $response->getBody();
        if (preg_match('/name="_csrf" value="([a-f0-9]+)"/', $this->lastBody, $m) === 1) {
            $this->csrf = $m[1];
        }

        return $response;
    }
}
