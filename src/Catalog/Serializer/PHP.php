<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Serializer;

use ideatic\l10n\Project;

class PHP extends ArraySerializer
{
  /** @inheritDoc */
  public function generate(array $domains, \stdClass|Project $config = null): string
  {
    // $phpArray = var_export($this->_generate($domains), true);
    $phpArray = ['['];
    foreach ($domains as $domain) {
      foreach ($domain->strings as $strings) {
        $string = reset($strings);

        $stringID = $string->fullyQualifiedID();
        if ($this->locale) {
          $translation = $domain->translator->getTranslation($string, $this->locale, false);

          if (isset($translation)) {
            if ($string->comments) {
              $phpArray[] = '  ' . var_export($stringID, true) . ' /* ' . $string->comments . ' */ => ' . var_export($translation, true) . ',';
            } else {
              $phpArray[] = '  ' . var_export($stringID, true) . ' => ' . var_export($translation, true) . ',';
            }
          }
        } else {
          $phpArray[] = '  ' . var_export($stringID, true) . ' => ' . var_export(null, true) . ',';
        }
      }
    }
    $phpArray [] = ']';
    $phpArray = implode(PHP_EOL, $phpArray);

    $php = ['<?php'];
    if (!$this->comments) {
      $this->comments = 'Created ' . date('r');
    }
    $php[] = "/* {$this->comments} */";
    $php[] = '';
    $php[] = "return {$phpArray};";

    return implode(PHP_EOL, $php);
  }
}

