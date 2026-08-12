<?php

declare(strict_types=1);

use ideatic\l10n\Tools\Analysis\Check\CommentQuality;
use ideatic\l10n\Tools\Analysis\Check\ContextFormat;
use ideatic\l10n\Tools\Analysis\Check\EmptyStrings;
use ideatic\l10n\Tools\Analysis\Check\IcuPluralForms;
use ideatic\l10n\Tools\Analysis\Check\IcuSyntax;
use ideatic\l10n\Tools\Analysis\Check\IdenticalTranslation;
use ideatic\l10n\Tools\Analysis\Check\MarkupMismatch;
use ideatic\l10n\Tools\Analysis\Check\MissingComment;
use ideatic\l10n\Tools\Analysis\Check\MissingTranslations;
use ideatic\l10n\Tools\Analysis\Check\ObsoleteTranslations;
use ideatic\l10n\Tools\Analysis\Check\PlaceholderMismatch;
use ideatic\l10n\Tools\Analysis\Check\PluralCandidate;
use ideatic\l10n\Tools\Analysis\Check\SimilarStrings;
use ideatic\l10n\Tools\Analysis\Check\SourceFormatting;
use ideatic\l10n\Tools\Analysis\Check\TranslationSyntax;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Options;
use ideatic\l10n\Tools\Analysis\Placeholders;
use ideatic\l10n\Tools\Analysis\Severity;
use PHPUnit\Framework\TestCase;

require_once 'AnalysisFixtures.php';

/**
 * Casos límite y combinaciones que no cubren las pruebas básicas de cada comprobación
 */
class AnalysisAdvancedCasesTest extends TestCase
{
    use AnalysisFixtures;

    // -----------------------------------------------------------------------------------------------------------
    // Marcadores de posición
    // -----------------------------------------------------------------------------------------------------------

    public function testMixedPlaceholderSyntaxesInASingleString(): void
    {
        $found = Placeholders::extract('%name% scored {{ goals }} in ${match} (%d total)');

        $this->assertSame(['{{ goals }}' => 1, '${match}' => 1, '%name%' => 1, '%d' => 1], $found);
    }

    /**
     * "%d%" es ambiguo: puede leerse como el marcador con nombre "%d%" o como el printf "%d" seguido de un "%".
     * Se resuelve como marcador con nombre, que es la convención del proyecto.
     */
    public function testNamedPlaceholdersTakePrecedenceOverPrintf(): void
    {
        $this->assertSame(['%d%' => 1], Placeholders::extract('%d%'));
        $this->assertSame(['%d' => 1], Placeholders::extract('%d items'));
    }

    public function testAngularInterpolationIsNormalised(): void
    {
        $diff = Placeholders::diff(
            Placeholders::extract('Hello {{ user.name }}'),
            Placeholders::extract("Hola {{   user.name\n }}")
        );

        $this->assertSame([], $diff['missing']);
        $this->assertSame([], $diff['extra']);
    }

    /**
     * El símbolo # se refiere siempre al plural que lo contiene, incluso desde dentro de un 'select' anidado
     */
    public function testHashInsideANestedSelectBelongsToTheEnclosingPlural(): void
    {
        $found = Placeholders::extract(
            '{count, plural, other {{gender, select, female {# jugadoras} other {# jugadores}}}}',
            isICU: true
        );

        $this->assertSame(1, $found['#']);
    }

    /**
     * El nº de ramas plurales depende del idioma, así que el # se cuenta por marcador y no por aparición: de lo
     * contrario toda traducción a un idioma con más formas plurales parecería tener marcadores de más
     */
    public function testHashIsCountedOncePerPluralRegardlessOfBranchCount(): void
    {
        $spanish = Placeholders::extract('{count, plural, one {1 pt.} other {# pts.}}', isICU: true);
        $russian = Placeholders::extract('{count, plural, one {# очко} few {# очка} many {# очков} other {# очка}}', isICU: true);

        $this->assertSame(1, $spanish['#']);
        $this->assertSame(1, $russian['#']);
        $this->assertSame(['missing' => [], 'extra' => []], Placeholders::diff($spanish, $russian));
    }

    public function testAPluralThatDropsTheNumberEntirelyIsStillDetected(): void
    {
        $diff = Placeholders::diff(
            Placeholders::extract('{count, plural, one {1 pt.} other {# pts.}}', isICU: true),
            Placeholders::extract('{count, plural, one {un punto} other {varios puntos}}', isICU: true)
        );

        $this->assertSame(['#' => 1], $diff['missing']);
    }

    public function testNestedPluralsAreCountedSeparately(): void
    {
        $found = Placeholders::extract(
            '{a, plural, other {# of {b, plural, other {# total}}}}',
            isICU: true
        );

        $this->assertSame(2, $found['#']);
    }

    public function testHashOutsideAPluralIsNotAPlaceholder(): void
    {
        $found = Placeholders::extract('{gender, select, other {# wins}}', isICU: true);

        $this->assertArrayNotHasKey('#', $found);
    }

    public function testIcuSyntaxAcceptsHashInsideANestedSelect(): void
    {
        $issues = $this->_run(
            new IcuSyntax(),
            [$this->_string('{count, plural, other {{gender, select, other {# players}}}}', ['isICU' => true])]
        );

        $this->assertSame([], $issues);
    }

    public function testMarkupPlaceholdersAreComparedAgainstTheDeclaredOnes(): void
    {
        $string = $this->_string('Read the <a>rules</a>', ['placeholders' => ['<a>' => '<a href="/rules">', '</a>' => '</a>']]);

        $issues = $this->_run(
            new PlaceholderMismatch(),
            [$string],
            ['es'],
            ['app@es' => $this->_catalog('es', ['Read the <a>rules</a>' => 'Lee las reglas'])]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('<a>', $issues[0]->message);
    }

    public function testPlaceholderMismatchComparesIcuPlaceholders(): void
    {
        $source = '{count, plural, one {1 player} other {# players}}';

        $issues = $this->_run(
            new PlaceholderMismatch(),
            [$this->_string($source, ['isICU' => true])],
            ['ru'],
            ['app@ru' => $this->_catalog('ru', [$source => '{count, plural, one {1 игрок} other {%count% игроков}}'])]
        );

        $this->assertCount(2, $issues, 'The named placeholder is extra and the # is missing');
        $this->assertTrue($this->_hasMessage($issues, '%count%'));
        $this->assertTrue($this->_hasMessage($issues, '#'));
    }

    public function testPlaceholderMismatchIgnoresEmptyTranslations(): void
    {
        $issues = $this->_run(
            new PlaceholderMismatch(),
            [$this->_string('Hello %name%')],
            ['es'],
            ['app@es' => $this->_catalog('es', ['Hello %name%' => '   '])]
        );

        $this->assertSame([], $issues);
    }

    // -----------------------------------------------------------------------------------------------------------
    // Formas plurales
    // -----------------------------------------------------------------------------------------------------------

    public function testExplicitPluralKeysDoNotCountAsCategories(): void
    {
        $source = '{count, plural, one {1 player} other {# players}}';

        $issues = $this->_run(
            new IcuPluralForms(),
            [$this->_string($source, ['isICU' => true])],
            ['en'],
            ['app@en' => $this->_catalog('en', [$source => '{count, plural, =0 {nobody} one {1 player} other {# players}}'])]
        );

        $this->assertSame([], $issues, 'An =0 key is neither a missing nor an unused category');
    }

    public function testWarnsAboutCategoriesTheLocaleNeverUses(): void
    {
        $source = '{count, plural, one {1 player} other {# players}}';

        $issues = $this->_run(
            new IcuPluralForms(),
            [$this->_string($source, ['isICU' => true])],
            ['ja'],
            ['app@ja' => $this->_catalog('ja', [$source => '{count, plural, one {1人} other {#人}}'])]
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Warning, $issues[0]->severity);
        $this->assertStringContainsString("defines the plural form(s) one that 'ja' never uses", $issues[0]->message);
    }

    public function testOrdinalsAreCheckedAgainstTheOrdinalCategories(): void
    {
        $source = '{n, selectordinal, one {#st} two {#nd} few {#rd} other {#th}}';

        $issues = $this->_run(
            new IcuPluralForms(),
            [$this->_string($source, ['isICU' => true])],
            ['en'],
            ['app@en' => $this->_catalog('en', [$source => '{n, selectordinal, one {#º} other {#º}}'])]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('missing the plural form(s) two, few', $issues[0]->message);
    }

    // -----------------------------------------------------------------------------------------------------------
    // Traducciones
    // -----------------------------------------------------------------------------------------------------------

    public function testDetectsIcuTranslationsOfNonIcuSources(): void
    {
        $issues = $this->_run(
            new TranslationSyntax(),
            [$this->_string('One player')],
            ['es'],
            ['app@es' => $this->_catalog('es', ['One player' => '{count, plural, one {1 jugador} other {# jugadores}}'])]
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Warning, $issues[0]->severity);
        $this->assertStringContainsString('not marked as ICU', $issues[0]->message);
    }

    public function testDetectsPlaceholderTypeChangedByTheTranslation(): void
    {
        $source = '{count, plural, one {1 player} other {# players}}';

        $issues = $this->_run(
            new TranslationSyntax(),
            [$this->_string($source, ['isICU' => true])],
            ['es'],
            ['app@es' => $this->_catalog('es', [$source => '{count, select, one {1 jugador} other {varios jugadores}}'])]
        );

        $this->assertTrue($this->_hasMessage($issues, "is 'plural' in the source but 'select' in the translation"));
    }

    public function testMarkupComparisonIgnoresAttributesAndCase(): void
    {
        $issues = $this->_run(
            new MarkupMismatch(),
            [$this->_string('Read the <a href="/rules">rules</a>')],
            ['es'],
            ['app@es' => $this->_catalog('es', ['Read the <a href="/reglas" target="_blank">reglas</A>' => 'x', 'Read the <a href="/rules">rules</a>' => 'Lee las <A href="/reglas">reglas</A>'])]
        );

        $this->assertSame([], $issues);
    }

    public function testDetectsExtraMarkupAddedByTheTranslation(): void
    {
        $issues = $this->_run(
            new MarkupMismatch(),
            [$this->_string('Premium league')],
            ['es'],
            ['app@es' => $this->_catalog('es', ['Premium league' => 'Liga <strong>Premium</strong>'])]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('unexpected <strong>, </strong>', $issues[0]->message);
    }

    public function testDetectsRepeatedMarkupDroppedByTheTranslation(): void
    {
        $issues = $this->_run(
            new MarkupMismatch(),
            [$this->_string('<b>A</b> and <b>B</b>')],
            ['es'],
            ['app@es' => $this->_catalog('es', ['<b>A</b> and <b>B</b>' => '<b>A</b> y B'])]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('<b>, </b>', $issues[0]->message);
    }

    public function testIdenticalTranslationIgnoresShortAndSymbolOnlyStrings(): void
    {
        $issues = $this->_run(
            new IdenticalTranslation(),
            [$this->_string('Bye'), $this->_string('%s'), $this->_string('Dashboard')],
            ['es'],
            ['app@es' => $this->_catalog('es', ['Bye' => 'Bye', '%s' => '%s', 'Dashboard' => 'Dashboard'])]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('Dashboard', $issues[0]->message);
    }

    public function testAnEmptyTranslationCountsAsMissing(): void
    {
        $issues = $this->_run(
            new MissingTranslations(),
            [$this->_string('One'), $this->_string('Two')],
            ['es'],
            ['app@es' => $this->_catalog('es', ['One' => 'Uno', 'Two' => '  '])]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('1 of 2 strings (50.0%)', $issues[0]->message);
    }

    public function testLongListsOfMissingTranslationsAreCapped(): void
    {
        $strings = [];
        for ($i = 0; $i < 25; $i++) {
            $strings[] = $this->_string("String number {$i}");
        }

        $issues = $this->_run(new MissingTranslations(), $strings, ['es'], ['app@es' => $this->_catalog('es', [])]);

        $this->assertCount(11, $issues[0]->details);
        $this->assertSame('(+15 more)', $issues[0]->details[10]);
    }

    public function testLongListsOfObsoleteEntriesAreCapped(): void
    {
        $translations = [];
        for ($i = 0; $i < 25; $i++) {
            $translations["Removed {$i}"] = "Eliminada {$i}";
        }

        $issues = $this->_run(
            new ObsoleteTranslations(),
            [$this->_string('Still used')],
            ['es'],
            ['app@es' => $this->_catalog('es', $translations)]
        );

        $this->assertStringContainsString('25 of 25 catalog entries', $issues[0]->message);
        $this->assertSame('(+15 more)', $issues[0]->details[10]);
    }

    // -----------------------------------------------------------------------------------------------------------
    // Cadenas de origen y metadatos
    // -----------------------------------------------------------------------------------------------------------

    public function testSimilarStringsHonoursTheConfiguredThreshold(): void
    {
        // Domain::generate() marca cada cadena con su dominio, por lo que no pueden reutilizarse entre contextos
        $strings = fn(): array => [$this->_string('The market closes soon'), $this->_string('The market closed soon')];

        $strict = new Options();
        $strict->threshold = 99;

        $loose = new Options();
        $loose->threshold = 50;

        $this->assertSame([], $this->_run(new SimilarStrings(), $strings(), options: $strict));
        $this->assertCount(1, $this->_run(new SimilarStrings(), $strings(), options: $loose));
    }

    public function testSimilarStringsHandlesMultibyteText(): void
    {
        $issues = $this->_run(
            new SimilarStrings(),
            [$this->_string('Añadir jugador a la plantilla'), $this->_string('AÑADIR JUGADOR A LA PLANTILLA')]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('case only', $issues[0]->message);
    }

    public function testSimilarStringsIgnoresEmptyDomains(): void
    {
        $this->assertSame([], $this->_run(new SimilarStrings(), [$this->_string('Only one string')]));
    }

    public function testDomainChecksRunOverEveryDomain(): void
    {
        $check = new EmptyStrings();
        $this->assertInstanceOf(DomainCheck::class, $check, 'EmptyStrings relies on the per-domain loop');

        $issues = $this->_run(
            $check,
            [
                $this->_string('', ['id' => 'a', 'domain' => 'app']),
                $this->_string('', ['id' => 'b', 'domain' => 'admin']),
            ]
        );

        $this->assertSame(['app', 'admin'], array_map(static fn(object $issue): string => $issue->domain, $issues));
    }

    public function testDetectsControlCharactersAndInvalidEncoding(): void
    {
        $issues = $this->_run(
            new SourceFormatting(),
            [
                $this->_string("Bell\x07here", ['id' => 'control']),
                $this->_string("Invalid \xC3\x28 sequence", ['id' => 'encoding']),
            ]
        );

        $this->assertTrue($this->_hasMessage($issues, 'control characters'));
        $this->assertTrue($this->_hasMessage($issues, 'invalid UTF-8 sequence'));
    }

    public function testContextEndingInPunctuationLooksLikeAComment(): void
    {
        $issues = $this->_run(new ContextFormat(), [$this->_string('Add', ['context' => 'button:'])]);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('reads like a comment', $issues[0]->message);
    }

    public function testACommentOnAnyOccurrenceIsEnough(): void
    {
        $issues = $this->_run(
            new MissingComment(),
            [
                $this->_string('MVP', ['file' => 'src/a.php']),
                $this->_string('MVP', ['file' => 'src/b.php', 'comments' => 'Most valuable player']),
            ]
        );

        $this->assertSame([], $issues, 'The comment is shared by every occurrence of the same string');
    }

    public function testShortCommentsAreNotLanguageChecked(): void
    {
        $options = new Options();
        $options->commentLanguage = 'es';

        $issues = $this->_run(
            new CommentQuality(),
            [$this->_string('Squad', ['comments' => 'team name'])],
            options: $options
        );

        $this->assertSame([], $issues, 'Two words are not enough evidence to guess a language');
    }

    public function testCommentLanguageIsNotCheckedWhenNotConfigured(): void
    {
        $issues = $this->_run(
            new CommentQuality(),
            [$this->_string('Squad', ['comments' => 'The name of the team that the user manages'])]
        );

        $this->assertSame([], $issues);
    }

    public function testCountPlaceholderNamesAreConfigurable(): void
    {
        $options = new Options();
        $options->countPlaceholders = ['jornadas'];

        $issues = $this->_run(
            new PluralCandidate(),
            [$this->_string('Cesión por %jornadas% jornadas'), $this->_string('Total: %count%')],
            options: $options
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString("'jornadas'", $issues[0]->message);
    }

    public function testPluralCandidateAlsoLooksAtDeclaredPlaceholders(): void
    {
        $issues = $this->_run(
            new PluralCandidate(),
            [$this->_string('count players', ['placeholders' => ['count' => '{{ players.length }}']])]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString("count placeholder 'count'", $issues[0]->message);
    }
}
