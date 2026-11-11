<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Admin;

use LiveScoreTracker\Action\Auth\AccountForm;
use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Security\PasswordPolicy;
use LiveScoreTracker\Domain\Security\Role;
use LiveScoreTracker\Infrastructure\Persistence\RecoveryCodeRepository;
use LiveScoreTracker\Infrastructure\Persistence\UserRepository;
use LiveScoreTracker\Infrastructure\Session\SessionStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Manages one individual account: profile, temporary password, disable/enable,
 * and resetting another admin's 2FA (spec § 3.1, § 19.1).
 */
final class UserEditAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly UserRepository $users,
        private readonly RecoveryCodeRepository $recoveryCodes,
        private readonly SessionStore $sessions,
    ) {}

    /**
     * @param array{id?: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $account = $this->users->find((int) ($args['id'] ?? 0));
        if ($account === null) {
            throw new HttpNotFoundException($request);
        }
        if ($account->role === Role::Referee) {
            return $this->responder->redirect($response, '/admin/referee');
        }

        $me = $this->responder->context->requireUser();
        $isSelf = $me->id === $account->id;
        $errors = [];
        $old = ['display_name' => $account->displayName, 'email' => $account->email ?? ''];

        if ($request->getMethod() === 'POST') {
            $op = Responder::field($request, 'op');
            $details = ['username' => $account->username];

            switch ($op) {
                case 'profile':
                    $old = ['display_name' => Responder::field($request, 'display_name'), 'email' => Responder::field($request, 'email')];
                    $errors = AccountForm::validate(['username' => $account->username, ...$old], $this->users, $account->id);
                    if ($errors === []) {
                        $this->users->updateProfile($account->id, $old['display_name'], $old['email']);
                        $this->done('account.updated', $details);
                    }
                    break;

                case 'password':
                    $password = Responder::field($request, 'password', trim: false);
                    $problem = PasswordPolicy::check($password, $account->role);
                    if ($isSelf) {
                        $errors['account.use_own_password_page'] = [];
                    } elseif ($problem !== null) {
                        $errors[$problem] = ['min' => PasswordPolicy::INDIVIDUAL_MIN_LENGTH];
                    } else {
                        $this->users->setPassword($account->id, $password, mustChange: true);
                        $this->sessions->deleteForUser($account->id);
                        $this->done('account.password_reset_by_admin', $details);
                    }
                    break;

                case 'disable':
                    if ($isSelf) {
                        $errors['account.cannot_disable_self'] = [];
                    } elseif ($account->role === Role::Admin && $this->users->countActiveAdmins() <= 1) {
                        $errors['account.last_admin'] = [];
                    } else {
                        $this->users->setDisabled($account->id, true);
                        $this->sessions->deleteForUser($account->id);
                        $this->done('account.disabled', $details);
                    }
                    break;

                case 'enable':
                    $this->users->setDisabled($account->id, false);
                    $this->done('account.enabled', $details);
                    break;

                case 'reset_2fa':
                    if ($isSelf || $account->role !== Role::Admin) {
                        $errors['account.cannot_reset_own_2fa'] = [];
                    } else {
                        // The admin will set up 2FA again at next login.
                        $this->users->setPendingTotpSecret($account->id, null);
                        $this->recoveryCodes->deleteAll($account->id);
                        $this->users->bumpSessionVersion($account->id);
                        $this->sessions->deleteForUser($account->id);
                        $this->done('account.2fa_reset', $details);
                    }
                    break;

                default:
                    $errors['form.unknown_action'] = [];
            }

            if ($errors === []) {
                return $this->responder->redirect($response, '/admin/users/' . $account->id);
            }
        }

        return $this->responder->render($response, 'admin/user-edit.html.twig', [
            'account' => $this->users->find($account->id),
            'isSelf' => $isSelf,
            'old' => $old,
            'errors' => $errors,
            'min' => PasswordPolicy::INDIVIDUAL_MIN_LENGTH,
            'remainingCodes' => $account->totpEnabled ? $this->recoveryCodes->remaining($account->id) : null,
        ], $errors === [] ? 200 : 422);
    }

    /**
     * @param array<string, string> $details
     */
    private function done(string $action, array $details): void
    {
        $this->responder->audit($action, $details);
        $this->responder->flash('success', $action);
    }
}
