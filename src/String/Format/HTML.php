<?php

namespace ideatic\l10n\String\Format;

use HTML_Parser;
use HTML_Parser_Attribute;
use HTML_Parser_Comment;
use HTML_Parser_Element;
use HTML_Parser_Text;
use ideatic\l10n\LString;
use ideatic\l10n\Plural\ICU;
use ideatic\l10n\Utils\Str;

/**
 * Proveedor de cadenas traducibles encontradas en código Javascript / TypeScript
 */
class HTML extends Format
{
    public $autoDetectIcuPatterns = true;
    private $_foundStrings;

    /**
     * @inheritDoc
     */
    public function getStrings(string $html, $path = null): array
    {
        $this->_foundStrings = [];

        $this->_process($html, $path);

        $found = $this->_foundStrings;
        unset($this->_foundStrings);
        return $found;
    }

    private function _process(string $html, ?string $path = null, ?callable $getTranslation = null)
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

    private function _processNode(HTML_Parser_Element $element, string $source, ?string $path, ?callable $getTranslation = null)
    {
        // Traducción de atributos
        $attrPrefix = 'i18n-';
        foreach ($element->attributes as $i18nAttribute) {
            if (!Str::startsWith($i18nAttribute->name, $attrPrefix, true)) {
                continue;
            }

            $attributeName = substr($i18nAttribute->name, strlen($attrPrefix));
            $attribute = $element->hasAttribute($attributeName);

            if (!$attribute) {
                throw new \Exception("i18n attribute {$attributeName} not found in {$path}: " . $element->render());
            }
            //var_dump(HTML_Tools::entityDecode($attribute->value));
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
            $content = preg_replace('/\s+/', ' ', Str::trim($element->innerHTML()));

            $string = $this->_registerString($source, $path, $content, $i18nAttribute, $element);
            $string->placeholders = $placeholders;

            if ($getTranslation) {
                $translation = call_user_func($getTranslation, $string);

                if ($translation !== null) {
                    // Eliminar atributo i18n
                    $i18nAttribute->remove();


                    // Reemplazar antiguo contenido con el nuevo
                    try {
                        $newContent = HTML_Parser::parse($translation, true)->children;
                    } catch (\Exception $err) {
                        throw new \Exception('Unable to parse HTML: ' . $err->getMessage() . ' at ' . $path . ' for ' . $translation);
                    }

                    if (strtolower($element->tag) == 'ng-container' && empty($element->attributes)) { // Eliminar ng-container utilizados solo para i18n
                        // Eliminar espacios en blanco antes y después
                        while ($element->nextSibling() instanceof HTML_Parser_Text && !Str::trim($element->nextSibling()->render())) {
                            $element->nextSibling()->remove();
                        }
                        while ($element->previousSibling() instanceof HTML_Parser_Text && !Str::trim($element->previousSibling()->render())) {
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
                throw new \Exception("Invalid i18n attribute found in element in '{$path}'", $element->render());
            }
        }

        // Procesar hijos
        foreach ($element->children as $child) {
            if ($child instanceof HTML_Parser_Element) {
                self::_processNode($child, $source, $path, $getTranslation);
            }
        }
    }

    private function _registerString(string $source, ?string $path, string $text, HTML_Parser_Attribute $i18nAttribute, $container): LString
    {
        $string = new LString();
        $string->text = $string->id = str_replace('&ngsp;', ' ', $text);
        $string->file = $path;
        $string->offset = $container->offset;
        $string->line = $string->offset == 0 || !$source ? 0 : substr_count($source, "\n", 0, $string->offset) + 1;
        $string->domainName = $this->defaultDomain;
        $string->comments = $i18nAttribute->value;

        // https://angular.io/guide/i18n#help-the-translator-with-a-description-and-meaning
        if (strpos($string->comments, '|') !== false) {
            [$string->context, $string->comments] = explode('|', $string->comments, 2);
        }
        if (strpos($string->comments, '@@') !== false) {
            [$string->comments, $string->id] = explode('@@', $string->comments, 2);
        }
        if (strpos($string->comments, '##') !== false) {
            [$string->comments, $string->domainName] = explode('##', $string->comments, 2);
        }

        if ($this->autoDetectIcuPatterns) {
            $pattern = new \ideatic\l10n\Utils\ICU\Pattern($string->text);
            if ($pattern->hasPlaceholders()) {
                $string->isICU = true;
            }
        }

        $string->raw = $i18nAttribute;

        $this->_foundStrings[] = $string;

        return $string;
    }

    private function _processChildPlaceholders(HTML_Parser_Element $element)
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
                    if ($i18nContentIgnoreAttr) { // Hay que eliminarlo antes de establecer el placeholder
                        $i18nContentIgnoreAttr->remove();
                    }

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
            $innerText = Str::trim($innerText);
            $innerText = trim($innerText, '()=');
            $innerText = str_replace('≈', '', $innerText);
            $innerText = Str::trim($innerText);

            if (!$innerText) { // Sin texto
                $suspicious = false;
            } elseif (Str::startsWith($innerText, '{{')) { // Expresión angular
                $suspicious = false;
            } elseif (!preg_match('/[a-zA-Z]/', $innerText)) { // Sin texto
                $suspicious = false;
            }
        }


        return $suspicious;
    }

    /**
     * @inheritDoc
     */
    public function translate(string $content, callable $getTranslation, $context = null): string
    {
        return $this->_process($content, $context, $getTranslation);
    }

    private function _parseAngularExpression(LString $string, ?string $path = null)
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

                throw new \Exception("No i18n placeholder found in expression '{$expr}' at '{$path}'", $string);
            },
            $string->text
        );

        if ($string->id == $string->text) {
            $string->id = $parsed;
        }

        $string->text = $parsed;
    }
}