<?php

declare(strict_types=1);

use ideatic\l10n\Tools\Analysis\Check;
use ideatic\l10n\Tools\Analysis\Options;
use ideatic\l10n\Tools\Analysis\Registry;
use ideatic\l10n\Tools\Analysis\Scope;
use PHPUnit\Framework\TestCase;

require_once 'AnalysisFixtures.php';

/**
 * The two halves of the analysis are acted upon by different people at different moments: the source strings are
 * the developer's job and can be checked on every commit without any catalog, while the translations are checked
 * against what came back from the translators.
 */
class AnalysisScopeTest extends TestCase
{
    use AnalysisFixtures;

    /**
     * @return list<string>
     */
    private function _names(Scope $scope, bool $hasTranslations = true): array
    {
        $options = new Options();
        $options->scope = $scope;
        $options->deep = true;

        return array_map(static fn(Check $check): string => $check->name(), Registry::resolve($options, $hasTranslations));
    }

    public function testTheDefaultRunsEveryCheck(): void
    {
        $this->assertSame(Scope::Both, (new Options())->scope);
        $this->assertCount(count(Registry::all()), $this->_names(Scope::Both));
    }

    public function testTheTwoHalvesCoverEveryCheckBetweenThem(): void
    {
        $covered = array_unique([...$this->_names(Scope::Source), ...$this->_names(Scope::Translations)]);
        $all = array_map(static fn(Check $check): string => $check->name(), Registry::all());

        sort($covered);
        sort($all);

        $this->assertSame($all, $covered, 'No check may fall outside both halves');
    }

    public function testNoSourceCheckReadsTheCatalogs(): void
    {
        foreach (Registry::all() as $check) {
            if ($check->scope() === Scope::Source) {
                $this->assertFalse(
                    $check->needsTranslations(),
                    "{$check->name()} claims the source half but needs the catalogs loaded"
                );
            }
        }
    }

    public function testSourceScopeLeavesOutTheTranslationChecks(): void
    {
        $source = $this->_names(Scope::Source);

        foreach (['placeholder-mismatch', 'icu-plural-forms', 'markup-mismatch', 'missing-translations',
                  'identical-translation', 'obsolete-translations', 'translation-syntax', 'empty-catalog'] as $name) {
            $this->assertNotContains($name, $source, $name);
        }

        foreach (['empty-strings', 'similar-strings', 'icu-syntax', 'plural-candidate', 'missing-comment'] as $name) {
            $this->assertContains($name, $source, $name);
        }
    }

    public function testTranslationScopeLeavesOutTheSourceChecks(): void
    {
        $translations = $this->_names(Scope::Translations);

        foreach (['empty-strings', 'similar-strings', 'icu-syntax', 'plural-candidate', 'missing-comment',
                  'source-formatting', 'non-localizable', 'duplicated-text'] as $name) {
            $this->assertNotContains($name, $translations, $name);
        }

        foreach (['placeholder-mismatch', 'icu-plural-forms', 'missing-translations', 'empty-catalog'] as $name) {
            $this->assertContains($name, $translations, $name);
        }
    }

    /** A broken configuration explains why the rest of the report is quiet, whichever half is being audited */
    public function testConfigurationChecksRunInBothHalves(): void
    {
        foreach (['config', 'unconfigured-domain'] as $name) {
            $this->assertContains($name, $this->_names(Scope::Source), $name);
            $this->assertContains($name, $this->_names(Scope::Translations), $name);
        }
    }

    /**
     * cross-domain-duplicates audits the source strings and only reads the catalogs to tell whether the copies have
     * already drifted apart, so it belongs to the source half and must survive with no catalog at all
     */
    public function testDuplicationChecksBelongToTheSourceHalf(): void
    {
        foreach (['cross-domain-duplicates', 'duplicated-text'] as $name) {
            $this->assertContains($name, $this->_names(Scope::Source, hasTranslations: false), $name);
        }
    }

    public function testTheSourceHalfRunsWithoutAnyTargetLocale(): void
    {
        $withCatalogs = $this->_names(Scope::Source);
        $withoutCatalogs = $this->_names(Scope::Source, hasTranslations: false);

        $this->assertSame($withCatalogs, $withoutCatalogs, 'The source half must not depend on having translations');
    }

    public function testScopeCombinesWithTheOtherFilters(): void
    {
        $options = new Options();
        $options->scope = Scope::Source;
        $options->checks = ['icu'];

        $names = array_map(static fn(Check $check): string => $check->name(), Registry::resolve($options));

        $this->assertContains('icu-syntax', $names);
        $this->assertNotContains('icu-plural-forms', $names, 'It is an ICU check, but of the translations');
        $this->assertNotContains('empty-strings', $names, '--checks still narrows the group');
    }

    // -----------------------------------------------------------------------------------------------------------
    // Parsing
    // -----------------------------------------------------------------------------------------------------------

    public function testParsesTheCommandLineValue(): void
    {
        $this->assertSame(Scope::Source, Options::create(null, ['scope' => 'source'])->scope);
        $this->assertSame(Scope::Translations, Options::create(null, ['scope' => 'translations'])->scope);
        $this->assertSame(Scope::Both, Options::create(null, ['scope' => 'all'])->scope);
        $this->assertSame(Scope::Source, Options::create(null, ['scope' => ' SOURCE '])->scope);
    }

    public function testReadsTheScopeFromTheProjectConfiguration(): void
    {
        $this->assertSame(Scope::Source, Options::create((object)['scope' => 'source'], [])->scope);
    }

    public function testTheCommandLineOverridesTheConfiguration(): void
    {
        $this->assertSame(Scope::Translations, Options::create((object)['scope' => 'source'], ['scope' => 'translations'])->scope);
    }

    public function testRejectsAnUnknownScope(): void
    {
        $this->expectExceptionMessageIsOrContains("Invalid analysis scope 'catalogs'");

        Options::create(null, ['scope' => 'catalogs']);
    }
}
