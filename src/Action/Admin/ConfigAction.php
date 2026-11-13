<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Admin;

use Internal\Toml\Toml;
use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\ConfigForms;
use LiveScoreTracker\Domain\Config\ConfigDiff;
use LiveScoreTracker\Domain\Config\InvalidConfig;
use LiveScoreTracker\Infrastructure\Config\ConfigStore;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * Configuration screen (spec § 12.3): one tab per section of
 * `competition.toml`, the workshops and their variants, and the file itself
 * (advanced edit, export, import, previous versions).
 */
final class ConfigAction
{
    /** Changes kept in one audit entry. */
    private const AUDIT_CHANGES = 60;

    public function __construct(
        private readonly Responder $responder,
        private readonly ConfigStore $store,
        private readonly AttemptRepository $attempts,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tab = $args['tab'] ?? 'edition';
        if (!\in_array($tab, ConfigForms::TABS, true)) {
            throw new HttpNotFoundException($request);
        }
        if ($tab === 'file' && Responder::query($request, 'export') === '1') {
            return $this->export($response);
        }
        $editable = ConfigForms::editable($tab, $this->responder->context->phase);
        $workshop = $args['workshop'] ?? null;

        if ($request->getMethod() === 'POST') {
            if (!$editable) {
                $this->responder->flash('error', 'config.read_only');

                return $this->responder->redirect($response, '/admin/config/' . $tab);
            }
            $form = (array) $request->getParsedBody();

            return match (true) {
                $workshop !== null => $this->saveWorkshop($request, $response, $workshop, $form),
                $tab === 'workshops' => $this->workshopListAction($response, $form),
                $tab === 'file' => $this->fileAction($request, $response, $form),
                default => $this->saveTab($response, $tab, $form),
            };
        }

        if ($workshop !== null) {
            $index = $this->workshopIndex($request, $workshop);
            $data = $this->store->data();

            return $this->renderWorkshop($response, $index, ConfigForms::workshopValues($index === null ? [] : $this->workshops($data)[$index]), []);
        }

        return $this->renderTab($response, $tab, ConfigForms::values($tab, $this->store->data()), []);
    }

    // --------------------------------------------------------------- sections

    /**
     * @param array<mixed> $form
     */
    private function saveTab(ResponseInterface $response, string $tab, array $form): ResponseInterface
    {
        $before = $this->store->data();
        $after = ConfigForms::apply($tab, $before, $form);
        try {
            $this->save($before, $after, $tab);
        } catch (InvalidConfig $exception) {
            return $this->renderTab($response, $tab, ConfigForms::values($tab, $after), $exception->errors, 422);
        }

        return $this->responder->redirect($response, '/admin/config/' . $tab);
    }

    /**
     * @param array<mixed>         $values
     * @param list<string>         $errors
     */
    private function renderTab(ResponseInterface $response, string $tab, array $values, array $errors, int $status = 200): ResponseInterface
    {
        $data = $this->store->data();
        $extra = match ($tab) {
            'workshops' => ['workshops' => $this->workshops($data)],
            'file' => ['toml' => \is_string($values['toml'] ?? null) ? $values['toml'] : $this->store->toml(), 'history' => $this->store->history()],
            default => [],
        };

        return $this->responder->render($response, 'admin/config.html.twig', [
            'tab' => $tab,
            'tabs' => ConfigForms::TABS,
            'values' => $values,
            'errors' => self::errorMap($errors),
            'error_list' => $errors,
            'editable' => ConfigForms::editable($tab, $this->responder->context->phase),
            'uses_example' => $this->store->usesExample(),
            'phase' => $this->responder->context->phase->value,
            'tie_breakers' => ConfigForms::TIE_BREAKERS,
            'import_fields' => ConfigForms::IMPORT_FIELDS,
        ] + $extra, $status);
    }

    // -------------------------------------------------------------- workshops

    /**
     * @param array<mixed> $form
     */
    private function saveWorkshop(ServerRequestInterface $request, ResponseInterface $response, string $workshop, array $form): ResponseInterface
    {
        $index = $this->workshopIndex($request, $workshop);
        $before = $this->store->data();
        $after = $before;
        $list = $this->workshops($before);
        $updated = ConfigForms::workshop($index === null ? [] : $list[$index], $form);
        if ($index !== null) {
            // The id of an existing workshop names its results: it cannot change.
            $updated['id'] = $list[$index]['id'] ?? $updated['id'] ?? '';
            $list[$index] = $updated;
        } else {
            $list[] = $updated;
        }
        $after['workshops'] = $list;

        try {
            $this->save($before, $after, 'workshops');
        } catch (InvalidConfig $exception) {
            $values = ConfigForms::workshopValues($updated);

            return $this->renderWorkshop($response, $index, $values, $exception->errors, 422);
        }

        return $this->responder->redirect($response, '/admin/config/workshops');
    }

    /**
     * @param array<mixed>         $values
     * @param list<string>         $errors
     */
    private function renderWorkshop(ResponseInterface $response, ?int $index, array $values, array $errors, int $status = 200): ResponseInterface
    {
        $data = $this->store->data();
        // One empty variant, modifier and action row to add new ones without script.
        $variants = \is_array($values['variants'] ?? null) ? $values['variants'] : [];
        $variants[] = ['group' => '', 'measure' => 'time', 'better' => 'lower', 'attempts' => '1', 'foul_mode' => 'strict', 'time_format' => 'ss.cc', 'bibs_per_timer' => '1', 'modifiers' => [], 'actions' => [], 'new' => true];
        $rows = [];
        foreach ($variants as $variant) {
            $variant = \is_array($variant) ? $variant : [];
            $variant['modifiers'] = [...(\is_array($variant['modifiers'] ?? null) ? $variant['modifiers'] : []), ['id' => '', 'name' => '', 'value' => '', 'repeatable' => false]];
            $variant['actions'] = [...(\is_array($variant['actions'] ?? null) ? $variant['actions'] : []), ['id' => '', 'name' => '', 'points' => '']];
            $rows[] = $variant;
        }
        $values['variants'] = $rows;
        $prefix = $index === null ? 'workshops[' . \count($this->workshops($data)) . '].' : "workshops[{$index}].";

        return $this->responder->render($response, 'admin/config-workshop.html.twig', [
            'tab' => 'workshops',
            'tabs' => ConfigForms::TABS,
            'index' => $index,
            'values' => $values,
            'errors' => self::errorMap($errors, $prefix),
            'error_list' => $errors,
            'editable' => ConfigForms::editable('workshops', $this->responder->context->phase),
            'groups' => array_keys(\is_array($data['level_groups'] ?? null) ? $data['level_groups'] : []),
            'measures' => ConfigForms::MEASURES,
            'uses_example' => $this->store->usesExample(),
            'phase' => $this->responder->context->phase->value,
        ], $status);
    }

    /**
     * Duplicate, delete or move a workshop.
     *
     * @param array<mixed> $form
     */
    private function workshopListAction(ResponseInterface $response, array $form): ResponseInterface
    {
        $before = $this->store->data();
        $list = $this->workshops($before);
        $index = (int) (\is_string($form['index'] ?? null) ? $form['index'] : -1);
        $op = \is_string($form['op'] ?? null) ? $form['op'] : '';
        if (!isset($list[$index])) {
            return $this->responder->redirect($response, '/admin/config/workshops');
        }
        $id = \is_string($list[$index]['id'] ?? null) ? $list[$index]['id'] : '';

        switch ($op) {
            case 'duplicate':
                $copy = $list[$index];
                $ids = array_map(static fn(array $w): mixed => $w['id'] ?? null, $list);
                $n = 2;
                while (\in_array("{$id}_{$n}", $ids, true)) {
                    ++$n;
                }
                $copy['id'] = "{$id}_{$n}";
                unset($copy['help']);
                array_splice($list, $index + 1, 0, [$copy]);
                break;
            case 'delete':
                if ($this->attempts->hasWorkshopResults($id)) {
                    $this->responder->flash('error', 'config.workshop_has_results');

                    return $this->responder->redirect($response, '/admin/config/workshops');
                }
                array_splice($list, $index, 1);
                break;
            case 'up':
            case 'down':
                $other = $op === 'up' ? $index - 1 : $index + 1;
                if (!isset($list[$other])) {
                    return $this->responder->redirect($response, '/admin/config/workshops');
                }
                [$list[$index], $list[$other]] = [$list[$other], $list[$index]];
                break;
            default:
                return $this->responder->redirect($response, '/admin/config/workshops');
        }

        $after = $before;
        $after['workshops'] = $list;
        try {
            $this->save($before, $after, 'workshops');
        } catch (InvalidConfig $exception) {
            return $this->renderTab($response, 'workshops', [], $exception->errors, 422);
        }

        return $this->responder->redirect($response, $op === 'duplicate' ? '/admin/config/workshops/' . ($index + 1) : '/admin/config/workshops');
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    private function workshops(array $data): array
    {
        $list = \is_array($data['workshops'] ?? null) ? $data['workshops'] : [];

        return array_values(array_filter($list, \is_array(...)));
    }

    private function workshopIndex(ServerRequestInterface $request, string $workshop): ?int
    {
        if ($workshop === 'new') {
            return null;
        }
        $index = (int) $workshop;
        if (!isset($this->workshops($this->store->data())[$index])) {
            throw new HttpNotFoundException($request);
        }

        return $index;
    }

    // ------------------------------------------------------------------- file

    /**
     * Advanced edit, import (with preview) and restore of a previous version.
     *
     * @param array<mixed> $form
     */
    private function fileAction(ServerRequestInterface $request, ResponseInterface $response, array $form): ResponseInterface
    {
        $op = \is_string($form['op'] ?? null) ? $form['op'] : '';
        $toml = match ($op) {
            'edit', 'confirm' => \is_string($form['toml'] ?? null) ? str_replace("\r\n", "\n", $form['toml']) : '',
            'import' => $this->uploaded($request),
            'restore' => $this->store->historyToml(\is_string($form['file'] ?? null) ? $form['file'] : '') ?? '',
            default => '',
        };
        if ($toml === '') {
            $this->responder->flash('error', 'config.file_empty');

            return $this->responder->redirect($response, '/admin/config/file');
        }

        if ($op === 'import' || $op === 'restore') {
            // Show what would change, the admin then confirms.
            $errors = ConfigStore::check($toml);
            try {
                $changes = ConfigDiff::compare($this->store->data(), Toml::parseToArray($toml));
            } catch (Throwable) {
                $changes = [];
            }

            return $this->responder->render($response, 'admin/config-preview.html.twig', [
                'tab' => 'file',
                'tabs' => ConfigForms::TABS,
                'op' => $op,
                'toml' => $toml,
                'changes' => $changes,
                'error_list' => $errors,
                'uses_example' => $this->store->usesExample(),
                'phase' => $this->responder->context->phase->value,
            ]);
        }

        $before = $this->store->data();
        try {
            $this->store->saveToml($toml);
        } catch (InvalidConfig $exception) {
            return $this->renderTab($response, 'file', ['toml' => $toml], $exception->errors, 422);
        }
        $this->audited($before, $this->store->data(), 'file', \is_string($form['source'] ?? null) ? $form['source'] : $op);

        return $this->responder->redirect($response, '/admin/config/file');
    }

    private function uploaded(ServerRequestInterface $request): string
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== \UPLOAD_ERR_OK || ($file->getSize() ?? 0) > 200_000) {
            return '';
        }

        return str_replace("\r\n", "\n", (string) $file->getStream());
    }

    private function export(ResponseInterface $response): ResponseInterface
    {
        $this->responder->audit('config.exported');
        $response->getBody()->write($this->store->toml());

        return $response
            ->withHeader('Content-Type', 'application/toml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="competition-' . date('Ymd') . '.toml"');
    }

    // ----------------------------------------------------------------- shared

    /**
     * @param array<mixed> $before
     * @param array<mixed> $after
     *
     * @throws InvalidConfig
     */
    private function save(array $before, array $after, string $tab): void
    {
        if (ConfigDiff::compare($before, $after) === []) {
            $this->responder->flash('success', 'config.unchanged');

            return;
        }
        $this->store->save($after);
        $this->audited($before, $after, $tab, 'form');
    }

    /**
     * @param array<mixed> $before
     * @param array<mixed> $after
     */
    private function audited(array $before, array $after, string $tab, string $source): void
    {
        $changes = ConfigDiff::compare($before, $after);
        $this->responder->audit('config.changed', [
            'tab' => $tab,
            'source' => $source,
            'count' => \count($changes),
            'changes' => implode("\n", array_map(
                static fn(array $c): string => \sprintf('%s: %s → %s', $c['path'], $c['before'] ?? '—', $c['after'] ?? '—'),
                \array_slice($changes, 0, self::AUDIT_CHANGES),
            )),
        ]);
        $this->responder->flash('success', 'config.saved');
    }

    /**
     * Errors by location ("edition.date: must be…" → ["edition.date" => […]]).
     *
     * @param list<string> $errors
     *
     * @return array<string, list<string>>
     */
    private static function errorMap(array $errors, string $prefix = ''): array
    {
        $map = [];
        foreach ($errors as $error) {
            $parts = explode(': ', $error, 2);
            if (\count($parts) === 2) {
                $path = str_starts_with($parts[0], $prefix) ? substr($parts[0], \strlen($prefix)) : $parts[0];
                $map[$path][] = $parts[1];
            }
        }

        return $map;
    }
}
