<?php

declare(strict_types=1);

use ideatic\l10n\LString;
use ideatic\l10n\Tools\Analysis\Check\CommentQuality;
use ideatic\l10n\Tools\Analysis\Check\IcuConsistency;
use ideatic\l10n\Tools\Analysis\Check\IcuIdCollision;
use ideatic\l10n\Tools\Analysis\Check\IcuRuntime;
use ideatic\l10n\Tools\Analysis\Check\IcuSyntax;
use ideatic\l10n\Tools\Analysis\Check\IdenticalTranslation;
use ideatic\l10n\Tools\Analysis\Check\MarkupMismatch;
use ideatic\l10n\Tools\Analysis\Check\PlaceholderMismatch;
use ideatic\l10n\Tools\Analysis\Check\PluralCandidate;
use ideatic\l10n\Tools\Analysis\Check\TranslationSyntax;
use ideatic\l10n\Tools\Analysis\Options;
use ideatic\l10n\Tools\Analysis\Severity;
use PHPUnit\Framework\TestCase;

require_once 'AnalysisFixtures.php';

/**
 * In Angular templates LString::$text keeps the original template syntax while $id is already normalised to what
 * the translator sees, and is the key the translation is stored under (Angular.php:198 and :226).
 *
 * The checks reasoning about the translatable content must always use translatableText(): reading $text instead
 * makes every ICU template string look broken in every locale at once.
 */
class AnalysisAngularIdTest extends TestCase
{
    use AnalysisFixtures;

    /** Original template syntax: the selector is the expression producing the value */
    private const string TEMPLATE = '{user.points, plural, one {1 pt.} other {# pts.}}';

    /** Id normalised by the extractor, which is the catalog key and what the translator translates */
    private const string NORMALISED = '{count, plural, one {1 pt.} other {# pts.}}';

    public function testTranslatableTextPrefersTheNormalisedId(): void
    {
        $string = new LString();
        $string->text = self::TEMPLATE;
        $string->id = self::NORMALISED;
        $string->isICU = true;

        $this->assertSame(self::NORMALISED, $string->translatableText());
    }

    /**
     * With i18n="@@myId" the id is an arbitrary identifier that means nothing to the translator: there the
     * translatable content is still the text
     */
    public function testTranslatableTextIgnoresAnExplicitIdentifier(): void
    {
        $string = new LString();
        $string->text = 'Añadir jugador';
        $string->id = 'squad.add-player';

        $this->assertSame('Añadir jugador', $string->translatableText());

        $icu = new LString();
        $icu->text = self::TEMPLATE;
        $icu->id = 'squad.points';
        $icu->isICU = true;

        $this->assertSame(self::TEMPLATE, $icu->translatableText(), 'An opaque id must not be mistaken for normalized text');
    }

    /**
     * A normalised id only counts as such when it is the same message: different content means an explicit
     */
    public function testTranslatableTextRejectsAnIdWithDifferentContent(): void
    {
        $string = new LString();
        $string->text = '{user.points, plural, one {1 pt.} other {# pts.}}';
        $string->id = '{count, plural, one {1 goal} other {# goals}}';
        $string->isICU = true;

        $this->assertSame($string->text, $string->translatableText());
    }

    public function testTranslatableTextFallsBackToTheTextWhenThereIsNoId(): void
    {
        $string = new LString();
        $string->text = 'Hello';

        $this->assertSame('Hello', $string->translatableText(), 'An uninitialized id must not throw');

        $string->id = '';

        $this->assertSame('Hello', $string->translatableText(), 'An empty id behaves like fullyQualifiedID()');
    }

    /**
     * The case from the report: the translation is byte for byte identical to the id and two issues were still raised
     * contradicting each other, per locale
     */
    public function testTranslationIdenticalToTheIdReportsNoPlaceholderMismatch(): void
    {
        $issues = $this->_run(
            new PlaceholderMismatch(),
            [$this->_angularPlural()],
            ['en', 'es', 'ru'],
            [
                'app@en' => $this->_catalog('en', [self::NORMALISED => self::NORMALISED]),
                'app@es' => $this->_catalog('es', [self::NORMALISED => '{count, plural, one {1 pto.} other {# ptos.}}']),
                'app@ru' => $this->_catalog('ru', [self::NORMALISED => '{count, plural, one {# очко} few {# очка} many {# очков} other {# очка}}']),
            ]
        );

        $this->assertSame([], $issues);
    }

    /**
     * ICU4C rejects "user.points" as an argument name because the dot is not valid
     */
    public function testAngularSelectorIsNotReportedAsInvalidIcu(): void
    {
        $issues = $this->_run(new IcuSyntax(), [
            $this->_angularPlural(),
            $this->_string('{events().goals ?? 0, plural, one {Gol} other {Goles}}', ['id' => '{count, plural, one {Gol} other {Goles}}', 'isICU' => true]),
        ]);

        $this->assertSame([], $issues);
    }

    public function testRealIcuErrorsAreStillReported(): void
    {
        $issues = $this->_run(new IcuSyntax(), [
            $this->_string('{user.points, plural, one {1 pt.}}', ['id' => '{count, plural, one {1 pt.}}', 'isICU' => true]),
        ]);

        $this->assertTrue($this->_hasMessage($issues, "has no 'other' branch"));
    }

    public function testTranslationSyntaxDoesNotChokeOnTheAngularSelector(): void
    {
        $issues = $this->_run(
            new TranslationSyntax(),
            [$this->_angularPlural()],
            ['es'],
            ['app@es' => $this->_catalog('es', [self::NORMALISED => '{count, plural, one {1 pto.} other {# ptos.}}'])]
        );

        $this->assertSame([], $issues);
    }

    public function testIcuRuntimeMeasuresTheNormalisedPattern(): void
    {
        $options = new Options();
        $options->icuRuntime = (object)['allowSurroundingText' => false, 'maxParameters' => 1, 'allowedTypes' => ['plural']];

        $issues = $this->_run(new IcuRuntime(), [$this->_angularPlural()], options: $options);

        $this->assertSame([], $issues);
    }

    public function testIcuConsistencyReadsTheNormalisedVariableName(): void
    {
        // All three use 'count' once normalised: there is no minority convention to report
        $issues = $this->_run(new IcuConsistency(), [
            $this->_angularPlural(),
            $this->_string('{team.goals, plural, one {1 gol} other {# goles}}', ['id' => '{count, plural, one {1 gol} other {# goles}}', 'isICU' => true]),
            $this->_string('{match.bids, plural, one {1 puja} other {# pujas}}', ['id' => '{count, plural, one {1 puja} other {# pujas}}', 'isICU' => true]),
        ]);

        $this->assertSame([], $issues);
    }

    public function testPluralCandidateLooksAtTheNormalisedText(): void
    {
        // %count% only appears in the id: reading $text would not detect it
        $issues = $this->_run(new PluralCandidate(), [
            $this->_string('{{ players.length | i18n:\'%count%\' }} jugadores', ['id' => '%count% jugadores']),
        ]);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString("count placeholder 'count'", $issues[0]->message);
    }

    public function testCommentPlaceholdersAreCheckedAgainstTheId(): void
    {
        $issues = $this->_run(new CommentQuality(), [
            $this->_string('{{ user.name | i18n:\'%player%\' }} scored', ['id' => '%player% scored', 'comments' => 'The %player% placeholder holds the player name']),
        ]);

        $this->assertSame([], $issues, 'The comment documents a placeholder that only exists in the id');
    }

    public function testMarkupIsComparedAgainstTheId(): void
    {
        $issues = $this->_run(
            new MarkupMismatch(),
            [$this->_string('Read the <a>rules</a>', ['id' => 'Read the <a>rules</a>'])],
            ['es'],
            ['app@es' => $this->_catalog('es', ['Read the <a>rules</a>' => 'Lee las <a>reglas</a>'])]
        );

        $this->assertSame([], $issues);
    }

    /**
     * It used to be compared against $text, so a translation equal to the id went undetected
     */
    public function testIdenticalTranslationComparesAgainstTheId(): void
    {
        $issues = $this->_run(
            new IdenticalTranslation(),
            [$this->_angularPlural()],
            ['es'],
            ['app@es' => $this->_catalog('es', [self::NORMALISED => self::NORMALISED])]
        );

        $this->assertCount(1, $issues);
    }

    /**
     * Several Angular expressions normalising to the same id are the intended behaviour of the extractor: it
     * does not matter which one is kept, because they all produce exactly the same translation
     */
    public function testDifferentSelectorsWithTheSameBranchesAreNotACollision(): void
    {
        $issues = $this->_run(new IcuIdCollision(), [
            $this->_string(self::TEMPLATE, ['id' => self::NORMALISED, 'isICU' => true]),
            $this->_string('{league.user.points, plural, one {1 pt.} other {# pts.}}', ['id' => self::NORMALISED, 'isICU' => true]),
            $this->_string('{-2 * penalty(), plural, one {1 pt.} other {# pts.}}', ['id' => self::NORMALISED, 'isICU' => true]),
        ]);

        $this->assertSame([], $issues);
    }

    /**
     * The dangerous case Domain::generate() does accept silently: same id, different content
     */
    public function testDifferentBranchesUnderTheSameIdAreStillAnError(): void
    {
        $issues = $this->_run(new IcuIdCollision(), [
            $this->_string('{a, plural, one {1 pt.} other {# pts.}}', ['id' => 'sharedId', 'isICU' => true]),
            $this->_string('{b, plural, one {1 goal} other {# goals}}', ['id' => 'sharedId', 'isICU' => true]),
        ]);

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Error, $issues[0]->severity);
    }

    public function testDifferentBranchCategoriesUnderTheSameIdAreAnError(): void
    {
        $issues = $this->_run(new IcuIdCollision(), [
            $this->_string('{a, plural, one {1 pt.} other {# pts.}}', ['id' => 'sharedId', 'isICU' => true]),
            $this->_string('{a, plural, other {# pts.}}', ['id' => 'sharedId', 'isICU' => true]),
        ]);

        $this->assertCount(1, $issues);
    }

    /**
     * The useful signal must not be lost: a stale translation inventing a placeholder is still an error
     */
    public function testStaleTranslationsAreStillDetected(): void
    {
        $issues = $this->_run(
            new PlaceholderMismatch(),
            [$this->_string('Ya podéis disfrutar de:', ['id' => 'Ya podéis disfrutar de:'])],
            ['ru'],
            ['app@ru' => $this->_catalog('ru', ['Ya podéis disfrutar de:' => 'Теперь вы можете насладиться %type%:'])]
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Error, $issues[0]->severity);
        $this->assertStringContainsString('%type%', $issues[0]->message);
    }

    private function _angularPlural(): LString
    {
        return $this->_string(self::TEMPLATE, ['id' => self::NORMALISED, 'isICU' => true]);
    }
}
