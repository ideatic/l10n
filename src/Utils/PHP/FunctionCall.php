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
     * @var string
     */
    public $method;

    /**
     * Código completo de llamada al método, incluido el objeto o clase al que pertenece
     * @var string
     */
    public $fullMethod;

    /**
     * Código PHP de la llamada al método completa, incluido objeto, clase y argumentos
     * @var string
     */
    public $code;

    /**
     * Array con el código PHP de los argumentos de la llamada
     * @var string[]
     */
    public $arguments;

    /**
     * Posición en el código fuente original de la llamada al método
     * @var int
     */
    public $offset;

    /**
     * Posición en el código fuente original de la llamada al método completo
     * @var int
     */
    public $full_method_offset;

    /**
     * Línea en el código fuente original de la llamada al método
     * @var int
     */
    public $line;

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
     *
     * @param int $index
     */
    public function parseArguments()
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
     * @param int     $index
     * @param boolean $protectVarStrings Valor que indica si se evitará un error de variable no definida al intentar procesar cadenas con variables. P.ej.: "Hola $name"
     *
     * @return mixed
     */
    public function parseArgument($index, $protectVarStrings = true)
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
            throw new Exception_General('Unable to parse function call', $code, null, 0, $err);
        }
    }

    /**
     * Obtiene los tokens PHP que forman el argumento indicado
     *
     * @param int $index
     *
     * @return array
     */
    private function _getArgumentTokens($index)
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
     *
     * @param int $index
     *
     * @return boolean
     */
    public function isConstantString($index)
    {
        return $this->_isOnlyAllowedTokens($index, [T_WHITESPACE, T_CONSTANT_ENCAPSED_STRING, T_COMMENT, T_DOC_COMMENT]);
    }

    private function _isOnlyAllowedTokens($index, array $allowed)
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
     * @return boolean
     */
    public function allScalar()
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
     *
     * @param int $index
     *
     * @return boolean
     */
    public function isScalar($index)
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
     *
     * @param int $index
     *
     * @return boolean
     */
    public function isArray($index)
    {
        $tokens = $this->_getArgumentTokens($index);

        //Ignorar espacios en blanco y comentarios antes del array
        $allowed = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

        for ($pos = 0; $pos < count($tokens); $pos++) {
            if (!in_array($tokens[$pos][0], $allowed)) {
                break;
            }
        }

        //Comprobar si el array comienza inmediatamente después
        return isset($tokens[$pos]) && $tokens[$pos][0] == T_ARRAY;
    }

    /**
     * Obtiene los comentarios incluidos en el argumento de índice indicado
     *
     * @param int $index
     * return string[]
     */
    public function getComments($index)
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
