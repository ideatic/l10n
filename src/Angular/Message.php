<?php

declare(strict_types=1);

namespace ideatic\l10n\Angular;

/**
 * A message extracted by Angular (`ng extract-i18n`)
 */
final class Message
{
    public string $id;

    /** @var list<Token> */
    public array $tokens = [];

    /** @var array<string, string> Original source of each placeholder, by Angular name (only the ones Angular reports) */
    public array $displays = [];

    /** @var list<string> Files where the message is used */
    public array $files = [];

    public ?string $meaning = null;
}
