<?php

declare(strict_types=1);

namespace LiveScoreTracker\Http;

use Slim\Exception\HttpException;
use Slim\Handlers\ErrorHandler;

/**
 * Logs only real server errors: refused access, missing pages or expired
 * forms are normal events, not problems to investigate.
 */
final class AppErrorHandler extends ErrorHandler
{
    protected function writeToErrorLog(): void
    {
        if ($this->exception instanceof HttpException && $this->statusCode < 500) {
            return;
        }
        parent::writeToErrorLog();
    }
}
