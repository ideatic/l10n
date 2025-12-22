<?php

declare(strict_types=1);


namespace ideatic\l10n\Utils\Gettext;

use Exception;

/**
 * A gettext Plural-Forms parser.
 *
 * @copyright WordPress
 */
class PluralRule
{
    /**
     * Operator characters.
     *
     * @since 4.9.0
     * @var string OP_CHARS Operator characters.
     */
    const string OP_CHARS = '|&><!=%?:';

    /**
     * Valid number characters.
     *
     * @since 4.9.0
     * @var string NUM_CHARS Valid number characters.
     */
    const string NUM_CHARS = '0123456789';

    /**
     * Operator precedence.
     *
     * Operator precedence from highest to lowest. Higher numbers indicate
     * higher precedence, and are executed first.
     *
     * @see   https://en.wikipedia.org/wiki/Operators_in_C_and_C%2B%2B#Operator_precedence
     *
     * @since 4.9.0
     * @var array<string, int> $op_precedence Operator precedence from highest to lowest.
     */
    protected static array $op_precedence = [
        '%' => 6,

        '<' => 5,
        '<=' => 5,
        '>' => 5,
        '>=' => 5,

        '==' => 4,
        '!=' => 4,

        '&&' => 3,

        '||' => 2,

        '?:' => 1,
        '?' => 1,

        '(' => 0,
        ')' => 0,
    ];

    /**
     * Tokens generated from the string.
     *
     * @since 4.9.0
     * @var array $tokens List of tokens.
     */
    protected array $tokens = [];

    /**
     * Cache for repeated calls to the function.
     *
     * @since 4.9.0
     * @var array $cache Map of $n => $result
     */
    protected array $cache = [];

    /**
     * Constructor.
     *
     * @param string $str Plural function (just the bit after `plural=` from Plural-Forms)
     *
     * @since 4.9.0
     *
     */
    public function __construct(string $str)
    {
        $this->parse($str);
    }

    /**
     * Parse a Plural-Forms string into tokens.
     *
     * Uses the shunting-yard algorithm to convert the string to Reverse Polish
     * Notation tokens.
     *
     * @param string $str String to parse.
     *
     * @since 4.9.0
     *
     */
    protected function parse(string $str): void
    {
        $pos = 0;
        $len = strlen($str);

        // Convert infix operators to postfix using the shunting-yard algorithm.
        $output = [];
        $stack = [];
        while ($pos < $len) {
            $next = substr($str, $pos, 1);

            switch ($next) {
                // Ignore whitespace
                case ' ':
                case "\t":
                    $pos++;
                    break;

                // Variable (n)
                case 'n':
                    $output[] = ['var'];
                    $pos++;
                    break;

                // Parentheses
                case '(':
                    $stack[] = $next;
                    $pos++;
                    break;

                case ')':
                    $found = false;
                    while (!empty($stack)) {
                        $o2 = $stack[count($stack) - 1];
                        if ($o2 !== '(') {
                            $output[] = ['op', array_pop($stack)];
                            continue;
                        }

                        // Discard open paren.
                        array_pop($stack);
                        $found = true;
                        break;
                    }

                    if (!$found) {
                        throw new Exception('Mismatched parentheses');
                    }

                    $pos++;
                    break;

                // Operators
                case '|':
                case '&':
                case '>':
                case '<':
                case '!':
                case '=':
                case '%':
                case '?':
                    $end_operator = strspn($str, self::OP_CHARS, $pos);
                    $operator = substr($str, $pos, $end_operator);
                    if (!array_key_exists($operator, self::$op_precedence)) {
                        throw new Exception(sprintf('Unknown operator "%s"', $operator));
                    }

                    while (!empty($stack)) {
                        $o2 = $stack[count($stack) - 1];

                        // Ternary is right-associative in C
                        if ($operator === '?:' || $operator === '?') {
                            if (self::$op_precedence[$operator] >= self::$op_precedence[$o2]) {
                                break;
                            }
                        } elseif (self::$op_precedence[$operator] > self::$op_precedence[$o2]) {
                            break;
                        }

                        $output[] = ['op', array_pop($stack)];
                    }
                    $stack[] = $operator;

                    $pos += $end_operator;
                    break;

                // Ternary "else"
                case ':':
                    $found = false;
                    $s_pos = count($stack) - 1;
                    while ($s_pos >= 0) {
                        $o2 = $stack[$s_pos];
                        if ($o2 !== '?') {
                            $output[] = ['op', array_pop($stack)];
                            $s_pos--;
                            continue;
                        }

                        // Replace.
                        $stack[$s_pos] = '?:';
                        $found = true;
                        break;
                    }

                    if (!$found) {
                        throw new Exception('Missing starting "?" ternary operator');
                    }
                    $pos++;
                    break;

                // Default - number or invalid
                default:
                    if ($next >= '0' && $next <= '9') {
                        $span = strspn($str, self::NUM_CHARS, $pos);
                        $output[] = ['value', intval(substr($str, $pos, $span))];
                        $pos += $span;
                        break;
                    }

                    throw new Exception(sprintf('Unknown symbol "%s"', $next));
            }
        }

        while (!empty($stack)) {
            $o2 = array_pop($stack);
            if ($o2 === '(' || $o2 === ')') {
                throw new Exception('Mismatched parentheses');
            }

            $output[] = ['op', $o2];
        }

        $this->tokens = $output;
    }

    /**
     * Get the plural form for a number.
     *
     * Caches the value for repeated calls.
     *
     * @param int|float $num Number to get plural form for.
     *
     * @return int Plural form value.
     * @since 4.9.0
     *
     */
    public function get(int|float $num): int
    {
        return $this->cache[$num] ??= $this->execute($num);
    }

    /**
     * Execute the plural form function.
     *
     * @param int|float $n Variable "n" to substitute.
     *
     * @return int Plural form value.
     * @since 4.9.0
     *
     */
    public function execute(int|float $n): int
    {
        $stack = [];
        $i = 0;
        $total = count($this->tokens);
        while ($i < $total) {
            $next = $this->tokens[$i];
            $i++;
            if ($next[0] === 'var') {
                $stack[] = $n;
                continue;
            } elseif ($next[0] === 'value') {
                $stack[] = $next[1];
                continue;
            }

            // Only operators left.
            switch ($next[1]) {
                case '%':
                    $v2 = array_pop($stack);
                    $v1 = array_pop($stack);
                    $stack[] = $v1 % $v2;
                    break;

                case '||':
                    $v2 = array_pop($stack);
                    $v1 = array_pop($stack);
                    $stack[] = $v1 || $v2;
                    break;

                case '&&':
                    $v2 = array_pop($stack);
                    $v1 = array_pop($stack);
                    $stack[] = $v1 && $v2;
                    break;

                case '<':
                    $v2 = array_pop($stack);
                    $v1 = array_pop($stack);
                    $stack[] = $v1 < $v2;
                    break;

                case '<=':
                    $v2 = array_pop($stack);
                    $v1 = array_pop($stack);
                    $stack[] = $v1 <= $v2;
                    break;

                case '>':
                    $v2 = array_pop($stack);
                    $v1 = array_pop($stack);
                    $stack[] = $v1 > $v2;
                    break;

                case '>=':
                    $v2 = array_pop($stack);
                    $v1 = array_pop($stack);
                    $stack[] = $v1 >= $v2;
                    break;

                case '!=':
                    $v2 = array_pop($stack);
                    $v1 = array_pop($stack);
                    $stack[] = $v1 != $v2;
                    break;

                case '==':
                    $v2 = array_pop($stack);
                    $v1 = array_pop($stack);
                    $stack[] = $v1 == $v2;
                    break;

                case '?:':
                    $v3 = array_pop($stack);
                    $v2 = array_pop($stack);
                    $v1 = array_pop($stack);
                    $stack[] = $v1 ? $v2 : $v3;
                    break;

                default:
                    throw new Exception(sprintf('Unknown operator "%s"', $next[1]));
            }
        }

        if (count($stack) !== 1) {
            throw new Exception('Too many values remaining on the stack');
        }

        return (int)$stack[0];
    }
}
