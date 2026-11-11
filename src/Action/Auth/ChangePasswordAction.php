<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Auth;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Security\PasswordPolicy;
use LiveScoreTracker\Infrastructure\Persistence\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Change one's own password; mandatory after logging in with a temporary password.
 */
final class ChangePasswordAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly UserRepository $users,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = $this->responder->context;
        $user = $context->requireUser();
        $errors = [];

        if ($request->getMethod() === 'POST') {
            $password = Responder::field($request, 'password', trim: false);
            $problem = PasswordPolicy::check($password, $user->role);
            if (!$user->verifyPassword(Responder::field($request, 'current_password', trim: false))) {
                $errors['password.current_wrong'] = [];
            } elseif ($problem !== null) {
                $errors[$problem] = ['min' => PasswordPolicy::INDIVIDUAL_MIN_LENGTH];
            } elseif ($password !== Responder::field($request, 'password_confirm', trim: false)) {
                $errors['password.mismatch'] = [];
            } elseif ($user->verifyPassword($password)) {
                $errors['password.same_as_before'] = [];
            } else {
                $this->users->setPassword($user->id, $password, mustChange: false);
                // setPassword logs out other sessions; keep this one alive with the new version.
                $updated = $this->users->find($user->id);
                if ($updated !== null) {
                    $context->session()->set('session_version', $updated->sessionVersion);
                }
                $this->responder->audit('auth.password_changed');
                $this->responder->flash('success', 'password.changed');

                return $this->responder->redirect($response, '/dashboard');
            }
        }

        return $this->responder->render($response, 'auth/change-password.html.twig', [
            'forced' => $user->mustChangePassword,
            'errors' => $errors,
            'min' => PasswordPolicy::INDIVIDUAL_MIN_LENGTH,
        ], $errors === [] ? 200 : 422);
    }
}
