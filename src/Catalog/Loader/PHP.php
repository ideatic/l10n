<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Loader;

use ideatic\l10n\Catalog\Catalog;

class PHP extends ArrayLoader
{
  /** @inheritDoc */
  public function load(string $content, string $locale): Catalog
  {
    if (preg_match('/^<\?php\s+declare\s*\(\s*strict_types\s*=\s*1\)\s*;/i', $content, $match)) {
      $content = substr($content, strlen($match[0]));
      return $this->_parse(eval($content), $locale);
    } else {
      return $this->_parse(eval("?> {$content}"), $locale);
    }
  }
}

