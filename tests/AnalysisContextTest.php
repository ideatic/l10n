<?php

declare(strict_types=1);

use ideatic\l10n\LString;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\Icu;
use ideatic\l10n\Utils\ICU\Placeholder;
use PHPUnit\Framework\TestCase;

require_once 'AnalysisFixtures.php';

class AnalysisContextTest extends TestCase
{
    use AnalysisFixtures;

    public function testLocationsAreDeduplicated(): void
    {
        $locations = Context::locations([
            $this->_string('A', ['file' => 'src/a.php', 'line' => 10]),
            $this->_string('A', ['file' => 'src/a.php', 'line' => 10]),
            $this->_string('A', ['file' => 'src/b.php', 'line' => 3]),
        ]);

        $this->assertSame(['src/a.php:10', 'src/b.php:3'], $locations);
    }

    public function testLocationsAreCappedWithACounter(): void
    {
        $strings = [];
        for ($i = 1; $i <= 8; $i++) {
            $strings[] = $this->_string('A', ['file' => 'src/a.php', 'line' => $i]);
        }

        $locations = Context::locations($strings, 3);

        $this->assertCount(4, $locations);
        $this->assertSame('(+5 more)', $locations[3]);
    }

    public function testLocationsIgnoreStringsWithoutFile(): void
    {
        $withoutFile = new LString();
        $withoutFile->text = 'A';

        $this->assertSame([], Context::locations([$withoutFile]));
    }

    public function testPreviewEscapesControlCharacters(): void
    {
        $this->assertSame('a\nb\tc<nbsp>d', Context::preview("a\nb\tc\xc2\xa0d"));
    }

    public function testPreviewTruncatesLongText(): void
    {
        $preview = Context::preview(str_repeat('a', 100), 10);

        $this->assertSame(10, mb_strlen($preview));
        $this->assertStringEndsWith('…', $preview);
    }

    public function testPreviewHandlesNull(): void
    {
        $this->assertSame('', Context::preview(null));
    }

    public function testTextIndexGroupsTheSameTextAcrossDomains(): void
    {
        $context = $this->_context([
            $this->_string('Name', ['domain' => 'app']),
            $this->_string('Name', ['domain' => 'admin']),
            $this->_string('Only here', ['domain' => 'app']),
        ]);

        $index = $context->textIndex();

        $this->assertCount(2, $index['Name']);
        $this->assertCount(1, $index['Only here']);
        $this->assertSame(['app', 'admin'], array_map(static fn(array $entry): string => $entry['domain']->name, $index['Name']));
    }

    public function testCatalogsAreCached(): void
    {
        $catalog = $this->_catalog('es', ['Hello' => 'Hola']);
        $context = $this->_context([$this->_string('Hello')], ['es'], ['app@es' => $catalog]);

        $domain = $context->domains[0];

        $this->assertSame($catalog, $context->catalog($domain, 'es'));
        $this->assertSame($catalog, $context->catalog($domain, 'es'), 'The catalog must be resolved only once');
        $this->assertNull($context->catalog($domain, 'fr'));
    }

    public function testCatalogExposesItsEntries(): void
    {
        $catalog = $this->_catalog('es', ['Hello' => 'Hola', 'Bye' => 'Adiós']);

        $this->assertSame(['Hello', 'Bye'], array_keys($catalog->getEntries()));
        $this->assertSame(2, $catalog->entryCount());
        $this->assertSame('Hola', $catalog->getEntries()['Hello']->translation);
    }

    public function testGetTranslationNeverFallsBackToAnotherLocale(): void
    {
        $context = $this->_context(
            [$this->_string('Hello')],
            ['es', 'es-MX'],
            ['app@es' => $this->_catalog('es', ['Hello' => 'Hola'])]
        );

        $string = $context->domains[0]->strings['Hello'][0];

        $this->assertNotNull($context->getTranslation($string, 'es'));
        $this->assertNull($context->getTranslation($string, 'es-MX'), 'A string translated in es must not count as translated in es-MX');
    }

    public function testIcuTryParseReturnsNullForInvalidPatterns(): void
    {
        $this->assertNotNull(Icu::tryParse('{count, plural, one {1} other {#}}'));
        $this->assertNull(Icu::tryParse('{count, plural, one {1} other {#}'));
        $this->assertNull(Icu::tryParse('{count, nonsense, other {#}}'));
    }

    public function testIcuParseErrorDescribesTheProblem(): void
    {
        $this->assertNull(Icu::parseError('{count, plural, one {1} other {#}}'));
        $this->assertStringContainsString('nonsense', Icu::parseError('{count, nonsense, other {#}}') ?? '');
    }

    public function testIcuFormatErrorUsesTheIcuEngine(): void
    {
        $this->assertNull(Icu::formatError('{count, plural, one {1 item} other {# items}}', 'en'));
        $this->assertNotNull(Icu::formatError('{count, plural, one {1 item}}', 'en'), 'A plural without an other branch is rejected by ICU');
    }

    public function testIcuPlaceholdersAreCollectedRecursively(): void
    {
        $pattern = Icu::tryParse('{count, plural, one {1 player of {team}} other {# players of {team}}}');

        $this->assertNotNull($pattern);

        $names = array_map(static fn(Placeholder $p): string => $p->name, Icu::placeholders($pattern));

        $this->assertSame(['count', 'team', 'team'], $names);
    }

    public function testIcuPluralsOnlyReturnsPluralPlaceholders(): void
    {
        $pattern = Icu::tryParse('{gender, select, other {{count, plural, other {# points}}}}');

        $this->assertNotNull($pattern);

        $plurals = Icu::plurals($pattern);

        $this->assertCount(1, $plurals);
        $this->assertSame('count', $plurals[0]->name);
        $this->assertSame('plural', $plurals[0]->type);
    }

    public function testIcuBranchesAreEmptyForSimplePlaceholders(): void
    {
        $pattern = Icu::tryParse('{name} scored {total, number, integer}');

        $this->assertNotNull($pattern);

        foreach (Icu::placeholders($pattern) as $placeholder) {
            $this->assertSame([], Icu::branches($placeholder));
        }
    }

    public function testIcuBranchesReturnEveryCategory(): void
    {
        $pattern = Icu::tryParse('{count, plural, =0 {none} one {1} other {#}}');

        $this->assertNotNull($pattern);
        $this->assertSame(['=0', 'one', 'other'], array_keys(Icu::branches(Icu::placeholders($pattern)[0])));
    }

    public function testLooksLikeIcuDetectsRealPatternsOnly(): void
    {
        $this->assertTrue(Icu::looksLikeIcu('{count, plural, one {1} other {#}}'));
        $this->assertTrue(Icu::looksLikeIcu('{name}'));
        $this->assertFalse(Icu::looksLikeIcu('Hello world'));
        $this->assertFalse(Icu::looksLikeIcu('Costs 5 € {'));
    }
}
