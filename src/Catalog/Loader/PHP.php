<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Loader;

use ideatic\l10n\Catalog\Catalog;
use ideatic\l10n\Catalog\Translation;
use ideatic\l10n\LString;

class PHP extends Loader
{
    /** @inheritDoc */
    public function load(string $content, string $locale): Catalog
    {
        if (preg_match('/^<\?php\s+declare\s*\(\s*strict_types\s*=\s*1\)\s*;/i', $content, $match)) {
            $content = '<?php ' . substr($content, strlen($match[0]));
        }

        $tokens = \PhpToken::tokenize($content);
        $pos = 1; // Ignorar el <?php inicial
        $this->_ignoreEmptyTokens($tokens, $pos);

        // Buscar el primer return
        if ($tokens[$pos]->id != T_RETURN) {
            throw new \Exception('Invalid PHP translation file: an array return was expected');
        }

        $pos++;
        $this->_ignoreEmptyTokens($tokens, $pos);

        // Buscar comienzo del array
        if ($tokens[$pos]->text != '[' && $tokens[$pos]->id != T_ARRAY) {
            throw new \Exception('Invalid PHP translation file: an array return was expected');
        }
        $pos++;
        $this->_ignoreEmptyTokens($tokens, $pos);

        // Leer array
        $strings = [];

        for (; $pos < count($tokens);) {
            $entryStart = $pos;
            $token = $this->_ignoreEmptyTokens($tokens, $pos);

            if ($token->text == ']' || $token->text == ')') { // Fin del array
                break;
            }

            if ($token->id == T_CONSTANT_ENCAPSED_STRING) {
                // Leer clave
                $key = substr($token->text, 1, -1);
                $pos++;
                $token = $this->_ignoreEmptyTokens($tokens, $pos);
                if ($token->id != T_DOUBLE_ARROW) {
                    throw new \Exception("Invalid PHP translation file: \"=>\" was expected for key {$key}, {$token->text} found at {$token->line}:{$token->pos}");
                }
                $pos++;
                $token = $this->_ignoreEmptyTokens($tokens, $pos);

                // Leer valor
                if ($token->id == T_CONSTANT_ENCAPSED_STRING) {
                    $value = substr($token->text, 1, -1);
                } else {
                    throw new \Exception("Invalid PHP translation file: a string was expected as value, {$token->text} found at {$token->line}:{$token->pos}");
                }

                $pos++;
                $token = $this->_ignoreEmptyTokens($tokens, $pos);


                // Leer todos los comentarios hasta la posición actual
                $metadata = new LString();
                $metadata->id = $key;
                $metadata->line = $token->line;
                $metadata->offset = $token->pos;
                for ($i = $entryStart; $i <= $pos; $i++) {
                    if ($tokens[$i]->id == T_COMMENT) {
                        $content = trim(substr($tokens[$i]->text, 2, -2));
                        $metadata->comments = trim(($metadata->comments ?? '') . PHP_EOL . $content);
                    }
                }

                $strings[$key] = new Translation($value, $metadata);

                if ($token->text == ',') { // Separador de elementos
                    $pos++;
                }
            } else {
                throw new \Exception("Invalid PHP translation file: a string key was expected, {$token->text} found at {$token->line}:{$token->pos}");
            }
        }

        return new Catalog($locale, $strings);

        // Opción alternativa: eval
        /*  if (preg_match('/^<\?php\s+declare\s*\(\s*strict_types\s*=\s*1\)\s*;/i', $content, $match)) {
            $content = substr($content, strlen($match[0]));
            return $this->_parse(eval($content), $locale);
          } else {
            return $this->_parse(eval("?> {$content}"), $locale);
          }*/
    }

    private function _ignoreEmptyTokens(array $tokens, int &$pos): \PhpToken|null
    {
        while ($pos < count($tokens) && in_array($tokens[$pos]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            $pos++;
        }

        return $tokens[$pos] ?? null;
    }
}

