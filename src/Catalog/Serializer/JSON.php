<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Serializer;

use ideatic\l10n\Project;

class JSON extends ArraySerializer
{
    /** @inheritDoc */
    public function generate(array $domains, \stdClass|Project $config = null): string
    {
        return json_encode($this->_generate($domains), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}


