<?php

namespace ideatic\l10n\Utils\PHP;

use ideatic\l10n\Utils\Str;

/**
 * Representa una llamada a una función de PHP
 */
class FunctionCall
{

    /**
     * Nombre de la función o método invocado
     */
    public string $method;

    /**
     * Código completo de llamada al método, incluido el objeto o clase al que pertenece
     */
    public string $fullMethod;

    /**
     * Código PHP de la llamada al método completa, incluido objeto, clase y argumentos
     */
    public string $code;

    /**
     * Array con el código PHP de los argumentos de la llamada
     * @var string[]
     */
    public array $arguments;

    /**
     * Posición en el código fuente original de la llamada al método
     */
    public int $offset;

    /**
     * Posición en el código fuente original de la llamada al método completo
     */
    public int $fullMethodOffset;

    /**
     * Línea en el código fuente original de la llamada al método
     */
    public int $line;

    /**
     * Obtiene un método que indica si la llamada es a una función normal (TRUE) o
     * a un método de clase (FALSE)
     */
    public function isFunction(): bool
    {
        return strpos($this->fullMethod, '->') === false;
    }

    /**
     * Obtiene el código fuente de la llamada completa, incluyendo los objetos
     * que contienen el método invocado
     */
    public function fullMethodName()
    {
        return preg_replace('/^' . preg_quote($this->method) . '/', $this->fullMethod, $this->code, 1);
    }

    /**
     * Obtiene el valor procesado de todos los parámetros de esta llamada
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
     * Obtiene el valor procesado del parámetro con el índice indicado
     *
     * @param boolean $protectVarStrings Valor que indica si se evitará un error de variable no definida al intentar procesar cadenas con variables. P.ej.: "Hola $name"
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
            throw new \Exception('Unable to parse function call', $code, $err);
        }
    }

    /**
     * Obtiene los tokens PHP que forman el argumento indicado
     */
    private function _getArgumentTokens(int $index): array
    {
        $code = '<?php ' . $this->arguments[$index] . '?>';

        $tokens = token_get_all($code);

        //Eliminar tokens de comienzo y final de código PHP
        $last_key = count($tokens) - 1;

        if ($tokens[0][0] == T_OPEN_TAG) {
            unset($tokens[0]);
        }
        if ($tokens[$last_key][0] == T_CLOSE_TAG) {
            unset($tokens[$last_key]);
        }

        return array_values($tokens);
    }

    /**
     * Obtiene un valor que indica si el parámetro con el índice indicado es una
     * cadena de texto constante
     */
    public function isConstantString(int $index): bool
    {
        return $this->_isOnlyAllowedTokens($index, [T_WHITESPACE, T_CONSTANT_ENCAPSED_STRING, T_COMMENT, T_DOC_COMMENT]);
    }

    private function _isOnlyAllowedTokens($index, array $allowed): bool
    {
        $tokens = $this->_getArgumentTokens($index);
        foreach ($tokens as $token) {
            if (!in_array($token[0], $allowed)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Obtiene un valor que indica si todos los parámetros son valores escalares
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
     * Obtiene un valor que indica si el parámetro con el índice indicado es un
     * escalar (cadena, numero, boolean, etc.)
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
                T_LNUMBER, //Números
                T_STRING, //Booleans
                T_COMMENT,//Comentarios
                T_DOC_COMMENT
            ]
        );
    }

    /**
     * Obtiene un valor que indica si el parámetro con el índice indicado es
     * un array
     */
    public function isArray(int $index): bool
    {
        $tokens = $this->_getArgumentTokens($index);

        // Ignorar espacios en blanco y comentarios antes del array
        $allowed = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

        for ($pos = 0; $pos < count($tokens); $pos++) {
            if (!in_array($tokens[$pos][0], $allowed)) {
                break;
            }
        }

        // Comprobar si el array comienza inmediatamente después
        return isset($tokens[$pos]) && ($tokens[$pos][0] == T_ARRAY || $tokens[$pos] == '[');
    }

    /**
     * Obtiene los comentarios incluidos en el argumento de índice indicado
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

                // Limpiar comentario
                if (Str::startsWith($comment, '/*')) {
                    $comment = substr($comment, 2, -2);
                } elseif (Str::startsWith($comment, '//')) {
                    $comment = substr($comment, 2);
                }

                $comments[] = $comment;
            }
        }

        return $comments;
    }

    public function __toString()
    {
        return $this->code;
    }

}
