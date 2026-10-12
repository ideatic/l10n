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
 * Provider of translatable strings found in Javascript / TypeScript code
 */
class Angular extends Format
{
    private Angular_HTML $_i18nHTML;
    private Angular_Methods $_i18nMethods;

    /** https://github.com/angular/angular/issues/9117 */
    public bool $fixIcuPluralHashes = true;

    public function __construct()
    {
        // Angular projects hold translatable strings in several places:

        // HTML attributes
        $this->_i18nHTML = new Angular_HTML();
        $this->_i18nHTML->autoDetectIcuPatterns = true;
        $this->_i18nHTML->supportAngularExpressions = true;

        // Calls to __(), $localize, etc. in TypeScript code
        // Calls to __(), $localize, etc. in HTML expressions
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

        // Look for i18n attributes in the HTML
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
     * Prepares a template (.html) or a TypeScript file (.ts) for a native Angular i18n build: the Angular compiler
     * extracts and inlines the translations, while this library still provides them.
     *
     * - The i18n attributes of the templates get a custom id (`i18n="@@id"`) and lose the syntax Angular does not
     *   understand (see HTML::annotate()). `{{ expr | i18n:'%x%' }}` becomes `{{ expr // i18n(ph="I18N_X") }}`,
     *   so the Angular placeholder has a known name, and the bare `#` of ICU plurals is turned into an interpolation.
     * - `__()`, `_x()` and `_icu()` calls become `$localize` strings, and `$localize` strings get a custom id.
     * - The calls inside templates move to class fields holding the `$localize` string, because templates cannot use
     *   `$localize`.
     *
     * @param callable(LString $string, 'element'|'attribute'|'code' $kind, string $raw): string $stamp Receives each
     *                                                                                                   string and returns its id
     */
    public function annotate(string $content, callable $stamp, string $path): string
    {
        $this->_i18nHTML->addHashPluralSupport = $this->fixIcuPluralHashes;
        $extension = IO::getExtension($path);

        if ($extension == 'html') {
            $content = $this->_i18nHTML->annotate($content, $stamp, $path);

            $calls = $this->_i18nMethods->getStrings($content, $path);
            if ($calls) {
                throw new Exception("Translation calls in external templates are not supported by the native i18n mode, found {$calls[0]->raw} @ {$path}");
            }

            return $content;
        } elseif ($extension != 'ts') {
            return $content;
        }

        // Inline templates
        $fields = [];
        foreach (self::_getInlineTemplates($content, true) as $inlineTemplate) {
            $template = $this->_i18nHTML->annotate($inlineTemplate['value'], $stamp, "{$path}.html");

            // Templates cannot call $localize: each call moves to a class field
            $replacements = [];
            foreach ($this->_i18nMethods->getStrings($template, "{$path}.html") as $string) {
                $id = $stamp($string, 'code', $string->raw);
                $fieldName = "__i18n_{$id}";
                $fields[$fieldName] = self::_localizeExpression($string, $id);
                $replacements[$string->raw] = $fieldName;
            }
            $template = strtr($template, $replacements);

            if (strcmp($inlineTemplate['value'], $template) != 0) {
                $newTemplate = json_encode($template, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                // Equal templates of several components are all replaced the first time
                $content = str_replace($inlineTemplate['raw'], $newTemplate, $content, $count);
                if ($count == 0 && !str_contains($content, $newTemplate)) {
                    throw new Exception("Unable to replace component html template at '{$path}'");
                }
            }
        }

        // Strings in the code
        $replacements = [];
        foreach ($this->_i18nMethods->getStrings($content, $path) as $string) {
            if (isset($replacements[$string->raw])) {
                continue;
            }

            if (str_starts_with($string->raw, '$localize')) {
                $replacements[$string->raw] = self::_stampLocalize($string, $stamp);
            } else {
                $replacements[$string->raw] = self::_localizeExpression($string, $stamp($string, 'code', $string->raw));
            }
        }
        $content = strtr($content, $replacements);

        // Declare the fields used by the templates in their component: the template precedes its class, and the same call
        // in the templates of several components of the file needs the field in each one
        $declarations = [];
        foreach ($fields as $fieldName => $expression) {
            $offset = 0;
            while (($usage = strpos($content, $fieldName, $offset)) !== false && preg_match('/\bclass\s+[\w$]+[^{]*\{/', $content, $match, PREG_OFFSET_CAPTURE, $usage)) {
                $offset = $match[0][1] + strlen($match[0][0]);
                $declarations[$offset][] = "\n  protected readonly {$fieldName} = {$expression};";
            }

            if ($offset == 0) {
                throw new Exception("Unable to find the class that uses the translation field {$fieldName} @ {$path}");
            }
        }

        krsort($declarations);
        foreach ($declarations as $insertAt => $lines) {
            $content = substr($content, 0, $insertAt) . implode('', $lines) . substr($content, $insertAt);
        }

        return $content;
    }

    /**
     * Name of the Angular placeholder given to a placeholder of this library (`%user%` → `I18N_USER`)
     */
    public static function placeholderName(string $name): string
    {
        if ($name === '#') {
            return 'I18N_HASH';
        }

        $name = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $name), '_'));

        return 'I18N_' . ($name !== '' ? $name : 'PH');
    }

    /** Empty placeholder added to an ICU expression without placeholders, only to give it the id of its string */
    public const string ICU_ID_PLACEHOLDER = 'I18N_ID';

    /**
     * Suffix that ties the placeholder names of a template string to its custom id (see Angular_HTML::_annotateText())
     */
    public static function placeholderSuffix(string $id): string
    {
        return '__L' . strtoupper(hash('xxh32', $id));
    }

    /**
     * Placeholder name without the suffix added by placeholderSuffix()
     */
    public static function placeholderBaseName(string $name): string
    {
        return preg_replace('/__L[0-9A-F]{8}$/', '', $name);
    }

    /**
     * Builds the $localize string that replaces a __(), _x() or _icu() call
     */
    private static function _localizeExpression(LString $string, string $id): string
    {
        $metadata = ($string->context !== null ? "{$string->context}|" : '') . "@@{$id}";
        $text = str_replace(['\\', '`', '${'], ['\\\\', '\\`', '\\${'], $string->text);

        return "\$localize`:{$metadata}:{$text}`";
    }

    /**
     * Adds a custom id to the metadata block of a $localize string, keeping the one it already has
     */
    private static function _stampLocalize(LString $string, callable $stamp): string
    {
        $body = substr($string->raw, strlen('$localize`'), -1);

        if (str_starts_with($body, ':')) {
            $metadataEnd = strpos($body, ':', 1);
            $metadata = substr($body, 1, $metadataEnd - 1);
            if (str_contains($metadata, '@@')) {
                $stamp($string, 'code', $string->raw);
                return $string->raw;
            }

            return '$localize`:' . $metadata . '@@' . $stamp($string, 'code', $string->raw) . substr($body, $metadataEnd) . '`';
        }

        return '$localize`:@@' . $stamp($string, 'code', $string->raw) . ':' . $body . '`';
    }

    /**
     * Gets the Angular templates of the components present in the given TypeScript code
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
        // Replace the expressions
        $parsed = preg_replace_callback(
            '/{{([^{].+?)}}/us',
            function ($match) use ($string, $path) {
                $expr = HTML_Parser::entityDecode($match[1]);
                foreach (explode('|', $expr) as $pipe) {
                    $parts = explode(':', mb_trim($pipe));
                    if (mb_trim($parts[0]) == 'i18n' && isset($parts[1])) {
                        $placeholderName = trim(mb_trim($parts[1]), '"\'');

                        if ($placeholderName === '') {
                            throw new Exception("Invalid i18n placeholder name in expression '{$expr}' @ {$path}");
                        }

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

        // Normalise the id (in Angular the name of the ICU placeholder is its interpolated value, use 'count' instead)
        if ($string->isICU && $string->id == $string->text) {
            $pattern = new Pattern($string->text);
            $placeholders = array_filter($pattern->nodes, fn($node) => $node instanceof Placeholder);

            // Process the i18n pipes so the placeholders get proper names
            $changed = false;
            if (count($placeholders) == 1 && array_first($placeholders)->type == 'plural' && !preg_match('/\|\s+i18n\:/', array_first($placeholders)->name)) {
                array_first($placeholders)->name = 'count';
                $changed = true;
            } else {
                foreach ($placeholders as $placeholder) {
                    foreach (explode('|', $placeholder->name) as $pipe) {
                        $parts = explode(':', mb_trim($pipe));
                        if (mb_trim($parts[0]) == 'i18n' && isset($parts[1])) {
                            $placeholder->name = trim(mb_trim($parts[1]), '"\'');
                            $changed = true;
                        }
                    }
                }
            }
            if ($changed) {
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
                // Denormalise the id
                $translation = str_replace('{count,', "{{$pattern->nodes[0]->name},", $translation);

                // Replace # with an Angular expression that formats the amount
                if ($fixHashPluralSupport && str_contains($string->fullyQualifiedID(), '#')) {
                    $translation = str_replace('#', "{{ {$pattern->nodes[0]->name} | number }}", $translation);
                }

                // Remove the i18n pipe
                $translation = preg_replace('/\|\s+i18n\s*:\s*[\'"]\w+[\'"]\s*/', '', $translation);
            }
        }

        if ($context == 'template') { // The @ character is special in templates
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

    /** @inheritDoc */
    protected function _prepareAnnotatedString(LString $string, ?string $path): void
    {
        Angular::prepareString($string, $path);
    }

    /** @inheritDoc */
    protected function _annotateText(string $text, LString $string, string $id): string
    {
        // Angular moves an ICU that shares the message with other content to a message of its own, whose id only
        // depends on its text and placeholder names, so equal ICUs of different strings would share one translation.
        // The placeholders of strings with an ICU carry the id of the string to tell them apart
        $suffix = $string->isICU ? Angular::placeholderSuffix($id) : '';

        // Name the placeholders: {{ expr | i18n:'%x%' }} → {{ expr // i18n(ph="I18N_X") }}
        $text = preg_replace_callback(
            '/{{([^{].*?)}}/us',
            fn($match) => '{{' . self::_namePlaceholder($match[1], '"', $suffix) . '}}',
            $text
        );

        // The i18n pipe is not allowed in the ICU expression either: {count | i18n:'%count%', plural, ...}
        $text = preg_replace('/\|\s*i18n\s*:\s*([\'"])[^\'"]*\1\s*(?=,\s*(plural|select|selectordinal)\s*,)/u', '', $text);

        // Angular does not support # in ICU plurals: write the interpolation it stands for, as translate() does
        $hashVariable = $this->_bareHashVariable($string);
        if ($hashVariable !== null) {
            $parts = preg_split('/({{.*?}})/us', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($parts as $i => $part) {
                if ($i % 2 == 0) {
                    $parts[$i] = preg_replace(
                        '/&#x?[0-9a-f]+;(*SKIP)(*FAIL)|#/i',
                        "{{ {$hashVariable} | number // i18n(ph=\"" . Angular::placeholderName('#') . $suffix . '") }}',
                        $part
                    );
                }
            }
            $text = implode('', $parts);
        }

        // An ICU without placeholders would still share its message: an empty one in its first branch carries the id
        if ($suffix !== '' && $this->_hasIcuWithoutPlaceholders($string)) {
            $text = preg_replace(
                '/(?<!\{)\{(?!\{)\s*[^{}]+?\s*,\s*(?:plural|select|selectordinal)\s*,[^{}]*?\{/u',
                "\$0{{ '' // i18n(ph=\"" . Angular::ICU_ID_PLACEHOLDER . $suffix . '") }}',
                $text
            );
        }

        return $text;
    }

    /**
     * Whether the string has an ICU expression with no placeholder in its branches, sharing the message with other
     * content (Angular gives a lone ICU expression the id of the string)
     */
    private function _hasIcuWithoutPlaceholders(LString $string): bool
    {
        try {
            $pattern = new Pattern($string->text);
        } catch (Exception) {
            return false;
        }

        if (count($pattern->nodes) == 1 && $pattern->nodes[0] instanceof Placeholder) {
            return false;
        }

        $placeholders = array_map('strval', array_keys($string->placeholders ?? []));
        foreach ($pattern->nodes as $node) {
            if ($node instanceof Placeholder && is_array($node->content)) {
                $icu = $node->render(false);
                $hasPlaceholders = array_any($placeholders, fn(string $name) => str_contains($icu, $name))
                    || ($node->type == 'plural' && str_contains($icu, '#') && $this->_bareHashVariable($string) !== null);
                if (!$hasPlaceholders) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @inheritDoc */
    protected function _annotateAttributeValue(string $value, string $enclosing, bool $translatable = true): string
    {
        if (!str_contains($value, 'i18n')) {
            return $value;
        }

        if ($translatable) {
            return preg_replace_callback(
                '/{{([^{].*?)}}/us',
                fn($match) => '{{' . self::_namePlaceholder($match[1], $enclosing == '"' ? "'" : '"') . '}}',
                $value
            );
        }

        // The attributes of the elements inside a translatable one are not translated: just drop the pipe
        return preg_replace('/\|\s*i18n\s*:\s*([\'"])[^\'"]*\1/u', '', $value);
    }

    /**
     * Replaces the i18n pipe of an interpolated expression with the comment that names its Angular placeholder
     */
    private static function _namePlaceholder(string $expression, string $quote, string $suffix = ''): string
    {
        if (!preg_match('/\|\s*i18n\s*:\s*([\'"])(.*?)\1\s*/u', $expression, $pipe)) {
            return $expression;
        }

        $expression = mb_rtrim(str_replace($pipe[0], '', $expression));
        $name = Angular::placeholderName($pipe[2]) . $suffix;

        return "{$expression} // i18n(ph={$quote}{$name}{$quote}) ";
    }

    /**
     * Expression of the plural whose # translate() would replace with the formatted number, or null when the string
     * has no bare # to replace
     */
    private function _bareHashVariable(LString $string): ?string
    {
        if (!$this->addHashPluralSupport || !$string->isICU || isset($string->placeholders['#']) || !str_contains($string->fullyQualifiedID(), '#')) {
            return null;
        }

        try {
            $pattern = new Pattern($string->text);
        } catch (Exception) {
            return null;
        }

        $first = $pattern->nodes[0] ?? null;
        if (!$first instanceof Placeholder || $first->type != 'plural') {
            return null;
        }

        return mb_trim(preg_replace('/\|\s*i18n\s*:.*$/u', '', $first->name));
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

        // Strip the code comments
        $code = preg_replace('/\/\/.*|\/\*[\s\S]*?\*\//', '', $code);

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

                $parsed = self::parseMetadata($metadata);
                $string->context = $parsed['meaning'];
                $string->comments = $parsed['description'];
                if ($parsed['customId'] !== null) {
                    $string->id = $parsed['customId'];
                }
                if ($parsed['domain'] !== null) {
                    $string->domainName = $parsed['domain'];
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


    /**
     * Parses the metadata block of a $localize string (`:meaning|description@@customId:`) with the same rules as
     * Angular's parseMetadata() (@angular/localize): legacy ids (␟) are removed first, then the block is split on `@@`
     * and then on `|`. Without `|` the whole block is the description, never the meaning.
     *
     * The meaning is the l10n context. On top of Angular's syntax, a `##domain` suffix in the description selects the
     * l10n domain (`:##domain:`, `:|##domain:`, `:meaning|description##domain:`).
     *
     * @return array{meaning: ?string, description: ?string, customId: ?string, domain: ?string}
     */
    public static function parseMetadata(string $block): array
    {
        // Same as JS String.prototype.split(sep, 2): anything after a second separator is dropped
        $split = static function (string $value, string $separator): array {
            $parts = explode($separator, $value, 3);
            return [$parts[0], $parts[1] ?? null];
        };

        $meaningDescAndId = explode("\u{241F}", $block)[0];
        [$meaningAndDesc, $customId] = $split($meaningDescAndId, '@@');
        [$meaning, $description] = $split($meaningAndDesc, '|');

        if ($description === null) {
            $description = $meaning;
            $meaning = null;
        }

        $domain = null;
        if (str_contains($description, '##')) {
            [$description, $domain] = explode('##', $description, 2);
        }

        return [
            'meaning' => $meaning !== '' ? $meaning : null,
            'description' => $description !== '' ? $description : null,
            'customId' => $customId !== '' ? $customId : null,
            'domain' => $domain !== '' ? $domain : null,
        ];
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
