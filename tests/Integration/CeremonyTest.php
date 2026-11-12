<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Step 9c: award ceremony driven from a phone (example configuration).
 */
final class CeremonyTest extends TestCase
{
    private string $dataDir;
    private WebClient $admin;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-ceremony-' . bin2hex(random_bytes(4));
        $this->admin = WebClient::migrated($this->dataDir);
        AdminSession::start($this->admin);
        foreach (['Zoé' => '2017-03-14', 'Léa' => '2017-05-02', 'Iris' => '2017-01-20', 'Anna' => '2017-02-11'] as $first => $birth) {
            $this->add('Martin', $first, $birth);
        }
        $this->admin->post('/admin/phase', ['phase' => 'running']);
        foreach (['1' => '300', '2' => '350', '3' => '250', '4' => '200'] as $bib => $jump) {
            $this->admin->post('/check-in/' . $bib, ['mark' => 'checked_in', 'done' => '1']);
            $this->admin->get('/entry/long_jump/' . $bib);
            $id = preg_match('#name="entry_id" value="([a-f0-9]{32})"#', $this->admin->lastBody, $m) === 1 ? $m[1] : '';
            $this->admin->post('/entry/long_jump/' . $bib, ['op' => 'record', 'kind' => 'value', 'value' => $jump, 'entry_id' => $id]);
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testOnlyAfterTheClosing(): void
    {
        $this->admin->get('/ceremony/control');
        self::assertStringContainsString('disponible une fois la compétition clôturée', $this->admin->lastBody);
        $this->admin->post('/ceremony/control', ['op' => 'reveal']);
        $this->admin->get('/admin/phase?close=1');
        $this->admin->post('/admin/phase', ['phase' => 'finished', 'confirm' => '1']);
        $this->admin->get('/ceremony');
        self::assertStringNotContainsString('podium-place place-3 shown', $this->admin->lastBody, 'Nothing revealed before the closing.');
    }

    public function testPodiumRevealedFromTheThirdPlace(): void
    {
        $this->admin->post('/admin/phase', ['phase' => 'finished', 'confirm' => '1']);
        $phone = AdminSession::organizer($this->admin);
        $screen = $this->admin;

        // CM1 girls are not the first category of the example: go there through the order.
        $phone->get('/ceremony/control');
        $index = preg_match('#<li class="[a-z]*">\s*<span>Filles CM1</span>.*?name="index" value="(\d+)"#s', $phone->lastBody, $m) === 1 ? $m[1] : '';
        self::assertNotSame('', $index);
        $phone->post('/ceremony/control', ['op' => 'go', 'index' => $index]);

        $screen->get('/ceremony');
        self::assertStringContainsString('<h1 class="ceremony-category">Filles CM1</h1>', $screen->lastBody);
        self::assertStringContainsString('data-live="2"', $screen->lastBody);
        self::assertStringNotContainsString('Iris MARTIN', $screen->lastBody, 'Nothing revealed yet.');

        $phone->get('/ceremony/control');
        self::assertStringContainsString('Révéler la 3e place', $phone->lastBody);
        $phone->post('/ceremony/control', ['op' => 'reveal']);
        $screen->get('/ceremony');
        self::assertStringContainsString('Iris MARTIN', $screen->lastBody, '3rd place first.');
        self::assertStringNotContainsString('Zoé MARTIN', $screen->lastBody);

        $phone->post('/ceremony/control', ['op' => 'reveal']);
        $phone->post('/ceremony/control', ['op' => 'reveal']);
        $screen->get('/ceremony');
        self::assertMatchesRegularExpression('#place-1 shown latest">\s*<p class="podium-rank">1re place</p>\s*<p class="podium-name">Léa MARTIN</p>#', $screen->lastBody);
        self::assertStringNotContainsString('Anna MARTIN', $screen->lastBody, 'Only the podium.');

        $phone->post('/ceremony/control', ['op' => 'hide']);
        $screen->get('/ceremony');
        self::assertStringNotContainsString('Léa MARTIN', $screen->lastBody);

        // Order: moving a category does not lose the current one.
        $phone->post('/ceremony/control', ['op' => 'up', 'category' => 'CM1-girl']);
        $phone->get('/ceremony/control');
        self::assertStringContainsString('<h2>Filles CM1</h2>', $phone->lastBody);
        self::assertStringContainsString('Révéler la 1re place', $phone->lastBody);
    }

    public function testRefereesHaveNoAccess(): void
    {
        $this->admin->post('/admin/referee', ['op' => 'create', 'username' => 'arbitre', 'password' => 'whistle-2027']);
        $phone = $this->admin->otherBrowser();
        $phone->post('/login', ['username' => 'arbitre', 'password' => 'whistle-2027']);
        $phone->post('/referee/name', ['first_name' => 'Paul']);
        self::assertSame(403, $phone->get('/ceremony/control')->getStatusCode());
    }

    private function add(string $lastName, string $firstName, string $birthDate): void
    {
        $response = $this->admin->post('/registrations/new', [
            'last_name' => $lastName, 'first_name' => $firstName, 'birth_date' => $birthDate, 'gender' => 'girl',
            'school' => 'Springfield Elementary', 'level' => 'CM1', 'guardian_name' => 'Anne ' . $lastName,
            'guardian_phone' => '0611223344', 'guardian_email' => 'parent@example.org', 'volunteer' => '0',
        ]);
        self::assertSame(303, $response->getStatusCode(), $this->admin->lastBody);
    }
}
