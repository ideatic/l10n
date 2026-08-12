<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Loader;

use Exception;
use ideatic\l10n\Catalog\Catalog;
use ideatic\l10n\Catalog\Translation;
use ideatic\l10n\LString;
use PhpToken;

/**
 * Loads translation catalogs from PHP files returning a dictionary.
 * It walks the native PHP tokens to avoid using eval() and improve safety.
 */
class PHP extends Loader
{
    /**
     * @inheritDoc
     * @throws Exception When the PHP file does not have a valid, safe format.
     */
    public function load(string $content, string $locale): Catalog
    {
        // Tokenise the whole content
        $tokens = PhpToken::tokenize($content);
        $count = count($tokens);
        $pos = 0;

        // 1. Find the 'return' statement
        $this->_seekToken($tokens, $pos, [T_RETURN]);
        $pos++; // Consume 'return'

        // 2. Verify the start of the array ('[' or 'array(')
        $token = $this->_getNextSignificantToken($tokens, $pos);

        if ($token === null) {
            throw new Exception('Invalid PHP translation file: unexpected end of file after return.');
        }

        $isArrayShort = $token->text === '[';
        $isArrayLong = $token->id === T_ARRAY;

        if (!$isArrayShort && !$isArrayLong) {
            throw new Exception(
                sprintf(
                    'Invalid PHP translation file: an array was expected, "%s" found at line %d.',
                    $token->text,
                    $token->line
                )
            );
        }

        if ($isArrayLong) {
            $pos++; // Consume 'array'
            $token = $this->_getNextSignificantToken($tokens, $pos);
            if ($token?->text !== '(') {
                throw new Exception('Invalid PHP translation file: "(" expected after "array".');
            }
        }

        $pos++; // Consume '[' or '('

        // 3. Parse the content of the array
        $strings = $this->_parseArrayContent($tokens, $pos, $isArrayShort ? ']' : ')');

        return new Catalog($locale, $strings);
    }

    /**
     * Parses the inner content of the translations array.
     *
     * @param list<PhpToken> $tokens
     * @param int $pos Reference to the current position.
     * @param string $closingChar Expected closing character (']' or ')').
     *
     * @return array<string, Translation>
     * @throws Exception
     */
    private function _parseArrayContent(array $tokens, int &$pos, string $closingChar): array
    {
        $strings = [];
        $count = count($tokens);

        while ($pos < $count) {
            // Capture the comments preceding the key
            $comments = '';
            $token = $this->_getNextSignificantToken($tokens, $pos, $comments);

            if ($token === null) {
                throw new Exception('Invalid PHP translation file: unexpected end of file inside array.');
            }

            // Verify the end of the array
            if ($token->text === $closingChar) {
                $pos++;
                break;
            }

            // --- 1. Read the key ---
            if ($token->id !== T_CONSTANT_ENCAPSED_STRING) {
                // Allow a trailing comma
                if ($token->text === ',') {
                    $pos++;
                    continue;
                }
                throw new Exception(
                    sprintf(
                        'Invalid PHP translation file: string key expected, "%s" found at line %d.',
                        $token->text,
                        $token->line
                    )
                );
            }

            $keyRaw = $token->text;
            $keyLine = $token->line;
            $keyOffset = $token->pos;

            // Decode the key
            $key = $this->_decodeString($keyRaw);
            $pos++;

            // --- 2. Read the arrow (=>) ---
            $token = $this->_getNextSignificantToken($tokens, $pos);
            if ($token?->id !== T_DOUBLE_ARROW) {
                throw new Exception(
                    sprintf(
                        'Invalid PHP translation file: "=>" expected after key "%s" at line %d.',
                        $key,
                        $keyLine
                    )
                );
            }
            $pos++;

            // --- 3. Read the value ---
            $token = $this->_getNextSignificantToken($tokens, $pos);
            if ($token?->id !== T_CONSTANT_ENCAPSED_STRING) {
                throw new Exception(
                    sprintf(
                        'Invalid PHP translation file: string value expected for key "%s" at line %d.',
                        $key,
                        $keyLine
                    )
                );
            }

            $value = $this->_decodeString($token->text);
            $pos++;

            // --- 4. Build the object ---
            $metadata = new LString();
            $metadata->id = $key;
            $metadata->line = $keyLine;
            $metadata->offset = $keyOffset;

            if ($comments !== '') {
                $metadata->comments = $comments;
            }

            $strings[$key] = new Translation($value, $metadata);

            // --- 5. Verify a comma or a closing character ---
            $token = $this->_getNextSignificantToken($tokens, $pos);
            if ($token?->text === ',') {
                $pos++; // Consume the comma and keep looping
            } elseif ($token?->text !== $closingChar) {
                throw new Exception(
                    sprintf(
                        'Invalid PHP translation file: "," or "%s" expected at line %d.',
                        $closingChar,
                        $token->line ?? -1
                    )
                );
            }
        }

        return $strings;
    }

    /**
     * Advances the pointer skipping whitespace and, optionally, capturing comments.
     *
     * @param list<PhpToken> $tokens
     * @param int $pos Reference to the current pointer.
     * @param string|null $capturedComments Reference to store the comments found (optional).
     *
     * @return PhpToken|null The next significant token, or null at the end of the file.
     */
    private function _getNextSignificantToken(array $tokens, int &$pos, ?string &$capturedComments = null): ?PhpToken
    {
        $count = count($tokens);

        while ($pos < $count) {
            $token = $tokens[$pos];

            if ($token->is(T_WHITESPACE)) {
                $pos++;
                continue;
            }

            if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                if ($capturedComments !== null) {
                    // Clean the comment up (//, #, /* */)
                    $cleanComment = $this->_cleanComment($token->text);
                    $capturedComments = $capturedComments === ''
                        ? $cleanComment
                        : $capturedComments . PHP_EOL . $cleanComment;
                }
                $pos++;
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * Scans forward until one of the requested token types is found.
     *
     * @param list<PhpToken> $tokens
     * @param int $pos
     * @param int[] $tokenTypes
     * @throws Exception When the token is not found.
     */
    private function _seekToken(array $tokens, int &$pos, array $tokenTypes): void
    {
        $count = count($tokens);
        while ($pos < $count) {
            if ($tokens[$pos]->is($tokenTypes)) {
                return;
            }
            $pos++;
        }

        throw new Exception('Invalid PHP translation file: required token not found.');
    }

    /**
     * Decodes a PHP string (single or double quoted) into its real value
     * without using eval().
     */
    private function _decodeString(string $raw): string
    {
        $quote = $raw[0];
        $content = substr($raw, 1, -1);

        if ($quote === "'") {
            // PHP single-quote rules: only \' and \\ are escaped
            return str_replace(['\\\'', '\\\\'], ["'", '\\'], $content);
        }

        if ($quote === '"') {
            // PHP double-quote rules: stripcslashes handles most of them (\n, \t, \", \\, etc.)
            // Note: variable interpolation {$var} is not supported, for safety.
            return stripcslashes($content);
        }

        return $raw;
    }

    /**
     * Strips the PHP comment delimiters.
     */
    private function _cleanComment(string $text): string
    {
        if (str_starts_with($text, '//')) {
            return trim(substr($text, 2));
        }
        if (str_starts_with($text, '#')) {
            return trim(substr($text, 1));
        }
        if (str_starts_with($text, '/*')) {
            // Remove /* and */
            return trim(substr($text, 2, -2));
        }
        return trim($text);
    }
}
