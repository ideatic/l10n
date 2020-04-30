<?php

/**
 * Por ahora no se utiliza el método XLIFF
 * @deprecated
 */
class NGi18n
{
    public $translationsNotFound = 0;

    public function execute(string $path, string $destiny, array $locales)
    {
        ini_set('memory_limit', -1);

        // Parser test
        /*  $path = IO::combinePaths($path, 'src', 'locales', 'messages.xlf');
          echo "Parsing...\n";
          $doc = HTML_Parser::parse(IO::read($path), true, []);

          echo "Writing...\n";
          $dest = IO::combinePaths($destiny, "en.xlf");
          IO::write($dest, $doc->render());
          return;*/

        echo "Generating Xliff ...";
        $start = microtime(true);

        $xliff = $this->_generateXLIFF($path);

        echo Time::formatUInterval(microtime(true) - $start, 1, 'en') . "\n";

        // Parsear documento
        echo "Parsing... ";
        $start = microtime(true);

        $xliff = $this->_parseXLIFF($xliff);

        echo Time::formatUInterval(microtime(true) - $start, 1, 'en') . "\n";

        // Generar archivos para cada idioma
        foreach ($locales as $locale) {
            $start = microtime(true);
            echo "Generating " . i18n::formatLanguage($locale) . "...";

            $this->_generateLocale($locale, $xliff, IO::combinePaths($destiny, "{$locale}.xlf"));

            echo Time::formatUInterval(microtime(true) - $start, 1, 'en') . "\n";
        }
    }

    private function _generateXLIFF(string $basePath): string
    {
        $xliffFile = IO::combinePaths($basePath, 'src', 'locales', 'messages.xlf');

        if (IO::exists($xliffFile)) {
            return IO::read($xliffFile);
        }

        // Generar fichero
        $previousWD = getcwd();
        chdir($basePath);

        $command = "node --max-old-space-size=4096 ./node_modules/@angular/cli/bin/ng " . implode(
                ' ',
                [
                    'xi18n ',
                    '--output-path locales',
                    '--out-file messages.xlf'
                ]
            );

        chdir($previousWD);

        echo "{$command}\n";
        passthru($command);

        // Leer contenido
        $content = IO::exists($xliffFile) ? IO::read($xliffFile) : null;

        if (!$content) {
            throw new Exception("Unable to read generated XLIFF file at {$xliffFile}");
        }

        // IO::delete($xliffFile);

        return $content;
    }

    private function _parseXLIFF(string $xliff): HTML_Parser_Document
    {
        return HTML_Parser::parse($xliff, true, []);
    }

    private function _generateLocale(string $locale, HTML_Parser_Document $xliff, string $file)
    {
        // Definir locales utilizados ordenados por prioridad
        $locales = i18n_Manager::localeVariants($locale);
        if (i18n_Languages::fallbackLocale($locale)) {
            $locales[] = i18n_Languages::fallbackLocale($locale);
        }
        $locales[] = 'en';

        // Procesar archivo de entrada
        $localized = clone $xliff;

        $localized->walk(
            function ($element) use ($locale, $locales) {
                if (!($element instanceof HTML_Parser_Element)) {
                    return;
                } elseif (strtolower($element->tag) != 'source') {
                    return;
                }

                $string = $this->_parseString($element);

                // Traducir cadena
                $translation = $originalTranslation = $string->getTranslation($locales);

                // Restaurar placeholders a su estado original
                $translation = strtr($translation, $string->placeholders);

                // Incluir traducción en el archivo XLIFF
                $translationElement = new HTML_Parser_Element();
                $translationElement->tag = 'target';
                $translationElement->children = [new HTML_Parser_Text($translation)];
                $element->appendSibling([new HTML_Parser_Text(PHP_EOL), $translationElement]);

                // Comprobar traducción
                if (app::$i18n->translator->lastTranslationLocale() != $locale) {
                    if ($locale == 'en') {
                        echo "Translation not found: '{$string->id}'\n";
                    }

                    $this->translationsNotFound++;
                }
            }
        );

        IO::write($file, $localized->render());
    }

    private function _parseString(HTML_Parser_Element $element): i18n_LocalizableText_String
    {
        $string = new i18n_LocalizableText_String();
        $string->section = 'app';

        // Restaurar placeholders a su valor original
        $string->id = $this->_convertPlaceholders($element, $placeholders);

        // Normalizar espacios
        $string->id = preg_replace('/\s+/', ' ', Str::trim($string->id));

        // Obtener contexto y comentarios
        foreach ($element->parent->findAll('note') as $note) {
            $fromAttr = $note->hasAttribute('from');
            if ($fromAttr && $fromAttr->value == 'meaning') {
                $string->context = $note->innerText();
            }

            if ($fromAttr && $fromAttr->value == 'description') {
                $string->comment = $note->innerText();
            }
        }

        $string->placeholders = $placeholders;

        return $string;
    }

    private function _convertPlaceholders(HTML_Parser_Element $element, &$placeholders = []): string
    {
        $innerText = [];
        $placeholders = [];
        foreach ($element->children as $child) {
            if ($child instanceof HTML_Parser_Text) {
                $innerText[] = $this->_decodeHtmlEntities($child->render());
            } elseif ($child instanceof HTML_Parser_Element && $child->tag == 'x') {
                $replacement = $this->_decodeHtmlEntities($child->hasAttribute('equiv-text')->value);

                if (Str::startsWith($replacement, '{{')) { // Buscar nombre del placeholder (pipe i18n)
                    $replacement = $this->_parseNgExpression($replacement, $element->innerText());
                }

                $placeholders[$replacement] = $child->render();
                $innerText[] = $replacement;
            }
        }
        return implode('', $innerText);
    }

    private function _decodeHtmlEntities(string $str)
    {
        return str_replace('&apos;', "'", HTML_Tools::entityDecode($str));
    }

    private function _parseNgExpression(string $expression, $string): string
    {
        if (!Str::startsWith($expression, '{{') || !Str::endsWith($expression, '}}')) {
            throw new Exception("Invalid angular expression '{$expression}'");
        }

        foreach (explode('|', substr($expression, 2, -2)) as $pipe) {
            $parts = explode(':', Str::trim($pipe));
            if (Str::trim($parts[0]) == 'i18n' && isset($parts[1])) {
                return trim(Str::trim($parts[1]), '"\'');
            }
        }

        throw new Exception("No i18n placeholder found in expression '{$expression}' in '{$string}'");
    }
}
