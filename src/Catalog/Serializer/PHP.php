<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Serializer;

use ideatic\l10n\LString;
use ideatic\l10n\Project;
use stdClass;

class PHP extends ArraySerializer
{
  /** @inheritDoc */
  public function generate(array $domains, stdClass|Project $config = null): string
  {
    $phpArray = ['['];
    foreach ($domains as $domain) {
      foreach ($domain->strings as $strings) {
        $string = reset($strings);

        $translation = $this->locale
            ? $domain->translator->getTranslation($string, $this->locale, false)
            : null;

        $comments = array_filter($strings, fn(LString $s) => $s->comments);
        if (!empty($comments)) {
          $comments = implode(PHP_EOL, array_map(fn(LString $string) => $string->comments, $comments));
          $phpArray[] = '  ' . var_export($string->fullyQualifiedID(), true) . ' /* ' . $comments . ' */ => ' . var_export($translation, true) . ',';
        } else {
          $phpArray[] = '  ' . var_export($string->fullyQualifiedID(), true) . ' => ' . var_export($translation, true) . ',';
        }
      }
    }
    $phpArray [] = ']';
    $phpArray = implode(PHP_EOL, $phpArray);

    $php = ['<?php'];
    $comments = $this->comments ?? 'Created ' . date('r');
    $php[] = "/* {$comments} */";
    $php[] = '';
    $php[] = "return {$phpArray};";

    return implode(PHP_EOL, $php);
  }
}

