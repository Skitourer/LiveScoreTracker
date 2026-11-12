<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Entry;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\EntryDesk;
use LiveScoreTracker\Http\Middleware\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Workshop entry screen, step 1 (spec § 8.2): type the bib. Unknown bibs and
 * children of another level group are refused here (spec § 10.1).
 *
 * On a time workshop the screen starts a race of 1 or 2 children instead
 * (spec § 8.4); a single bib still opens a child's attempts, to correct them.
 */
final class EntryBibAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly EntryDesk $desk,
    ) {}

    /**
     * @param array{workshop: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $workshop = $this->desk->workshop($args['workshop']) ?? throw new HttpNotFoundException($request);
        // Remember the workshop on this phone.
        $response = $response->withAddedHeader('Set-Cookie', SessionMiddleware::cookie(
            WorkshopChoiceAction::COOKIE,
            $workshop->id,
            400 * 86400,
            SessionMiddleware::isHttps($request),
        ));

        $bib = Responder::query($request, 'bib');
        $error = null;
        if ($bib !== '') {
            $resolved = preg_match('/^\d{1,6}$/', $bib) === 1 ? $this->desk->resolve($workshop, (int) $bib) : EntryDesk::UNKNOWN_BIB;
            if (\is_array($resolved)) {
                return $this->responder->redirect($response, '/entry/' . $workshop->id . '/' . (int) $bib);
            }
            $error = $resolved;
        }

        return $this->responder->render($response, 'entry/bib.html.twig', [
            'workshop' => $workshop,
            'levels' => $this->desk->levels($workshop),
            'bib' => $bib,
            'error' => $error,
            // Time events: a race of 1 or 2 children (spec § 8.4).
            'timed' => $this->desk->isTimed($workshop),
            'bibs_per_race' => $this->desk->bibsPerRace($workshop),
        ], $error === null ? 200 : 422);
    }
}
