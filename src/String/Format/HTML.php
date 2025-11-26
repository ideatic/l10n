<?php

declare(strict_types=1);

namespace ideatic\l10n\String\Format;

use Exception;
use HTML_Parser;
use HTML_Parser_Attribute;
use HTML_Parser_Comment;
use HTML_Parser_Element;
use HTML_Parser_Text;
use ideatic\l10n\LString;
use ideatic\l10n\Plural\ICU;
use ideatic\l10n\Utils\ICU\Pattern;

/**
 * Proveedor de cadenas traducibles encontradas en código Javascript / TypeScript
 */
class HTML extends Format
{
    public bool $autoDetectIcuPatterns = true;
    public bool $normalizeWhitespaces = true;
    private array $_foundStrings;

    /** @inheritDoc */
    public function getStrings(string $html, mixed $path = null): array
    {
        $this->_foundStrings = [];

        $this->_process($html, $path);

        $found = $this->_foundStrings;
        unset($this->_foundStrings);
        return $found;
    }

    private function _process(string $html, ?string $path = null, ?callable $getTranslation = null): string|array
    {
        $dom = HTML_Parser::parse($html);

        foreach ($dom->children as $node) {
            if ($node instanceof HTML_Parser_Element) {
                $this->_processNode($node, $html, $path, $getTranslation);
            }
        }

        // Eliminar atributos noI18n
        if ($getTranslation) {
            $dom->walk(
                function ($node) {
                    if ($node instanceof HTML_Parser_Element && $node->hasAttribute('noI18n')) {
                        $node->hasAttribute('noI18n')->remove();
                    }
                }
            );
        }

        return $getTranslation ? $dom->render() : [];
    }

    private function _processNode(HTML_Parser_Element $element, string $source, ?string $path, ?callable $getTranslation = null): void
    {
        // Traducción de atributos
        $attrPrefix = 'i18n-';
        foreach ($element->attributes as $i18nAttribute) {
            if (!str_starts_with(strtolower($i18nAttribute->name), $attrPrefix)) {
                continue;
            }

            $attributeName = substr($i18nAttribute->name, strlen($attrPrefix));
            $attribute = $element->hasAttribute($attributeName);

            if (!$attribute) {
                throw new Exception("i18n attribute {$attributeName} not found in {$path}: " . $element->render());
            }

            $string = $this->_registerString($source, $path, HTML_Parser::entityDecode($attribute->value), $i18nAttribute, $attribute);

            if ($getTranslation) {
                $translation = call_user_func($getTranslation, $string);
                if ($translation !== null) {
                    $i18nAttribute->remove();
                    $attribute->value = $translation;
                }
            }
        }

        // El contenido del elemento es traducible
        $i18nAttribute = $element->hasAttribute('i18n');
        if ($i18nAttribute) {
            // Procesar nodos hijo
            $element->walk(
                function ($child) use ($source, $path, $getTranslation) {
                    if ($child instanceof HTML_Parser_Comment) { // Eliminar comentarios
                        $child->remove();
                    } elseif ($child instanceof HTML_Parser_Element) { // Traducir hijos
                        self::_processNode($child, $source, $path, $getTranslation);
                    }
                }
            );

            // Procesar atributos i18nPlaceholder de los elementos hijo
            $placeholders = $this->_processChildPlaceholders($element);

            // Normalizar espacios en blanco
            switch ($element->hasAttribute('i18nWhitespaces')?->value ?? ($this->normalizeWhitespaces ? 'normalize' : 'keep')) {
                case 'keep':
                    $content = $element->innerHTML();
                    break;

                case 'normalize':
                    $content = preg_replace('/\s+/', ' ', mb_trim($element->innerHTML()));
                    break;
                case 'trim':

                    $content = mb_trim($element->innerHTML());
                    break;

                case 'unindent':
                    // Encontrar y eliminar la indentación común
                    $content = $element->innerHTML();
                    preg_match_all('/^[ \t]*(?=\S)/m', $content, $matches);
                    $minIndent = empty(array_filter($matches[0])) ? 0 : min(array_map(mb_strlen(...), array_filter($matches[0])));
                    $content = mb_trim(preg_replace('/^[ \t]{' . $minIndent . '}/m', '', $content));
                    break;

                default:
                    throw new Exception("Invalid i18nWhitespaces value in {$path}: " . $element->render());
            }

            $content = HTML_Parser::entityDecode($content);

            $string = $this->_registerString($source, $path, $content, $i18nAttribute, $element);
            $string->placeholders = $placeholders;

            if ($getTranslation) {
                $translation = call_user_func($getTranslation, $string);

                if ($translation !== null) {
                    // Eliminar atributo i18n
                    $i18nAttribute->remove();
                    $element->hasAttribute('i18nWhitespaces')?->remove();

                    // Reemplazar antiguo contenido con el nuevo
                    try {
                        $newContent = HTML_Parser::parse($translation, true)->children;
                    } catch (Exception $err) {
                        throw new Exception('Unable to parse HTML: ' . $err->getMessage() . ' at ' . $path . ' for ' . $translation);
                    }

                    if (strtolower($element->tag) == 'ng-container' && empty($element->attributes)) { // Eliminar ng-container utilizados solo para i18n
                        // Eliminar espacios en blanco antes y después
                        while ($element->nextSibling() instanceof HTML_Parser_Text && !mb_trim($element->nextSibling()->render())) {
                            $element->nextSibling()->remove();
                        }
                        while ($element->previousSibling() instanceof HTML_Parser_Text && !mb_trim($element->previousSibling()->render())) {
                            $element->previousSibling()->remove();
                        }

                        $element->replaceWith(count($newContent) == 1 ? reset($newContent) : $newContent);
                    } else {
                        $element->children = $newContent;
                    }
                }
            }
        } elseif (!$getTranslation) { // Comprobar si el nodo solo tiene texto y no está marcado como traducible
            if ($this->_checkIfMissingI18nAttribute($element)) {
                echo "Suspicious missing i18n attribute at {$path}: {$element->render()}\n";
            }
        }

        // Comprobar que no se use un atributo i18n donde no pertenezca
        if ($element->hasAttribute('i18nPlaceholder') || $element->hasAttribute('i18nContent')) {
            $parent = $element->parent;

            $parentI18nFound = false;
            while ($parent instanceof HTML_Parser_Element) {
                if ($parent->hasAttribute('i18n')) {
                    $parentI18nFound = true;
                    break;
                }

                $parent = $parent->parent;
            }

            if (!$parentI18nFound) {
                throw new Exception("i18n special attribute with no i18n parent found in element in '{$path}': {$element->render()}");
            }
        }

        // Procesar hijos
        foreach ($element->children as $child) {
            if ($child instanceof HTML_Parser_Element) {
                self::_processNode($child, $source, $path, $getTranslation);
            }
        }
    }

    private function _registerString(
        string $source,
        ?string $path,
        string $text,
        HTML_Parser_Attribute $i18nAttribute,
        HTML_Parser_Attribute|HTML_Parser_Element $container
    ): LString {
        $string = new LString();
        $string->text = $string->id = str_replace('&ngsp;', ' ', $text);
        $string->file = $path;
        $string->offset = $container->offset;
        $string->line = $string->offset == 0 || !$source ? 0 : substr_count($source, "\n", 0, $string->offset) + 1;
        $string->domainName = $this->defaultDomain;
        $string->comments = $i18nAttribute->value;

        // https://angular.io/guide/i18n#help-the-translator-with-a-description-and-meaning
        if ($string->comments) {
            if (str_contains($string->comments, '|')) {
                [$string->context, $string->comments] = explode('|', $string->comments, 2);
            }
            if (str_contains($string->comments, '@@')) {
                [$string->comments, $string->id] = explode('@@', $string->comments, 2);
            }
            if (str_contains($string->comments, '##')) {
                [$string->comments, $string->domainName] = explode('##', $string->comments, 2);
            }
        }

        if ($this->autoDetectIcuPatterns) {
            $pattern = new Pattern($string->text);
            if ($pattern->hasPlaceholders()) {
                $string->isICU = true;
            }
        }

        $string->raw = $i18nAttribute;

        $this->_foundStrings[] = $string;

        return $string;
    }

    private function _processChildPlaceholders(HTML_Parser_Element $element): array
    {
        $placeholders = [];

        foreach ($element->children as $child) {
            if (!($child instanceof HTML_Parser_Element)) {
                continue;
            }

            $i18nPlaceholderAttr = $child->hasAttribute('i18nPlaceholder');
            $i18nContentAttr = $child->hasAttribute('i18nContent');
            $i18nContentIgnoreAttr = $child->hasAttribute('i18nContentIgnore');

            $placeholders += $this->_processChildPlaceholders($child);

            if ($i18nPlaceholderAttr) { // Reemplazar elemento completo por su placeholder
                $i18nPlaceholderAttr->remove();

                if ($i18nContentAttr) {
                    $i18nContentAttr->remove();
                    $i18nContentIgnoreAttr?->remove();

                    // Solo incluir el comienzo del elemento padre
                    $copy = clone $child;
                    $copy->children = [];
                    $copy->autoClosed = true;
                    $placeholders[$i18nPlaceholderAttr->value] = substr($copy->render(), 0, -2) . '>';

                    if ($i18nPlaceholderAttr->value[0] == '<') {
                        $realTag = $child->tag;
                        $child->tag = substr($i18nPlaceholderAttr->value, 1, -1);
                        $placeholders["</{$child->tag}>"] = "</{$realTag}>";
                    }

                    // Ignorar ciertos elementos de los hijos e incluirlos en el placeholder
                    foreach ($child->children as $subChild) {
                        if ($subChild instanceof HTML_Parser_Element && $subChild->hasAttribute('i18nIgnore')) {
                            $subChild->removeAttribute('i18nIgnore');
                            $placeholders[$i18nPlaceholderAttr->value] .= $subChild->render();
                            $subChild->remove();
                        }
                    }

                    // Eliminar el resto de atributos para no procesarlos
                    foreach ($child->attributes as $attr) {
                        $attr->remove();
                    }
                } else {
                    $child->replaceWith(new HTML_Parser_Text($i18nPlaceholderAttr->value));
                    $placeholders[$i18nPlaceholderAttr->value] = $child->render();
                }
            }
        }

        return $placeholders;
    }

    private function _checkIfMissingI18nAttribute(HTML_Parser_Element $element): bool
    {
        $suspicious = !in_array($element->tag, ['script', 'style', 'noscript', 'mat-icon']);

        foreach ($element->children as $child) {
            if ($child instanceof HTML_Parser_Element) {
                $suspicious = false;
                break;
            }
        }

        if ($suspicious && $element->hasAttribute('noI18n')) {
            $suspicious = false;
        }

        if ($suspicious) {
            $element->walkParents(
                function ($parent) use (&$suspicious) {
                    if ($parent instanceof HTML_Parser_Element && ($parent->hasAttribute('i18n') || $parent->hasAttribute('noI18n'))) {
                        $suspicious = false;
                    }
                }
            );
        }

        if ($suspicious) {
            $innerText = HTML_Parser::entityDecode($element->innerText());
            $innerText = str_replace('&ngsp;', ' ', $innerText);
            $innerText = mb_trim($innerText);
            $innerText = trim($innerText, '()=');
            $innerText = str_replace('≈', '', $innerText);
            $innerText = mb_trim($innerText);

            if (!$innerText) { // Sin texto
                $suspicious = false;
            } elseif (str_starts_with($innerText, '{{')
                || str_starts_with($innerText, '@if')
                || str_starts_with($innerText, '@switch')
                || str_starts_with($innerText, '@for')
                || str_starts_with($innerText, '${')) { // Expresión angular
                $suspicious = false;
            } elseif (!preg_match('/[a-zA-Z]/', $innerText)) { // Sin texto
                $suspicious = false;
            } elseif (preg_match('/^&([a-z\d]+|#\d+|#x[a-f\d]+);$/', $innerText)) { // Entidad HTML
                $suspicious = false;
            }
        }


        return $suspicious;
    }

    /** @inheritDoc */
    public function translate(string $content, callable $getTranslation, $context = null): string
    {
        return $this->_process($content, $context, $getTranslation);
    }
}
