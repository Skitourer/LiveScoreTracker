<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Participant;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\Standings;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Security\Phase;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use LiveScoreTracker\Infrastructure\Persistence\Throttle;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Participant space (spec § 13.3): a family logs in with the bib and the
 * birth date and sees, depending on the phase, the events to do, the raw
 * results so far, then scores, ranks and the ranking of the category. Other
 * children are shown as first name + initial only.
 *
 * Separate from the volunteers' accounts: the child's id is kept in the
 * session and forgotten after 30 minutes without visit. Failed attempts
 * are throttled like every login (spec § 15).
 */
final class ParticipantAction
{
    public const SESSION_KEY = 'participant';
    public const IDLE_SECONDS = 1800;
    private const BIB_MAX_FAILURES = 5;
    private const IP_MAX_FAILURES = 20;

    public function __construct(
        private readonly Responder $responder,
        private readonly CompetitionConfig $config,
        private readonly RegistrationRepository $registrations,
        private readonly AttemptRepository $attempts,
        private readonly Standings $standings,
        private readonly Throttle $throttle,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->responder->context->session();
        if ($request->getMethod() === 'POST') {
            if (Responder::field($request, 'op') === 'logout') {
                $session->remove(self::SESSION_KEY);

                return $this->responder->redirect($response, '/participant');
            }

            return $this->logIn($request, $response);
        }

        $child = $this->current();
        if ($child === null) {
            return $this->responder->render($response, 'participant/login.html.twig', ['bib' => '', 'errors' => []]);
        }

        return $this->responder->render($response, 'participant/page.html.twig', $this->page($child));
    }

    private function logIn(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $bib = Responder::field($request, 'bib');
        $birthDate = Responder::field($request, 'birth_date');
        $ipKey = 'participant-ip:' . ($this->responder->context->ip ?? 'unknown');
        $bibKey = 'participant-bib:' . $bib;
        $blocked = max($this->throttle->secondsBlocked($ipKey), $this->throttle->secondsBlocked($bibKey));
        if ($blocked > 0) {
            return $this->responder->render($response, 'participant/login.html.twig', [
                'bib' => $bib,
                'errors' => ['login.blocked' => ['minutes' => Responder::minutes($blocked)]],
            ], 429);
        }

        $child = ctype_digit($bib) ? $this->registrations->findByBib((int) $bib) : null;
        if ($child === null || $child->status !== RegistrationStatus::Registered || $child->child->birthDate !== $birthDate) {
            $this->throttle->recordFailure($ipKey, self::IP_MAX_FAILURES);
            $this->throttle->recordFailure($bibKey, self::BIB_MAX_FAILURES);

            return $this->responder->render($response, 'participant/login.html.twig', [
                'bib' => $bib,
                'errors' => ['participant.invalid' => []],
            ], 401);
        }

        $this->throttle->clear($bibKey);
        $session = $this->responder->context->session();
        $session->regenerate();
        $session->set(self::SESSION_KEY, ['id' => $child->id, 'seen' => time()]);

        return $this->responder->redirect($response, '/participant');
    }

    /**
     * The child of this session, if logged in and seen in the last 30 minutes.
     */
    private function current(): ?Registration
    {
        $session = $this->responder->context->session();
        $data = $session->get(self::SESSION_KEY);
        if (!\is_array($data) || !\is_int($data['id'] ?? null) || !\is_int($data['seen'] ?? null)) {
            return null;
        }
        $child = time() - $data['seen'] <= self::IDLE_SECONDS ? $this->registrations->find($data['id']) : null;
        if ($child === null || $child->status !== RegistrationStatus::Registered || $child->bib === null) {
            $session->remove(self::SESSION_KEY);

            return null;
        }
        $session->set(self::SESSION_KEY, ['id' => $child->id, 'seen' => time()]);

        return $child;
    }

    /**
     * @return array<string, mixed>
     */
    private function page(Registration $child): array
    {
        $phase = $this->responder->context->phase;
        $category = $this->config->category($child->child->level, $child->child->gender);
        $variants = $this->config->variantsForGroup($this->config->groupOf($child->child->level));
        $events = [];
        foreach ($variants as $variant) {
            $events[] = ['variant' => $variant, 'attempts' => $phase === Phase::Preparation ? [] : $this->attempts->current($child->id, $variant->id())];
        }

        $standings = null;
        $line = null;
        if ($phase === Phase::Finished) {
            $standings = $this->standings->forCategory($category, true);
            foreach ($standings['ranking'] as $candidate) {
                if ($candidate->bib === (string) $child->bib) {
                    $line = $candidate;
                }
            }
        }

        return [
            'child' => $child,
            'category' => $category,
            'phase' => $phase->value,
            'events' => $events,
            'standings' => $standings,
            'line' => $line,
        ];
    }
}
