<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Reception;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Phone check-in screen (spec § 6.2): type a bib (numeric keypad) or search a
 * name, with the counters at the top and the lists of absent children and of
 * children who have not left yet.
 */
final class CheckInAction
{
    public const LISTS = ['absent', 'not_left'];

    public function __construct(
        private readonly Responder $responder,
        private readonly RegistrationRepository $registrations,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = Responder::query($request, 'q');
        $list = Responder::query($request, 'list');
        $matches = null;
        $notFound = false;

        if (preg_match('/^\d{1,6}$/', $query) === 1) {
            $child = $this->registrations->findByBib((int) $query);
            if ($child !== null) {
                return $this->responder->redirect($response, '/check-in/' . $child->id);
            }
            $notFound = true;
        } elseif ($query !== '') {
            $matches = $this->registrations->search(['q' => $query, 'names_only' => true, 'statuses' => [RegistrationStatus::Registered, RegistrationStatus::Withdrawn]]);
            if (\count($matches) === 1) {
                return $this->responder->redirect($response, '/check-in/' . $matches[0]->id);
            }
            $notFound = $matches === [];
        }

        $shown = null;
        if (\in_array($list, self::LISTS, true)) {
            $shown = array_values(array_filter(
                $this->registrations->search(['statuses' => [RegistrationStatus::Registered]]),
                static fn(Registration $r): bool => $list === 'absent' ? !$r->isPresent() : $r->isPresent() && !$r->hasLeft(),
            ));
        }

        return $this->responder->render($response, 'reception/check-in.html.twig', [
            'query' => $query,
            'matches' => $matches,
            'notFound' => $notFound,
            'list' => $shown === null ? '' : $list,
            'listed' => $shown ?? [],
            'counts' => $this->registrations->attendanceCounts(),
        ], $notFound ? 404 : 200);
    }
}
