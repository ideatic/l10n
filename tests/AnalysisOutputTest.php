<?php

declare(strict_types=1);

use ideatic\l10n\CLI\Output;
use ideatic\l10n\Tools\Analysis\Severity;
use PHPUnit\Framework\TestCase;

class AnalysisOutputTest extends TestCase
{
    public function testWritesPlainTextWhenColoursAreDisabled(): void
    {
        [$output, $read] = $this->_output(colors: false);

        $output->line('Hello');

        $this->assertSame("Hello\n", $read());
        $this->assertSame('Hello', $output->color('Hello', 'red'));
    }

    public function testWrapsTextInAnsiCodesWhenColoursAreEnabled(): void
    {
        [$output] = $this->_output(colors: true);

        $this->assertSame("\033[31mHello\033[0m", $output->color('Hello', 'red'));
        $this->assertSame("\033[33mHello\033[0m", $output->color('Hello', 'yellow'));
    }

    public function testUnknownColoursAreIgnored(): void
    {
        [$output] = $this->_output(colors: true);

        $this->assertSame('Hello', $output->color('Hello', 'chartreuse'));
    }

    public function testSeverityLabelsArePaddedAndColoured(): void
    {
        [$plain] = $this->_output(colors: false);
        [$coloured] = $this->_output(colors: true);

        $this->assertSame('ERROR  ', $plain->severity(Severity::Error));
        $this->assertSame('WARNING', $plain->severity(Severity::Warning));
        $this->assertSame('INFO   ', $plain->severity(Severity::Info));

        $this->assertStringContainsString("\033[31m", $coloured->severity(Severity::Error));
    }

    public function testHeadingsUseMarkdownStyleHashes(): void
    {
        [$output, $read] = $this->_output(colors: false);

        $output->heading('Summary', 4);

        $this->assertSame("\n#### Summary\n\n", $read());
    }

    public function testTableAlignsColumns(): void
    {
        [$output, $read] = $this->_output(colors: false);

        $output->table(
            ['check', 'errors', 'warnings'],
            [
                ['similar-strings', '0', '12'],
                ['icu-syntax', '3', '0'],
            ],
            rightAlign: [1, 2]
        );

        $this->assertSame(
            "  check            errors  warnings\n"
            . "  similar-strings       0        12\n"
            . "  icu-syntax            3         0\n",
            $read()
        );
    }

    public function testTableLeftAlignsByDefault(): void
    {
        [$output, $read] = $this->_output(colors: false);

        $output->table(['name', 'group'], [['icu-syntax', 'icu'], ['config', 'pipeline']]);

        $this->assertSame(
            "  name        group\n"
            . "  icu-syntax  icu\n"
            . "  config      pipeline\n",
            $read()
        );
    }

    public function testColoursAreDisabledWhenNoColorIsSet(): void
    {
        $previous = getenv('NO_COLOR');
        putenv('NO_COLOR=1');

        try {
            $stream = fopen('php://memory', 'r+');
            $this->assertFalse(new Output(stream: $stream)->colors);
        } finally {
            $previous === false ? putenv('NO_COLOR') : putenv("NO_COLOR={$previous}");
        }
    }

    public function testColoursAreDisabledOnANonInteractiveStream(): void
    {
        $stream = fopen('php://memory', 'r+');

        $this->assertFalse(new Output(stream: $stream)->colors, 'A memory stream is not a terminal');
    }

    /**
     * @return array{0: Output, 1: callable(): string}
     */
    private function _output(bool $colors): array
    {
        $stream = fopen('php://memory', 'r+');

        $read = function () use ($stream): string {
            rewind($stream);
            return stream_get_contents($stream) ?: '';
        };

        return [new Output($colors, $stream), $read];
    }
}
