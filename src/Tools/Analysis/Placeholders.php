<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis;

use ideatic\l10n\Utils\ICU\Pattern;

/**
 * Extraction of the placeholders present in a string, whatever syntax they use.
 *
 * The result is a multiset (placeholder => number of occurrences) so that the cases where a placeholder appears
 * twice in the source and only once in the translation can also be detected.
 */
abstract class Placeholders
{
    /** Recognised syntaxes, in the order they must be consumed */
    private const array PATTERNS = [
        'angular' => '/\{\{.+?\}\}/su',
        'template' => '/\$\{.+?\}/su',
        'percent' => '/%[a-zA-Z_][\w.]*%/u',
        'positional' => '/%\d+\$[+-]?[0-9.]*[bcdeEfFgGosuxX]/',
        'printf' => '/%[+-]?[0-9.]*[bcdeEfFgGosuxX]/',
        'brace' => '/\{[a-zA-Z_][\w.]*\}/u',
    ];

    /**
     * Extracts the multiset of placeholders in a text
     *
     * @param array<string, string> $known Placeholders declared in LString::$placeholders (name => original markup)
     *
     * @return array<string, int> placeholder => number of occurrences
     */
    public static function extract(string $text, bool $isICU = false, array $known = []): array
    {
        $pattern = $isICU ? Icu::tryParse($text) : null;

        if ($pattern === null) {
            return self::_extractText($text, $known, false);
        }

        return self::_extractPattern($pattern, $known, false);
    }

    /**
     * @param array<string, string> $known
     *
     * @return array<string, int>
     */
    private static function _extractPattern(Pattern $pattern, array $known, bool $insidePlural): array
    {
        $found = [];

        foreach ($pattern->nodes as $node) {
            if (is_string($node)) {
                $found = self::_add($found, self::_extractText($node, $known, $insidePlural));
                continue;
            }

            if ($node->name !== '') {
                $found = self::_add($found, ["{{$node->name}}" => 1]);
            }

            $branches = Icu::branches($node);

            if (!$branches) {
                continue;
            }

            // Only one branch of a nested message is ever rendered, so its placeholders are counted once and not
            // once per branch: the number of branches depends on the locale (Spanish needs two plural forms and
            // Arabic six), and adding them up would make every translation into a locale with more forms look
            // like it had extra placeholders, and one with fewer forms look like it had lost some.
            $isPlural = in_array($node->type, Icu::PLURAL_TYPES, true);
            $branchTotal = [];

            foreach ($branches as $branch) {
                $branchTotal = self::_max($branchTotal, self::_extractPattern($branch, $known, $isPlural || $insidePlural));
            }

            $found = self::_add($found, $branchTotal);
        }

        return $found;
    }

    /**
     * Extracts the placeholders in a plain text fragment
     *
     * @param array<string, string> $known
     *
     * @return array<string, int>
     */
    private static function _extractText(string $text, array $known, bool $insidePlural): array
    {
        $found = [];
        $residual = $text;

        // The # symbol is only a placeholder inside a plural branch, which is where ICU replaces it with the
        // number. It is consumed here so that it is not counted again when it is also a declared placeholder.
        if ($insidePlural && str_contains($residual, '#')) {
            $found['#'] = substr_count($residual, '#');
            $residual = str_replace('#', "\x00", $residual);
        }

        // Placeholders declared by the source format (HTML elements, named Angular interpolations, etc.)
        foreach (array_keys($known) as $name) {
            $name = strval($name);

            if ($name === '' || $name === '#') {
                continue;
            }

            $delimiter = self::_delimiter($name);
            $count = preg_match_all($delimiter, $residual);

            if ($count) {
                $found[$name] ??= 0;
                $found[$name] += $count;
                $residual = preg_replace($delimiter, "\x00", $residual) ?? $residual;
            }
        }

        foreach (self::PATTERNS as $regex) {
            $residual = preg_replace_callback(
                $regex,
                function (array $match) use (&$found): string {
                    $key = self::_normalize($match[0]);
                    $found[$key] ??= 0;
                    $found[$key]++;
                    return "\x00";
                },
                $residual
            ) ?? $residual;
        }

        return $found;
    }

    /**
     * Builds the expression that locates a declared placeholder.
     *
     * Word boundaries are only checked against ASCII characters: with the /u modifier PCRE also treats hangul and
     * kana as word characters, so "Eventia에서" would have no boundary at all and the placeholder would look
     * missing even though the runtime substitution (a strtr) finds it without any trouble.
     */
    private static function _delimiter(string $name): string
    {
        $quoted = preg_quote($name, '/');

        return preg_match('/^[\w.]+$/u', $name)
            ? '/(?<![A-Za-z0-9_])' . $quoted . '(?![A-Za-z0-9_])/u'
            : '/' . $quoted . '/u';
    }

    /**
     * Adds two multisets together
     *
     * @param array<string, int> $a
     * @param array<string, int> $b
     *
     * @return array<string, int>
     */
    private static function _add(array $a, array $b): array
    {
        foreach ($b as $placeholder => $count) {
            $a[$placeholder] = ($a[$placeholder] ?? 0) + $count;
        }

        return $a;
    }

    /**
     * Combines two multisets keeping the highest number of occurrences of each placeholder
     *
     * @param array<string, int> $a
     * @param array<string, int> $b
     *
     * @return array<string, int>
     */
    private static function _max(array $a, array $b): array
    {
        foreach ($b as $placeholder => $count) {
            $a[$placeholder] = max($a[$placeholder] ?? 0, $count);
        }

        return $a;
    }

    /**
     * Gets the placeholder syntaxes used by a string
     *
     * @return list<string>
     */
    public static function styles(string $text, bool $isICU = false): array
    {
        $styles = [];
        $residual = $text;

        $pattern = $isICU ? Icu::tryParse($text) : null;

        if ($pattern !== null) {
            $styles[] = 'icu';
            $residual = implode("\x00", $pattern->textNodes());
        }

        foreach (self::PATTERNS as $style => $regex) {
            if ($style === 'brace' && $isICU) {
                continue;
            }

            $residual = preg_replace_callback(
                $regex,
                function () use ($style, &$styles): string {
                    if (!in_array($style, $styles, true)) {
                        $styles[] = $style;
                    }
                    return "\x00";
                },
                $residual
            ) ?? $residual;
        }

        return $styles;
    }

    /**
     * Gets the multiset of HTML tags present in a text
     *
     * @return array<string, int>
     */
    public static function markup(string $text, bool $isICU = false): array
    {
        $pattern = $isICU ? Icu::tryParse($text) : null;

        return $pattern === null ? self::_markupText($text) : self::_markupPattern($pattern);
    }

    /**
     * @return array<string, int>
     */
    private static function _markupPattern(Pattern $pattern): array
    {
        $found = [];

        foreach ($pattern->nodes as $node) {
            if (is_string($node)) {
                $found = self::_add($found, self::_markupText($node));
                continue;
            }

            $branches = Icu::branches($node);

            if (!$branches) {
                continue;
            }

            // Same as with placeholders: only one branch is rendered, so the tags are not added up across branches
            $branchTotal = [];

            foreach ($branches as $branch) {
                $branchTotal = self::_max($branchTotal, self::_markupPattern($branch));
            }

            $found = self::_add($found, $branchTotal);
        }

        return $found;
    }

    /**
     * @return array<string, int>
     */
    private static function _markupText(string $text): array
    {
        $tags = [];

        if (preg_match_all('/<(\/?)([a-zA-Z][\w-]*)\b[^>]*>/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tag = "<{$match[1]}" . strtolower($match[2]) . '>';
                $tags[$tag] ??= 0;
                $tags[$tag]++;
            }
        }

        return $tags;
    }

    /**
     * Computes the difference between two placeholder multisets
     *
     * @param array<string, int> $expected
     * @param array<string, int> $actual
     *
     * @return array{missing: array<string, int>, extra: array<string, int>}
     */
    public static function diff(array $expected, array $actual): array
    {
        $missing = [];
        $extra = [];

        foreach ($expected as $placeholder => $count) {
            $difference = $count - ($actual[$placeholder] ?? 0);

            if ($difference > 0) {
                $missing[$placeholder] = $difference;
            }
        }

        foreach ($actual as $placeholder => $count) {
            $difference = $count - ($expected[$placeholder] ?? 0);

            if ($difference > 0) {
                $extra[$placeholder] = $difference;
            }
        }

        return ['missing' => $missing, 'extra' => $extra];
    }

    /**
     * Renders a placeholder multiset in a readable way
     *
     * @param array<string, int> $placeholders
     */
    public static function render(array $placeholders): string
    {
        $parts = [];

        foreach ($placeholders as $placeholder => $count) {
            $parts[] = $count > 1 ? "{$placeholder} (x{$count})" : strval($placeholder);
        }

        return implode(', ', $parts);
    }

    /**
     * Normalises a placeholder so that two equivalent occurrences compare equal
     */
    private static function _normalize(string $placeholder): string
    {
        return preg_replace('/\s+/u', ' ', mb_trim($placeholder)) ?? $placeholder;
    }


}
