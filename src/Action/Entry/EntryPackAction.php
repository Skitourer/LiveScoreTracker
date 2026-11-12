<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Entry;

use LiveScoreTracker\Action\JsonResponder;
use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\EntryDesk;
use LiveScoreTracker\Application\EntryPack;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * The data a phone keeps to enter results at a workshop offline (spec § 8.8), as JSON.
 */
final class EntryPackAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly EntryDesk $desk,
        private readonly EntryPack $pack,
    ) {}

    /**
     * @param array{workshop: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $workshop = $this->desk->workshop($args['workshop']) ?? throw new HttpNotFoundException($request);
        $context = $this->responder->context;

        return JsonResponder::write($response, $this->pack->build($workshop, $context->phase->value, $context->session()->csrfToken()));
    }
}
