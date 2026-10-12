<?php

declare(strict_types=1);

namespace ideatic\l10n\Angular;

use ideatic\l10n\LString;

/**
 * A string registered in the manifest
 */
class ManifestEntry
{
    public string $id;

    /** @var 'element'|'attribute'|'code' */
    public string $kind;

    public LString $string;
}
