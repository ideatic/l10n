<?php

declare(strict_types=1);

namespace ideatic\l10n\CLI;

use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Formatted writing to the console
 */
class Output
{
    private const array COLORS = [
        'bold' => '1',
        'red' => '31',
        'green' => '32',
        'yellow' => '33',
        'blue' => '34',
        'cyan' => '36',
        'gray' => '90',
    ];

    public bool $colors;

    /** @var resource */
    private mixed $_stream;

    /**
     * @param bool|null $colors Force or disable colours (null = auto-detect)
     * @param resource|null $stream
     */
    public function __construct(?bool $colors = null, mixed $stream = null)
    {
        $this->_stream = $stream ?? STDOUT;
        $this->colors = $colors ?? self::_supportsColors($this->_stream);
    }

    public function write(string $text): void
    {
        fwrite($this->_stream, $text);
    }

    public function line(string $text = ''): void
    {
        $this->write("{$text}\n");
    }

    public function heading(string $text, int $level = 2): void
    {
        $this->line();
        $this->line($this->color(str_repeat('#', $level) . " {$text}", $level <= 2 ? 'bold' : 'cyan'));
        $this->line();
    }

    public function color(string $text, string $color): string
    {
        if (!$this->colors || !isset(self::COLORS[$color])) {
            return $text;
        }

        return "\033[" . self::COLORS[$color] . "m{$text}\033[0m";
    }

    public function severity(Severity $severity): string
    {
        return $this->color($severity->label(), $severity->color());
    }

    /**
     * Writes a text table with aligned columns
     *
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     * @param list<int>          $rightAlign Indexes of the right-aligned columns
     */
    public function table(array $headers, array $rows, array $rightAlign = [], string $indent = '  '): void
    {
        $widths = [];

        foreach ([$headers, ...$rows] as $row) {
            foreach ($row as $column => $value) {
                $widths[$column] = max($widths[$column] ?? 0, mb_strlen($value));
            }
        }

        $renderRow = function (array $row) use ($widths, $rightAlign, $indent): string {
            $cells = [];

            foreach ($row as $column => $value) {
                $cells[] = mb_str_pad($value, $widths[$column], ' ', in_array($column, $rightAlign, true) ? STR_PAD_LEFT : STR_PAD_RIGHT);
            }

            return $indent . mb_rtrim(implode('  ', $cells));
        };

        $this->line($this->color($renderRow($headers), 'bold'));

        foreach ($rows as $row) {
            $this->line($renderRow($row));
        }
    }

    /**
     * @param resource $stream
     */
    private static function _supportsColors(mixed $stream): bool
    {
        if (getenv('NO_COLOR') !== false || getenv('TERM') === 'dumb') {
            return false;
        }

        return function_exists('stream_isatty') && @stream_isatty($stream);
    }
}
