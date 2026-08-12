<?php

declare(strict_types=1);

use ideatic\l10n\Tools\Analysis\Check\CommentQuality;
use ideatic\l10n\Tools\Analysis\Check\ContextFormat;
use ideatic\l10n\Tools\Analysis\Check\CrossDomainDuplicates;
use ideatic\l10n\Tools\Analysis\Check\DuplicatedText;
use ideatic\l10n\Tools\Analysis\Check\EmptyStrings;
use ideatic\l10n\Tools\Analysis\Check\FragmentStrings;
use ideatic\l10n\Tools\Analysis\Check\IdenticalTranslation;
use ideatic\l10n\Tools\Analysis\Check\MarkupMismatch;
use ideatic\l10n\Tools\Analysis\Check\MissingComment;
use ideatic\l10n\Tools\Analysis\Check\MissingTranslations;
use ideatic\l10n\Tools\Analysis\Check\NonLocalizable;
use ideatic\l10n\Tools\Analysis\Check\ObsoleteTranslations;
use ideatic\l10n\Tools\Analysis\Check\PlaceholderConvention;
use ideatic\l10n\Tools\Analysis\Check\PlaceholderMismatch;
use ideatic\l10n\Tools\Analysis\Check\SourceFormatting;
use ideatic\l10n\Tools\Analysis\Options;
use ideatic\l10n\Tools\Analysis\Registry;
use ideatic\l10n\Tools\Analysis\Severity;
use PHPUnit\Framework\TestCase;

require_once 'AnalysisFixtures.php';

class AnalysisChecksTest extends TestCase
{
    use AnalysisFixtures;

    public function testDetectsEmptyStrings(): void
    {
        $issues = $this->_run(new EmptyStrings(), [$this->_string('', ['id' => 'empty']), $this->_string('   ', ['id' => 'blank'])]);

        $this->assertCount(2, $issues);
        $this->assertSame(Severity::Error, $issues[0]->severity);
    }

    public function testDetectsSourceFormattingProblems(): void
    {
        $issues = $this->_run(
            new SourceFormatting(),
            [
                $this->_string('Save changes '),
                $this->_string('Auto  adjust photo'),
                $this->_string("Non\xc2\xa0breaking"),
                $this->_string('Join the "%league%" league'),
                $this->_string('Perfectly fine'),
            ]
        );

        $this->assertTrue($this->_hasMessage($issues, 'leading or trailing whitespace'));
        $this->assertTrue($this->_hasMessage($issues, 'consecutive spaces'));
        $this->assertTrue($this->_hasMessage($issues, 'non-breaking space'));
        $this->assertTrue($this->_hasMessage($issues, 'straight quotes wrapping a placeholder'));
        $this->assertCount(4, $issues);
    }

    public function testDetectsDuplicatedTextUnderDifferentIds(): void
    {
        $issues = $this->_run(
            new DuplicatedText(),
            [
                $this->_string('Add'),
                $this->_string('Add', ['context' => 'menu']),
            ]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('2 entries share the text "Add"', $issues[0]->message);
        $this->assertSame(Severity::Warning, $issues[0]->severity, 'One of them reaches the translator without any context');
    }

    public function testDetectsNonLocalizableStrings(): void
    {
        $issues = $this->_run(
            new NonLocalizable(),
            [
                $this->_string('123'),
                $this->_string('https://example.com'),
                $this->_string('support@example.com'),
                $this->_string('x'),
                $this->_string('Real text'),
            ]
        );

        $this->assertCount(4, $issues);
        $this->assertTrue($this->_hasMessage($issues, 'contains no letters'));
        $this->assertTrue($this->_hasMessage($issues, 'is a bare URL'));
        $this->assertTrue($this->_hasMessage($issues, 'is a bare email address'));
        $this->assertTrue($this->_hasMessage($issues, 'is a single character'));
    }

    public function testDetectsSentenceFragments(): void
    {
        $issues = $this->_run(
            new FragmentStrings(),
            [
                $this->_string('the round %number%'),
                $this->_string('https://example.com'),
                $this->_string('Complete sentence here'),
                $this->_string('esto es una frase completa.'),
            ]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('the round %number%', $issues[0]->message);
    }

    public function testDetectsEmptyContext(): void
    {
        $issues = $this->_run(new ContextFormat(), [$this->_string('Add', ['context' => ''])]);

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Error, $issues[0]->severity);
        $this->assertStringContainsString("ends in a bare '@'", $issues[0]->message);
    }

    public function testDetectsContextUsedAsAComment(): void
    {
        $issues = $this->_run(
            new ContextFormat(),
            [
                $this->_string('Add', ['context' => 'Button used to add a player into an empty slot']),
                $this->_string('Save', ['context' => 'button']),
            ]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('reads like a comment', $issues[0]->message);
    }

    public function testDetectsCommentsDocumentingMissingPlaceholders(): void
    {
        $issues = $this->_run(
            new CommentQuality(),
            [$this->_string('Round %number%', ['comments' => '%round% is replaced by the round number'])]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('Comment documents %round%', $issues[0]->message);
    }

    public function testDetectsCommentsInTheWrongLanguage(): void
    {
        $options = new Options();
        $options->commentLanguage = 'es';

        $issues = $this->_run(
            new CommentQuality(),
            [
                $this->_string('Squad', ['comments' => 'The name of the team that the user manages']),
                $this->_string('Bench', ['comments' => 'El nombre de la zona donde se colocan los suplentes']),
            ],
            options: $options
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString("written in 'en'", $issues[0]->message);
    }

    public function testReportsOnlyAmbiguousStringsWithoutComment(): void
    {
        $options = new Options();
        // Standalone Spanish participles (-ado/-ada/-ido/-ida): their gender is invisible in other languages
        $options->ambiguousWords = ['/^\p{L}+[ai]d[oa]s?$/ui'];

        $issues = $this->_run(
            new MissingComment(),
            [
                $this->_string('Cedidos'),
                $this->_string('MVP'),
                $this->_string('Hello %name%, you have %points% points'),
                $this->_string('This is a perfectly clear sentence'),
                $this->_string('MVP', ['id' => 'mvp-documented', 'comments' => 'Most valuable player']),
            ],
            options: $options
        );

        $this->assertCount(3, $issues);
        $this->assertTrue($this->_hasMessage($issues, 'ambiguous word pattern'));
        $this->assertTrue($this->_hasMessage($issues, 'short standalone label'));
        $this->assertTrue($this->_hasMessage($issues, '2 undocumented placeholders'));
    }

    public function testDetectsMinorityPlaceholderConventions(): void
    {
        $issues = $this->_run(
            new PlaceholderConvention(),
            [
                $this->_string('Hello %name%'),
                $this->_string('Bye %name%', ['id' => 'bye']),
                $this->_string('Round %number%'),
                $this->_string('Welcome %s'),
            ]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('printf (%s)', $issues[0]->message);
    }

    public function testDetectsPlaceholdersAddedByTheTranslation(): void
    {
        $issues = $this->_run(
            new PlaceholderMismatch(),
            [$this->_string('Enjoy the season')],
            ['es'],
            ['app@es' => $this->_catalog('es', ['Enjoy the season' => 'Disfruta de la temporada de %league%'])]
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Error, $issues[0]->severity);
        $this->assertStringContainsString('%league%', $issues[0]->message);
    }

    public function testDetectsPlaceholdersDroppedByTheTranslation(): void
    {
        $issues = $this->_run(
            new PlaceholderMismatch(),
            [$this->_string('Loans last between %min% and %max% rounds')],
            ['es'],
            ['app@es' => $this->_catalog('es', ['Loans last between %min% and %max% rounds' => 'Las cesiones duran %min% jornadas'])]
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Warning, $issues[0]->severity);
        $this->assertStringContainsString('%max%', $issues[0]->message);
    }

    public function testAcceptsMatchingPlaceholders(): void
    {
        $issues = $this->_run(
            new PlaceholderMismatch(),
            [$this->_string('Hello %name%')],
            ['es'],
            ['app@es' => $this->_catalog('es', ['Hello %name%' => 'Hola %name%'])]
        );

        $this->assertSame([], $issues);
    }

    public function testDetectsMarkupMismatches(): void
    {
        $issues = $this->_run(
            new MarkupMismatch(),
            [$this->_string('Read the <a>rules</a>')],
            ['es'],
            ['app@es' => $this->_catalog('es', ['Read the <a>rules</a>' => 'Lee las reglas'])]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('missing <a>, </a>', $issues[0]->message);
    }

    public function testDetectsIdenticalTranslations(): void
    {
        $issues = $this->_run(
            new IdenticalTranslation(),
            [$this->_string('Dashboard'), $this->_string('Squad')],
            ['es'],
            ['app@es' => $this->_catalog('es', ['Dashboard' => 'Dashboard', 'Squad' => 'Plantilla'])]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('Dashboard', $issues[0]->message);
    }

    public function testCountsMissingTranslations(): void
    {
        $issues = $this->_run(
            new MissingTranslations(),
            [$this->_string('One'), $this->_string('Two'), $this->_string('Three'), $this->_string('Four')],
            ['es'],
            ['app@es' => $this->_catalog('es', ['One' => 'Uno'])]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('3 of 4 strings (75.0%)', $issues[0]->message);
    }

    public function testDetectsObsoleteCatalogEntries(): void
    {
        $issues = $this->_run(
            new ObsoleteTranslations(),
            [$this->_string('Still used')],
            ['es'],
            ['app@es' => $this->_catalog('es', ['Still used' => 'Aún se usa', 'Removed long ago' => 'Ya no existe'])]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('1 of 2 catalog entries', $issues[0]->message);
        $this->assertStringContainsString('Removed long ago', implode('', $issues[0]->details));
    }

    public function testDetectsDivergentTranslationsAcrossDomains(): void
    {
        $issues = $this->_run(
            new CrossDomainDuplicates(),
            [
                $this->_string('Log in', ['domain' => 'app']),
                $this->_string('Log in', ['domain' => 'admin']),
            ],
            ['es'],
            [
                'app@es' => $this->_catalog('es', ['Log in' => 'Acceder']),
                'admin@es' => $this->_catalog('es', ['Log in' => 'Iniciar sesión']),
            ]
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Error, $issues[0]->severity);
        $this->assertStringContainsString('divergent translations', $issues[0]->message);
    }

    public function testCrossDomainDuplicatesWithoutDivergenceIsOnlyAWarning(): void
    {
        $issues = $this->_run(
            new CrossDomainDuplicates(),
            [
                $this->_string('Name', ['domain' => 'app']),
                $this->_string('Name', ['domain' => 'admin']),
            ],
            ['es'],
            [
                'app@es' => $this->_catalog('es', ['Name' => 'Nombre']),
                'admin@es' => $this->_catalog('es', ['Name' => 'Nombre']),
            ]
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Warning, $issues[0]->severity);
    }

    public function testRegistryResolvesChecksAndGroups(): void
    {
        $options = new Options();
        $options->checks = ['icu'];
        $options->skip = ['icu-runtime'];

        $names = array_map(static fn(object $check): string => $check->name(), Registry::resolve($options));

        $this->assertContains('icu-syntax', $names);
        $this->assertNotContains('icu-runtime', $names);
        $this->assertNotContains('similar-strings', $names);
    }

    public function testRegistrySkipsTranslationChecksWithoutLocales(): void
    {
        $names = array_map(static fn(object $check): string => $check->name(), Registry::resolve(new Options(), hasTranslations: false));

        $this->assertNotContains('placeholder-mismatch', $names);
        $this->assertContains('similar-strings', $names);
    }

    public function testRegistryRejectsUnknownCheckNames(): void
    {
        $options = new Options();
        $options->checks = ['does-not-exist'];

        $this->expectExceptionMessage("Unknown analysis check or group 'does-not-exist'");

        Registry::resolve($options);
    }

    public function testRegistryOnlyRunsExpensiveChecksInDeepMode(): void
    {
        $shallow = array_map(static fn(object $check): string => $check->name(), Registry::resolve(new Options()));

        $options = new Options();
        $options->deep = true;
        $deep = array_map(static fn(object $check): string => $check->name(), Registry::resolve($options));

        $this->assertNotContains('excluded-strings', $shallow);
        $this->assertContains('excluded-strings', $deep);
    }

    public function testEveryCheckHasAUniqueNameAndAKnownGroup(): void
    {
        $names = [];

        foreach (Registry::all() as $check) {
            $this->assertNotContains($check->name(), $names, "Duplicated check name '{$check->name()}'");
            $this->assertContains($check->group(), Registry::GROUPS, "Unknown group for check '{$check->name()}'");
            $this->assertNotSame('', $check->title());

            $names[] = $check->name();
        }

        $this->assertGreaterThan(20, count($names));
    }
}
