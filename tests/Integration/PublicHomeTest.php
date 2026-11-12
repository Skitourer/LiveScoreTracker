<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Step 9a: public home page and rules (example configuration, fictitious data).
 */
final class PublicHomeTest extends TestCase
{
    private string $dataDir;
    private WebClient $visitor;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-home-' . bin2hex(random_bytes(4));
        $this->visitor = WebClient::migrated($this->dataDir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testHomeShowsTheEditionTheEventsAndTheRegistration(): void
    {
        self::assertSame(200, $this->visitor->get('/')->getStatusCode());
        $body = $this->visitor->lastBody;
        self::assertStringContainsString('<h1>Multisport Games 2027</h1>', $body);
        self::assertStringContainsString('Dimanche 6 juin 2027', $body);
        self::assertStringContainsString('de 09:00 à 12:00', $body);
        self::assertStringContainsString('Springfield Municipal Stadium', $body);
        self::assertStringContainsString('Organisé par Springfield School Sports Club', $body);
        self::assertStringContainsString('Une matinée de sport en plein air', $body, 'Presentation text from pages/fr/home.md.');
        self::assertStringContainsString('Sprint 30 m', $body);
        self::assertStringContainsString('Sprint 40 m', $body);
        self::assertStringContainsString('href="/reglement"', $body);
        self::assertStringContainsString('mailto:contact@example.org', $body);
        self::assertStringContainsString('>Connexion des bénévoles</a>', $body);
        // Example dates: registration opens on 15 March 2027.
        self::assertStringContainsString('Les inscriptions ouvriront le lundi 15 mars 2027', $body);
        self::assertStringContainsString('content="noindex, nofollow"', $body, 'Test sites stay out of search engines.');
        self::assertStringNotContainsString('Base de données', $body, 'The technical status moved to /status.');
    }

    public function testOnlyTheHomeIsIndexedInProduction(): void
    {
        $production = WebClient::migrated($this->dataDir, 'production');
        $production->get('/');
        self::assertStringContainsString('content="index, follow"', $production->lastBody);
        $production->get('/reglement');
        self::assertStringContainsString('content="noindex, nofollow"', $production->lastBody);
    }

    public function testRulesPage(): void
    {
        self::assertSame(200, $this->visitor->get('/reglement')->getStatusCode());
        self::assertStringContainsString('Article 3 — Responsabilité', $this->visitor->lastBody);
    }

    public function testCompetitionDayReplacesTheRegistrationBlock(): void
    {
        AdminSession::start($this->visitor);
        $this->visitor->get('/dashboard');
        self::assertStringContainsString('href="/status"', $this->visitor->lastBody);
        $this->visitor->post('/admin/phase', ['phase' => 'running']);
        $this->visitor->get('/');
        self::assertStringContainsString('La compétition est en cours', $this->visitor->lastBody);
        self::assertStringNotContainsString('Les inscriptions ouvriront', $this->visitor->lastBody);
    }
}
