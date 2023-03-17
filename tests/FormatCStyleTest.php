<?php
declare(strict_types=1);

use ideatic\l10n\String\Format\CStyle;
use PHPUnit\Framework\TestCase;

require_once 'Helpers.php';

class FormatCStyleTest extends TestCase
{
    use Helpers;

    public function testBasic()
    {
        $input = 'function() {
        let a = __("Hello world");
        }';

        $expected = 'function() {
        let a = "Hola mundo";
        }';


        $translated = $this->_translate(new CStyle(), $input);

        $this->assertEquals($expected, $translated);
    }

    public function testComplexUnoptimized()
    {
        $input = 'function() {
        let a = __("Hello {name}").replace("{name}", "World");
        }';

        $expected = 'function() {
        let a = "Hola {name}".replace("{name}", "World");
        }';


        $format = new CStyle();
        $format->optimizePlaceholderReplacement = false;
        $translated = $this->_translate($format, $input);

        $this->assertEquals($expected, $translated);
    }

    public function testComplexOptimized()
    {
        $input = 'function() {
        let a = __("Hello {name}").replace("{name}", "World");
        }';

        $expected = 'function() {
        let a = "Hola \' + ("World") + \'";
        }';


        $format = new CStyle();
        $format->optimizePlaceholderReplacement = true;
        $translated = $this->_translate($format, $input);

        $this->assertEquals($expected, $translated);
    }
}
