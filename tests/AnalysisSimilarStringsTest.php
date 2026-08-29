<?php

declare(strict_types=1);

use ideatic\l10n\Tools\Analysis\Check\SimilarStrings;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Options;
use ideatic\l10n\Tools\Analysis\Severity;
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
                // Case-only difference, but short strings
                $this->_string('Save'),
                $this->_string('save'),

                // A real difference, but much longer strings
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

    // -----------------------------------------------------------------------------------------------------------
    // Inflections of the same stem
    // -----------------------------------------------------------------------------------------------------------

    /**
     * "Regalo" and "Regalos" are different words, so unifying them would be wrong. No morphology is used to tell:
     * what is recognised is that one is the other plus a short run of letters, which holds in any language.
     */
    public function testInflectionsOfTheSameStemAreOnlyInformative(): void
    {
        // 'gift'/'gifts' is deliberately absent: at 88.9% it falls under the default threshold and is never
        // reported in the first place
        foreach ([['Regalo', 'Regalos'], ['Adulto', 'Adultos'], ['Bebé', 'Bebés'], ['Copia', 'Copiar'],
                  ['Respuesta', 'Respuestas'], ['Invitado', 'Invitados']] as [$singular, $plural]) {
            $issues = $this->_run(new SimilarStrings(), [$this->_string($singular), $this->_string($plural)]);

            $this->assertCount(1, $issues, "{$singular}/{$plural}");
            $this->assertSame(Severity::Info, $issues[0]->severity, "{$singular}/{$plural}");
            $this->assertStringContainsString('same stem, different ending', $issues[0]->message);
        }
    }

    public function testTheHintPointsAtIcuRatherThanAtMerging(): void
    {
        $issues = $this->_run(new SimilarStrings(), [$this->_string('Regalo'), $this->_string('Regalos')]);

        $this->assertTrue(
            array_any($issues[0]->details, static fn(string $detail): bool => str_contains($detail, 'ICU plural')),
            'Merging them is the wrong advice; an ICU plural is the right one'
        );
    }

    /** A real duplicate must not be demoted just because it also happens to be longer */
    public function testRealDuplicatesRemainWarnings(): void
    {
        foreach ([['Guardar cambios', 'Guardar Cambios'], ['Buscar', 'Buscar...'],
                  ['Crear invitación', 'Crear mi invitación']] as [$a, $b]) {
            $issues = $this->_run(new SimilarStrings(), [$this->_string($a), $this->_string($b)]);

            $this->assertCount(1, $issues, "{$a}/{$b}");
            $this->assertSame(Severity::Warning, $issues[0]->severity, "{$a}/{$b}");
        }
    }

    /** Only letters are an inflection: trailing punctuation is a formatting problem and keeps its own kind */
    public function testATrailingPunctuationDifferenceIsNotAnInflection(): void
    {
        $issues = $this->_run(new SimilarStrings(), [$this->_string('Buscar'), $this->_string('Buscar…')]);

        $this->assertSame(Severity::Warning, $issues[0]->severity);
        $this->assertStringContainsString('spacing/punctuation only', $issues[0]->message);
    }

    /** A difference in the middle of the string is not a shared stem */
    public function testADifferenceThatIsNotATrailingSuffixStaysAWarning(): void
    {
        $issues = $this->_run(
            new SimilarStrings(),
            [$this->_string('Enviar invitación por email'), $this->_string('Enviar invitaciones por email')]
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Warning, $issues[0]->severity);
    }

    /**
     * A cluster mixing an inflection with a real duplicate keeps the higher severity: the cluster kind is the
     * strongest relation found, and a seed of its own must not outrank it
     */
    public function testAClusterMixingKindsKeepsTheStrongestOne(): void
    {
        $issues = $this->_run(
            new SimilarStrings(),
            [$this->_string('Regalo'), $this->_string('Regalos'), $this->_string('regalo')]
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Warning, $issues[0]->severity);
        $this->assertStringContainsString('case only', $issues[0]->message);
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
     * The length filter is an optimisation: it must produce exactly the same groups as comparing every pair without
     * discarding any of them
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

        // Every pair beating the threshold when compared directly must end up in the same group
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
