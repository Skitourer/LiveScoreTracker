<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Admin;

use LiveScoreTracker\Action\Auth\AccountForm;
use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Security\PasswordPolicy;
use LiveScoreTracker\Domain\Security\Role;
use LiveScoreTracker\Infrastructure\Persistence\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Creates an admin or organizer account with a temporary password,
 * which the person must change at first login.
 */
final class UserCreateAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly UserRepository $users,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $old = ['username' => '', 'display_name' => '', 'email' => '', 'role' => Role::Organizer->value];
        $errors = [];

        if ($request->getMethod() === 'POST') {
            $old = [
                'username' => UserRepository::normalize(Responder::field($request, 'username')),
                'display_name' => Responder::field($request, 'display_name'),
                'email' => Responder::field($request, 'email'),
                'role' => Responder::field($request, 'role'),
            ];
            $role = Role::tryFrom($old['role']);
            $errors = AccountForm::validate($old, $this->users, null);
            if ($role === null || $role === Role::Referee) {
                $errors['account.role_invalid'] = [];
            }
            $password = Responder::field($request, 'password', trim: false);
            $problem = PasswordPolicy::check($password, Role::Organizer);
            if ($problem !== null) {
                $errors[$problem] = ['min' => PasswordPolicy::INDIVIDUAL_MIN_LENGTH];
            }

            if ($errors === [] && $role !== null) {
                $this->users->create($old['username'], $old['display_name'], $old['email'], $role, $password, mustChangePassword: true);
                $this->responder->audit('account.created', ['username' => $old['username'], 'role' => $role->value]);
                $this->responder->flash('success', 'account.created', ['username' => $old['username']]);

                return $this->responder->redirect($response, '/admin/users');
            }
        }

        return $this->responder->render($response, 'admin/user-new.html.twig', [
            'old' => $old,
            'errors' => $errors,
            'min' => PasswordPolicy::INDIVIDUAL_MIN_LENGTH,
        ], $errors === [] ? 200 : 422);
    }
}
