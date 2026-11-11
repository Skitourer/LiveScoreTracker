<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Support;

use LiveScoreTracker\Http\RequestContext;
use LiveScoreTracker\Infrastructure\Persistence\AuditLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Shared helpers for page actions: rendering, redirects, flash messages, form input and audit.
 */
final class Responder
{
    public function __construct(
        private readonly Twig $twig,
        public readonly RequestContext $context,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function render(ResponseInterface $response, string $template, array $data = [], int $status = 200): ResponseInterface
    {
        return $this->twig->render($response->withStatus($status), $template, $data);
    }

    public function redirect(ResponseInterface $response, string $location): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', $location);
    }

    /**
     * @param array<string, string|int> $params
     */
    public function flash(string $type, string $key, array $params = []): void
    {
        $this->context->session()->flash($type, $key, $params);
    }

    /**
     * @param array<string, scalar|null> $details
     */
    public function audit(string $action, array $details = [], ?\LiveScoreTracker\Domain\Security\User $actor = null): void
    {
        $referee = $this->context->refereeName();
        if ($referee !== null) {
            $details = ['referee' => $referee, ...$details];
        }
        $this->audit->record($action, $actor ?? $this->context->user, $details, $this->context->ip, $this->context->deviceId);
    }

    /**
     * Text field of the submitted form, trimmed ('' when absent).
     */
    public static function field(ServerRequestInterface $request, string $name, bool $trim = true): string
    {
        $body = $request->getParsedBody();
        $value = \is_array($body) && \is_string($body[$name] ?? null) ? $body[$name] : '';

        return $trim ? trim($value) : $value;
    }

    public static function query(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getQueryParams()[$name] ?? '';

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * Minutes, rounded up, for "try again in N minutes" messages.
     */
    public static function minutes(int $seconds): int
    {
        return max(1, (int) ceil($seconds / 60));
    }
}
