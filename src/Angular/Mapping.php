<?php

declare(strict_types=1);

namespace ideatic\l10n\Angular;

/**
 * How the placeholders, tags and ICU expressions of a string of this library map to the ones of its Angular message.
 *
 * It is learned by walking the source string and the Angular message side by side, and then used to write every
 * translation of the string with the Angular placeholder names.
 */
final class Mapping
{
    /** @var array<string, 'interpolation'|'element'> Kind of each placeholder of the string */
    public array $kinds = [];

    /** @var array<string, string> Every placeholder name found in the Angular message, ICU branches included: name without its suffix → name */
    public array $angularNames = [];

    /** @var array<string, string> Placeholder (%x%, #) → Angular placeholder name */
    public array $placeholders = [];

    /** @var array<string, list<Token>> Placeholder that stands for a whole element → its Angular tokens */
    public array $elements = [];

    /** @var array<string, string> Opening tag → Angular placeholder name */
    public array $open = [];

    /** @var array<string, array<string, true>> Tag name → Angular names of its opening tags */
    public array $openByTag = [];

    /** @var array<string, string> Tag name → Angular name of its closing tag */
    public array $close = [];

    /** @var array<string, string> Void tag → Angular placeholder name */
    public array $void = [];

    /** @var array<string, array<string, true>> */
    public array $voidByTag = [];

    /**
     * ICU expressions of the source string, in order. 'placeholder' is set when Angular moved the expression to a
     * message of its own (an ICU next to text), and 'variable' when it stays inline.
     *
     * @var list<array{source: Token, placeholder?: string, variable?: string}>
     */
    public array $icus = [];
}
