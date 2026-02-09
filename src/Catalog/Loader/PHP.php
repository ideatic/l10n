<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Loader;

use Exception;
use ideatic\l10n\Catalog\Catalog;
use ideatic\l10n\Catalog\Translation;
use ideatic\l10n\LString;
use PhpToken;

/**
 * Carga catálogos de traducción desde archivos PHP que retornan un diccionario.
 * Analiza los tokens nativos de PHP para evitar el uso de eval() y mejorar la seguridad.
 */
class PHP extends Loader
{
    /**
     * @inheritDoc
     * @throws Exception Si el archivo PHP no tiene un formato válido o seguro.
     */
    public function load(string $content, string $locale): Catalog
    {
        // Tokenizar el contenido completo
        $tokens = PhpToken::tokenize($content);
        $count = count($tokens);
        $pos = 0;

        // 1. Encontrar la sentencia 'return'
        $this->_seekToken($tokens, $pos, [T_RETURN]);
        $pos++; // Consumir 'return'

        // 2. Verificar inicio de array ('[' o 'array(')
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
            $pos++; // Consumir 'array'
            $token = $this->_getNextSignificantToken($tokens, $pos);
            if ($token?->text !== '(') {
                throw new Exception('Invalid PHP translation file: "(" expected after "array".');
            }
        }

        $pos++; // Consumir '[' o '('

        // 3. Parsear el contenido del array
        $strings = $this->_parseArrayContent($tokens, $pos, $isArrayShort ? ']' : ')');

        return new Catalog($locale, $strings);
    }

    /**
     * Parsea el contenido interno del array de traducciones.
     *
     * @param list<PhpToken> $tokens
     * @param int $pos Referencia a la posición actual.
     * @param string $closingChar Carácter de cierre esperado (']' o ')').
     *
     * @return array<string, Translation>
     * @throws Exception
     */
    private function _parseArrayContent(array $tokens, int &$pos, string $closingChar): array
    {
        $strings = [];
        $count = count($tokens);

        while ($pos < $count) {
            // Capturar comentarios previos a la clave
            $comments = '';
            $token = $this->_getNextSignificantToken($tokens, $pos, $comments);

            if ($token === null) {
                throw new Exception('Invalid PHP translation file: unexpected end of file inside array.');
            }

            // Verificar fin de array
            if ($token->text === $closingChar) {
                $pos++;
                break;
            }

            // --- 1. Leer Clave ---
            if ($token->id !== T_CONSTANT_ENCAPSED_STRING) {
                // Permitir coma final (trailing comma)
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

            // Decodificar clave
            $key = $this->_decodeString($keyRaw);
            $pos++;

            // --- 2. Leer Flecha (=>) ---
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

            // --- 3. Leer Valor ---
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

            // --- 4. Construir Objeto ---
            $metadata = new LString();
            $metadata->id = $key;
            $metadata->line = $keyLine;
            $metadata->offset = $keyOffset;

            if ($comments !== '') {
                $metadata->comments = $comments;
            }

            $strings[$key] = new Translation($value, $metadata);

            // --- 5. Verificar coma o cierre ---
            $token = $this->_getNextSignificantToken($tokens, $pos);
            if ($token?->text === ',') {
                $pos++; // Consumir coma y continuar bucle
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
     * Avanza el puntero ignorando espacios y, opcionalmente, capturando comentarios.
     *
     * @param list<PhpToken> $tokens
     * @param int $pos Referencia al puntero actual.
     * @param string|null $capturedComments Referencia para almacenar comentarios encontrados (opcional).
     *
     * @return PhpToken|null El siguiente token significativo o null si fin de archivo.
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
                    // Limpiar el comentario (//, #, /* */)
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
     * Busca hacia adelante hasta encontrar uno de los tipos de token solicitados.
     *
     * @param list<PhpToken> $tokens
     * @param int $pos
     * @param int[] $tokenTypes
     * @throws Exception Si no se encuentra el token.
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
     * Decodifica un string PHP (con comillas simples o dobles) a su valor real
     * sin usar eval().
     */
    private function _decodeString(string $raw): string
    {
        $quote = $raw[0];
        $content = substr($raw, 1, -1);

        if ($quote === "'") {
            // Estándar PHP comillas simples: solo escapa \' y \\
            return str_replace(['\\\'', '\\\\'], ["'", '\\'], $content);
        }

        if ($quote === '"') {
            // Estándar PHP comillas dobles: stripcslashes maneja la mayoría (\n, \t, \", \\, etc.)
            // Nota: No soportamos interpolación de variables {$var} por seguridad.
            return stripcslashes($content);
        }

        return $raw;
    }

    /**
     * Limpia los delimitadores de comentarios PHP.
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
            // Remover /* y */
            return trim(substr($text, 2, -2));
        }
        return trim($text);
    }
}
