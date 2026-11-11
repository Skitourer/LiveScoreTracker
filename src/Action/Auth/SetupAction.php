<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Auth;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Security\PasswordPolicy;
use LiveScoreTracker\Domain\Security\Role;
use LiveScoreTracker\Infrastructure\Persistence\Throttle;
use LiveScoreTracker\Infrastructure\Persistence\UserRepository;
use LiveScoreTracker\Infrastructure\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Creates the first admin account of a new installation.
 *
 * Shared hosting gives no command line, so this page exists while there is no
 * admin. It asks for the server's DEPLOY_TOKEN, known only to the installer.
 */
final class SetupAction
{
    private const MAX_FAILURES = 5;

    public function __construct(
        private readonly Responder $responder,
        private readonly UserRepository $users,
        private readonly Throttle $throttle,
        private readonly Settings $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->users->countActiveAdmins() > 0) {
            throw new HttpNotFoundException($request);
        }
        if ($this->settings->deployToken === '') {
            return $this->responder->render($response, 'auth/setup.html.twig', ['disabled' => true, 'errors' => [], 'old' => []], 503);
        }

        $old = ['username' => '', 'display_name' => '', 'email' => ''];
        $errors = [];
        if ($request->getMethod() === 'POST') {
            $old = [
                'username' => UserRepository::normalize(Responder::field($request, 'username')),
                'display_name' => Responder::field($request, 'display_name'),
                'email' => Responder::field($request, 'email'),
            ];
            $password = Responder::field($request, 'password', trim: false);
            $key = 'setup-ip:' . ($this->responder->context->ip ?? 'unknown');
            $blocked = $this->throttle->secondsBlocked($key);

            if ($blocked > 0) {
                $errors['login.blocked'] = ['minutes' => Responder::minutes($blocked)];
            } elseif (!hash_equals($this->settings->deployToken, Responder::field($request, 'setup_token'))) {
                $this->throttle->recordFailure($key, self::MAX_FAILURES);
                $errors['setup.wrong_token'] = [];
            } else {
                $errors = AccountForm::validate($old, $this->users, null);
                $problem = PasswordPolicy::check($password, Role::Admin);
                if ($problem !== null) {
                    $errors[$problem] = ['min' => PasswordPolicy::INDIVIDUAL_MIN_LENGTH];
                } elseif ($password !== Responder::field($request, 'password_confirm', trim: false)) {
                    $errors['password.mismatch'] = [];
                }
            }

            if ($errors === []) {
                $id = $this->users->create($old['username'], $old['display_name'], $old['email'], Role::Admin, $password, false);
                $this->responder->audit('setup.first_admin', ['username' => $old['username']], $this->users->find($id));
                $this->responder->flash('success', 'setup.done');

                return $this->responder->redirect($response, '/login');
            }
        }

        return $this->responder->render($response, 'auth/setup.html.twig', [
            'disabled' => false,
            'errors' => $errors,
            'old' => $old,
            'min' => PasswordPolicy::INDIVIDUAL_MIN_LENGTH,
        ], $errors === [] ? 200 : 422);
    }
}
