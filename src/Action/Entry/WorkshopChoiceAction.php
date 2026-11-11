<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Entry;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\EntryDesk;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * "Choose your workshop" (spec § 8.1). The choice is remembered on the phone:
 * coming back goes straight to the workshop, unless the referee asks to change.
 */
final class WorkshopChoiceAction
{
    public const COOKIE = 'lst_workshop';

    public function __construct(
        private readonly Responder $responder,
        private readonly CompetitionConfig $config,
        private readonly EntryDesk $desk,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $remembered = $request->getCookieParams()[self::COOKIE] ?? null;
        if (\is_string($remembered) && $this->desk->workshop($remembered) !== null && Responder::query($request, 'change') !== '1') {
            return $this->responder->redirect($response, '/entry/' . $remembered);
        }

        $workshops = [];
        foreach ($this->config->workshops as $workshop) {
            $workshops[] = ['workshop' => $workshop, 'levels' => $this->desk->levels($workshop)];
        }

        return $this->responder->render($response, 'entry/choose.html.twig', ['workshops' => $workshops]);
    }
}
