<?php

namespace ideatic\l10n\String\Format;

use ideatic\l10n\LString;
use ideatic\l10n\Plural\Symfony;
use ideatic\l10n\Utils\PHP\FunctionCall;

/**
 * Proveedor de cadenas traducibles encontradas en código PHP
 */
class PHP extends Format
{
    private $_functions;

    public function __construct($functions = ['__' => 1, '_x' => 2, '_f' => 2, '_icu' => 2])
    {
        $this->_functions = $functions;
    }

    public function translate(string $content, callable $getTranslation, $context = null): string
    {
        $correction = 0;
        foreach ($this->getStrings($content, $context) as $string) {
            if ($string->requestedLocale) { // Se espera la cadena en un locale especificado
                continue;
            }

            $translatedString = call_user_func($getTranslation, $string);
            if ($translatedString === null) {
                continue;
            }

            $translation = $this->_getTranslationCode($string, $translatedString);

            // Reemplazar la versión optimizada en el código
            /** @var \ideatic\l10n\Utils\PHP\FunctionCall $rawCall */
            $rawCall = $string->raw;
            $content = substr_replace($content, $translation, $rawCall->offset - $correction, strlen($rawCall->code));
            $correction += strlen($rawCall->code) - strlen($translation);
        }

        return $content;
    }

    /** @inheritDoc */
    public function getStrings(string $phpCode, $path = null): array
    {
        // Analizar el código y buscar llamadas a métodos de traducción
        $analyzer = new \ideatic\l10n\Utils\PHP\Code($phpCode);
        $calls = $analyzer->getFunctionCalls(array_keys($this->_functions));

        $found = [];
        foreach ($calls as $call) {
            if (isset($this->_functions[$call->method]) && $call->isFunction()) {
                // Solo se pueden editar llamadas a funciones con una id constante
                if (!$call->isConstantString(0)) {
                    continue;
                }

                $string = new LString();

                // Obtener parámetros de la traducción (id, locales y sección)
                $domainIndex = $this->_functions[$call->method];
                $string->id = $string->text = $call->parseArgument(0);
                $string->comments = trim(implode("\n", $call->getComments(0)));
                $string->file = $path;
                $string->line = $call->line;
                $string->offset = $call->offset;
                $string->raw = $call;
                if ($call->method == '_x') {
                    $string->context = $call->parseArgument(1);
                }
                if ($call->method == '_icu') {
                    $string->isICU = true;
                }

                // Choose localization group
                if (isset($call->arguments[$domainIndex])) {
                    $string->domainName = $call->parseArgument($domainIndex);
                } else {
                    $string->domainName = $this->defaultDomain;
                }

                // Requested locale
                if (isset($call->arguments[$domainIndex + 1])) {
                    $string->requestedLocale = $call->parseArgument($domainIndex);
                }

                $found[] = $string;
            }
        }

        return $found;
    }

    private function _getTranslationCode(LString $string, string $translatedString): string
    {
        // Obtener cadena traducida
        $translatedString = var_export($translatedString, true);

        /** @var FunctionCall $phpCall */
        $phpCall = $string->raw;

        switch ($phpCall->method) {
            case '__':
            case 'translate':
                if ($phpCall->method == '_e') {
                    return "echo {$translatedString}";
                } else {
                    return $translatedString;
                }

            case '_f':
            case '_e':
            case 'translate_format':
                if (!empty($phpCall->arguments[1])) { //Si es una llamada sin parámetros, entonces el texto es estático y no se necesita llamar a strtr
                    $replacements = $phpCall->arguments[1];
                    if (!$phpCall->isArray(1)) {
                        $replacements = "is_array({$phpCall->arguments[1]}) ? {$phpCall->arguments[1]} : array('%'=>{$phpCall->arguments[1]})";
                    }
                    $format = "strtr({$translatedString}, {$replacements})";
                    if ($phpCall->method == '_e') {
                        $format = "echo {$format}";
                    }
                    return $format;
                }

            case '_icu':
            case 'formatICU':
                $arguments = $phpCall->arguments[2] ?? '[]';
                return "_icu({$translatedString}, {$arguments}, null, null, false)";
        }

        return $phpCall->code;
    }
}