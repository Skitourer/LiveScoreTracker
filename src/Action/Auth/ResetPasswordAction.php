<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Auth;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Security\PasswordPolicy;
use LiveScoreTracker\Infrastructure\Persistence\PasswordResetRepository;
use LiveScoreTracker\Infrastructure\Persistence\Throttle;
use LiveScoreTracker\Infrastructure\Persistence\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * New password from an emailed link (spec § 19.1).
 */
final class ResetPasswordAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly UserRepository $users,
        private readonly PasswordResetRepository $resets,
        private readonly Throttle $throttle,
    ) {}

    /**
     * @param array{token?: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = (string) ($args['token'] ?? '');
        $userId = preg_match('/^[a-f0-9]{64}$/', $token) === 1 ? $this->resets->validUserId($token) : null;
        $user = $userId === null ? null : $this->users->find($userId);

        if ($user === null || $user->disabled) {
            return $this->responder->render($response, 'auth/reset-password.html.twig', ['invalid' => true, 'errors' => []], 410);
        }

        $errors = [];
        if ($request->getMethod() === 'POST') {
            $password = Responder::field($request, 'password', trim: false);
            $problem = PasswordPolicy::check($password, $user->role);
            if ($problem !== null) {
                $errors[$problem] = ['min' => PasswordPolicy::INDIVIDUAL_MIN_LENGTH];
            } elseif ($password !== Responder::field($request, 'password_confirm', trim: false)) {
                $errors['password.mismatch'] = [];
            } else {
                $this->users->setPassword($user->id, $password, mustChange: false);
                $this->resets->consumeAll($user->id);
                $this->throttle->clear('login-user:' . $user->username);
                $this->responder->audit('auth.password_reset', [], $user);
                $this->responder->flash('success', 'password.reset_done');

                return $this->responder->redirect($response, '/login');
            }
        }

        return $this->responder->render($response, 'auth/reset-password.html.twig', [
            'invalid' => false,
            'errors' => $errors,
            'min' => PasswordPolicy::INDIVIDUAL_MIN_LENGTH,
        ], $errors === [] ? 200 : 422);
    }
}
