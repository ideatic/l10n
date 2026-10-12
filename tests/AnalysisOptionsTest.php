<?php

declare(strict_types=1);

use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Options;
use ideatic\l10n\Tools\Analysis\Severity;
use PHPUnit\Framework\TestCase;

class AnalysisOptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $options = Options::create(null, []);

        $this->assertSame(90.0, $options->threshold);
        $this->assertSame([], $options->checks);
        $this->assertSame([], $options->skip);
        $this->assertSame([], $options->domains);
        $this->assertSame(Severity::Info, $options->minSeverity);
        $this->assertSame(20, $options->limit);
        $this->assertSame('text', $options->format);
        $this->assertFalse($options->strict);
        $this->assertFalse($options->deep);
        $this->assertNull($options->colors);
        $this->assertNull($options->icuRuntime);
        $this->assertNotEmpty($options->countPlaceholders);
    }

    /**
     * The short --t parameter predates this tool and must keep working
     */
    public function testAcceptsBothThresholdParameters(): void
    {
        $this->assertSame(85.0, Options::create(null, ['t' => '85'])->threshold);
        $this->assertSame(75.5, Options::create(null, ['threshold' => '75.5'])->threshold);
    }

    public function testSplitsCommaSeparatedLists(): void
    {
        $options = Options::create(null, ['checks' => 'icu, similar-strings ', 'skip' => 'missing-comment', 'domains' => 'app,admin']);

        $this->assertSame(['icu', 'similar-strings'], $options->checks);
        $this->assertSame(['missing-comment'], $options->skip);
        $this->assertSame(['app', 'admin'], $options->domains);
    }

    public function testDomainAllMeansEveryDomain(): void
    {
        $this->assertSame([], Options::create(null, ['domain' => 'all'])->domains);
    }

    public function testParsesSeverity(): void
    {
        $this->assertSame(Severity::Error, Options::create(null, ['severity' => 'error'])->minSeverity);
        $this->assertSame(Severity::Warning, Options::create(null, ['severity' => ' WARNING '])->minSeverity);
    }

    public function testRejectsUnknownSeverity(): void
    {
        $this->expectExceptionMessageIsOrContains("Invalid severity 'critical'");

        Options::create(null, ['severity' => 'critical']);
    }

    public function testRejectsUnknownFormat(): void
    {
        $this->expectExceptionMessageIsOrContains("Invalid analysis output format 'xml'");

        Options::create(null, ['format' => 'xml']);
    }

    public function testLimitNeverGoesNegative(): void
    {
        $this->assertSame(0, Options::create(null, ['limit' => '-5'])->limit);
        $this->assertSame(0, Options::create(null, ['limit' => '0'])->limit);
        $this->assertSame(50, Options::create(null, ['limit' => '50'])->limit);
    }

    public function testFlagsWithoutValueAreEnabled(): void
    {
        $options = Options::create(null, ['strict' => null, 'deep' => null, 'list-checks' => null]);

        $this->assertTrue($options->strict);
        $this->assertTrue($options->deep);
        $this->assertTrue($options->listChecks);
    }

    public function testFlagsCanBeExplicitlyDisabled(): void
    {
        $this->assertFalse(Options::create(null, ['strict' => 'false'])->strict);
        $this->assertFalse(Options::create(null, ['deep' => '0'])->deep);
        $this->assertFalse(Options::create(null, ['strict' => 'no'])->strict);
    }

    public function testColorFlags(): void
    {
        $this->assertFalse(Options::create(null, ['no-color' => null])->colors);
        $this->assertTrue(Options::create(null, ['color' => null])->colors);
        $this->assertNull(Options::create(null, [])->colors, 'Without flags the colours are auto-detected');
    }

    public function testReadsTheProjectConfiguration(): void
    {
        $config = (object)[
            'threshold' => 80,
            'skip' => ['identical-translation', 'missing-comment'],
            'commentLanguage' => 'es',
            'countPlaceholders' => ['count', 'total'],
            'ambiguousWords' => ['/^\p{L}+[ai]d[oa]s?$/ui'],
            'identicalLocales' => ['gl', 'pt', 'ca'],
            'icuRuntime' => ['maxParameters' => 1],
        ];

        $options = Options::create($config, []);

        $this->assertSame(80.0, $options->threshold);
        $this->assertSame(['identical-translation', 'missing-comment'], $options->skip);
        $this->assertSame('es', $options->commentLanguage);
        $this->assertSame(['count', 'total'], $options->countPlaceholders);
        $this->assertCount(1, $options->ambiguousWords);
        $this->assertSame(['gl', 'pt', 'ca'], $options->identicalLocales);
        $this->assertSame(1, $options->icuRuntime->maxParameters);
    }

    public function testCommandLineOverridesTheConfiguration(): void
    {
        $config = (object)['threshold' => 80, 'skip' => ['identical-translation']];

        $options = Options::create($config, ['t' => '95', 'skip' => 'missing-comment']);

        $this->assertSame(95.0, $options->threshold);
        $this->assertSame(['missing-comment'], $options->skip);
    }

    public function testConfigurationSkipSurvivesWhenNotOverridden(): void
    {
        $options = Options::create((object)['skip' => 'identical-translation'], ['checks' => 'translations']);

        $this->assertSame(['identical-translation'], $options->skip);
    }

    public function testEmptyConfigurationListsKeepTheDefaults(): void
    {
        $defaults = Options::create(null, [])->countPlaceholders;

        $this->assertSame($defaults, Options::create((object)['countPlaceholders' => []], [])->countPlaceholders);
    }

    public function testSeverityWeightsAndLabels(): void
    {
        $this->assertGreaterThan(Severity::Warning->weight(), Severity::Error->weight());
        $this->assertGreaterThan(Severity::Info->weight(), Severity::Warning->weight());

        foreach (Severity::cases() as $severity) {
            $this->assertSame(7, mb_strlen($severity->label()), 'Labels must be padded to the same width');
            $this->assertNotSame('', $severity->color());
        }
    }

    public function testIssueSerialisationOmitsEmptyFields(): void
    {
        $issue = new Issue(check: 'empty-strings', severity: Severity::Error, message: 'Empty localizable string');

        $this->assertSame(
            ['check' => 'empty-strings', 'severity' => 'error', 'message' => 'Empty localizable string'],
            $issue->toArray()
        );
    }

    public function testIssueSerialisationKeepsEveryProvidedField(): void
    {
        $issue = new Issue(
            check: 'placeholder-mismatch',
            severity: Severity::Warning,
            message: 'Translation drops the placeholders: %max%',
            domain: 'app',
            stringID: 'Loans last %min% to %max% rounds',
            locale: 'es',
            locations: ['src/app.php:10'],
            details: ['Source: "…"'],
            priority: 500,
        );

        $serialised = $issue->toArray();

        $this->assertSame('app', $serialised['domain']);
        $this->assertSame('es', $serialised['locale']);
        $this->assertSame(['src/app.php:10'], $serialised['locations']);
        $this->assertSame(['Source: "…"'], $serialised['details']);
        $this->assertSame(500.0, $serialised['priority']);
        $this->assertSame('Loans last %min% to %max% rounds', $serialised['string']);
    }
}
