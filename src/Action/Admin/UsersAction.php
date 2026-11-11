<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Admin;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Security\Role;
use LiveScoreTracker\Infrastructure\Persistence\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * List of individual accounts (admins and organizers).
 */
final class UsersAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly UserRepository $users,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accounts = array_values(array_filter($this->users->all(), static fn($u) => $u->role !== Role::Referee));

        return $this->responder->render($response, 'admin/users.html.twig', [
            'accounts' => $accounts,
            'adminCount' => $this->users->countActiveAdmins(),
        ]);
    }
}
