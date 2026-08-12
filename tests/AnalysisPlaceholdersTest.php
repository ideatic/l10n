<?php

declare(strict_types=1);

use ideatic\l10n\Tools\Analysis\Placeholders;
use PHPUnit\Framework\TestCase;

class AnalysisPlaceholdersTest extends TestCase
{
    public function testExtractsNamedPlaceholders(): void
    {
        $this->assertSame(
            ['%name%' => 1, '%points%' => 1],
            Placeholders::extract('Hello %name%, you have %points% points')
        );
    }

    public function testCountsRepeatedPlaceholders(): void
    {
        $this->assertSame(
            ['%player%' => 2],
            Placeholders::extract('%player% replaces %player%')
        );
    }

    public function testExtractsPrintfPlaceholders(): void
    {
        $this->assertSame(['%s' => 1, '%d' => 1], Placeholders::extract('Welcome %s, you are number %d'));
        $this->assertSame(['%1$s' => 1, '%2$s' => 1], Placeholders::extract('%1$s beat %2$s'));
    }

    public function testExtractsAngularAndTemplatePlaceholders(): void
    {
        $this->assertSame(['{{ user.name }}' => 1], Placeholders::extract('Hello {{ user.name }}'));
        $this->assertSame(['${name}' => 1], Placeholders::extract('Hello ${name}'));
    }

    public function testExtractsIcuPlaceholdersRecursively(): void
    {
        $found = Placeholders::extract('{count, plural, one {1 player of {team}} other {# players of {team}}}', isICU: true);

        $this->assertSame(1, $found['{count}']);
        $this->assertSame(1, $found['{team}'], 'A placeholder repeated across branches renders only once');
        $this->assertSame(1, $found['#'], 'The # symbol must be counted only inside plural branches');
    }

    public function testCountsRepeatedPlaceholdersWithinASingleBranch(): void
    {
        $found = Placeholders::extract('{count, plural, other {{team} beat {team}}}', isICU: true);

        $this->assertSame(2, $found['{team}'], 'Two occurrences in the same branch do render twice');
    }

    public function testDoesNotDoubleCountIcuPlaceholders(): void
    {
        $found = Placeholders::extract('{name} says hello', isICU: true);

        $this->assertSame(['{name}' => 1], $found);
    }

    public function testExtractsDeclaredMarkupPlaceholders(): void
    {
        $found = Placeholders::extract('Read the <a>rules</a> first', known: ['<a>' => '<a href="/rules">', '</a>' => '</a>']);

        $this->assertSame(1, $found['<a>']);
        $this->assertSame(1, $found['</a>']);
    }

    public function testDetectsMissingAndExtraPlaceholders(): void
    {
        $expected = Placeholders::extract('Hello %name%, you have %points% points');
        $actual = Placeholders::extract('Hola %name% de %league%');

        $diff = Placeholders::diff($expected, $actual);

        $this->assertSame(['%points%' => 1], $diff['missing']);
        $this->assertSame(['%league%' => 1], $diff['extra']);
    }

    public function testDetectsRepeatedPlaceholderDroppedInTranslation(): void
    {
        $diff = Placeholders::diff(
            Placeholders::extract('%player% replaces %player%'),
            Placeholders::extract('%player% entra al campo')
        );

        $this->assertSame(['%player%' => 1], $diff['missing']);
        $this->assertSame([], $diff['extra']);
    }

    public function testDetectsPlaceholderStyles(): void
    {
        $this->assertSame(['percent'], Placeholders::styles('Hello %name%'));
        $this->assertSame(['printf'], Placeholders::styles('Hello %s'));
        $this->assertSame(['positional'], Placeholders::styles('Hello %1$s'));
        $this->assertSame(['brace'], Placeholders::styles('Hello {name}'));
        $this->assertSame(['icu'], Placeholders::styles('{count, plural, one {1} other {#}}', isICU: true));
        $this->assertSame([], Placeholders::styles('Hello world'));
    }

    public function testExtractsMarkupTags(): void
    {
        $this->assertSame(
            ['<a>' => 1, '</a>' => 1, '<strong>' => 1, '</strong>' => 1],
            Placeholders::markup('Read the <a href="/rules">rules</a> and the <strong>terms</strong>')
        );
    }

    public function testInvalidIcuPatternDoesNotThrow(): void
    {
        $this->assertIsArray(Placeholders::extract('{count, plural, one {unclosed', isICU: true));
    }
}
