<?php

declare(strict_types=1);

namespace ideatic\l10n\String\Format;

use ideatic\l10n\LString;
use ideatic\l10n\Plural\Symfony;
use ideatic\l10n\Utils\PHP\Code;
use ideatic\l10n\Utils\PHP\FunctionCall;

/**
 * Provider of translatable strings found in PHP code
 */
class PHP extends Format
{
    private array $_functions;

    public function __construct(array $functions = ['__' => 1, '_x' => 2, '_f' => 2, '_icu' => 2])
    {
        $this->_functions = $functions;
    }

    public function translate(string $content, callable $getTranslation, mixed $context = null): string
    {
        $offsetCorrection = 0;
        foreach ($this->getStrings($content, $context) as $string) {
            if (!empty($string->requestedLocale)) { // The string is expected in a specific locale
                continue;
            }

            $translatedString = call_user_func($getTranslation, $string);
            if ($translatedString === null) {
                continue;
            }

            $translation = $this->_getTranslationCode($string, $translatedString);

            // Replace the optimised version in the code
            /** @var FunctionCall $rawCall */
            $rawCall = $string->raw;
            $content = substr_replace($content, $translation, $rawCall->offset - $offsetCorrection, strlen($rawCall->code));
            $offsetCorrection += strlen($rawCall->code) - strlen($translation);
        }

        return $content;
    }

    /** @inheritDoc */
    public function getStrings(string $phpCode, mixed $path = null): array
    {
        // Parse the code and look for calls to the translation methods
        $analyzer = new Code($phpCode);
        $calls = $analyzer->getFunctionCalls(array_keys($this->_functions));

        $found = [];
        foreach ($calls as $call) {
            if (isset($this->_functions[$call->method]) && $call->isFunction()) {
                // Only function calls with a constant id can be edited
                if ($call->argumentCount() == 0 || !$call->isConstantString(0)) {
                    // Warn when there is text written in the code itself that will not be translatable. An
                    // indirection without literals, such as __($message), is deliberate and is not reported.
                    if ($call->argumentCount() > 0 && $call->containsStringLiteral(0)) {
                        echo "\033[31mWarning! Localizable string ignored, its first argument is not a constant string: {$call->code} @ {$path}:{$call->line}\033[0m\n";
                    }

                    continue;
                }

                $string = new LString();

                // Get the translation parameters (id, locales and section)
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
        // Get the translated string
        $translatedString = var_export($translatedString, true);

        /** @var FunctionCall $phpCall */
        $phpCall = $string->raw;

        switch ($phpCall->method) {
            case '__':
            case 'translate':
                return $translatedString;

            case '_f':
            case '_e':
            case 'translate_format':
                if (!empty($phpCall->arguments[1])) { // When the call has no parameters the text is static and there is no need to call strtr
                    $replacements = $phpCall->arguments[1];
                    if (!$phpCall->isArray(1)) {
                        $replacements = "is_array({$phpCall->arguments[1]}) ? {$phpCall->arguments[1]} : array('%'=>{$phpCall->arguments[1]})";
                    }
                    $format = "strtr({$translatedString},{$replacements})";
                    if ($phpCall->method == '_e') {
                        $format = "echo {$format}";
                    }
                    return $format;
                }

            case '_icu':
            case 'formatICU':
                $arguments = $phpCall->arguments[2] ?? '[]';
                return "_icu({$translatedString},{$arguments}, null, null, false)";
        }

        return $phpCall->code;
    }
}
