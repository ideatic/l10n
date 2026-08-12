<?php

declare(strict_types=1);

use ideatic\l10n\Tools\Analysis\Check\CrossDomainDuplicates;
use ideatic\l10n\Tools\Analysis\Check\DuplicatedText;
use ideatic\l10n\Tools\Analysis\Check\EmptyCatalog;
use ideatic\l10n\Tools\Analysis\Check\IcuSuperseded;
use ideatic\l10n\Tools\Analysis\Check\IdenticalTranslation;
use ideatic\l10n\Tools\Analysis\Check\MissingTranslations;
use ideatic\l10n\Tools\Analysis\Check\NonLocalizable;
use ideatic\l10n\Tools\Analysis\Check\PluralCandidate;
use ideatic\l10n\Tools\Analysis\Check\UnconfiguredDomain;
use ideatic\l10n\Tools\Analysis\Options;
use ideatic\l10n\Tools\Analysis\Severity;
use PHPUnit\Framework\TestCase;

require_once 'AnalysisFixtures.php';

/**
 * A report that flags what is already correct can never be driven to zero, and the noise ends up burying what
 * does need fixing. Every case in this file is a correct situation the analyzer used to report as a problem.
 */
class AnalysisFalsePositivesTest extends TestCase
{
    use AnalysisFixtures;

    private const string ICU_POINTS = '{count, plural, one {# punto} other {# puntos}}';

    // -----------------------------------------------------------------------------------------------------------
    // icu-superseded: a label without a quantity is superseded by no plural at all
    // -----------------------------------------------------------------------------------------------------------

    public function testALabelWithoutAQuantityDoesNotSupersedeAPluralBranch(): void
    {
        foreach (['Puntos', 'Jugadores y puntos'] as $label) {
            $issues = $this->_run(new IcuSuperseded(), [
                $this->_string(self::ICU_POINTS, ['isICU' => true]),
                $this->_string($label),
            ]);

            $this->assertSame([], $issues, "\"{$label}\" is a static label, pluralising it would be wrong");
        }
    }

    public function testAStringShowingAQuantityIsStillReported(): void
    {
        foreach (['%count% puntos', '3 puntos', '{{ user.points }} puntos'] as $text) {
            $issues = $this->_run(new IcuSuperseded(), [
                $this->_string(self::ICU_POINTS, ['isICU' => true]),
                $this->_string($text),
            ]);

            $this->assertCount(1, $issues, "\"{$text}\" does duplicate the 'other' branch");
            $this->assertStringContainsString("duplicates the 'other' branch", $issues[0]->message);
        }
    }

    // -----------------------------------------------------------------------------------------------------------
    // non-localizable: a context or a comment proves the string was already thought about
    // -----------------------------------------------------------------------------------------------------------

    public function testAnnotatedStringsAreNotReportedAsNonLocalizable(): void
    {
        // 'C' for captain: it is 'K' in Russian and 'A' in Greek, so it does get translated
        $annotated = [
            'a context' => $this->_string('C', ['context' => 'acronym']),
            'a comment' => $this->_string('C', ['comments' => 'Abbreviation for "captain"']),
        ];

        foreach ($annotated as $annotation => $string) {
            $this->assertSame([], $this->_run(new NonLocalizable(), [$string]), "{$annotation} means it was considered");
        }
    }

    public function testUnannotatedSingleCharactersAreStillReported(): void
    {
        $issues = $this->_run(new NonLocalizable(), [$this->_string('C')]);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('is a single character', $issues[0]->message);
    }

    public function testAnEmptyContextDoesNotCountAsAnAnnotation(): void
    {
        $issues = $this->_run(new NonLocalizable(), [$this->_string('C', ['context' => '  '])]);

        $this->assertCount(1, $issues);
    }

    // -----------------------------------------------------------------------------------------------------------
    // identical-translation: the distance from the source locale changes what a match means
    // -----------------------------------------------------------------------------------------------------------

    public function testIdenticalTranslationsWeighTheWritingSystem(): void
    {
        $text = 'Total points';

        // 'gl' and 'ca' share the alphabet and much vocabulary with 'es': matching is normal.
        // A match in Cyrillic, Greek, Hebrew or Arabic means nobody has translated it
        $expected = ['gl' => Severity::Info, 'ca' => Severity::Info, 'pt' => Severity::Info, 'it' => Severity::Info,
                     'ru' => Severity::Warning, 'el' => Severity::Warning, 'he' => Severity::Warning, 'ar' => Severity::Warning];

        foreach ($expected as $locale => $severity) {
            $issues = $this->_run(
                new IdenticalTranslation(),
                [$this->_string($text)], // Domain::generate stamps the string, so it cannot be reused
                [$locale],
                ["app@{$locale}" => $this->_catalog($locale, [$text => $text])],
                configuration: ['sourceLocale' => 'es']
            );

            $this->assertCount(1, $issues, $locale);
            $this->assertSame($severity, $issues[0]->severity, "{$locale} vs es");
        }
    }

    public function testForeignScriptIdenticalTranslationsAreReportedFirst(): void
    {
        $text = 'Total points';

        $issues = $this->_run(
            new IdenticalTranslation(),
            [$this->_string($text)],
            ['gl', 'ru'],
            ['app@gl' => $this->_catalog('gl', [$text => $text]), 'app@ru' => $this->_catalog('ru', [$text => $text])],
            configuration: ['sourceLocale' => 'es']
        );

        $this->assertCount(2, $issues);
        $this->assertGreaterThan($issues[0]->priority, $issues[1]->priority, 'The Russian one matters more');
    }

    public function testIdenticalLocalesAreSkippedEntirely(): void
    {
        $text = 'Total points';
        $options = new Options();
        $options->identicalLocales = ['gl', 'pt'];

        $issues = $this->_run(
            new IdenticalTranslation(),
            [$this->_string($text)],
            ['gl', 'pt', 'ru'],
            [
                'app@gl' => $this->_catalog('gl', [$text => $text]),
                'app@pt' => $this->_catalog('pt', [$text => $text]),
                'app@ru' => $this->_catalog('ru', [$text => $text]),
            ],
            $options,
            ['sourceLocale' => 'es']
        );

        $this->assertCount(1, $issues);
        $this->assertSame('ru', $issues[0]->locale);
    }

    /** Without a source locale there is no distance to measure, and the check must keep working */
    public function testAMissingSourceLocaleFallsBackToInfo(): void
    {
        $text = 'Total points';

        $issues = $this->_run(
            new IdenticalTranslation(),
            [$this->_string($text)],
            ['ru'],
            ['app@ru' => $this->_catalog('ru', [$text => $text])],
            configuration: ['sourceLocale' => null]
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Info, $issues[0]->severity);
    }

    // -----------------------------------------------------------------------------------------------------------
    // Domains outside the pipeline: unconfigured-domain says it once, and that is enough
    // -----------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function _onlyAppIsConfigured(): array
    {
        $sources = new stdClass();
        $sources->app = new stdClass();

        $exports = new stdClass();
        $exports->domains = ['app'];

        return ['sources' => $sources, 'exports' => $exports];
    }

    public function testUntranslatedDomainsAreSkippedByTranslationChecks(): void
    {
        $strings = [$this->_string('Hello'), $this->_string('Buy', ['domain' => 'shop'])];

        $issues = $this->_run(new MissingTranslations(), $strings, ['fr'], configuration: $this->_onlyAppIsConfigured());

        $this->assertCount(1, $issues);
        $this->assertSame('app', $issues[0]->domain, 'The shop domain has no catalog and never will');
    }

    public function testUntranslatedDomainsDoNotReportAnEmptyCatalogPerLocale(): void
    {
        $strings = [$this->_string('Hello'), $this->_string('Buy', ['domain' => 'shop'])];
        $locales = ['fr', 'de', 'it'];

        $catalogs = ['app@fr' => $this->_catalog('fr', ['Hello' => 'Bonjour'])];

        $issues = $this->_run(new EmptyCatalog(), $strings, $locales, $catalogs, configuration: $this->_onlyAppIsConfigured());

        // Only the locales missing from 'app': 'shop' does not contribute one per locale
        $this->assertSame(['de', 'it'], array_map(static fn($issue): ?string => $issue->locale, $issues));
    }

    public function testDuplicatesWithAnUntranslatedSideAreNotReported(): void
    {
        $strings = [$this->_string('Cancel'), $this->_string('Cancel', ['domain' => 'shop'])];

        $issues = $this->_run(new CrossDomainDuplicates(), $strings, ['fr'], configuration: $this->_onlyAppIsConfigured());

        $this->assertSame([], $issues, 'A copy that is never translated cannot diverge');
    }

    public function testDuplicatesBetweenConfiguredDomainsAreStillReported(): void
    {
        $sources = new stdClass();
        $sources->app = new stdClass();
        $sources->shop = new stdClass();

        $strings = [$this->_string('Cancel'), $this->_string('Cancel', ['domain' => 'shop'])];

        $issues = $this->_run(
            new CrossDomainDuplicates(),
            $strings,
            ['fr'],
            [
                'app@fr' => $this->_catalog('fr', ['Cancel' => 'Annuler']),
                'shop@fr' => $this->_catalog('fr', ['Cancel' => 'Abandonner']),
            ],
            configuration: ['sources' => $sources]
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Error, $issues[0]->severity, 'The translations diverge');
    }

    public function testTheRootCauseIsStillReportedOnce(): void
    {
        $strings = [$this->_string('Hello'), $this->_string('Buy', ['domain' => 'shop'])];

        $issues = $this->_run(new UnconfiguredDomain(), $strings, ['fr'], configuration: $this->_onlyAppIsConfigured());

        $this->assertCount(1, $issues);
        $this->assertSame('shop', $issues[0]->domain);
    }

    /**
     * A project that does not describe its pipeline in l10n.json is not a project where every domain has been
     * forgotten: with nothing to compare against, they are all checked
     */
    public function testWithoutAnyPipelineConfigurationEveryDomainIsChecked(): void
    {
        $strings = [$this->_string('Hello'), $this->_string('Buy', ['domain' => 'shop'])];

        $issues = $this->_run(new MissingTranslations(), $strings, ['fr']);

        $this->assertSame(['app', 'shop'], array_map(static fn($issue): ?string => $issue->domain, $issues));
    }

    // -----------------------------------------------------------------------------------------------------------
    // duplicated-text: two homonyms split on purpose are not duplicated work
    // -----------------------------------------------------------------------------------------------------------

    /** "Plano" is 'Flat' or 'Floor plan' depending on what is meant: the catalogs already prove it */
    public function testHomonymsAlreadyTranslatedDifferentlyAreNotReported(): void
    {
        $issues = $this->_run(
            new DuplicatedText(),
            [$this->_string('Plano', ['context' => 'style']), $this->_string('Plano', ['context' => 'table'])],
            ['en'],
            ['app@en' => $this->_catalog('en', ['Plano@style' => 'Flat', 'Plano@table' => 'Floor plan'])]
        );

        $this->assertSame([], $issues, 'Merging them would break every locale');
    }

    /** A missing translation proves nothing: two are needed before they can be compared */
    public function testASingleTranslationIsNotEvidenceOfDivergence(): void
    {
        $issues = $this->_run(
            new DuplicatedText(),
            [$this->_string('Plano', ['context' => 'style']), $this->_string('Plano', ['context' => 'table'])],
            ['en'],
            ['app@en' => $this->_catalog('en', ['Plano@style' => 'Flat'])]
        );

        $this->assertCount(1, $issues);
    }

    public function testDeliberateContextsAreOnlyInformative(): void
    {
        $issues = $this->_run(
            new DuplicatedText(),
            [$this->_string('Otro', ['context' => 'eventType']), $this->_string('Otro', ['context' => 'gift'])],
            ['en'],
            ['app@en' => $this->_catalog('en', ['Otro@eventType' => 'Other', 'Otro@gift' => 'Other'])]
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Info, $issues[0]->severity);
        $this->assertStringContainsString('each with a different context', $issues[0]->message);
    }

    /** The real problem: the translator receives a single entry for two meanings */
    public function testAnEntryWithoutContextIsStillAWarning(): void
    {
        $issues = $this->_run(
            new DuplicatedText(),
            [$this->_string('Pendiente'), $this->_string('Pendiente', ['context' => 'rsvp'])],
            ['en'],
            ['app@en' => $this->_catalog('en', ['Pendiente' => 'Pending', 'Pendiente@rsvp' => 'Pending'])]
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Warning, $issues[0]->severity);
    }

    /** Without catalogs there is no evidence to consult, but the check still has to run */
    public function testDuplicatesAreStillDetectedWithoutAnyCatalog(): void
    {
        $issues = $this->_run(
            new DuplicatedText(),
            [$this->_string('Pendiente'), $this->_string('Pendiente', ['context' => 'rsvp'])]
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Warning, $issues[0]->severity);
    }

    // -----------------------------------------------------------------------------------------------------------
    // plural-candidate: a quantity only needs ICU when there is a word that has to agree with it
    // -----------------------------------------------------------------------------------------------------------

    public function testAmountsWithoutACountedNounAreNotPluralCandidates(): void
    {
        foreach (['Pagar %amount%', 'Por %amount%', 'Cláusula de %amount%', '%user% ofrece %amount%', 'Total: %count%'] as $text) {
            $issues = $this->_run(new PluralCandidate(), [$this->_string($text)]);

            $this->assertSame([], $issues, "\"{$text}\" formats a value, it does not count anything");
        }
    }

    public function testCountedNounsAreStillReported(): void
    {
        foreach (['%count% jugadores' => 'jugadores', '{total} puntos disponibles' => 'puntos'] as $text => $noun) {
            $issues = $this->_run(new PluralCandidate(), [$this->_string($text)]);

            $this->assertCount(1, $issues, $text);
            $this->assertSame(["Words that cannot agree with the number: {$noun}"], $issues[0]->details);
        }
    }

    /** The hint was taken from any placeholder, so it pointed at the verb following a different one */
    public function testTheHintOnlyLooksAtTheCountPlaceholder(): void
    {
        $issues = $this->_run(new PluralCandidate(), [$this->_string('%user% ofrece %count% puntos')]);

        $this->assertCount(1, $issues);
        $this->assertSame(['Words that cannot agree with the number: puntos'], $issues[0]->details);
    }
}
