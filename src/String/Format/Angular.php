<?php

declare(strict_types=1);

namespace ideatic\l10n\String\Format;

use Exception;
use HTML_Parser;
use ideatic\l10n\LString;
use ideatic\l10n\Utils\ICU\Pattern;
use ideatic\l10n\Utils\ICU\Placeholder;
use ideatic\l10n\Utils\IO;
use JsonException;

/**
 * Proveedor de cadenas traducibles encontradas en código Javascript / TypeScript
 */
class Angular extends Format
{
    private Angular_HTML $_i18nHTML;
    private Angular_Methods $_i18nMethods;

    /** https://github.com/angular/angular/issues/9117 */
    public bool $fixIcuPluralHashes = true;

    public function __construct()
    {
        // Los proyectos Angular tienen varias ubicaciones para las cadenas traducibles:

        // Atributos HTML
        $this->_i18nHTML = new Angular_HTML();
        $this->_i18nHTML->autoDetectIcuPatterns = true;

        // Llamadas a __(), $localize, etc. en código TypeScript
        // Llamadas a __(), $localize, etc. en código expresiones HTML
        $this->_i18nMethods = new Angular_Methods();
        $this->_i18nMethods->defaultDomain = $this->defaultDomain;
        $this->_i18nMethods->optimizePlaceholderReplacement = true;
        $this->_i18nMethods->encodeTranslationsWithSingleQuotes = true;
    }

    /** @inheritDoc */
    public function getStrings(string $content, mixed $path = null): array
    {
        return $this->_processStrings($content, $path);
    }

    /** @inheritDoc */
    public function translate(string $content, callable $getTranslation, mixed $path = null): string
    {
        return $this->_processStrings($content, $path, $getTranslation);
    }

    private function _processStrings(string $content, ?string $file, ?callable $getTranslation = null): array|string
    {
        $this->_i18nHTML->addHashPluralSupport = $this->fixIcuPluralHashes;

        if (!$file) {
            throw new Exception("Path required!");
        }

        $extension = IO::getExtension($file);
        $foundStrings = [];

        // Buscar atributos i18n en el HTML
        if ($extension == 'html') {
            foreach ($this->_i18nHTML->getStrings($content, $file) as $string) {
                $foundStrings[] = $string;
            }

            if ($getTranslation) {
                $content = $this->_i18nHTML->translate($content, $getTranslation, $file);
            }
        } elseif ($extension == 'ts') { // Procesar HTML incrustado
            foreach (self::_getInlineTemplates($content, true) as $inlineTemplate) {
                $result = $this->_processStrings($inlineTemplate['value'], "{$file}.html", $getTranslation);

                if ($getTranslation) { // Reemplazar plantilla anterior
                    if (strcmp($inlineTemplate['value'], $result) != 0) {
                        $newTemplate = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        $content = str_replace($inlineTemplate['raw'], $newTemplate, $content, $count);
                        if ($count == 0) {
                            throw new Exception("Unable to replace component html template at '{$file}'");
                        }
                    }
                } else {
                    $foundStrings = array_merge($foundStrings, $result);
                }
            }
        }

        // Buscar llamadas a __() en html y TS
        if (in_array($extension, ['html', 'ts'])) {
            foreach ($this->_i18nMethods->getStrings($content, $file) as $string) {
                $foundStrings[] = $string;
            }

            if ($getTranslation) {
                $content = $this->_i18nMethods->translate($content, $getTranslation, $file);
            }
        }

        return $getTranslation ? $content : $foundStrings;
    }


    /**
     * Obtiene las plantillas Angular de los componentes existentes en el código TypeScript indicado
     */
    private static function _getInlineTemplates(string $tsSource, bool $getFullDeclaration = false): array
    {
        $result = [];
        preg_match_all('/\btemplate:\s*[`\'\"]/Ssiu', $tsSource, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $string = self::_readString($tsSource, $match[0][1] + strlen($match[0][0]) - 1);
            $result[] = $getFullDeclaration ? $string : $string['value'];
        }

        return $result;
    }

    private static function _readString(string $source, int $offset): ?array
    {
        $inString = false;
        $prev = null;
        $stringStartOffset = null;

        for ($i = $offset; $i < strlen($source); $i++) {
            $char = $source[$i];

            if ($inString) {
                if ($char == $inString && $prev != '\\') {
                    $raw = substr($source, $stringStartOffset, $i - $stringStartOffset + 1);
                    $value = null;

                    try {
                        if ($char == '`') {
                            $value = substr($raw, 1, -1);
                        } elseif ($char == '"') {
                            $value = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
                        } else {
                            $value = json_decode('"' . addcslashes(substr($raw, 1, -1), '"') . '"', false, 512, JSON_THROW_ON_ERROR);
                        }
                    } catch (JsonException $err) {
                        echo "\n" . $raw . "\n";
                        throw $err;
                    }

                    return [
                        'raw' => $raw,
                        'value' => $value,
                    ];
                }
            } elseif (($char == '`' || $char == "'" || $char == '"') && $prev != '\\') {
                $inString = $char;
                $stringStartOffset = $i;
            }

            $prev = $char;
        }

        return null;
    }

    /**
     * @internal
     */
    public static function prepareString(LString $string, ?string $path = null): void
    {
        // Reemplazar expresiones
        $parsed = preg_replace_callback(
            '/{{([^{].+?)}}/us',
            function ($match) use ($string, $path) {
                $expr = HTML_Parser::entityDecode($match[1]);
                foreach (explode('|', $expr) as $pipe) {
                    $parts = explode(':', mb_trim($pipe));
                    if (mb_trim($parts[0]) == 'i18n' && isset($parts[1])) {
                        $placeholderName = trim(mb_trim($parts[1]), '"\'');
                        $string->placeholders[$placeholderName] = str_replace("|{$pipe}", '', $match[0]);
                        return $placeholderName;
                    }
                }

                throw new Exception("No i18n placeholder found in expression '{$expr}' at '{$path}'");
            },
            $string->text
        );

        if ($parsed != $string->text) {
            if ($string->id == $string->text) {
                $string->id = $parsed;
            }

            $string->text = $parsed;
        }

        // Normalizar ID (en angular, el nombre del placeholder ICU es su valor interpolado, usar 'count' en su lugar)
        if ($string->isICU && $string->id == $string->text) {
            $pattern = new Pattern($string->text);
            if (count($pattern->nodes) == 1 && $pattern->nodes[0] instanceof Placeholder && $pattern->nodes[0]->type == 'plural') {
                $pattern->nodes[0]->name = 'count';
                $string->id = $pattern->render(false);
            }
        }
    }

    /**
     * @param 'template'|'code' $context
     */
    public static function fixTranslation(LString $string, ?string $translation, string $context, bool $fixHashPluralSupport = false): ?string
    {
        if ($string->isICU && $translation != null) {
            $pattern = new Pattern($string->text);

            if ($pattern->nodes[0] instanceof Placeholder && $pattern->nodes[0]->type == 'plural') {
                // Desnormalizar ID
                $translation = str_replace('{count,', "{{$pattern->nodes[0]->name},", $translation);

                // Reemplazar # por una expresión angular que formatee la cantidad
                if ($fixHashPluralSupport && str_contains($string->fullyQualifiedID(), '#')) {
                    $translation = str_replace('#', "{{ {$pattern->nodes[0]->name} | number }}", $translation);
                }
            }
        }

        if ($context == 'template') { // El carácter @ es especial en templates
            $translation = str_replace("@", '&#64;', $translation);
        }

        return $translation;
    }
}

/**
 * @internal
 */
class Angular_HTML extends HTML
{
    public bool $addHashPluralSupport = true;

    /** @inheritDoc */
    public function getStrings(string $html, mixed $path = null): array
    {
        $strings = parent::getStrings($html, $path);

        foreach ($strings as $string) {
            Angular::prepareString($string, $path);
        }

        return $strings;
    }

    /** @inheritDoc */
    public function translate(string $content, callable $getTranslation, $context = null): string
    {
        return parent::translate(
            $content,
            function (LString $string) use ($getTranslation, $context) {
                Angular::prepareString($string, $context);

                $translation = call_user_func($getTranslation, $string);

                if ($translation != null && !empty($string->placeholders)) { // Restaurar expresiones Angular
                    $translation = strtr($translation, $string->placeholders);
                }

                return Angular::fixTranslation($string, $translation, 'template', $this->addHashPluralSupport);
            },
            $context
        );
    }
}

/**
 * @internal
 */
class Angular_Methods extends CStyle
{
    /** @inheritDoc */
    public function getStrings(string $code, mixed $path = null): array
    {
        $strings = parent::getStrings($code, $path);

        // Incluir llamadas a $localize``
        $pattern = '/\$localize`(.*?)`/s';
        preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $jsonStr = str_replace(["\n", "\r"], ['\n', '\r'], '"' . addcslashes($match[1][0], '"') . '"');

            $string = new LString();
            $string->id = $string->text = json_decode($jsonStr, false, 1, JSON_THROW_ON_ERROR);
            $string->raw = $match[0][0];
            $string->offset = $match[0][1];
            $string->file = $path;
            $string->line = substr_count($code, "\n", 0, $string->offset) + 1;
            $string->domainName = $this->defaultDomain;

            // Comentarios y contexto https://angular.dev/guide/i18n/prepare#i18n-metadata-for-translation
            if (str_starts_with($string->text, ':')) {
                $metadataEnd = mb_strpos($string->text, ':', 1);
                $metadata = mb_substr($string->text, 1, $metadataEnd - 1);
                $string->id = $string->text = mb_substr($string->text, $metadataEnd + 1);

                if (str_contains($metadata, '|')) {
                    if ($metadata[0] == '|') {
                        $string->comments = mb_substr($metadata, 1);
                    } else {
                        [$string->context, $string->comments] = explode('|', $metadata, 2);
                    }
                } else {
                    $string->context = $metadata;
                }

                if (str_contains($string->comments ?? '', '@@')) {
                    [$string->comments, $string->id] = explode('@@', $string->comments, 2);
                }
                if (str_contains($string->comments ?? '', '##')) {
                    [$string->comments, $string->domainName] = explode('##', $string->comments, 2);
                }
            }

            if (str_contains($string->text, '${')) {
                throw new Exception("Unsupported template string {$string->raw}");
            }

            $strings[] = $string;
        }

        foreach ($strings as $string) {
            Angular::prepareString($string, $path);
        }

        return $strings;
    }


    /** @inheritDoc */
    public function translate(string $content, callable $getTranslation, $path = null): string
    {
        return parent::translate(
            $content,
            function (LString $string) use ($getTranslation, $path) {
                Angular::prepareString($string, $path);

                return Angular::fixTranslation($string, call_user_func($getTranslation, $string), 'code');
            },
            $path,
        );
    }
}
