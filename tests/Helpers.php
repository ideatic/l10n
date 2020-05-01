<?php

use ideatic\l10n\LString;
use ideatic\l10n\String\Format\Format;


trait Helpers
{
    protected function _translate(Format $format, string $input, ?string $context = null, $allowFallback = true): string
    {
        return $format->translate(
            $input,
            function (LString $string) use ($allowFallback) {
                if ($string->id == 'Hello world') {
                    return 'Hola mundo';
                } elseif ($string->id == 'Hello {name}') {
                    return 'Hola {name}';
                } elseif ($string->id == '{count, plural, one {1 day} other {# days}}') {
                    return '{n, plural, one {1 día} other {# días}}';
                } elseif ($allowFallback == 'throw') {
                    throw new Exception("No translations found for string '{$string->id}'");
                } elseif ($allowFallback) {
                    return $string->id;
                } else {
                    return null;
                }
            },
            $context
        );
    }
}