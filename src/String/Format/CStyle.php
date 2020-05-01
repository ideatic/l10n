<?php

namespace ideatic\l10n\String\Format;

use ideatic\l10n\LString;
use ideatic\l10n\Utils\IO;

/**
 * Proveedor de cadenas traducibles encontradas en código estilo C (Javascript, C, PHP, C#, etc.)
 */
class CStyle extends Format
{
    public $methods = ['__', '_x', '_icu'];

    /** @var bool Al traducir, optimizar llamadas a string.replace() constantes encontradas */
    public $optimizePlaceholderReplacement = true;

    public $encodeTranslationsWithSingleQuotes = false;

    /**
     * @inheritDoc
     */
    public function translate(string $content, callable $getTranslation, $path = null): string
    {
        foreach ($this->getStrings($content, $path) as $string) {
            $translation = call_user_func($getTranslation, $string);

            if ($translation === null) {
                continue;
            }

            $translation = json_encode($translation);
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

    /**
     * @inheritDoc
     */
    public function getStrings(string $code, $path = null): array
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

            $sectionIndex = 1;
            if ($method == '_x') {
                if (empty($params[1]) || !$this->_isString($params[1])) {
                    throw new \Exception("Constant context string required for {$string->raw}");
                }
                $string->context = $this->_parseString($params[1]);
                $sectionIndex = 2;
            } elseif (count($params) > 2) {
                throw new \Exception("Invalid param count in {$string->raw}");
            }

            $string->offset = $match[0][1];
            $string->file = $path;
            $string->line = substr_count($code, "\n", 0, $string->offset) + 1;
            $string->domainName = isset($params[$sectionIndex]) ? $this->_parseString($params[$sectionIndex]) : $this->defaultDomain;
            $string->comments = $comments;

            // Autodetectar plurales
            if ($method == '_icu') {
                $string->isICU = true;
            }

            $found[] = $string;
        }

        return $found;
    }

    private function _parseFnArgs(string $source, int $offset = 0, &$pos = null, &$comments = ''): array
    {
        if ($source[$offset] !== '(') {
            throw new \InvalidArgumentException("Open parenthesis expected at initial offset, received " . substr($source, $offset, 20));
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
        } catch (\Throwable $err) {
            return false;
        }
    }

    private function _parseString(string $string): string
    {
        $str = json_decode('"' . addcslashes(trim(trim($string), '\'"`'), '"') . '"');
        if (json_last_error() != JSON_ERROR_NONE) {
            throw new \Exception("Unable to parse CStyle string {$string}: " . json_last_error_msg());
        }
        return $str;
    }

    private function _optimizePlaceholders(string $content, LString $i18nString, string $translation): string
    {
        if (stripos($content, '.replace') === false) {
            return str_replace($i18nString->raw, $translation, $content);
        }

        $offset = 0;
        do {
            $rawCall = $i18nString->raw;
            $i18nStringPos = strpos($content, $rawCall, $offset);

            if ($i18nStringPos) {
                $translationEnd = $i18nStringPos + strlen($rawCall);
                while (preg_match('#^\s*\.replace\(#iu', substr($content, $translationEnd), $match)) {
                    $args = $this->_parseFnArgs($content, $translationEnd + strlen($match[0]) - 1, $argsFinish);

                    if (count($args) != 2) {
                        break;
                    } elseif (!$this->_isString($args[0])) { // No es una cadena
                        break;
                    } else {
                        $args[1] = trim($args[1]);
                        $replacement = "' + ({$args[1]}) + '";
                        $translation = str_replace(substr($args[0], 1, -1), $replacement, $translation);

                        // Incluir llamada a .replace() en la cadena a reemplazar, y reajustar posición del fin de la traducción para comprobar si hay más llamadas a replace()
                        $rawCall = substr($content, $i18nStringPos, $argsFinish - $i18nStringPos + 1);
                        $translationEnd = $argsFinish + 1;
                    }
                }

                $content = str_replace($rawCall, $translation, $content, $replaceCount);

                if ($replaceCount == 0) {
                    throw new \Exception("Unable to find string '{$rawCall}', offset {$offset}", ['content' => $content, 'translation' => $translation]);
                }

                // Seguir buscando coincidencias de la cadena
                $offset = $i18nStringPos + 1;
            }
        } while ($i18nStringPos !== false);

        return $content;
    }
}