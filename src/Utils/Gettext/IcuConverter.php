<?php


namespace ideatic\l10n\Utils\Gettext;

use ideatic\l10n\Utils\ICU\Pattern;

class IcuConverter
{

    /**
     * Convierte un plural formato Gettext a formato ICU
     */
    public static function getTextPluralToICU(Pattern $baseIcuPattern, array $getTextPluralForms, string $pluralRulesExpression): Pattern
    {
        // Obtener regla para formar el plural Gettext
        $pluralRules = new PluralRule($pluralRulesExpression);

        // http://cldr.unicode.org/index/cldr-spec/plural-rules#TOC-Choosing-Plural-Category-Names
        // https://unicode-org.github.io/cldr-staging/charts/37/supplemental/language_plural_rules.html
        /*If no forms change, then stop (there are no plural rules — everything gets 'other')
'one': Use the category 'one' for the form used with 1.
'other': Use the category 'other' for the form used with the most integers.
'two': Use the category 'two' for the form used with 2, if it is limited to numbers whose integer values end with '2'.
'zero': Use the category 'zero' for the form used with 0, if it is limited to numbers whose integer values end with '0'.
'few': Use the category 'few' for the form used with the least remaining number (such as '4')
'many': Use the category 'many' for the form used with the least remaining number (such as '10')
If there needs to be a category for items only have fractional values, use 'many'
If there are more categories needed for the language, describe what those categories need to cover in the bug report.*/

        $icuForms = [];
        if (count($getTextPluralForms) == 1) {
            $icuForms['other'] = new Pattern(reset($getTextPluralForms));
        } else {
            // Obtener una muestra de los números que se utilizan en cada forma plural, y ordenarlos por frecuencia
            $validNumbers = [];
            foreach ($getTextPluralForms as $index => $pluralForm) {
                // Encontrar qué números utilizan esta forma plural
                $validNumbers[$index] = [];
                for ($i = 0; $i < 1000; $i++) {
                    if ($pluralRules->get($i) == $index) {
                        $validNumbers[$index][] = $i;
                    }
                }
            }

            uasort(
                $validNumbers,
                function ($arr) {
                    return count($arr);
                }
            );
            $mostUsedForms = array_keys($validNumbers);


            // Asignar a cada forma plural su categoría ICU
            foreach ($getTextPluralForms as $index => $pluralForm) {
                $validFormNumbers = $validNumbers[$index];

                $categoryName = null;
                if (count($validFormNumbers) == 1 && reset($validFormNumbers) == 1) {
                    $categoryName = 'one';
                } elseif (count($validFormNumbers) == 1 && reset($validFormNumbers) == 2) {
                    $categoryName = 'two';
                } elseif (count($validFormNumbers) == 1 && reset($validFormNumbers) == 0) {
                    $categoryName = 'zero';
                } elseif ($index == $mostUsedForms[0]) {
                    $categoryName = 'other';
                } elseif ($index == $mostUsedForms[1]) {
                    $categoryName = 'many';
                } elseif ($index == $mostUsedForms[2]) {
                    $categoryName = 'few';
                }

                if (!$categoryName) {
                    throw new \Exception("Unable to find ICU plural category name for Gettext rule:\n\t{$pluralRulesExpression}\n\tfor n={$index}\nNo category found");
                } elseif (isset($icuForms[$categoryName])) {
                    throw new \Exception(
                        "Unable to find ICU plural category name for Gettext rule:\n\t{$pluralRulesExpression}\n\tfor n={$index}\n"
                        . "Collision found for category '{$categoryName}'.\n"
                        /** @phpstan-ignore-next-line */
                        . "Valid numbers in this rule: " . implode(', ', array_slice($validNumbers, 0, 5))
                    );
                }

                $icuForms[$categoryName] = new Pattern($pluralForm);
            }
        }

        $translatedPatter = clone $baseIcuPattern;
        $translatedPatter->nodes[0]->content = $icuForms;

        return $translatedPatter;
    }
}