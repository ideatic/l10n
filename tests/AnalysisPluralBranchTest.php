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
 * El número de ramas de un plural lo decide el idioma: el japonés tiene una, el español dos y el árabe seis. Todo
 * lo que se cuente sumando las ramas hace que una traducción correcta parezca distinta del original en cuanto el
 * idioma de destino no tiene exactamente las mismas formas que el de origen.
 */
class AnalysisPluralBranchTest extends TestCase
{
    use AnalysisFixtures;

    private const string SOURCE = '{count, plural, one {1 foto} other {# fotos}}';

    // -----------------------------------------------------------------------------------------------------------
    // El símbolo # declarado además como marcador conocido
    // -----------------------------------------------------------------------------------------------------------

    /**
     * Las plantillas de Angular pueden nombrar '#' a una interpolación, con lo que queda registrado en
     * LString::$placeholders y antes se contaba dos veces: una por el recorrido ICU y otra por el nombre conocido
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
    // Marcado HTML dentro de las ramas
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
    // Marcadores con nombre dentro de las ramas
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
    // Categorías plurales inalcanzables
    // -----------------------------------------------------------------------------------------------------------

    /**
     * 'many' en francés solo se selecciona a partir del millón, y en checo solo con decimales: falta, pero el
     * usuario no llegará a verlo
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
     * En hebreo 'one' se selecciona con 0,5 pero también con 1: lo que importa es la menor cantidad entera
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
        $options->pluralRange = 10_000_000; // Una aplicación que sí muestra millones

        $this->assertSame(Severity::Error, $this->_missingForms('fr', $options)['many']);
    }

    // -----------------------------------------------------------------------------------------------------------
    // Claves explícitas =N
    // -----------------------------------------------------------------------------------------------------------

    /**
     * En árabe 'zero' solo corresponde a n=0, e ICU resuelve antes las claves exactas, así que "=0 {...}" la cubre
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
        // '=2' no cubre 'few' en ruso, que también se selecciona con 3, 4, 22...
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
    // Límites de palabra fuera del alfabeto latino
    // -----------------------------------------------------------------------------------------------------------

    public function testPlaceholderFollowedByHangulOrKanaIsFound(): void
    {
        $known = ['Eventia' => '<span>Eventia</span>'];

        $this->assertSame(['Eventia' => 1], Placeholders::extract('Eventia에서 ♥로 제작', false, $known));
        $this->assertSame(['Eventia' => 1], Placeholders::extract('Eventiaで♥を込めて', false, $known));
    }

    /**
     * Un sufijo latino sí es una modificación real del marcador y debe seguir informándose
     */
    public function testPlaceholderWithALatinSuffixIsStillReportedAsMissing(): void
    {
        $known = ['Eventia' => '<span>Eventia</span>'];

        $this->assertSame([], Placeholders::extract('Tehty ♥:lla Eventiassa', false, $known));
        $this->assertSame([], Placeholders::extract('Készítve ♥-el Eventiá-ban', false, $known));
    }

    // -----------------------------------------------------------------------------------------------------------
    // Traducciones idénticas sin contenido traducible
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
     * Ejecuta icu-plural-forms con una traducción de solo dos formas y devuelve categoría => gravedad
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
