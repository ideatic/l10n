<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis;

use Exception;

/**
 * Half of the analysis a check belongs to.
 *
 * The two halves answer different questions and are usually acted upon by different people at different moments:
 * the source strings are the developer's job and can be checked on every commit without any catalog, while the
 * translations are checked against what came back from the translators.
 */
enum Scope: string
{
    /** Reads only the strings extracted from the source code, so it runs without any catalog */
    case Source = 'source';

    /** Compares the translation catalogs against those strings */
    case Translations = 'translations';

    /**
     * Declared by a check, it means it belongs to both halves and runs whichever one is requested; requested on the
     * command line, it means no filter at all.
     */
    case Both = 'both';

    /** Label shown in the report and in --list-checks */
    public function label(): string
    {
        return match ($this) {
            self::Source => 'source',
            self::Translations => 'translations',
            self::Both => 'both',
        };
    }

    public static function parse(string $name): self
    {
        $name = strtolower(mb_trim($name));

        return match ($name) {
            'source', 'sources', 'src' => self::Source,
            'translation', 'translations' => self::Translations,
            'both', 'all' => self::Both,
            default => throw new Exception("Invalid analysis scope '{$name}', expected one of: source, translations, all"),
        };
    }
}
