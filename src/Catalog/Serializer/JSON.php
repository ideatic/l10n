<?php

namespace ideatic\l10n\Catalog\Serializer;

class JSON extends ArraySerializer
{
    /** @inheritDoc */
    public function generate(array $domains): string
    {
        return json_encode($this->_generate($domains), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}


