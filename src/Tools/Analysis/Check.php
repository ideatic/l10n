<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis;

/**
 * A single check performed by the analyzer
 */
abstract class Check
{
    /** Identifier used by --checks and --skip */
    abstract public function name(): string;

    /** Group it belongs to: source | icu | metadata | translations | pipeline */
    abstract public function group(): string;

    /** Title shown in the report */
    abstract public function title(): string;

    /** Whether this check needs the translation catalogs to be loaded */
    public function needsTranslations(): bool
    {
        return false;
    }

    /** Whether this check is expensive and should only run with --deep */
    public function isExpensive(): bool
    {
        return false;
    }

    /**
     * @return list<Issue>
     */
    abstract public function analyze(Context $context): array;
}
