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
    /** Lists below the search, in display order (spec § 6.2). */
    public const LISTS = ['all', 'present', 'absent', 'not_left', 'left'];

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

        // Every child holding a bib, sorted by bib: registered children, and withdrawn
        // children who keep their bib once the competition has started.
        $holders = array_values(array_filter(
            $this->registrations->search(['statuses' => [RegistrationStatus::Registered, RegistrationStatus::Withdrawn]]),
            static fn(Registration $r): bool => $r->bib !== null,
        ));
        $lists = [];
        foreach (self::LISTS as $name) {
            $lists[$name] = array_values(array_filter($holders, static fn(Registration $r): bool => self::inList($name, $r)));
        }

        return $this->responder->render($response, 'reception/check-in.html.twig', [
            'query' => $query,
            'matches' => $matches,
            'notFound' => $notFound,
            'list' => isset($lists[$list]) ? $list : '',
            'listed' => $lists[$list] ?? [],
            'listCounts' => array_map(\count(...), $lists),
            'counts' => $this->registrations->attendanceCounts(),
        ], $notFound ? 404 : 200);
    }

    private static function inList(string $list, Registration $r): bool
    {
        $registered = $r->status === RegistrationStatus::Registered;

        return match ($list) {
            'all' => true,
            'present' => $registered && $r->isPresent(),
            'absent' => $registered && !$r->isPresent(),
            'not_left' => $r->isPresent() && !$r->hasLeft(),
            'left' => $r->hasLeft(),
            default => false,
        };
    }
}
