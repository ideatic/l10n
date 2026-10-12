<?php

declare(strict_types=1);

namespace ideatic\l10n\Angular;

/**
 * A piece of a message: text, a placeholder, an element tag or an ICU expression.
 *
 * The same class models both sides of a conversion:
 * - Angular messages: PH, OPEN and CLOSE hold the Angular placeholder name (INTERPOLATION, START_BOLD_TEXT...),
 *   and ICU the name of its expression placeholder (VAR_PLURAL...).
 * - Strings of this library: PH holds the placeholder (%user%), OPEN and VOID the original tag (<b class="x">),
 *   CLOSE the tag name, and ICU the argument name (count).
 */
final class Token
{
    public const string TEXT = 'text';
    public const string PH = 'ph';
    public const string HASH = 'hash';
    public const string OPEN = 'open';
    public const string CLOSE = 'close';
    public const string VOID = 'void';
    public const string ICU = 'icu';

    /** ICU type: plural, select or selectordinal */
    public string $icuType = '';

    /** @var array<string, list<Token>> Branches of an ICU expression, by category */
    public array $cases = [];

    /**
     * @param self::* $type
     * @param ?string $tag Tag name of OPEN, CLOSE and VOID tokens of this library's strings
     */
    public function __construct(public string $type, public string $value = '', public ?string $tag = null) {}

    public function isMarker(): bool
    {
        return $this->type !== self::TEXT;
    }
}
