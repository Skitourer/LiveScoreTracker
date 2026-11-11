<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Registration;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\RegistrationImport;
use LiveScoreTracker\Application\RegistrationMailer;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use LiveScoreTracker\Infrastructure\Settings;
use LiveScoreTracker\Infrastructure\Spreadsheet\SpreadsheetReader;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Spreadsheet import (spec § 5.7): upload, preview, then confirmation.
 *
 * The uploaded file is read in memory and deleted at once (spec § 25.2);
 * between preview and confirmation, only the cleaned new lines are kept
 * in the admin's session.
 */
final class RegistrationImportAction
{
    private const SESSION_KEY = 'import_plan';
    private const MAX_SIZE = 5 * 1024 * 1024;

    public function __construct(
        private readonly Responder $responder,
        private readonly RegistrationImport $import,
        private readonly SpreadsheetReader $reader,
        private readonly RegistrationRepository $registrations,
        private readonly CompetitionConfig $config,
        private readonly Settings $settings,
        private readonly RegistrationMailer $mailer,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->responder->context->session();

        if ($request->getMethod() === 'POST' && Responder::field($request, 'op') === 'confirm') {
            $plan = $session->get(self::SESSION_KEY);
            $session->remove(self::SESSION_KEY);
            $children = \is_array($plan) && \is_array($plan['children'] ?? null) ? array_values(array_filter($plan['children'], 'is_array')) : [];
            /** @var list<array<string, string|bool|null>> $children */
            $ids = $this->import->apply($children, $this->responder->context->user);
            $this->responder->audit('registration.imported', ['added' => \count($ids)]);
            $this->responder->flash('success', 'import.done', ['count' => \count($ids)]);
            if (Responder::field($request, 'send_emails') === '1') {
                $failed = 0;
                foreach ($ids as $id) {
                    $registration = $this->registrations->find($id);
                    if ($registration !== null && !$this->mailer->sendConfirmation($registration)) {
                        ++$failed;
                    }
                }
                $this->responder->audit('registration.emails_sent', ['sent' => \count($ids) - $failed, 'failed' => $failed]);
                $this->responder->flash($failed === 0 ? 'success' : 'warning', 'import.emails_sent', ['sent' => \count($ids) - $failed, 'failed' => $failed]);
            }

            return $this->responder->redirect($response, '/registrations');
        }

        $errors = [];
        if ($request->getMethod() === 'POST') {
            $session->remove(self::SESSION_KEY);
            $file = $request->getUploadedFiles()['file'] ?? null;
            try {
                $data = $this->readUpload($file);
                $plan = $this->import->plan($data['headers'], $data['rows']);
                $newChildren = [];
                foreach ($plan['lines'] as $line) {
                    if ($line['state'] === 'new' && $line['data'] !== null) {
                        $newChildren[] = $line['data'];
                    }
                }
                $session->set(self::SESSION_KEY, ['children' => $newChildren]);
                $registered = $this->registrations->countByStatus()[RegistrationStatus::Registered->value] ?? 0;

                return $this->responder->render($response, 'registrations/import-preview.html.twig', [
                    'plan' => $plan,
                    'fileName' => $file instanceof UploadedFileInterface ? (string) $file->getClientFilename() : '',
                    'overQuota' => $registered + \count($newChildren) > $this->config->registration->quota,
                    'quota' => $this->config->registration->quota,
                ]);
            } catch (RuntimeException $exception) {
                $errors[$exception->getMessage()] = ['max' => SpreadsheetReader::MAX_ROWS];
            }
        }

        return $this->responder->render($response, 'registrations/import.html.twig', [
            'errors' => $errors,
            'columns' => $this->config->importColumns,
            'fields' => RegistrationImport::FIELDS,
            'sample' => !$this->settings->isProduction(),
        ], $errors === [] ? 200 : 422);
    }

    /**
     * @return array{headers: list<string>, rows: list<array{line: int, cells: array<string, string>}>}
     */
    private function readUpload(mixed $file): array
    {
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('import.no_file');
        }
        if (($file->getSize() ?? 0) > self::MAX_SIZE) {
            throw new RuntimeException('import.too_big');
        }

        $temp = tempnam(sys_get_temp_dir(), 'lst-import-');
        if ($temp === false) {
            throw new RuntimeException('import.unreadable');
        }
        try {
            file_put_contents($temp, (string) $file->getStream());

            return $this->reader->read($temp, (string) $file->getClientFilename());
        } finally {
            @unlink($temp);
        }
    }
}
