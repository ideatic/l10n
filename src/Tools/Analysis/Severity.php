<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis;

/**
 * Severity of a problem found by the analyzer
 */
enum Severity: string
{
    /** A problem that breaks the translation or loses data */
    case Error = 'error';

    /** A problem that degrades translation quality */
    case Warning = 'warning';

    /** A suggested improvement */
    case Info = 'info';

    /** Weight used to sort and filter by severity */
    public function weight(): int
    {
        return match ($this) {
            self::Error => 3,
            self::Warning => 2,
            self::Info => 1,
        };
    }

    /** Fixed-width label used in the report */
    public function label(): string
    {
        return match ($this) {
            self::Error => 'ERROR  ',
            self::Warning => 'WARNING',
            self::Info => 'INFO   ',
        };
    }

    /** Associated ANSI colour */
    public function color(): string
    {
        return match ($this) {
            self::Error => 'red',
            self::Warning => 'yellow',
            self::Info => 'gray',
        };
    }

    public static function parse(string $name): self
    {
        return self::tryFrom(strtolower(trim($name)))
            ?? throw new \Exception("Invalid severity '{$name}', expected one of: " . implode(', ', array_column(self::cases(), 'value')));
    }
}
