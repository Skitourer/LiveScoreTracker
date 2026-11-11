<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Admin;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Security\PasswordPolicy;
use LiveScoreTracker\Domain\Security\Role;
use LiveScoreTracker\Infrastructure\Persistence\UserRepository;
use LiveScoreTracker\Infrastructure\Session\SessionStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The shared referee account (spec § 19.1): one login and password for every
 * referee phone, changed each edition, with "disconnect all phones".
 */
final class RefereeAccountAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly UserRepository $users,
        private readonly SessionStore $sessions,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $account = $this->users->refereeAccount();
        $errors = [];
        $username = $account === null ? '' : $account->username;

        if ($request->getMethod() === 'POST') {
            $op = Responder::field($request, 'op');
            $password = Responder::field($request, 'password', trim: false);
            $problem = PasswordPolicy::check($password, Role::Referee);

            if ($op === 'create' && $account === null) {
                $username = UserRepository::normalize(Responder::field($request, 'username'));
                if (preg_match('/^[a-z0-9._-]{3,32}$/', $username) !== 1) {
                    $errors['account.username_invalid'] = [];
                } elseif ($this->users->usernameExists($username)) {
                    $errors['account.username_taken'] = [];
                }
                if ($problem !== null) {
                    $errors[$problem] = ['min' => PasswordPolicy::SHARED_MIN_LENGTH];
                }
                if ($errors === []) {
                    $this->users->create($username, 'referees', null, Role::Referee, $password, mustChangePassword: false);
                    $this->done('referee.created', ['username' => $username]);

                    return $this->responder->redirect($response, '/admin/referee');
                }
            } elseif ($op === 'password' && $account !== null) {
                if ($problem !== null) {
                    $errors[$problem] = ['min' => PasswordPolicy::SHARED_MIN_LENGTH];
                } else {
                    // A new password also logs out every phone.
                    $this->users->setPassword($account->id, $password, mustChange: false);
                    $this->sessions->deleteForUser($account->id);
                    $this->done('referee.password_changed');

                    return $this->responder->redirect($response, '/admin/referee');
                }
            } elseif ($op === 'disconnect' && $account !== null) {
                $this->users->bumpSessionVersion($account->id);
                $this->sessions->deleteForUser($account->id);
                $this->done('referee.disconnected_all');

                return $this->responder->redirect($response, '/admin/referee');
            } else {
                $errors['form.unknown_action'] = [];
            }
        }

        return $this->responder->render($response, 'admin/referee.html.twig', [
            'account' => $account,
            'username' => $username !== '' ? $username : 'arbitre',
            'phones' => $account === null ? [] : $this->sessions->activeForUser($account->id),
            'errors' => $errors,
            'min' => PasswordPolicy::SHARED_MIN_LENGTH,
        ], $errors === [] ? 200 : 422);
    }

    /**
     * @param array<string, string> $details
     */
    private function done(string $action, array $details = []): void
    {
        $this->responder->audit($action, $details);
        $this->responder->flash('success', $action);
    }
}
