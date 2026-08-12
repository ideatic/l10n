<?php

declare(strict_types=1);

use ideatic\l10n\Tools\Analysis\Check\SimilarStrings;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Options;
use PHPUnit\Framework\TestCase;

require_once 'AnalysisFixtures.php';

class AnalysisSimilarStringsTest extends TestCase
{
    use AnalysisFixtures;

    public function testGroupsEveryMemberOfAClusterTogether(): void
    {
        $issues = $this->_run(
            new SimilarStrings(),
            [
                $this->_string('Create league'),
                $this->_string('Create League'),
                $this->_string('CREATE LEAGUE'),
            ]
        );

        $this->assertCount(1, $issues, 'Three related strings must produce a single cluster, not one per pair');
        $this->assertCount(3, $issues[0]->details);
        $this->assertStringContainsString('3 similar strings', $issues[0]->message);
    }

    public function testCaseOnlyDifferencesRankAboveFuzzyMatches(): void
    {
        $issues = $this->_run(
            new SimilarStrings(),
            [
                // Diferencia solo de mayúsculas, pero cadenas cortas
                $this->_string('Save'),
                $this->_string('save'),

                // Diferencia real, pero cadenas mucho más largas
                $this->_string('The championship standings are updated every hour'),
                $this->_string('The championship standings are updated every hours'),
            ]
        );

        $this->assertCount(2, $issues);

        usort($issues, static fn(Issue $a, Issue $b): int => $b->priority <=> $a->priority);

        $this->assertStringContainsString('case only', $issues[0]->message);
        $this->assertStringContainsString('similar', $issues[1]->message);
    }

    public function testLongerStringsRankFirstWithinTheSameKind(): void
    {
        $issues = $this->_run(
            new SimilarStrings(),
            [
                $this->_string('Ok'),
                $this->_string('OK'),
                $this->_string('Delete every pending offer'),
                $this->_string('Delete Every Pending Offer'),
            ]
        );

        $this->assertCount(2, $issues);

        usort($issues, static fn(Issue $a, Issue $b): int => $b->priority <=> $a->priority);

        $this->assertStringContainsString('Delete every pending offer', $issues[0]->details[0] . $issues[0]->details[1]);
    }

    public function testFrequentlyUsedStringsRankFirstWithinTheSameKind(): void
    {
        $issues = $this->_run(
            new SimilarStrings(),
            [
                $this->_string('Accept offer'),
                $this->_string('Accept Offer'),
                $this->_string('Reject offer'),
                $this->_string('Reject Offer'),
                $this->_string('Reject Offer', ['line' => 2]),
                $this->_string('Reject Offer', ['line' => 3]),
            ]
        );

        $this->assertCount(2, $issues);

        usort($issues, static fn(Issue $a, Issue $b): int => $b->priority <=> $a->priority);

        $this->assertStringContainsString('Reject', implode('', $issues[0]->details));
    }

    public function testSpacingAndPunctuationOnlyDifferences(): void
    {
        $issues = $this->_run(
            new SimilarStrings(),
            [
                $this->_string('Finishes soon.'),
                $this->_string('Finishes  soon'),
            ]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('spacing/punctuation only', $issues[0]->message);
    }

    public function testReportsTheSourceLocations(): void
    {
        $issues = $this->_run(
            new SimilarStrings(),
            [
                $this->_string('Create league', ['file' => 'src/league.php', 'line' => 12]),
                $this->_string('Create League', ['file' => 'src/home.php', 'line' => 40]),
            ]
        );

        $details = implode("\n", $issues[0]->details);

        $this->assertStringContainsString('src/league.php:12', $details);
        $this->assertStringContainsString('src/home.php:40', $details);
    }

    public function testUnrelatedStringsProduceNoIssues(): void
    {
        $issues = $this->_run(
            new SimilarStrings(),
            [
                $this->_string('Create league'),
                $this->_string('Delete account'),
                $this->_string('Round %number%'),
            ]
        );

        $this->assertSame([], $issues);
    }

    /**
     * El filtro por longitud es una optimización: debe producir exactamente los mismos grupos que comparar todos
     * los pares sin descartar ninguno
     */
    public function testLengthFilterDoesNotHideAnyMatch(): void
    {
        $strings = [
            $this->_string('The market closes in a few minutes'),
            $this->_string('The market closes in a few minute'),
            $this->_string('The market closed in a few minutes'),
            $this->_string('Short'),
            $this->_string('Shorter'),
            $this->_string('A completely unrelated sentence'),
        ];

        $options = new Options();
        $options->threshold = 90;
        $clusters = array_map(
            static fn(Issue $issue): string => implode("\n", $issue->details),
            $this->_run(new SimilarStrings(), $strings, options: $options)
        );

        // Todo par que supere el umbral comparándolo directamente debe acabar en un mismo grupo
        for ($i = 0; $i < count($strings); $i++) {
            for ($j = $i + 1; $j < count($strings); $j++) {
                $percent = 0.0;
                similar_text($strings[$i]->text, $strings[$j]->text, $percent);

                if ($percent <= 90) {
                    continue;
                }

                $shared = array_filter(
                    $clusters,
                    static fn(string $cluster): bool => str_contains($cluster, "\"{$strings[$i]->text}\"")
                        && str_contains($cluster, "\"{$strings[$j]->text}\"")
                );

                $this->assertNotEmpty(
                    $shared,
                    "The length pre-filter discarded the match between '{$strings[$i]->text}' and '{$strings[$j]->text}'"
                );
            }
        }
    }
}
