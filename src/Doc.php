<?php

namespace IbraheemGhazi\Stager;

class Doc
{

    var $out = [];
    public function __construct()
    {
    }

    public function start($start = '<?php')
    {
        $this->out[] = $start;

        return $this;

    }

    public function setNameSpace($nameSpace)
    {
        $this->out[] = 'namespace '.$nameSpace.';';

        return $this;
    }

    public function addUse($use)
    {
        $this->out[] = 'use '.$use.';';

        return $this;
    }

    public function openTrait($traitName, $callback)
    {
        if (strpos($traitName, '\\')) {
            $traitName = substr(strrchr($traitName, '\\'), 1);
        }
        $this->out[] = 'trait '.$traitName;
        $this->out[] = '{';
        if ($callback instanceof \Closure) {
            $invoke = $callback->__invoke();
            if ($invoke instanceof Doc) {
                $this->out[] = $invoke->out;
            } else {
                $this->out[] = $invoke;
            }
        } else {
            $this->out[] = $callback;
        }
        $this->out[] = '}';

        return $this;
    }
    public function openInterface($interfaceName, $callback, $extends = '')
    {
        if (strpos($interfaceName, '\\')) {
            $nameSpace = substr($interfaceName, 0, strrpos($interfaceName, '\\'));
            $this->setNameSpace($nameSpace);
            $interfaceName = substr(strrchr($interfaceName, '\\'), 1);
        }
        $this->out[] = 'interface '.$interfaceName.($extends ? ' extends '.$extends
                : '');
        $this->out[] = '{';
        if ($callback instanceof \Closure) {
            $invoke = $callback->__invoke();
            if ($invoke instanceof Doc) {
                $this->out[] = $invoke->out;
            } else {
                $this->out[] = $invoke;
            }
        } else {
            $this->out[] = $callback;
        }
        $this->out[] = '}';

        return $this;
    }

    public function openClass($className, $callback, $extends = null)
    {
        if (strpos($className, '\\')) {
            $nameSpace = substr($className, 0, strrpos($className, '\\'));
            $this->setNameSpace($nameSpace);
            $className = substr(strrchr($className, '\\'), 1);
        }
        $this->out[] = 'class '.$className.($extends ? ' extends '.$extends
                : '');
        $this->out[] = '{';
        if ($callback instanceof \Closure) {
            $invoke = $callback->__invoke();
            if ($invoke instanceof Doc) {
                $this->out[] = $invoke->out;
            } else {
                $this->out[] = $invoke;
            }
        } else {
            $this->out[] = $callback;
        }
        $this->out[] = '}';

        return $this;

    }

    public function addConstant($constName)
    {
        $constKey = strtoupper(self::snakeCase($constName));
        $this->out[] = 'const '.$constKey.' = '."'$constName'".';';

        return $this;
    }

    public function addVar(
        $varName,
        $default = null,
        $scope = 'public',
        $static = false
    ) {
        $varName = self::snakeCase($varName);
        $this->out[] = $scope.' '.($static ? 'static ' : '').'$'.$varName
            .(is_null($default) ? '' : ' = '.self::varToText($default)).';';

        return $this;
    }

    public function addMethod(
        $methodName,
        $params = [],
        $scope = 'public',
        $static = false
    ) {
        $params = implode(' ,', array_map(function ($i) {
            return $i;
        }, $params));
        $methodName = camel_case($methodName);
        $this->out[] = $scope.' '.($static ? 'static ' : '').'function '
            .$methodName
            ."($params)";
        $this->out[] = '{';
        $this->out[] = [''];
        $this->out[] = '}';

        return $this;
    }

//    var $f  = [];
    public function exportFile($filename)
    {
        return file_put_contents($filename, $this->getText());

    }

    public function getText()
    {
        return implode("\r\n", $this->getLinedArray());
    }

    public function getLinedArray($arr = null, $tabs = 0)
    {
        static $f = [];
        $arr = is_null($arr) ? $this->out : $arr;
        foreach ($arr as $line) {
            if (is_string($line)) {
                $f[] = str_repeat("    ", $tabs).$line;
            } elseif (is_array($line)) {
                $this->getLinedArray($line, $tabs + 1);
            }

        }

        return $f;
    }

    private static function snakeCase($str)
    {
        return snake_case(str_replace('-', '_', $str));
    }

    public static function varToText($var)
    {
        if (is_null($var)) {
            return 'null';
        } elseif (is_array($var)) {
            $json = json_encode($var);
            $arrayText = str_replace(['{', '}', ':'], ['[', ']', '=>'], $json);

            return $arrayText;
        } elseif (is_string($var)) {
            return "'$var'";
        }

        return $var;

    }


}

