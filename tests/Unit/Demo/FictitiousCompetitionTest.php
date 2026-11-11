<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Demo;

use LiveScoreTracker\Demo\FictitiousCompetition;
use LiveScoreTracker\Demo\ScoringReport;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Infrastructure\Config\ConfigRepository;
use PHPUnit\Framework\TestCase;

final class FictitiousCompetitionTest extends TestCase
{
    public function testSameSeedGivesSameData(): void
    {
        $a = new FictitiousCompetition(self::config(), 42);
        $b = new FictitiousCompetition(self::config(), 42);
        $c = new FictitiousCompetition(self::config(), 43);

        self::assertEquals($a->children, $b->children);
        self::assertEquals($a->attempts, $b->attempts);
        self::assertNotEquals($a->children, $c->children);
    }

    public function testGeneratesPlausibleCompetition(): void
    {
        $config = self::config();
        $competition = new FictitiousCompetition($config, 7);

        self::assertCount(100, $competition->children);
        self::assertSame('1', $competition->children[0]->bib);
        self::assertMatchesRegularExpression('/^\S+ \S\.$/u', $competition->children[0]->displayName());

        foreach ($config->variants() as $variant) {
            foreach ($competition->attempts[$variant->id()] ?? [] as $attempts) {
                self::assertCount($variant->attempts, $attempts);
            }
        }
    }

    public function testEveryCategoryCanBeRanked(): void
    {
        $config = self::config();
        $report = new ScoringReport($config, new FictitiousCompetition($config, 2027));
        $children = 0;

        foreach ($config->categories() as $category) {
            $closed = $report->forCategory($category, true);
            $children += \count($closed->children);
            self::assertCount(\count($closed->children), $closed->ranking);
            foreach ($closed->ranking as $line) {
                if ($line->present) {
                    self::assertTrue($line->complete, 'Every event is scored once the competition is closed.');
                }
            }
        }

        self::assertSame(100, $children);
    }

    private static function config(): CompetitionConfig
    {
        return ConfigRepository::loadFile(\dirname(__DIR__, 3) . '/config.example/competition.toml');
    }
}
