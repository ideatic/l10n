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
 * Un informe que informa de lo que está bien no se puede vaciar, y el ruido acaba tapando lo que sí hay que
 * arreglar. Cada caso de este archivo es una situación correcta que el analizador informaba como problema.
 */
class AnalysisFalsePositivesTest extends TestCase
{
    use AnalysisFixtures;

    private const string ICU_POINTS = '{count, plural, one {# punto} other {# puntos}}';

    // -----------------------------------------------------------------------------------------------------------
    // icu-superseded: una etiqueta sin cantidad no la sustituye ningún plural
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
    // non-localizable: el contexto y el comentario demuestran que ya se ha pensado en la cadena
    // -----------------------------------------------------------------------------------------------------------

    public function testAnnotatedStringsAreNotReportedAsNonLocalizable(): void
    {
        // 'C' de capitán: es 'К' en ruso y 'A' en griego, así que sí se traduce
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
    // identical-translation: la distancia con el idioma de origen cambia lo que significa una coincidencia
    // -----------------------------------------------------------------------------------------------------------

    public function testIdenticalTranslationsWeighTheWritingSystem(): void
    {
        $text = 'Total points';

        // 'gl' y 'ca' comparten alfabeto y léxico con 'es': coincidir es normal.
        // Que coincida un idioma en cirílico, griego, hebreo o árabe significa que nadie lo ha traducido
        $expected = ['gl' => Severity::Info, 'ca' => Severity::Info, 'pt' => Severity::Info, 'it' => Severity::Info,
                     'ru' => Severity::Warning, 'el' => Severity::Warning, 'he' => Severity::Warning, 'ar' => Severity::Warning];

        foreach ($expected as $locale => $severity) {
            $issues = $this->_run(
                new IdenticalTranslation(),
                [$this->_string($text)], // Domain::generate marca la cadena, así que no se puede reutilizar
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

    /** Sin idioma de origen no hay distancia que medir, y la comprobación debe seguir funcionando */
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
    // Dominios fuera de la canalización: unconfigured-domain lo dice una vez, y con eso basta
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

        // Solo los idiomas que faltan en 'app': 'shop' no aporta uno por idioma
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
     * Un proyecto que no describe su canalización en l10n.json no es un proyecto en el que todos los dominios
     * estén olvidados: sin nada con lo que comparar, se comprueban todos
     */
    public function testWithoutAnyPipelineConfigurationEveryDomainIsChecked(): void
    {
        $strings = [$this->_string('Hello'), $this->_string('Buy', ['domain' => 'shop'])];

        $issues = $this->_run(new MissingTranslations(), $strings, ['fr']);

        $this->assertSame(['app', 'shop'], array_map(static fn($issue): ?string => $issue->domain, $issues));
    }

    // -----------------------------------------------------------------------------------------------------------
    // duplicated-text: dos homónimos separados a propósito no son trabajo duplicado
    // -----------------------------------------------------------------------------------------------------------

    /** "Plano" es 'Flat' o 'Floor plan' según de qué se hable: los catálogos ya lo demuestran */
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

    /** Una traducción que falta no demuestra nada: hacen falta dos para poder compararlas */
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

    /** El problema de verdad: el traductor recibe una sola entrada para dos significados */
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

    /** Sin catálogos no hay pruebas que consultar, pero la comprobación tiene que seguir ejecutándose */
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
    // plural-candidate: una cantidad solo necesita ICU cuando hay una palabra que concuerde con ella
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

    /** La pista se sacaba de cualquier marcador, así que señalaba al verbo que seguía a otro distinto */
    public function testTheHintOnlyLooksAtTheCountPlaceholder(): void
    {
        $issues = $this->_run(new PluralCandidate(), [$this->_string('%user% ofrece %count% puntos')]);

        $this->assertCount(1, $issues);
        $this->assertSame(['Words that cannot agree with the number: puntos'], $issues[0]->details);
    }
}
