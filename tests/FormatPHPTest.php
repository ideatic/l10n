<?php

use ideatic\l10n\String\Format\PHP;
use PHPUnit\Framework\TestCase;


require_once 'Helpers.php';

class FormatPHPTest extends TestCase
{
    use Helpers;

    public function testBasic()
    {
        $input = '<?php
            echo __("Hello world");
            ';

        $expected = '<?php
            echo \'Hola mundo\';
            ';

        $translated = $this->_translate(new PHP(), $input);

        $this->assertEquals($expected, $translated);
    }

    public function testComplex()
    {
        $input = '<?php
            echo _f("Hello {name}", ["{name}" => "World"]);
            ';

        $expected = '<?php
            echo strtr(\'Hola {name}\',  ["{name}" => "World"]);
            ';


        $translated = $this->_translate(new PHP(), $input);

        $this->assertEquals($expected, $translated);
    }
}
