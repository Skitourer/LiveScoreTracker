<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Admin;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Demo\TestDataReset;
use LiveScoreTracker\Infrastructure\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Test sites only: replaces every registration and result by 100 fictitious
 * children ready for the start of the competition (95 with a bib). The admin
 * types a confirmation word, as everything is erased. Not available in production.
 */
final class TestDataAction
{
    public const CONFIRMATION = 'EFFACER';

    public function __construct(
        private readonly Responder $responder,
        private readonly TestDataReset $reset,
        private readonly Settings $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->settings->isProduction()) {
            throw new HttpNotFoundException($request);
        }
        if ($request->getMethod() === 'POST') {
            if (mb_strtoupper(trim(Responder::field($request, 'confirmation'))) !== self::CONFIRMATION) {
                $this->responder->flash('error', 'test_data.not_confirmed', ['word' => self::CONFIRMATION]);

                return $this->responder->redirect($response, '/admin/test-data');
            }
            $result = $this->reset->reset($this->responder->context->user);
            $this->responder->audit('test_data.reset', $result);
            $this->responder->flash('success', 'test_data.done', $result);

            return $this->responder->redirect($response, '/admin/test-data');
        }

        return $this->responder->render($response, 'admin/test-data.html.twig', ['word' => self::CONFIRMATION]);
    }
}
