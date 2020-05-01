<?php

namespace ideatic\l10n\String\Format;

use HTML_Parser;
use ideatic\l10n\LString;
use ideatic\l10n\Utils\ICU\Pattern;
use ideatic\l10n\Utils\ICU\Placeholder;
use ideatic\l10n\Utils\IO;
use ideatic\l10n\Utils\Str;

/**
 * Proveedor de cadenas traducibles encontradas en código Javascript / TypeScript
 */
class Angular extends Format
{
    private $_i18nHTML;
    private $_i18nMethods;

    /** @var bool https://github.com/angular/angular/issues/9117 */
    public $fixIcuPluralHashes = true;

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

    /**
     * @inheritDoc
     */
    public function getStrings(string $content, $path = null): array
    {
        return $this->_processStrings($content, $path);
    }

    /**
     * @inheritDoc
     */
    public function translate(string $content, callable $getTranslation, $path = null): string
    {
        return $this->_processStrings($content, $path, $getTranslation);
    }

    private function _processStrings(string $content, ?string $file, ?callable $getTranslation = null)
    {
        $this->_i18nHTML->addHashPluralSupport = $this->fixIcuPluralHashes;

        if (!$file) {
            throw new \Exception("Path required!");
        }

        $extension = IO::getExtension($file);
        $foundStrings = [];

        // Buscar llamadas a __() en html y TS
        if (in_array($extension, ['html', 'ts'])) {
            foreach ($this->_i18nMethods->getStrings($content, $file) as $string) {
                $foundStrings[] = $string;
            }

            if ($getTranslation) {
                $content = $this->_i18nMethods->translate($content, $getTranslation, $file);
            }
        }

        // Buscar atributos i18n en el HTML
        if ($extension == 'html') {
            foreach ($this->_i18nHTML->getStrings($content, $file) as $string) {
                $foundStrings[] = $string;
            }

            if ($getTranslation) {
                $content = $this->_i18nHTML->translate($content, $getTranslation, $file);
            }
        } elseif ($extension == 'ts') { // Buscar etiquetas i18n en el HTML incrustado
            foreach (self::_getInlineTemplates($content) as $template) {
                foreach ($this->_i18nHTML->getStrings($template, $file) as $string) {
                    $foundStrings[] = $string;
                }
            }
        }

        return $getTranslation ? $content : $foundStrings;
    }

    /**
     * Obtiene las plantillas Angular de los componentes existentes en el código TypeScript indicado
     */
    private static function _getInlineTemplates(string $tsSource, bool $getFullDeclaration = false): array
    {
        preg_match_all('/template:\s*[`](.+?)[`]/Ssiu', $tsSource, $matches, PREG_SET_ORDER);

        $result = [];
        foreach ($matches as $match) {
            $result[] = $getFullDeclaration ? $match[0] : $match[1];
        }

        return $result;
    }

    /**
     * @internal
     */
    public static function prepareString(LString $string, ?string $path = null)
    {
        // Reemplazar expresiones
        $parsed = preg_replace_callback(
            '/{{([^{]*?)}}/',
            function ($match) use ($string, &$placeholders, $path) {
                $expr = HTML_Parser::entityDecode($match[1]);
                foreach (explode('|', $expr) as $pipe) {
                    $parts = explode(':', Str::trim($pipe));
                    if (Str::trim($parts[0]) == 'i18n' && isset($parts[1])) {
                        $placeholderName = trim(Str::trim($parts[1]), '"\'');
                        $string->placeholders[$placeholderName] = str_replace("|{$pipe}", '', $match[0]);
                        return $placeholderName;
                    }
                }

                throw new \Exception("No i18n placeholder found in expression '{$expr}' at '{$path}'");
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
}

/**
 * @internal
 */
class Angular_HTML extends HTML
{
    public $addHashPluralSupport = true;

    /**
     * @inheritDoc
     */
    public function getStrings(string $code, $path = null): array
    {
        $strings = parent::getStrings($code, $path);

        foreach ($strings as $string) {
            Angular::prepareString($string, $path);
        }

        return $strings;
    }

    /**
     * @inheritDoc
     */
    public function translate(string $content, callable $getTranslation, $path = null): string
    {
        return parent::translate(
            $content,
            function (LString $string) use ($getTranslation, $path) {
                Angular::prepareString($string, $path);

                $translation = call_user_func($getTranslation, $string);

                // Reemplazar # por una expresión angular que formatee la cantidad
                if ($string->isICU && $this->addHashPluralSupport && $translation != null && strpos($string->fullyQualifiedID(), '#') !== false) {
                    $pattern = new Pattern($string->text);
                    $translation = str_replace('#', "{{ {$pattern->nodes[0]->name} | number }}", $translation);
                }

                return $translation;
            },
            $path
        );
    }
}

/**
 * @internal
 */
class Angular_Methods extends CStyle
{
    /**
     * @inheritDoc
     */
    public function getStrings(string $code, $path = null): array
    {
        $strings = parent::getStrings($code, $path);

        // Incluir llamadas a $localize``
        $pattern = '/\$localize`(.*?)`/s';
        preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $string = new LString();
            $string->id = $string->text = $match[1][0];
            $string->raw = $match[0][0];
            $string->offset = $match[0][1];
            $string->file = $path;
            $string->line = substr_count($code, "\n", 0, $string->offset) + 1;
            $string->domainName = $this->defaultDomain;

            if (strpos($string->text, '${') !== false) {
                throw new \Exception("Unsupported template string {$string->raw}");
            }

            $strings[] = $string;
        }

        foreach ($strings as $string) {
            Angular::prepareString($string, $path);
        }

        return $strings;
    }


    /**
     * @inheritDoc
     */
    public function translate(string $content, callable $getTranslation, $path = null): string
    {
        return parent::translate(
            $content,
            function (LString $string) use ($getTranslation, $path) {
                Angular::prepareString($string, $path);

                return call_user_func($getTranslation, $string);
            },
            $path
        );
    }
}