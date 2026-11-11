<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests of step 4: reception list and check-in on arrival and when leaving.
 */
final class ReceptionTest extends TestCase
{
    private string $dataDir;
    private WebClient $admin;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-reception-' . bin2hex(random_bytes(4));
        $this->admin = WebClient::migrated($this->dataDir);
        AdminSession::start($this->admin);
        $this->add('Martin', 'Zoé');
        $this->add('Durand', 'Hugo', 'boy');
        $this->add('Petit', 'Léo', 'boy');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testReceptionListPdf(): void
    {
        $response = $this->admin->get('/reception-list.pdf');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('%PDF-', $this->admin->lastBody);

        $this->admin->get('/admin/audit');
        self::assertStringContainsString('Liste d&#039;accueil imprimée', $this->admin->lastBody);
    }

    public function testCheckInIsClosedDuringPreparation(): void
    {
        $this->admin->get('/check-in/1');
        self::assertStringContainsString('L&#039;émargement s&#039;ouvre', $this->admin->lastBody);
        self::assertStringNotContainsString('name="mark"', $this->admin->lastBody);
        $this->admin->post('/check-in/1', ['mark' => 'checked_in', 'done' => '1']);
        $this->admin->get('/check-in');
        self::assertStringContainsString('<strong>0</strong> / 3', $this->admin->lastBody);
    }

    public function testArrivalAndLeaving(): void
    {
        $this->admin->post('/admin/phase', ['phase' => 'running']);

        // Type a bib: the child's card opens.
        self::assertSame('/check-in/2', $this->admin->get('/check-in?q=2')->getHeaderLine('Location'));
        self::assertSame(404, $this->admin->get('/check-in?q=99')->getStatusCode());
        self::assertStringContainsString('Aucun enfant avec le dossard 99', $this->admin->lastBody);
        // Or a name.
        self::assertSame('/check-in/3', $this->admin->get('/check-in?q=leo')->getHeaderLine('Location'));

        $this->admin->get('/check-in/2');
        self::assertStringContainsString('<p class="check-in-first-name">Hugo</p>', $this->admin->lastBody);
        // Leaving boxes are not offered before arrival.
        self::assertStringNotContainsString('name="mark" value="bib_returned"', $this->admin->lastBody);
        $this->admin->post('/check-in/2', ['mark' => 'bib_returned', 'done' => '1']);
        $this->admin->get('/check-in/2');
        self::assertStringContainsString('n&#039;a pas été pointé', $this->admin->lastBody);

        // Arrival: back to the bib field for the next child.
        self::assertSame('/check-in', $this->admin->post('/check-in/2', ['mark' => 'checked_in', 'done' => '1'])->getHeaderLine('Location'));
        $this->admin->get('/check-in');
        self::assertStringContainsString('Hugo DURAND (2) : dossard remis', $this->admin->lastBody);
        self::assertStringContainsString('<strong>1</strong> / 3', $this->admin->lastBody);

        $this->admin->get('/check-in?list=absent');
        self::assertStringContainsString('Zoé</strong> MARTIN', $this->admin->lastBody);
        self::assertStringNotContainsString('Hugo</strong>', $this->admin->lastBody);

        // Leaving: the card stays open until both boxes are ticked.
        self::assertSame('/check-in/2', $this->admin->post('/check-in/2', ['mark' => 'bib_returned', 'done' => '1'])->getHeaderLine('Location'));
        $this->admin->get('/check-in?list=not_left');
        self::assertStringContainsString('Hugo</strong> DURAND', $this->admin->lastBody);
        // Arrival cannot be undone while a leaving box is ticked.
        $this->admin->post('/check-in/2', ['mark' => 'checked_in', 'done' => '0']);
        $this->admin->get('/check-in/2');
        self::assertStringContainsString('Décochez d&#039;abord la sortie', $this->admin->lastBody);
        self::assertSame('/check-in', $this->admin->post('/check-in/2', ['mark' => 'gift_given', 'done' => '1'])->getHeaderLine('Location'));
        $this->admin->get('/check-in');
        self::assertStringContainsString('<strong>1</strong> dossards rendus', $this->admin->lastBody);
        self::assertStringContainsString('<strong>1</strong> cadeaux remis', $this->admin->lastBody);

        // The lists, then undo a mistake and see it all in the history.
        $this->admin->get('/check-in?list=left');
        self::assertStringContainsString('Partis (1)', $this->admin->lastBody);
        self::assertStringContainsString('Hugo</strong> DURAND', $this->admin->lastBody);

        // The full list, by bib, with each child's state.
        $this->admin->get('/check-in?list=all');
        self::assertStringContainsString('Tous (3)', $this->admin->lastBody);
        self::assertStringContainsString('Présents (1)', $this->admin->lastBody);
        self::assertStringContainsString('Absents (2)', $this->admin->lastBody);
        self::assertMatchesRegularExpression('#Zoé</strong>.*Hugo</strong>.*Léo</strong>#s', $this->admin->lastBody);
        self::assertStringContainsString('parti', $this->admin->lastBody);

        $this->admin->post('/check-in/2', ['mark' => 'gift_given', 'done' => '0']);
        $this->admin->get('/registrations/2');
        self::assertStringContainsString('Cadeau + médaille remis : oui → non', $this->admin->lastBody);
        self::assertStringContainsString('Dossard remis : non → oui', $this->admin->lastBody);
        self::assertStringContainsString('présent', $this->admin->lastBody);
    }

    public function testWithdrawnChildCannotBeCheckedIn(): void
    {
        $this->admin->post('/admin/phase', ['phase' => 'running']);
        $this->admin->post('/registrations/3', ['op' => 'status', 'status' => 'withdrawn']);
        $this->admin->get('/check-in/3');
        self::assertStringNotContainsString('name="mark"', $this->admin->lastBody);
        $this->admin->post('/check-in/3', ['mark' => 'checked_in', 'done' => '1']);
        $this->admin->get('/check-in/3');
        self::assertStringContainsString('n&#039;est pas inscrit', $this->admin->lastBody);
    }

    public function testOrganizerCanCheckInButRefereeCannot(): void
    {
        $this->admin->post('/admin/phase', ['phase' => 'running']);
        $organizer = AdminSession::organizer($this->admin);
        self::assertSame('/check-in', $organizer->post('/check-in/1', ['mark' => 'checked_in', 'done' => '1'])->getHeaderLine('Location'));
        self::assertSame(200, $organizer->get('/reception-list.pdf')->getStatusCode());

        $this->admin->post('/admin/referee', ['op' => 'create', 'username' => 'arbitre', 'password' => 'whistle-2027']);
        $phone = $this->admin->otherBrowser();
        $phone->get('/login');
        $phone->post('/login', ['username' => 'arbitre', 'password' => 'whistle-2027']);
        $phone->post('/referee/name', ['first_name' => 'Paul']);
        self::assertSame(403, $phone->get('/check-in')->getStatusCode());
        self::assertSame(403, $phone->get('/reception-list.pdf')->getStatusCode());
    }

    private function add(string $lastName, string $firstName, string $gender = 'girl'): void
    {
        $response = $this->admin->post('/registrations/new', [
            'last_name' => $lastName,
            'first_name' => $firstName,
            'birth_date' => '2017-03-14',
            'gender' => $gender,
            'school' => 'Springfield Elementary',
            'level' => 'CM1',
            'guardian_name' => 'Anne ' . $lastName,
            'guardian_phone' => '0611223344',
            'guardian_email' => 'parent@example.org',
            'volunteer' => '0',
        ]);
        self::assertSame(303, $response->getStatusCode(), $this->admin->lastBody);
    }
}
