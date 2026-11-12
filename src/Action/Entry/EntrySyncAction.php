<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Entry;

use LiveScoreTracker\Action\JsonResponder;
use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\EntryDesk;
use LiveScoreTracker\Application\EntrySync;
use LiveScoreTracker\Infrastructure\Clock;
use LiveScoreTracker\Infrastructure\Persistence\Author;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Receives the entries queued on a phone (spec § 8.8), as JSON:
 * {"entries": [...]} → {"results": [{"id", "status", "reason"?}]}.
 */
final class EntrySyncAction
{
    /** Entries accepted in one request. */
    private const MAX_ENTRIES = 200;

    public function __construct(
        private readonly Responder $responder,
        private readonly EntryDesk $desk,
        private readonly EntrySync $sync,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array{workshop: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $workshop = $this->desk->workshop($args['workshop']) ?? throw new HttpNotFoundException($request);
        $body = $request->getParsedBody();
        $entries = \is_array($body) && \is_array($body['entries'] ?? null) ? array_values($body['entries']) : null;
        if ($entries === null) {
            return JsonResponder::write($response, ['error' => 'bad_request'], 400);
        }
        $context = $this->responder->context;
        $results = $this->sync->apply(
            $workshop,
            \array_slice($entries, 0, self::MAX_ENTRIES),
            $context->phase,
            Author::from($context),
            $this->clock->now()->getTimestamp(),
        );

        return JsonResponder::write($response, ['results' => $results]);
    }
}
