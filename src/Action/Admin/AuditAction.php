<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Admin;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Infrastructure\Persistence\AuditLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Audit log viewer (spec § 11.6), most recent first, filterable by action.
 */
final class AuditAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $actions = $this->audit->actions();
        $action = Responder::query($request, 'action');
        $action = \in_array($action, $actions, true) ? $action : null;
        $page = max(1, (int) Responder::query($request, 'page'));
        $total = $this->audit->count($action);

        return $this->responder->render($response, 'admin/audit.html.twig', [
            'entries' => $this->audit->page($page, $action),
            'actions' => $actions,
            'action' => $action,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / AuditLog::PAGE_SIZE)),
            'total' => $total,
        ]);
    }
}
