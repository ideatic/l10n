<?php

declare(strict_types=1);

namespace ideatic\l10n\String\Format;

use Exception;
use ideatic\l10n\LString;
use ideatic\l10n\Utils\IO;
use InvalidArgumentException;
use Throwable;

/**
 * Proveedor de cadenas traducibles encontradas en código estilo C (Javascript, C, PHP, C#, etc.)
 */
class CStyle extends Format
{
    public array $methods = ['__', '_x', '_icu'];

    /** Al traducir, optimizar llamadas a string.replace() constantes encontradas */
    public bool $optimizePlaceholderReplacement = true;

    public bool $encodeTranslationsWithSingleQuotes = false;

    /** @inheritDoc */
    public function translate(string $content, callable $getTranslation, mixed $path = null): string
    {
        foreach ($this->getStrings($content, $path) as $string) {
            $translation = call_user_func($getTranslation, $string);

            if ($translation === null) {
                continue;
            }

            $translation = json_encode($translation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($this->encodeTranslationsWithSingleQuotes) {
                $translation = "'" . addcslashes(substr($translation, 1, -1), "'") . "'";
            }

            if ($path && IO::getExtension($path) == 'html') {
                $translation = htmlspecialchars($translation);
            }

            if ($this->optimizePlaceholderReplacement) {
                $content = $this->_optimizePlaceholders($content, $string, $translation);
            } else {
                $content = str_replace($string->raw, $translation, $content);
            }
        }

        return $content;
    }

    /** @inheritDoc */
    public function getStrings(string $code, mixed $path = null): array
    {
        $pattern = '/(' . implode('|', $this->methods) . ')\s*\(/';

        preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $found = [];

        foreach ($matches as $match) {
            $string = new LString();

            $method = $match[1][0];

            // Comprobar si estamos en un comentario
            $lineBegin = strrpos(substr($code, 0, $match[0][1]), "\n");
            if ($lineBegin !== false) {
                $commentStart = strpos($code, '//', $lineBegin);
                if ($commentStart !== false && $commentStart < $match[0][1]) {
                    continue;
                }
            }

            // Parsear parámetros usando JSON
            $methodEnd = 0;
            $params = $this->_parseFnArgs($code, $match[0][1] + strlen($match[0][0]) - 1, $methodEnd, $comments);

            if (count($params) < 1 || !$this->_isString($params[0])) {
                continue;
            }
            $string->id = $string->text = $this->_parseString($params[0]);
            $string->raw = substr($code, $match[0][1], $methodEnd - $match[0][1] + 1);

            $domainIndex = 1;
            if ($method == '_x') {
                if (empty($params[1]) || !$this->_isString($params[1])) {
                    throw new Exception("Constant context string required for {$string->raw}");
                }
                $string->context = $this->_parseString($params[1]);
                $domainIndex = 2;
            } elseif (count($params) > 2) {
                throw new Exception("Invalid param count in {$string->raw}");
            }

            $string->offset = $match[0][1];
            $string->file = $path;
            $string->line = substr_count($code, "\n", 0, $string->offset) + 1;
            $string->domainName = isset($params[$domainIndex]) ? $this->_parseString($params[$domainIndex]) : $this->defaultDomain;
            $string->requestedLocale = isset($params[$domainIndex + 1]) ? $this->_parseString($params[$domainIndex + 1]) : null;
            $string->comments = $comments;

            // Autodetectar plurales
            if ($method == '_icu') {
                $string->isICU = true;
            }

            $found[] = $string;
        }

        return $found;
    }

    private function _parseFnArgs(string $source, int $offset = 0, int &$pos = null, string|null &$comments = ''): array
    {
        if ($source[$offset] !== '(') {
            throw new InvalidArgumentException("Open parenthesis expected at initial offset, received " . substr($source, $offset, 20));
        }
        $offset++;

        $args = [];
        $currentArg = '';
        $inString = false;
        $inComment = false;
        $openParenthesis = 0;
        for ($pos = $offset; $pos < strlen($source); $pos++) {
            $char = $source[$pos];

            if ($inComment) {
                if ($char == '/' && $source[$pos - 1] == '*' && $inComment == '*') { // Comentarios hasta encontrar */
                    $inComment = false;
                } elseif ($char == "\n" && $inComment == '/') { // Comentarios hasta final de línea
                    $inComment = false;
                }

                if ($inComment) {
                    $comments ??= '';
                    $comments .= $char;
                }
            } elseif ($char == '"' || $char == "'" || $char == '`') {
                if ($inString && $source[$pos - 1] == '\\') {
                    // Carácter escapado
                } else {
                    $inString = !$inString;
                }

                $currentArg .= $char;
            } elseif (!$inString) {
                if ($char == ',') {
                    $args[] = $currentArg;
                    $currentArg = '';
                } elseif ($char == '(') {
                    $openParenthesis++;
                    $currentArg .= $char;
                } elseif ($char == ')') {
                    $openParenthesis--;
                    if ($openParenthesis < 0) {
                        break;
                    } else {
                        $currentArg .= $char;
                    }
                } elseif ($char == '/' && isset($source[$pos + 1]) && ($source[$pos + 1] == '*' || $source[$pos + 1] == '/')) {
                    $inComment = $source[$pos + 1];
                } else {
                    $currentArg .= $char;
                }
            } else {
                $currentArg .= $char;
            }
        }

        if ($currentArg) {
            $args[] = $currentArg;
        }

        if ($comments) {
            $comments = trim(trim($comments, '*/'));
        }

        return $args;
    }

    private function _isString(string $string): bool
    {
        try {
            $str = $this->_parseString($string);
            return $str != $string;
        } catch (Throwable $err) {
            return false;
        }
    }

    private function _parseString(string $string): string
    {
        $str = json_decode('"' . addcslashes(trim(trim($string), '\'"`'), '"') . '"');
        if (json_last_error() != JSON_ERROR_NONE) {
            throw new Exception("Unable to parse CStyle string {$string}: " . json_last_error_msg());
        }
        return $str;
    }

    private function _optimizePlaceholders(string $source, LString $i18nString, string $translation): string
    {
        if (stripos($source, '.replace') === false) {
            return str_replace($i18nString->raw, $translation, $source);
        }

        $offset = 0;
        do {
            $rawCall = $i18nString->raw;
            $i18nStringPos = strpos($source, $rawCall, $offset);
            $currentTranslation = $translation;

            if ($i18nStringPos) {
                $translationEnd = $i18nStringPos + strlen($rawCall);
                while (preg_match('#^\s*\.replace\(#iu', substr($source, $translationEnd), $match)) {
                    $args = $this->_parseFnArgs($source, $translationEnd + strlen($match[0]) - 1, $argsFinish);

                    if (count($args) != 2) {
                        break;
                    } elseif (!$this->_isString($args[0])) { // No es una cadena
                        break;
                    } else {
                        $args[1] = trim($args[1]);
                        $search = substr($args[0], 1, -1);
                        $replacement = "' + ({$args[1]}) + '";
                        $currentTranslation = str_replace($search, $replacement, $currentTranslation, $replaceCount);

                        if ($replaceCount == 0) {
                            echo "\033[31mWarning! Unable to find placeholder '{$search}' in string {$translation} @ {$i18nString->file}\033[0m\n";
                        }

                        // Incluir llamada a .replace() en la cadena a reemplazar, y reajustar posición del fin de la traducción para comprobar si hay más llamadas a replace()
                        $rawCall = substr($source, $i18nStringPos, $argsFinish - $i18nStringPos + 1);
                        $translationEnd = $argsFinish + 1;
                    }
                }

                $source = str_replace($rawCall, $currentTranslation, $source, $replaceCount);

                if ($replaceCount == 0) {
                    throw new Exception("Unable to find string '{$rawCall}', offset {$offset} for translation {$currentTranslation}");
                }

                // Seguir buscando coincidencias de la cadena
                $offset = $i18nStringPos + 1;
            }
        } while ($i18nStringPos !== false);

        return $source;
    }
}