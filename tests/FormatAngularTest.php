<?php

use ideatic\l10n\String\Format\Angular;
use PHPUnit\Framework\TestCase;

require_once 'Helpers.php';


class FormatAngularTest extends TestCase
{
    use Helpers;

    public function testBasic()
    {
        $input = '<html>
        <body>
        <p i18n>
        	Hello world
        </p>
        </body>
        </html>';

        $expected = '<html>
        <body>
        <p>Hola mundo</p>
        </body>
        </html>';

        $translated = $this->_translate(new Angular(), $input, 'file.html');

        $this->assertEquals($expected, $translated);
    }

    public function testPlaceholders()
    {
        $input = '<html>
        <body>
        <p i18n>
        	Hello {{ userName | i18n:"{name}" }}
        </p>
        </body>
        </html>';

        $expected = '<html>
        <body>
        <p>Hola {{ userName }}</p>
        </body>
        </html>';

        $translated = $this->_translate(new Angular(), $input, 'file.html', 'throw');

        $this->assertEquals($expected, $translated);
    }

    public function testAttributes()
    {
        $input = '<html>
        <body>
        <div title="Hello world" i18n-title></div>
        <div title="D\'Artagnan" i18n-title></div>
        </body>
        </html>';

        $expected = '<html>
        <body>
        <div title="Hola mundo"></div>
        <div title="D&quot;Artagnan"></div>
        </body>
        </html>';

        $translated = $this->_translate(new Angular(), $input, 'file.html', 'throw');

        $this->assertEquals($expected, $translated);
    }

    public function testIcu()
    {
        $input = '<html>
        <body>
        <p i18n>
        	{n, plural, one {1 day} other {# days}}
        </p>
        </body>
        </html>';

        $expected = '<html>
        <body>
        <p>{n, plural, one {1 día} other {# días}}</p>
        </body>
        </html>';

        $format = new Angular();
        $format->fixIcuPluralHashes = false;
        $translated = $this->_translate($format, $input, 'file.html');

        $this->assertEquals($expected, $translated);
    }

    public function testIcuFixHash()
    {
        $input = '<html>
        <body>
        <p i18n>
        	{n, plural, one {1 day} other {# days}}
        </p>
        </body>
        </html>';

        $expected = '<html>
        <body>
        <p>{n, plural, one {1 día} other {{{ n | number }} días}}</p>
        </body>
        </html>';

        $format = new Angular();
        $format->fixIcuPluralHashes = true;
        $translated = $this->_translate($format, $input, 'file.html');

        $this->assertEquals($expected, $translated);
    }
}
