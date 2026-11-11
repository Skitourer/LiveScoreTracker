<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Reception;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Registration\AttendanceMark;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Security\Phase;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * One child at the reception (spec § 6.2): name shown large for checking, then
 * the boxes "bib handed out" (arrival) and "bib given back" and "gift + medal
 * handed out" (leaving), each timestamped. A box ticked by mistake can be unticked.
 *
 * The reception opens with the competition: during preparation the card is read-only.
 */
final class CheckInCardAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly RegistrationRepository $registrations,
    ) {}

    /**
     * @param array{id: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $child = $this->registrations->find((int) $args['id']) ?? throw new HttpNotFoundException($request);
        $context = $this->responder->context;
        $open = $context->phase !== Phase::Preparation;

        if ($request->getMethod() === 'POST') {
            $mark = AttendanceMark::tryFrom(Responder::field($request, 'mark'));
            $done = Responder::field($request, 'done') === '1';
            $error = match (true) {
                !$open => 'check_in.closed',
                $mark === null => 'form.unknown_action',
                default => self::refusal($child, $mark, $done),
            };
            if ($mark === null || $error !== null) {
                $this->responder->flash('error', $error, ['name' => $child->fullName()]);

                return $this->responder->redirect($response, '/check-in/' . $child->id);
            }

            if ($this->registrations->setAttendance($child->id, $mark, $done, $context->user)) {
                $this->responder->flash($done ? 'success' : 'warning', 'check_in.done.' . $mark->value . ($done ? '' : '_undone'), [
                    'name' => $child->fullName(),
                    'bib' => $child->bib ?? '–',
                ]);
            }

            // Arrival done, or both leaving boxes ticked: back to the bib field for the next child.
            $updated = $this->registrations->find($child->id) ?? $child;
            $finished = $done && ($mark === AttendanceMark::CheckedIn || $updated->hasLeft());

            return $this->responder->redirect($response, $finished ? '/check-in' : '/check-in/' . $child->id);
        }

        return $this->responder->render($response, 'reception/card.html.twig', [
            'child' => $child,
            'open' => $open,
            'marks' => AttendanceMark::cases(),
        ]);
    }

    /**
     * Why this box cannot change, or null when it can.
     */
    private static function refusal(Registration $child, AttendanceMark $mark, bool $done): ?string
    {
        if ($mark === AttendanceMark::CheckedIn) {
            if ($done && $child->status !== RegistrationStatus::Registered) {
                return 'check_in.not_registered';
            }
            if (!$done && ($child->bibReturnedAt !== null || $child->giftGivenAt !== null)) {
                return 'check_in.undo_exit_first';
            }

            return null;
        }

        return $done && !$child->isPresent() ? 'check_in.not_present' : null;
    }
}
