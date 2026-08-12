<?php

declare(strict_types=1);

use ideatic\l10n\Tools\Analysis\Check\IcuConsistency;
use ideatic\l10n\Tools\Analysis\Check\IcuIdCollision;
use ideatic\l10n\Tools\Analysis\Check\IcuPluralForms;
use ideatic\l10n\Tools\Analysis\Check\IcuRuntime;
use ideatic\l10n\Tools\Analysis\Check\IcuSuperseded;
use ideatic\l10n\Tools\Analysis\Check\IcuSyntax;
use ideatic\l10n\Tools\Analysis\Check\PluralCandidate;
use ideatic\l10n\Tools\Analysis\Check\TranslationSyntax;
use ideatic\l10n\Tools\Analysis\Options;
use ideatic\l10n\Tools\Analysis\Severity;
use PHPUnit\Framework\TestCase;

require_once 'AnalysisFixtures.php';

class AnalysisIcuChecksTest extends TestCase
{
    use AnalysisFixtures;

    public function testIcuSyntaxDetectsMissingOtherBranch(): void
    {
        $issues = $this->_run(new IcuSyntax(), [$this->_string('{count, plural, one {1 item}}', ['isICU' => true])]);

        $this->assertTrue($this->_hasMessage($issues, "has no 'other' branch"));
        $this->assertSame(Severity::Error, $issues[0]->severity);
    }

    public function testIcuSyntaxDetectsInvalidPattern(): void
    {
        $issues = $this->_run(new IcuSyntax(), [$this->_string('{count, wrongtype, one {1} other {#}}', ['isICU' => true])]);

        $this->assertTrue($this->_hasMessage($issues, 'Invalid ICU pattern'));
    }

    public function testIcuSyntaxDetectsUnknownPluralCategory(): void
    {
        $issues = $this->_run(new IcuSyntax(), [$this->_string('{count, plural, singular {1 item} other {# items}}', ['isICU' => true])]);

        $this->assertTrue($this->_hasMessage($issues, "unknown plural category 'singular'"));
    }

    public function testIcuSyntaxDetectsHashOutsideAPlural(): void
    {
        $issues = $this->_run(new IcuSyntax(), [$this->_string('# of {total, number}', ['isICU' => true])]);

        $this->assertTrue($this->_hasMessage($issues, "only replaced by the number inside a plural branch"));
    }

    public function testIcuSyntaxAcceptsValidPatterns(): void
    {
        $issues = $this->_run(
            new IcuSyntax(),
            [$this->_string('{count, plural, one {1 player} other {# players}}', ['isICU' => true])]
        );

        $this->assertSame([], $issues);
    }

    /**
     * Domain::generate() only throws when neither of the two strings is ICU, so this is the only warning that
     * content is being lost
     */
    public function testDetectsIcuIdCollisions(): void
    {
        $issues = $this->_run(
            new IcuIdCollision(),
            [
                $this->_string('{count, plural, one {1 goal} other {# goals}}', ['id' => 'goals', 'isICU' => true]),
                $this->_string('{count, plural, one {1 point} other {# points}}', ['id' => 'goals', 'isICU' => true]),
            ]
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Error, $issues[0]->severity);
        $this->assertStringContainsString('only the first one is kept', $issues[0]->message);
    }

    public function testPluralCandidateDetectsCountPlaceholders(): void
    {
        $issues = $this->_run(new PluralCandidate(), [$this->_string('%count% players')]);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString("count placeholder 'count'", $issues[0]->message);
        $this->assertStringContainsString('players', implode('', $issues[0]->details));
    }

    public function testPluralCandidateIgnoresStringsWithoutCounts(): void
    {
        $issues = $this->_run(new PluralCandidate(), [$this->_string('Welcome %player%'), $this->_string('Round')]);

        $this->assertSame([], $issues);
    }

    public function testPluralCandidateIgnoresExistingIcuPatterns(): void
    {
        $issues = $this->_run(
            new PluralCandidate(),
            [$this->_string('{count, plural, one {1 player} other {# players}}', ['isICU' => true])]
        );

        $this->assertSame([], $issues);
    }

    public function testPluralCandidateDetectsHardcodedNumbers(): void
    {
        $issues = $this->_run(new PluralCandidate(), [$this->_string('The 3 highest bids')]);

        $this->assertTrue($this->_hasMessage($issues, "Hardcoded number '3 highest'"));
    }

    public function testIcuSupersededDetectsDuplicatedBranch(): void
    {
        $issues = $this->_run(
            new IcuSuperseded(),
            [
                $this->_string('{count, plural, one {1 player} other {# players}}', ['isICU' => true]),
                $this->_string('%count% players'),
            ]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString("duplicates the 'other' branch", $issues[0]->message);
    }

    public function testIcuSupersededIgnoresUnrelatedStrings(): void
    {
        $issues = $this->_run(
            new IcuSuperseded(),
            [
                $this->_string('{count, plural, one {1 player} other {# players}}', ['isICU' => true]),
                $this->_string('%count% teams'),
            ]
        );

        $this->assertSame([], $issues);
    }

    public function testIcuConsistencyDetectsNamedPlaceholderInsideABranch(): void
    {
        $issues = $this->_run(
            new IcuConsistency(),
            [$this->_string('{count, plural, one {1 goal} other {%count% goals}}', ['isICU' => true])]
        );

        $this->assertTrue($this->_hasMessage($issues, "uses the 'count' placeholder instead of '#'"));
    }

    public function testIcuConsistencyDetectsMinorityVariableName(): void
    {
        $issues = $this->_run(
            new IcuConsistency(),
            [
                $this->_string('{count, plural, one {1 goal} other {# goals}}', ['isICU' => true]),
                $this->_string('{count, plural, one {1 team} other {# teams}}', ['isICU' => true]),
                $this->_string('{n, plural, one {1 bid} other {# bids}}', ['isICU' => true]),
            ]
        );

        $this->assertTrue($this->_hasMessage($issues, "plural variable name 'n', while the domain mostly uses plural variable name 'count'"));
    }

    public function testIcuRuntimeIsSkippedWithoutConfiguration(): void
    {
        $issues = $this->_run(
            new IcuRuntime(),
            [$this->_string('You have {count, plural, one {1 message} other {# messages}} from {sender}', ['isICU' => true])]
        );

        $this->assertSame([], $issues);
    }

    public function testIcuRuntimeAppliesConfiguredConstraints(): void
    {
        $options = new Options();
        $options->icuRuntime = (object)[
            'allowSurroundingText' => false,
            'maxParameters' => 1,
            'allowedTypes' => ['plural'],
            'allowNesting' => false,
        ];

        $issues = $this->_run(
            new IcuRuntime(),
            [$this->_string('You have {count, plural, one {1 message} other {# messages}} from {sender}', ['isICU' => true])],
            options: $options
        );

        $this->assertTrue($this->_hasMessage($issues, 'does not accept text outside the ICU expression'));
        $this->assertTrue($this->_hasMessage($issues, 'at most 1 parameter(s)'));
    }

    public function testIcuPluralFormsDetectsMissingCategories(): void
    {
        $source = '{count, plural, one {1 player} other {# players}}';

        $issues = $this->_run(
            new IcuPluralForms(),
            [$this->_string($source, ['isICU' => true])],
            ['ru'],
            ['app@ru' => $this->_catalog('ru', [$source => '{count, plural, one {1 игрок} other {# игроков}}'])]
        );

        $this->assertTrue($this->_hasMessage($issues, 'missing the plural form(s) few, many'));
        $this->assertSame(Severity::Error, $issues[0]->severity);
    }

    public function testIcuPluralFormsAcceptsCompleteTranslations(): void
    {
        $source = '{count, plural, one {1 player} other {# players}}';
        $translation = '{count, plural, one {# игрок} few {# игрока} many {# игроков} other {# игрока}}';

        $issues = $this->_run(
            new IcuPluralForms(),
            [$this->_string($source, ['isICU' => true])],
            ['ru'],
            ['app@ru' => $this->_catalog('ru', [$source => $translation])]
        );

        $this->assertSame([], $issues);
    }

    public function testIcuPluralFormsWarnsAboutUnknownLocales(): void
    {
        $issues = $this->_run(new IcuPluralForms(), [$this->_string('Hello')], ['xx-YY']);

        $this->assertTrue($this->_hasMessage($issues, 'ICU does not know the locale'));
    }

    public function testTranslationSyntaxDetectsStructureChanges(): void
    {
        $source = '{gender, select, male {He won} female {She won} other {They won}}';

        $issues = $this->_run(
            new TranslationSyntax(),
            [$this->_string($source, ['isICU' => true])],
            ['es'],
            ['app@es' => $this->_catalog('es', [$source => '{gender, select, male {Ganó} other {Ganaron}}'])]
        );

        $this->assertTrue($this->_hasMessage($issues, 'missing the option(s) female'));
    }

    public function testTranslationSyntaxDetectsInvalidTranslations(): void
    {
        $source = '{count, plural, one {1 player} other {# players}}';

        $issues = $this->_run(
            new TranslationSyntax(),
            [$this->_string($source, ['isICU' => true])],
            ['es'],
            ['app@es' => $this->_catalog('es', [$source => '{count, plural, one {1 jugador} other {# jugadores}'])]
        );

        $this->assertTrue($this->_hasMessage($issues, 'Invalid ICU pattern in translation'));
    }
}
