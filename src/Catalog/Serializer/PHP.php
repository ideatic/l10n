<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Serializer;

class PHP extends ArraySerializer
{
    /** @inheritDoc */
    public function generate(array $domains): string
    {
        $phpArray = var_export($this->_generate($domains), true);

        $php = [
            '<?php
declare(strict_types=1);'
        ];
        if (!$this->comments) {
            $this->comments = 'Created ' . date('r');
        }
        $php[] = "/* {$this->comments} */";
        $php[] = '';
        $php[] = "return {$phpArray};";

        return implode(PHP_EOL, $php);
    }
}

