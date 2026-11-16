<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Admin;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\ConfigForms;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Infrastructure\Config\ConfigStore;
use LiveScoreTracker\Infrastructure\Help\HelpPages;
use LiveScoreTracker\Infrastructure\Help\MediaStore;
use LiveScoreTracker\Infrastructure\I18n\PublicLanguages;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Slim\Exception\HttpNotFoundException;

/**
 * "Contents" tab of the configuration screen (spec § 12.3): Markdown editor
 * with preview for the home and rules pages (10 languages) and the workshop
 * help pages, and the images they use. Admins only.
 */
final class ContentsAction
{
    /** Public pages that can be edited. */
    public const PAGES = ['home', 'rules'];

    public function __construct(
        private readonly Responder $responder,
        private readonly HelpPages $pages,
        private readonly MediaStore $media,
        private readonly ConfigStore $store,
        private readonly CompetitionConfig $config,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $editable = ConfigForms::editable('contents', $this->responder->context->phase);
        $kind = $args['kind'] ?? null;
        if ($kind !== null) {
            return $this->editor($request, $response, $kind, $args['name'] ?? '', $editable);
        }

        if ($request->getMethod() === 'POST') {
            if (!$editable) {
                $this->responder->flash('error', 'config.read_only');
            } else {
                $this->images($request);
            }

            return $this->responder->redirect($response, '/admin/config/contents');
        }

        return $this->responder->render($response, 'admin/config-contents.html.twig', $this->view('contents', $editable) + [
            'pages' => self::PAGES,
            'workshops' => $this->config->workshops,
            'images' => $this->media->all(),
            'languages' => PublicLanguages::NAMES,
        ]);
    }

    private function editor(ServerRequestInterface $request, ResponseInterface $response, string $kind, string $name, bool $editable): ResponseInterface
    {
        $file = $this->file($kind, $name) ?? throw new HttpNotFoundException($request);
        $languages = $kind === 'pages' ? PublicLanguages::NAMES : array_intersect_key(PublicLanguages::NAMES, ['fr' => 1, 'en' => 1]);
        $lang = Responder::query($request, 'lang');
        if (!isset($languages[$lang])) {
            $lang = $this->config->edition->defaultLocale;
        }
        $back = '/admin/config/contents/' . $kind . '/' . $name . '?lang=' . $lang;
        $written = $this->pages->written($kind, $file, $lang);
        $text = $written ?? $this->pages->shipped($kind, $file, $lang) ?? '';
        $preview = null;

        if ($request->getMethod() === 'POST') {
            if (!$editable) {
                $this->responder->flash('error', 'config.read_only');

                return $this->responder->redirect($response, $back);
            }
            $op = Responder::field($request, 'op');
            $posted = Responder::field($request, 'markdown', false);
            if ($op === 'preview') {
                $text = $posted;
                $preview = $this->pages->convert($posted);
            } else {
                $this->pages->write($kind, $file, $lang, $op === 'reset' ? '' : $posted);
                $this->responder->audit('content.saved', ['page' => $kind . '/' . $file, 'lang' => $lang, 'action' => $op === 'reset' || trim($posted) === '' ? 'reset' : 'saved']);
                $this->responder->flash('success', $op === 'reset' ? 'config.contents.reset_done' : 'config.contents.saved');

                return $this->responder->redirect($response, $back);
            }
        }

        return $this->responder->render($response, 'admin/config-content-edit.html.twig', $this->view('contents', $editable) + [
            'kind' => $kind,
            'name' => $name,
            'lang' => $lang,
            'languages' => $languages,
            'markdown' => $text,
            'written' => $written !== null,
            'preview' => $preview,
            'images' => $this->media->all(),
        ]);
    }

    /**
     * Markdown file name of an editable page, or null.
     */
    private function file(string $kind, string $name): ?string
    {
        if ($kind === 'pages') {
            return \in_array($name, self::PAGES, true) ? $name . '.md' : null;
        }
        if ($kind === 'help') {
            foreach ($this->config->workshops as $workshop) {
                if ($workshop->id === $name) {
                    return HelpPages::helpFile($workshop);
                }
            }
        }

        return null;
    }

    private function images(ServerRequestInterface $request): void
    {
        if (Responder::field($request, 'op') === 'delete_image') {
            $name = Responder::field($request, 'image');
            $this->media->delete($name);
            $this->responder->audit('content.image_deleted', ['image' => $name]);

            return;
        }
        $file = $request->getUploadedFiles()['image'] ?? null;
        try {
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                throw new RuntimeException('config.contents.image_too_big');
            }
            $name = $this->media->save((string) $file->getClientFilename(), (string) $file->getStream());
            $this->responder->audit('content.image_added', ['image' => $name]);
            $this->responder->flash('success', 'config.contents.image_added', ['name' => $name]);
        } catch (RuntimeException $exception) {
            $this->responder->flash('error', $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function view(string $tab, bool $editable): array
    {
        return [
            'tab' => $tab,
            'tabs' => ConfigForms::TABS,
            'editable' => $editable,
            'error_list' => [],
            'uses_example' => $this->store->usesExample(),
            'phase' => $this->responder->context->phase->value,
        ];
    }
}
