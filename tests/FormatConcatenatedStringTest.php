<?php

declare(strict_types=1);

use ideatic\l10n\String\Format\CStyle;
use ideatic\l10n\String\Format\PHP;
use PHPUnit\Framework\TestCase;

/**
 * Un patrón ICU largo no cabe en una línea razonable, así que es natural repartirlo concatenando literales. El
 * lenguaje resuelve esa concatenación en tiempo de compilación, de modo que la cadena sigue siendo constante y debe
 * extraerse igual que si estuviera escrita de una vez.
 *
 * Cuando la expresión no es constante (incluye una variable) no hay nada que extraer, pero el aviso es obligatorio:
 * el caso contrario es que el desarrollador marque un texto, no se extraiga, y nadie se entere nunca.
 */
class FormatConcatenatedStringTest extends TestCase
{
    public function testPhpExtractsConcatenatedLiterals(): void
    {
        $source = "<?php _icu('{count, plural, one {a}' . ' other {b}}', ['count' => 1]);";

        $strings = new PHP()->getStrings($source, 'test.php');

        $this->assertCount(1, $strings);
        $this->assertSame('{count, plural, one {a} other {b}}', $strings[0]->text);
        $this->assertTrue($strings[0]->isICU);
    }

    public function testPhpExtractsConcatenationSplitAcrossLines(): void
    {
        $source = <<<'PHP'
            <?php
            _icu(
                '{count, plural, one {Debes esperar 1 día para vender a %player%.}'
                . ' other {Debes esperar # días para vender a %player%.}}',
                ['count' => $pendingDays]
            );
            PHP;

        $strings = new PHP()->getStrings($source, 'test.php');

        $this->assertCount(1, $strings);
        $this->assertStringContainsString('one {Debes esperar 1 día', $strings[0]->text);
        $this->assertStringContainsString('other {Debes esperar # días', $strings[0]->text);
        $this->assertStringNotContainsString("'", $strings[0]->text, 'The concatenation operator must not leak into the text');
    }

    public function testPhpExtractsSeveralConcatenatedLiterals(): void
    {
        $strings = new PHP()->getStrings('<?php __("a" . "b" . "c");', 'test.php');

        $this->assertCount(1, $strings);
        $this->assertSame('abc', $strings[0]->text);
    }

    public function testPhpKeepsExtractingPlainStrings(): void
    {
        $strings = new PHP()->getStrings('<?php __("Hello world"); _x("Add", "button");', 'test.php');

        $this->assertCount(2, $strings);
        $this->assertSame('Hello world', $strings[0]->text);
        $this->assertSame('button', $strings[1]->context);
    }

    public function testPhpWarnsWhenTheStringCannotBeExtracted(): void
    {
        $output = $this->_capture(fn(): array => new PHP()->getStrings('<?php __("Hello " . $name);', 'test.php'));

        $this->assertSame([], $output['strings']);
        $this->assertStringContainsString('not a constant string', $output['output']);
        $this->assertStringContainsString('test.php:1', $output['output']);
    }

    /**
     * __($mensaje) es una indirección deliberada: no hay ningún texto escrito en el código que se esté perdiendo
     */
    public function testPhpStaysQuietForADeliberateIndirection(): void
    {
        $output = $this->_capture(fn(): array => new PHP()->getStrings('<?php __($message);', 'test.php'));

        $this->assertSame([], $output['strings']);
        $this->assertSame('', $output['output']);
    }

    public function testPhpReportsTheLineOfTheOffendingCall(): void
    {
        $source = "<?php\n\n\n__('Total: ' . \$amount);";

        $output = $this->_capture(fn(): array => new PHP()->getStrings($source, 'test.php'));

        $this->assertStringContainsString('test.php:4', $output['output']);
    }

    public function testCStyleExtractsConcatenatedLiterals(): void
    {
        $strings = new CStyle()->getStrings('__("{count, plural, one {a}" + " other {b}}");', 'test.ts');

        $this->assertCount(1, $strings);
        $this->assertSame('{count, plural, one {a} other {b}}', $strings[0]->text);
    }

    public function testCStyleExtractsConcatenationWithMixedQuotes(): void
    {
        $strings = new CStyle()->getStrings("__('Hola ' + \"mundo\");", 'test.ts');

        $this->assertCount(1, $strings);
        $this->assertSame('Hola mundo', $strings[0]->text);
    }

    /**
     * Antes no se descartaba: se extraía una cadena con el operador y las comillas incrustados en el texto
     */
    public function testCStyleDoesNotExtractACorruptedStringFromANonConstantExpression(): void
    {
        $output = $this->_capture(fn(): array => new CStyle()->getStrings('__("Hello " + name);', 'test.ts'));

        $this->assertSame([], $output['strings']);
        $this->assertStringContainsString('not a constant string', $output['output']);
    }

    public function testCStyleStaysQuietForADeliberateIndirection(): void
    {
        $output = $this->_capture(fn(): array => new CStyle()->getStrings('__(message);', 'test.ts'));

        $this->assertSame([], $output['strings']);
        $this->assertSame('', $output['output']);
    }

    public function testCStyleKeepsExtractingPlainStrings(): void
    {
        $strings = new CStyle()->getStrings('__("Hello world"); _x("Add", "button");', 'test.ts');

        $this->assertCount(2, $strings);
        $this->assertSame('Hello world', $strings[0]->text);
        $this->assertSame('button', $strings[1]->context);
    }

    /**
     * @return array{strings: array<mixed>, output: string}
     */
    private function _capture(callable $callback): array
    {
        ob_start();
        try {
            $strings = $callback();
        } finally {
            $output = strval(ob_get_clean());
        }

        return ['strings' => $strings, 'output' => $output];
    }
}
