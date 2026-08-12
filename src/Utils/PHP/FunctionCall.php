<?php

declare(strict_types=1);

namespace ideatic\l10n\Utils\PHP;

use Exception;
use Throwable;

/**
 * Represents a call to a PHP function
 */
class FunctionCall
{

    /**
     * Name of the invoked function or method
     */
    public string $method;

    /**
     * Full code of the method call, including the object or class it belongs to
     */
    public string $fullMethod;

    /**
     * PHP code of the complete method call, including object, class and arguments
     */
    public string $code;

    /**
     * Array with the PHP code of the call arguments
     * @var string[]
     */
    public array $arguments;

    /**
     * Position in the original source code of the method call
     */
    public int $offset;

    /**
     * Position in the original source code of the complete method call
     */
    public int $fullMethodOffset;

    /**
     * Line in the original source code of the method call
     */
    public int $line;

    /**
     * Tells whether the call targets a plain function (TRUE) or a class method (FALSE)
     */
    public function isFunction(): bool
    {
        return !str_contains($this->fullMethod, '->');
    }

    /**
     * Gets the source code of the complete call, including the objects holding the invoked method
     */
    public function fullMethodName(): string
    {
        return preg_replace('/^' . preg_quote($this->method, '/') . '/', $this->fullMethod, $this->code, 1);
    }

    /**
     * Gets how many arguments this function call has
     */
    public function argumentCount(): int
    {
        return count($this->arguments);
    }

    /**
     * Gets the parsed value of every parameter of this call
     */
    public function parseArguments(): array
    {
        $args = [];
        for ($i = 0; $i < count($this->arguments); $i++) {
            $args[] = $this->parseArgument($i);
        }
        return $args;
    }

    /**
     * Gets the parsed value of the parameter at the given index
     *
     * @param boolean $protectVarStrings Whether to avoid an undefined variable error when parsing strings holding variables. E.g.: "Hola $name"
     *
     * @return mixed
     */
    public function parseArgument(int $index, bool $protectVarStrings = true)
    {
        if ($protectVarStrings) {
            $tokens = $this->_getArgumentTokens($index);
            $code = '';
            $inString = false;
            foreach ($tokens as $token) {
                if ($inString && $token[0] == T_VARIABLE) {
                    $code .= '\\' . $token[1];
                } elseif ($token[0] != T_OPEN_TAG && $token[0] != T_CLOSE_TAG) {
                    if (is_array($token)) {
                        $code .= $token[1];
                    } else {
                        $code .= $token;
                        if ($token == '"') {
                            $inString = !$inString;
                        }
                    }
                }
            }
        } else {
            $code = $this->arguments[$index];
        }

        try {
            return eval("return {$code};");
        } catch (Throwable $err) {
            throw new Exception("Unable to parse function call: {$code}", 1, $err);
        }
    }

    /**
     * Gets the PHP tokens making up the given argument
     */
    private function _getArgumentTokens(int $index): array
    {
        if (!isset($this->arguments[$index])) {
            return [];
        }

        $code = '<?php ' . $this->arguments[$index] . '?>';

        $tokens = token_get_all($code);

        // Remove the PHP open and close tokens
        $lastKey = count($tokens) - 1;

        if ($tokens[0][0] == T_OPEN_TAG) {
            unset($tokens[0]);
        }
        if ($tokens[$lastKey][0] == T_CLOSE_TAG) {
            unset($tokens[$lastKey]);
        }

        return array_values($tokens);
    }

    /**
     * Tells whether the parameter at the given index is a constant text string.
     *
     * Concatenation of literals ('a' . 'b') is accepted, which is essential to spread long ICU patterns over
     * several lines: PHP resolves it at compile time, so the value is still constant.
     */
    public function isConstantString(int $index): bool
    {
        return $this->_isOnlyAllowedTokens($index, [T_WHITESPACE, T_CONSTANT_ENCAPSED_STRING, T_COMMENT, T_DOC_COMMENT, '.']);
    }

    /**
     * Tells whether the parameter at the given index holds any text literal.
     *
     * Useful to tell a deliberate indirection (__($message), where there is nothing to extract) apart from text
     * written in the code itself that the extractor cannot process (__('Hola ' . $name)).
     */
    public function containsStringLiteral(int $index): bool
    {
        return array_any($this->_getArgumentTokens($index), static fn($token) => $token[0] === T_CONSTANT_ENCAPSED_STRING);
    }

    private function _isOnlyAllowedTokens(int $index, array $allowed): bool
    {
        $tokens = $this->_getArgumentTokens($index);

        return array_all($tokens, static fn($token) => in_array($token[0], $allowed));
    }

    /**
     * Tells whether every parameter is a scalar value
     */
    public function allScalar(): bool
    {
        for ($i = 0; $i < count($this->arguments); $i++) {
            if (!$this->isScalar($i)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Tells whether the parameter at the given index is a scalar (string, number, boolean, etc.)
     * @see http://php.net/manual/en/function.is-scalar.php
     */
    public function isScalar(int $index): bool
    {
        return $this->_isOnlyAllowedTokens(
            $index,
            [
                T_WHITESPACE,
                T_CONSTANT_ENCAPSED_STRING,
                T_ENCAPSED_AND_WHITESPACE,
                T_VARIABLE,
                '"',
                T_DOLLAR_OPEN_CURLY_BRACES,
                T_CURLY_OPEN,
                T_STRING_VARNAME,
                T_NUM_STRING, //Strings
                T_DNUMBER,
                T_LNUMBER, // Numbers
                T_STRING, //Booleans
                T_COMMENT,//Comentarios
                T_DOC_COMMENT,
            ]
        );
    }

    /**
     * Tells whether the parameter at the given index is an array
     */
    public function isArray(int $index): bool
    {
        $tokens = $this->_getArgumentTokens($index);

        // Skip the whitespace and comments preceding the array
        $allowed = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

        $pos = 0;
        for (; $pos < count($tokens); $pos++) {
            if (!in_array($tokens[$pos][0], $allowed)) {
                break;
            }
        }

        // Check whether the array starts immediately afterwards
        return isset($tokens[$pos]) && ($tokens[$pos][0] == T_ARRAY || $tokens[$pos] == '[');
    }

    /**
     * Gets the comments held in the argument at the given index
     *
     * @return string[]
     */
    public function getComments(int $index): array
    {
        $tokens = $this->_getArgumentTokens($index);

        $comments = [];
        foreach ($tokens as $token) {
            if ($token[0] == T_COMMENT || $token[0] == T_DOC_COMMENT) {
                $comment = $token[1];

                // Clean the comment up
                if (str_starts_with($comment, '/*')) {
                    $comment = substr($comment, 2, -2);
                } elseif (str_starts_with($comment, '//')) {
                    $comment = substr($comment, 2);
                }

                $comments[] = $comment;
            }
        }

        return $comments;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
