<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use LiveScoreTracker\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Step 12c: contents editor, images, accent colour, logo and name display (spec § 12.3).
 */
final class ContentsAndBrandingTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\x0dIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xa7\x35\x81\x84\x00\x00\x00\x00IEND\xaeB`\x82";

    private string $dataDir;
    private WebClient $admin;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-contents-' . bin2hex(random_bytes(4));
        $this->admin = WebClient::migrated($this->dataDir);
        AdminSession::start($this->admin);
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dataDir);
    }

    public function testEditsThePublicRulesWithPreviewAndReset(): void
    {
        $this->admin->get('/admin/config/contents/pages/rules?lang=fr');
        self::assertStringContainsString('Pas encore de texte', $this->admin->lastBody);

        $this->admin->post('/admin/config/contents/pages/rules?lang=fr', ['op' => 'preview', 'markdown' => "# Règlement fictif\n\n[contacts]"]);
        self::assertStringContainsString('<h1>Règlement fictif</h1>', $this->admin->lastBody);
        self::assertStringContainsString('Camille Fournier', $this->admin->lastBody, 'The [contacts] line shows the contacts.');
        self::assertFileDoesNotExist($this->dataDir . '/pages/fr/rules.md');

        $this->admin->post('/admin/config/contents/pages/rules?lang=fr', ['op' => 'save', 'markdown' => '# Règlement fictif']);
        self::assertFileExists($this->dataDir . '/pages/fr/rules.md');
        $this->admin->get('/reglement');
        self::assertStringContainsString('Règlement fictif', $this->admin->lastBody);

        $this->admin->post('/admin/config/contents/pages/rules?lang=fr', ['op' => 'reset']);
        self::assertFileDoesNotExist($this->dataDir . '/pages/fr/rules.md');
        $this->admin->get('/admin/audit');
        self::assertStringContainsString('content.saved', $this->admin->lastBody);
    }

    public function testImagesAreCheckedStoredAndShown(): void
    {
        $png = $this->dataDir . '/plan.png';
        file_put_contents($png, self::PNG);
        $this->admin->upload('/admin/config/contents', 'image', $png, 'Plan Atelier.png', ['op' => 'add_image']);
        self::assertFileExists($this->dataDir . '/media/plan-atelier.png');

        $svg = $this->dataDir . '/x.png';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->admin->upload('/admin/config/contents', 'image', $svg, 'x.png', ['op' => 'add_image']);
        self::assertFileDoesNotExist($this->dataDir . '/media/x.png');

        $this->admin->post('/admin/config/contents/help/sprint?lang=fr', ['op' => 'save', 'markdown' => '![Plan](image:plan-atelier.png)']);
        $this->admin->get('/entry/sprint/help');
        self::assertStringContainsString('/media/plan-atelier.png', $this->admin->lastBody);
        $response = $this->admin->get('/media/plan-atelier.png');
        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
    }

    public function testAccentLogoAndNameDisplay(): void
    {
        $refused = $this->admin->post('/admin/config/theme', ['accent' => '#ffd700', 'referee_names' => 'short', 'participant_names' => 'short']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('Habillage › Couleur d&#039;accent', $this->admin->lastBody);

        $this->admin->post('/admin/config/theme', ['accent' => '#8A1538', 'referee_names' => 'full', 'participant_names' => 'short']);
        $file = (string) file_get_contents($this->dataDir . '/competition.toml');
        self::assertStringContainsString("accent = '#8a1538'", $file);
        self::assertStringContainsString("referee_names = 'full'", $file);
        $this->admin->get('/theme.css');
        self::assertStringContainsString('--color-accent: #8a1538;', $this->admin->lastBody);
        $this->admin->get('/');
        self::assertStringContainsString('/theme.css?c=8a1538', $this->admin->lastBody);

        $png = $this->dataDir . '/logo.png';
        file_put_contents($png, self::PNG);
        $this->admin->upload('/admin/config/theme', 'logo', $png, 'logo.png', ['op' => 'logo']);
        self::assertFileExists($this->dataDir . '/branding/logo.png');
        $this->admin->get('/');
        self::assertStringContainsString('class="brand-logo"', $this->admin->lastBody);

        // Referees now see full names, not "first name + initial".
        $this->admin->post('/admin/test-data', ['confirmation' => 'EFFACER']);
        $this->admin->get('/entry/sprint/pack');
        self::assertStringContainsString('"name":"', $this->admin->lastBody);
        self::assertDoesNotMatchRegularExpression('/"name":"[^"]+ \\p{Lu}\\."/u', $this->admin->lastBody);
    }
}
