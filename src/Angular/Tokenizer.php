<?php

declare(strict_types=1);

namespace ideatic\l10n\Angular;

use ideatic\l10n\Utils\ICU\Pattern;
use ideatic\l10n\Utils\ICU\Placeholder;
use Throwable;

/**
 * Splits messages into tokens (see Token).
 *
 * Placeholders are first replaced with private-use characters, so the ICU parser of this library can read the
 * structure of the message without mistaking a placeholder ({name}) for an ICU argument.
 */
final class Tokenizer
{
    public const string MARK_PH = "\u{E000}";
    public const string MARK_OPEN = "\u{E001}";
    public const string MARK_CLOSE = "\u{E002}";
    public const string MARK_END = "\u{E003}";
    private const string MARK_L10N = "\u{E004}";

    private const array VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    private const string TAG_PATTERN = '<\/?[a-zA-Z][\w:.-]*(?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*\s*\/?>';

    /**
     * Tokenizes the source of an Angular message, where placeholders are written with the MARK_* characters
     *
     * @return list<Token>
     */
    public static function angular(string $text): array
    {
        return self::_tokenize($text, fn(string $segment, bool $inIcu) => self::_angularSegment($segment));
    }

    /**
     * Tokenizes a string of this library (a source text or a translation)
     *
     * @param list<string> $placeholders Names of the placeholders of the string (%user%, #...)
     * @param bool         $hash         Whether # inside a plural branch is a placeholder
     * @param bool         $tags         Whether tags are read as tags (the value of an attribute is plain text)
     *
     * @return list<Token>
     */
    public static function l10n(string $text, array $placeholders, bool $hash = false, bool $tags = true): array
    {
        // Longest names first, so %user% is not found inside %user_name%
        usort($placeholders, fn($a, $b) => strlen($b) <=> strlen($a));
        $names = [];
        $replacements = [];
        foreach (array_values(array_filter($placeholders, fn($name) => $name !== '#' && $name !== '')) as $index => $name) {
            $replacements[$name] = self::MARK_L10N . $index . self::MARK_END;
            $names[$index] = $name;
        }
        $text = strtr($text, $replacements);

        return self::_tokenize($text, fn(string $segment, bool $inIcu) => self::_l10nSegment($segment, $names, $hash && $inIcu, $tags));
    }

    /**
     * @param callable(string $segment, bool $inIcu): list<Token> $tokenizeSegment
     *
     * @return list<Token>
     */
    private static function _tokenize(string $text, callable $tokenizeSegment): array
    {
        if (!str_contains($text, '{')) {
            return $tokenizeSegment($text, false);
        }

        try {
            $pattern = new Pattern($text);
        } catch (Throwable) {
            return $tokenizeSegment($text, false);
        }

        return self::_patternTokens($pattern, $tokenizeSegment, false);
    }

    /**
     * @return list<Token>
     */
    private static function _patternTokens(Pattern $pattern, callable $tokenizeSegment, bool $inIcu): array
    {
        $tokens = [];
        foreach ($pattern->nodes as $node) {
            if (is_string($node)) {
                array_push($tokens, ...$tokenizeSegment($node, $inIcu));
            } elseif ($node instanceof Placeholder && is_array($node->content) && in_array($node->type, ['plural', 'select', 'selectordinal'])) {
                $icu = new Token(Token::ICU, $node->name);
                $icu->icuType = $node->type;
                foreach ($node->content as $category => $branch) {
                    $icu->cases[(string)$category] = self::_patternTokens($branch, $tokenizeSegment, true);
                }
                $tokens[] = $icu;
            } elseif ($node instanceof Placeholder) { // Not an ICU expression, just text between braces
                array_push($tokens, ...$tokenizeSegment($node->render(false), $inIcu));
            }
        }

        return self::_mergeText($tokens);
    }

    /**
     * @return list<Token>
     */
    private static function _angularSegment(string $segment): array
    {
        $tokens = [];
        $parts = preg_split('/([' . self::MARK_PH . self::MARK_OPEN . self::MARK_CLOSE . '])([^' . self::MARK_END . ']*)' . self::MARK_END . '/u', $segment, -1, PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 0; $i < count($parts); $i += 3) {
            if ($parts[$i] !== '') {
                $tokens[] = new Token(Token::TEXT, $parts[$i]);
            }
            if (isset($parts[$i + 1])) {
                $type = match ($parts[$i + 1]) {
                    self::MARK_PH => Token::PH,
                    self::MARK_OPEN => Token::OPEN,
                    default => Token::CLOSE,
                };
                $tokens[] = new Token($type, $parts[$i + 2]);
            }
        }

        return $tokens;
    }

    /**
     * @param array<int, string> $names
     *
     * @return list<Token>
     */
    private static function _l10nSegment(string $segment, array $names, bool $hash, bool $tags): array
    {
        // A group that never matches keeps the numbering of the groups when tags are not read
        $pattern = '/(' . self::MARK_L10N . '(\d+)' . self::MARK_END . ')|(' . ($tags ? self::TAG_PATTERN : '(*FAIL)') . ')' . ($hash ? '|(#)' : '') . '/u';

        $tokens = [];
        $offset = 0;
        preg_match_all($pattern, $segment, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            if ($match[0][1] > $offset) {
                $tokens[] = new Token(Token::TEXT, substr($segment, $offset, $match[0][1] - $offset));
            }
            $offset = $match[0][1] + strlen($match[0][0]);

            if (isset($match[2]) && $match[2][1] >= 0) {
                $tokens[] = new Token(Token::PH, $names[(int)$match[2][0]]);
            } elseif (isset($match[3]) && $match[3][1] >= 0) {
                $tag = $match[3][0];
                preg_match('/^<\/?([a-zA-Z][\w:.-]*)/', $tag, $nameMatch);
                $tagName = strtolower($nameMatch[1]);

                if (str_starts_with($tag, '</')) {
                    $tokens[] = new Token(Token::CLOSE, $tag, $tagName);
                } elseif (str_ends_with($tag, '/>') || in_array($tagName, self::VOID_ELEMENTS)) {
                    $tokens[] = new Token(Token::VOID, self::normalizeTag($tag), $tagName);
                } else {
                    $tokens[] = new Token(Token::OPEN, self::normalizeTag($tag), $tagName);
                }
            } else {
                $tokens[] = new Token(Token::HASH, '#');
            }
        }

        if ($offset < strlen($segment)) {
            $tokens[] = new Token(Token::TEXT, substr($segment, $offset));
        }

        return $tokens;
    }

    /**
     * Normalizes the whitespace of a tag, so translations that only reformat it still match the source
     */
    public static function normalizeTag(string $tag): string
    {
        return preg_replace('/\s*(\/?>)$/', '$1', preg_replace('/\s+/', ' ', $tag));
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<Token>
     */
    private static function _mergeText(array $tokens): array
    {
        $result = [];
        foreach ($tokens as $token) {
            $last = end($result);
            if ($token->type === Token::TEXT && $last instanceof Token && $last->type === Token::TEXT) {
                $last->value .= $token->value;
            } else {
                $result[] = $token;
            }
        }

        return $result;
    }
}
