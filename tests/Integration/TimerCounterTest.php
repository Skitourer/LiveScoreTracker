<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests of step 6: races on time events (built-in or external
 * timer, 1 or 2 children) and counter events.
 * Example configuration: sprint for both groups (2 children per race,
 * external timer offered first), hockey (built-in timer first, 1 child,
 * "goal scored" −3 s), basketball for CE2-CM2 (counter, 1:30, max 60 points).
 */
final class TimerCounterTest extends TestCase
{
    private string $dataDir;
    private WebClient $admin;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-timer-' . bin2hex(random_bytes(4));
        $this->admin = WebClient::migrated($this->dataDir);
        AdminSession::start($this->admin);
        $this->add('Martin', 'Zoé', 'CM1', '2017-03-14');   // bib 1, senior
        $this->add('Durand', 'Hugo', 'CP', '2020-05-10');   // bib 2, junior
        $this->add('Petit', 'Léa', 'CM2', '2016-09-02');    // bib 3, senior, not checked in
        $this->admin->post('/admin/phase', ['phase' => 'running']);
        $this->admin->post('/check-in/1', ['mark' => 'checked_in', 'done' => '1']);
        $this->admin->post('/check-in/2', ['mark' => 'checked_in', 'done' => '1']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testBibScreenOffersTheBuiltInTimerFirst(): void
    {
        // Sprint: 2 bibs, built-in timer first (main button).
        $this->admin->get('/entry/sprint');
        self::assertStringContainsString('name="bib2"', $this->admin->lastBody);
        self::assertStringContainsString('value="timer" class="button large"', $this->admin->lastBody);
        self::assertLessThan(strpos($this->admin->lastBody, 'value="manual"'), strpos($this->admin->lastBody, 'value="timer"'));
        self::assertStringContainsString('Voir ou corriger les essais', $this->admin->lastBody);

        // Hockey: 1 bib; the race opens with the built-in timer when no mode is given.
        $this->admin->get('/entry/hockey');
        self::assertStringNotContainsString('name="bib2"', $this->admin->lastBody);
        $this->admin->get('/entry/sprint/race?bib1=1');
        self::assertStringContainsString('name="mode" value="timer"', $this->admin->lastBody);

        // Other events keep the plain bib field.
        $this->admin->get('/entry/long_jump');
        self::assertStringNotContainsString('name="bib1"', $this->admin->lastBody);
        self::assertSame(404, $this->admin->get('/entry/basketball/race?bib1=1')->getStatusCode());
    }

    public function testEveryEntryScreenLeadsBackToTheWorkshopEntry(): void
    {
        $back = 'class="button small" href="/entry/sprint">Saisie</a>';
        foreach (['/entry/sprint/remaining', '/entry/sprint/1', '/entry/sprint/race?bib1=1', '/entry/sprint/help'] as $page) {
            $this->admin->get($page);
            self::assertStringContainsString($back, $this->admin->lastBody, $page);
        }
        $this->admin->get('/entry/sprint');
        self::assertStringNotContainsString($back, $this->admin->lastBody);
    }

    public function testRaceOfTwoIsSavedOnlyOnceEverythingIsConfirmed(): void
    {
        $this->admin->get('/entry/sprint/race?bib1=1&bib2=3&mode=timer');
        self::assertStringContainsString('Zoé M.', $this->admin->lastBody);
        self::assertStringContainsString('Léa P.', $this->admin->lastBody);
        self::assertStringContainsString('Départ', $this->admin->lastBody);
        self::assertStringContainsString('non pointé', $this->admin->lastBody);
        $form = $this->raceForm();

        // Léa is not checked in: confirm first, and nothing is saved meanwhile.
        $race = [...$form, '1_value' => '812', '1_timed' => '812', '2_value' => '845', '2_timed' => '840'];
        self::assertSame(422, $this->admin->post('/entry/sprint/race', $race)->getStatusCode());
        self::assertStringContainsString('pas été pointé', $this->admin->lastBody);
        self::assertStringContainsString('value="845"', $this->admin->lastBody, 'The times are kept for checking.');
        $this->admin->get('/entry/sprint/1');
        self::assertStringContainsString('Aucun essai', $this->admin->lastBody);

        $response = $this->admin->post('/entry/sprint/race', [...$race, 'confirm' => '1']);
        self::assertSame('/entry/sprint', $response->getHeaderLine('Location'));
        // The same race sent twice is saved once.
        $this->admin->post('/entry/sprint/race', [...$race, 'confirm' => '1']);
        $this->admin->get('/entry/sprint');
        self::assertStringContainsString('Zoé M. (1) : 8,12 s', $this->admin->lastBody);
        self::assertStringContainsString('Léa P. (3) : 8,45 s', $this->admin->lastBody);

        $this->admin->get('/entry/sprint/1');
        self::assertSame(1, substr_count($this->admin->lastBody, '<strong>8,12 s</strong>'));
        self::assertStringContainsString('chrono intégré', $this->admin->lastBody);
        self::assertStringNotContainsString('temps modifié', $this->admin->lastBody);
        $this->admin->get('/entry/sprint/3');
        self::assertStringContainsString('chrono intégré, temps modifié à la main', $this->admin->lastBody);
        self::assertStringContainsString('à vérifier', $this->admin->lastBody);
    }

    public function testDidNotFinishModifiersAndInvalidTimes(): void
    {
        // Hockey, one child, built-in timer by default; "did not finish" is a foul.
        $this->admin->get('/entry/hockey/race?bib1=1');
        self::assertStringContainsString('name="mode" value="timer"', $this->admin->lastBody);
        self::assertStringContainsString('But marqué', $this->admin->lastBody);
        $form = $this->raceForm();
        $this->admin->post('/entry/hockey/race', [...$form, '1_dnf' => '1']);
        $this->admin->get('/entry/hockey');
        self::assertStringContainsString('essai nul enregistré', $this->admin->lastBody);

        // External stopwatch: typed time, unreadable then valid, with a modifier.
        $this->admin->get('/entry/hockey/race?bib1=1&mode=manual');
        $form = $this->raceForm();
        self::assertSame(422, $this->admin->post('/entry/hockey/race', [...$form, '1_value' => '12a'])->getStatusCode());
        self::assertStringContainsString('Résultat illisible', $this->admin->lastBody);
        $this->admin->post('/entry/hockey/race', [...$form, '1_value' => '2000', '1_mod_goal_scored' => '1']);
        $this->admin->get('/entry/hockey/1');
        self::assertStringContainsString('<strong>17,00 s</strong>', $this->admin->lastBody);
        self::assertStringContainsString('Essais terminés (2/2)', $this->admin->lastBody);
        self::assertStringNotContainsString('chrono intégré', $this->admin->lastBody, 'Typed times are not marked as timed.');
    }

    public function testRaceBibsAreCheckedBeforeTheStart(): void
    {
        $this->admin->get('/entry/sprint/race?bib1=1&bib2=1');
        self::assertStringContainsString('saisi deux fois', $this->admin->get('/entry/sprint')->getBody()->__toString());
        $this->admin->get('/entry/sprint/race?bib1=99');
        self::assertStringContainsString('Dossard 99 inconnu', $this->admin->get('/entry/sprint')->getBody()->__toString());
        $this->admin->get('/entry/sprint/race?bib1=');
        self::assertStringContainsString('au moins un dossard', $this->admin->get('/entry/sprint')->getBody()->__toString());

        // A CP and a CM1 child: allowed, but the different events are pointed out.
        $this->admin->get('/entry/sprint/race?bib1=2&bib2=1');
        self::assertStringContainsString('30 m', $this->admin->lastBody);
        self::assertStringContainsString('40 m', $this->admin->lastBody);
        self::assertStringContainsString('pas la même épreuve', $this->admin->lastBody);

        // Outside the "running" phase the race can be prepared but not saved.
        $this->admin->post('/admin/phase', ['phase' => 'preparation']);
        $this->admin->get('/entry/sprint/race?bib1=1');
        self::assertStringNotContainsString('1_value', $this->admin->lastBody);
    }

    public function testCounterKeepsTheDetailOfEachAction(): void
    {
        $this->admin->get('/entry/basketball/1');
        self::assertStringContainsString('Démarrer', $this->admin->lastBody);
        self::assertStringContainsString('Panier', $this->admin->lastBody);
        self::assertStringContainsString('durée 1:30', $this->admin->lastBody);
        $entryId = self::match('#name="entry_id" value="([a-f0-9]{32})"#', $this->admin->lastBody);

        $this->admin->post('/entry/basketball/1', [
            'op' => 'record', 'kind' => 'counter', 'entry_id' => $entryId,
            'act_basket' => '3', 'act_rim' => '2', 'act_board' => '1',
        ]);
        $this->admin->get('/entry/basketball');
        self::assertStringContainsString('Zoé M. (1) : 14 pts', $this->admin->lastBody);
        $this->admin->get('/entry/basketball/1');
        self::assertStringContainsString('Panier ×3, Cercle ×2, Cadre ×1', $this->admin->lastBody);

        // Correction: the counts come back for checking, not a new run.
        $id = self::match('#\?correct=(\d+)#', $this->admin->lastBody);
        $this->admin->get('/entry/basketball/1?correct=' . $id);
        self::assertStringContainsString('name="act_basket" min="0" max="999" inputmode="numeric" value="3"', $this->admin->lastBody);
        self::assertStringContainsString('data-review="1"', $this->admin->lastBody);

        // Above the maximum (21 baskets = 63 points): to confirm.
        $this->admin->post('/entry/basketball/1', ['op' => 'correct', 'attempt_id' => $id, 'kind' => 'counter', 'act_basket' => '21']);
        self::assertStringContainsString('au-dessus du maximum', $this->admin->lastBody);
        self::assertStringContainsString('63 pts', $this->admin->lastBody);

        // Fallback: the total typed directly.
        $this->admin->post('/entry/basketball/1', ['op' => 'correct', 'attempt_id' => $id, 'kind' => 'value', 'value' => '22']);
        $this->admin->get('/entry/basketball/1');
        self::assertStringContainsString('corrigé en 22 pts', $this->admin->lastBody);
        self::assertStringContainsString('<strong>22 pts</strong>', $this->admin->lastBody);
    }

    /**
     * The hidden fields of the race form (bibs, mode, entry ids).
     *
     * @return array<string, string>
     */
    private function raceForm(): array
    {
        preg_match_all('#<input type="hidden" name="(bib1|bib2|mode|\d_entry_id)" value="([^"]*)"#', $this->admin->lastBody, $matches, PREG_SET_ORDER);
        $form = [];
        foreach ($matches as $match) {
            $form[$match[1]] = $match[2];
        }
        self::assertArrayHasKey('1_entry_id', $form);

        return $form;
    }

    private function add(string $lastName, string $firstName, string $level, string $birthDate): void
    {
        $response = $this->admin->post('/registrations/new', [
            'last_name' => $lastName, 'first_name' => $firstName, 'birth_date' => $birthDate, 'gender' => 'girl',
            'school' => 'Springfield Elementary', 'level' => $level, 'guardian_name' => 'Anne ' . $lastName,
            'guardian_phone' => '0611223344', 'guardian_email' => 'parent@example.org', 'volunteer' => '0',
        ]);
        self::assertSame(303, $response->getStatusCode(), $this->admin->lastBody);
    }

    private static function match(string $pattern, string $subject): string
    {
        if (preg_match($pattern, $subject, $m) !== 1 || !isset($m[1])) {
            self::fail("Pattern {$pattern} not found.");
        }

        return $m[1];
    }
}
