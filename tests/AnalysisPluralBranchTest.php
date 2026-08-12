<?php

declare(strict_types=1);

use ideatic\l10n\Tools\Analysis\Check\IcuPluralForms;
use ideatic\l10n\Tools\Analysis\Check\IdenticalTranslation;
use ideatic\l10n\Tools\Analysis\Check\MarkupMismatch;
use ideatic\l10n\Tools\Analysis\Check\PlaceholderMismatch;
use ideatic\l10n\Tools\Analysis\Options;
use ideatic\l10n\Tools\Analysis\Placeholders;
use ideatic\l10n\Tools\Analysis\Severity;
use ideatic\l10n\Utils\ICU\Plural;
use PHPUnit\Framework\TestCase;

require_once 'AnalysisFixtures.php';

/**
 * The number of branches of a plural is decided by the locale: Japanese has one, Spanish two and Arabic six.
 * Anything counted by adding the branches up makes a correct translation look different from the source as soon
 * as the target locale does not have exactly the same forms as the source one.
 */
class AnalysisPluralBranchTest extends TestCase
{
    use AnalysisFixtures;

    private const string SOURCE = '{count, plural, one {1 foto} other {# fotos}}';

    // -----------------------------------------------------------------------------------------------------------
    // The # symbol also declared as a known placeholder
    // -----------------------------------------------------------------------------------------------------------

    /**
     * Angular templates may name an interpolation '#', which registers it in LString::$placeholders; it used to be
     * counted twice: once by the ICU walk and once by the known name
     */
    public function testHashDeclaredAsAKnownPlaceholderIsNotCountedTwice(): void
    {
        $known = ['#' => '#'];

        $this->assertSame(1, Placeholders::extract(self::SOURCE, true, $known)['#']);
        $this->assertSame(
            1,
            Placeholders::extract('{count, plural, one {Jedna fotka} few {# fotky} other {# fotek}}', true, $known)['#']
        );
    }

    public function testHashDeclaredAsKnownProducesNoMismatch(): void
    {
        $string = $this->_string(self::SOURCE, ['isICU' => true, 'placeholders' => ['#' => '#']]);
        $czech = '{count, plural, one {1 fotka} few {# fotky} other {# fotek}}';

        $issues = $this->_run(
            new PlaceholderMismatch(),
            [$string],
            ['cs'],
            ['app@cs' => $this->_catalog('cs', [self::SOURCE => $czech])]
        );

        $this->assertSame([], $issues);
    }

    // -----------------------------------------------------------------------------------------------------------
    // HTML markup inside the branches
    // -----------------------------------------------------------------------------------------------------------

    public function testMarkupIsNotSummedAcrossBranches(): void
    {
        $source = '{count, plural, one {<strong>1</strong> foto} other {<strong>#</strong> fotos}}';

        $this->assertSame(['<strong>' => 1, '</strong>' => 1], Placeholders::markup($source, isICU: true));
    }

    public function testMarkupMatchesAcrossLocalesWithDifferentBranchCounts(): void
    {
        $source = '{count, plural, one {<strong>1</strong> foto} other {<strong>#</strong> fotos}}';

        $issues = $this->_run(
            new MarkupMismatch(),
            [$this->_string($source, ['isICU' => true])],
            ['ru', 'ja'],
            [
                'app@ru' => $this->_catalog('ru', [$source => '{count, plural, one {<strong>#</strong> ф} few {<strong>#</strong> ф} many {<strong>#</strong> ф} other {<strong>#</strong> ф}}']),
                'app@ja' => $this->_catalog('ja', [$source => '{count, plural, other {<strong>#</strong> 枚}}']),
            ]
        );

        $this->assertSame([], $issues);
    }

    public function testMarkupDroppedFromEveryBranchIsStillReported(): void
    {
        $source = '{count, plural, one {<strong>1</strong> foto} other {<strong>#</strong> fotos}}';

        $issues = $this->_run(
            new MarkupMismatch(),
            [$this->_string($source, ['isICU' => true])],
            ['ja'],
            ['app@ja' => $this->_catalog('ja', [$source => '{count, plural, other {# 枚}}'])]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('missing <strong>, </strong>', $issues[0]->message);
    }

    // -----------------------------------------------------------------------------------------------------------
    // Named placeholders inside the branches
    // -----------------------------------------------------------------------------------------------------------

    public function testNamedPlaceholderIsCountedOncePerPluralRegardlessOfBranchCount(): void
    {
        $spanish = Placeholders::extract('{count, plural, one {{name} punto} other {{name} puntos}}', isICU: true);
        $russian = Placeholders::extract('{count, plural, one {{name} очко} few {{name} очка} many {{name} очков} other {{name} очков}}', isICU: true);
        $japanese = Placeholders::extract('{count, plural, other {{name} ポイント}}', isICU: true);

        $this->assertSame(1, $spanish['{name}']);
        $this->assertSame(['missing' => [], 'extra' => []], Placeholders::diff($spanish, $russian));
        $this->assertSame(['missing' => [], 'extra' => []], Placeholders::diff($spanish, $japanese));
    }

    public function testNamedPlaceholderDroppedFromEveryBranchIsStillReported(): void
    {
        $diff = Placeholders::diff(
            Placeholders::extract('{count, plural, one {{name} punto} other {{name} puntos}}', isICU: true),
            Placeholders::extract('{count, plural, other {puntos}}', isICU: true)
        );

        $this->assertSame(['{name}' => 1], $diff['missing']);
    }

    public function testPlaceholderAddedByTheTranslationIsStillReported(): void
    {
        $diff = Placeholders::diff(
            Placeholders::extract('{count, plural, other {# puntos}}', isICU: true),
            Placeholders::extract('{count, plural, other {# puntos de {league}}}', isICU: true)
        );

        $this->assertSame(['{league}' => 1], $diff['extra']);
    }

    // -----------------------------------------------------------------------------------------------------------
    // Unreachable plural categories
    // -----------------------------------------------------------------------------------------------------------

    /**
     * French 'many' is only selected from a million onwards, and Czech 'many' only by fractions: it is missing, but
     * the user will never get to see it
     */
    public function testUnreachableCategoriesAreWarningsInsteadOfErrors(): void
    {
        foreach (['fr' => 'many', 'it' => 'many', 'cs' => 'many'] as $locale => $category) {
            $issues = $this->_missingForms($locale);

            $this->assertArrayHasKey($category, $issues, "{$locale} should report {$category}");
            $this->assertSame(Severity::Warning, $issues[$category], "{$locale} {$category} is not reachable");
        }
    }

    public function testReachableCategoriesRemainErrors(): void
    {
        foreach (['ru' => 'few', 'cs' => 'few', 'he' => 'two', 'ar' => 'zero'] as $locale => $category) {
            $issues = $this->_missingForms($locale);

            $this->assertArrayHasKey($category, $issues, "{$locale} should report {$category}");
            $this->assertSame(Severity::Error, $issues[$category], "{$locale} {$category} is reachable");
        }
    }

    /**
     * In Hebrew 'one' is selected by 0.5 but also by 1: what matters is the smallest whole amount
     */
    public function testFractionalSamplesDoNotMakeACategoryLookUnreachable(): void
    {
        $this->assertSame(1, Plural::getSmallestCount('he', 'one'));
        $this->assertSame(1_000_000, Plural::getSmallestCount('fr', 'many'));
        $this->assertNull(Plural::getSmallestCount('cs', 'many'), 'Only fractions select it');
        $this->assertSame(2, Plural::getSmallestCount('ru', 'few'));
    }

    public function testPluralRangeIsConfigurable(): void
    {
        $options = new Options();
        $options->pluralRange = 10_000_000; // An application that does display millions

        $this->assertSame(Severity::Error, $this->_missingForms('fr', $options)['many']);
    }

    // -----------------------------------------------------------------------------------------------------------
    // Explicit =N keys
    // -----------------------------------------------------------------------------------------------------------

    /**
     * In Arabic 'zero' only covers n=0, and ICU resolves the exact keys first, so "=0 {...}" covers it
     */
    public function testExplicitKeyCoversTheCategoryItReplaces(): void
    {
        $arabic = '{count, plural, =0 {ninguna} one {1} two {2} few {#} many {#} other {#}}';

        $issues = $this->_run(
            new IcuPluralForms(),
            [$this->_string(self::SOURCE, ['isICU' => true])],
            ['ar'],
            ['app@ar' => $this->_catalog('ar', [self::SOURCE => $arabic])]
        );

        $this->assertSame([], $issues);
    }

    public function testAnExplicitKeyDoesNotCoverACategoryWithManyValues(): void
    {
        // '=2' does not cover Russian 'few', which is also selected by 3, 4, 22...
        $russian = '{count, plural, =2 {dos} one {#} many {#} other {#}}';

        $issues = $this->_run(
            new IcuPluralForms(),
            [$this->_string(self::SOURCE, ['isICU' => true])],
            ['ru'],
            ['app@ru' => $this->_catalog('ru', [self::SOURCE => $russian])]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('few', $issues[0]->message);
    }

    // -----------------------------------------------------------------------------------------------------------
    // Word boundaries outside the Latin alphabet
    // -----------------------------------------------------------------------------------------------------------

    public function testPlaceholderFollowedByHangulOrKanaIsFound(): void
    {
        $known = ['Eventia' => '<span>Eventia</span>'];

        $this->assertSame(['Eventia' => 1], Placeholders::extract('Eventia에서 ♥로 제작', false, $known));
        $this->assertSame(['Eventia' => 1], Placeholders::extract('Eventiaで♥を込めて', false, $known));
    }

    /**
     * A Latin suffix is a real modification of the placeholder and must still be reported
     */
    public function testPlaceholderWithALatinSuffixIsStillReportedAsMissing(): void
    {
        $known = ['Eventia' => '<span>Eventia</span>'];

        $this->assertSame([], Placeholders::extract('Tehty ♥:lla Eventiassa', false, $known));
        $this->assertSame([], Placeholders::extract('Készítve ♥-el Eventiá-ban', false, $known));
    }

    // -----------------------------------------------------------------------------------------------------------
    // Identical translations with nothing translatable in them
    // -----------------------------------------------------------------------------------------------------------

    public function testStringsMadeOnlyOfPlaceholdersAreNotReportedAsIdentical(): void
    {
        foreach (['%groom% & %bride%', '%unit%M', '%paid% de %total%', '%start% - %end%'] as $text) {
            $issues = $this->_run(
                new IdenticalTranslation(),
                [$this->_string($text)],
                ['es'],
                ['app@es' => $this->_catalog('es', [$text => $text])]
            );

            $this->assertSame([], $issues, "\"{$text}\" has nothing to translate");
        }
    }

    public function testStringsWithRealContentAreStillReported(): void
    {
        $text = '%count% jugadores';

        $issues = $this->_run(
            new IdenticalTranslation(),
            [$this->_string($text)],
            ['es'],
            ['app@es' => $this->_catalog('es', [$text => $text])]
        );

        $this->assertCount(1, $issues);
    }

    /**
     * Runs icu-plural-forms with a two-form translation and returns category => severity
     *
     * @return array<string, Severity>
     */
    private function _missingForms(string $locale, ?Options $options = null): array
    {
        $issues = $this->_run(
            new IcuPluralForms(),
            [$this->_string(self::SOURCE, ['isICU' => true])],
            [$locale],
            ['app@' . $locale => $this->_catalog($locale, [self::SOURCE => '{count, plural, one {1} other {#}}'])],
            $options
        );

        $found = [];
        foreach ($issues as $issue) {
            preg_match('/plural form\(s\) (.+?) required by/', $issue->message, $match);

            foreach (explode(', ', mb_trim($match[1] ?? '')) as $category) {
                $found[$category] = $issue->severity;
            }
        }

        return $found;
    }
}
