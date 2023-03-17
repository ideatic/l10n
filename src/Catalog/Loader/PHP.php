<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Loader;

use ideatic\l10n\Catalog\Catalog;

class PHP extends ArrayLoader
{
    /** @inheritDoc */
    public function load(string $content, string $locale): Catalog
    {
        return $this->_parse(eval("?> {$content}"), $locale);
    }
}

