<?
/*
 * PHP-to-Scala source code migration helper.
 * See http://code.google.com/p/php-to-scala-migration-helper/ for details.
 *
 * Copyright(C) 2010 Alex T. Ramos / Zigabyte Corporation.
 * COPYING is permitted under the terms of the GNU General Public License, v3.
 *
 * $Id: php-to-scala.php,v 1.14 2010-04-27 17:52:20 alex Exp $
 */

$file = $argv[1];
$code = trim(file_get_contents($file));

$conv = new PhpToScala();
echo $conv->convert($argv[0], $code, $file);

class PhpToScala {

	function is_global($var) {
	   return in_array($var, array('argv', '_GLOBALS', '_SERVER', '_SESSION', '_GET'));
	}

	function match($token1, $ttype) {
		if(is_array($token1)) {
			return $token1[TTYPE] == $ttype;
		}
		else {
			return $token1 === $ttype;
		}
	}

	function contains(array $T, $ttype) {
		foreach($T as $t) {
			if($this->match($t, $ttype)) {
				return true;
			}
		}
		return false;
	}

	function skip(&$T, $ttype) {
		while($this->match($T[0], $ttype)) {
			array_shift($T);
		}
	}

	function skip_thru(&$T, $ttype) {
        while(!$this->match($T[0], $ttype)) {
        	if(!count($T)) throw new Exception();
            array_shift($T);
        }
        array_shift($T);
    }

	function display($token) {
		if(is_array($token)) {
			return token_name($token[TTYPE]);
		}
		else if(is_numeric($token)) {
			return token_name($token);
		}
		else {
			return $token;
		}
	}

	function expect(&$T, $ttype) {
		$this->skip($T, T_WHITESPACE);
		$t = array_shift($T);
		if(!$this->match($t, $ttype)) {
			throw new Exception('Expected: ' . ($this->display($ttype)) . " got: " . ($this->display($t)));
		}
	}

	function peek($T, $ttype) {
        $this->skip($T, T_WHITESPACE);
        $t = array_shift($T);
        return $this->match($t, $ttype);
    }

	function parse_expr_tail(&$T) {
		$out = '';
		while($T[0] !== ')' && $T[0] !== ';') {
			if($T[0] === '(') {
				array_shift($T);
				$out .= '(' . $this->parse_expr_tail($T) . ')';
			}
			else {
				$out .= $this->parse($T);
			}
		}
		if($T[0] != ';') {
			array_shift($T);
		}
		return $out;
	}

	function parse_block_tail(&$T) {
		$out = '';
		while(count($T) && $T[0] !== '}') {
			if($T[0] === '{') {
				array_shift($T);
				$out .= "{ " . $this->parse_block_tail($T) . " }";
			}
			else {
				$out .= $this->parse($T);
			}
		}
		array_shift($T);
		return $out;
	}

    function fetch_expr(&$T) {
        $out = array();
        while(count($T) && $T[0] !== ')' && $T[0] !== ';') {
            if($T[0] === '(') {
                array_shift($T);
                $out []= '(';
                array_splice($out, count($out), 0, $this->fetch_expr($T));
                $out []= ')';
            }
            else {
                $out []= array_shift($T);
            }
        }
        array_shift($T);
        return $out;
    }

	function fetch_block(&$T) {
		$out = array();
		while(count($T) && $T[0] != '}') {
			$t = array_shift($T);
			if($t == '{') {
				$out[] = '{';
				array_splice($out, count($out), 0, $this->fetch_block($T));
				$out[] = '}';
			}
			else {
				$out[] = $t;
			}
		}
		array_shift($T);
		return $out;
	}

    function fetch_stmt(&$T) {
        $this->skip($T, T_WHITESPACE);
        if($T[0] === '{') {
        	$out = array();
        	array_shift($T);
            $out []= "{";
            array_splice($out, count($out), 0, $this->fetch_block($T));
            $out []= "}";
            return $out;
        }
        else {
            return $this->fetch_expr($T);
        }
    }

	function parse_stmt(&$T) {
		$this->skip($T, T_WHITESPACE);
		if($T[0] === '{') {
			array_shift($T);
			return "{ " . $this->parse_block_tail($T) . " }";
		}
		else {
			return "{ " . $this->parse_expr_tail($T) . " }";
		}
	}

	function parse_for(&$T) {
		$this->expect($T, '(');
		$init_expr = $this->parse_expr_tail($T);
		$this->expect($T, ';');
		$cond_expr = $this->parse_expr_tail($T);
		$this->expect($T, ';');
		$term_expr = $this->parse_expr_tail($T);
		$body_stmt = $this->parse_stmt($T);
		return "\n$init_expr;\nwhile($cond_expr) {\n $body_stmt;\n$term_expr\n}\n";
	}

	function scan_vars($T) { /* type inference and variable declaration */
        $out = '';
        $vars = array();

        while(count($T)) {
        	if($this->match($T[0], T_VARIABLE)) {

                $name = $this->parse($T);
                $this->skip($T, T_WHITESPACE);
                $t = array_shift($T);

                if($t != '=' && $t != '[') {
        			continue;
        		}

        		$type = 'ref';
        		$default = 'undef';

        		$this->skip($T, T_WHITESPACE);

        		$u = array_shift($T);
        		if($this->match($u, T_LNUMBER)) {
        			$type = 'integer';
        			$default = '0';
        		}
        		if(!isset($vars[$name]) && !$this->is_global($name)) {
        			$out .= "\nvar $name: $type = $default;";
        			$vars[$name] = 1;
        		}
        	}
        	else {
        		array_shift($T);
        	}
        }
        return $out;
	}

	function scan_globals($T) {
        $out = '';
        $vars = array();
        $global = array();

        while(count($T)) {
            if($this->match($T[0], T_CLASS) || $this->match($T[0], T_FUNCTION)) {
            	// skip over non-global scope
            	do {
            		$t = array_shift($T);
            	} while($t != '{' && count($T));
            	$this->parse_block_tail($T);
            }
            else {
            	$global []= array_shift($T);
            }
        }
        return $this->scan_vars($global);
    }

	function parse_f_args(&$T) {
		$out = '';
        while($T[0] != '{') {
            $prev = $T[0];
            $out .= $this->parse($T);
            if($prev[TTYPE] == T_VARIABLE) {
                $out .= ': ref';
            }
        }
        return $out;
	}

	function parse_function(&$T) {
		$out = "def" . $this->parse_f_args($T);
		$this->expect($T, '{');
		$body = $this->fetch_block($T);

		if($this->contains($body, T_RETURN)) {
			$out .= ': ref = ';
		}
		$vars = $this->scan_vars($body);
		return $out . '{' . $vars . "\n" . $this->parse_all($body) . '}';
	}

	function parse_vars(&$T) {
		$out = '';
		while($T[0] != ';') {
			if($T[0][TTYPE] == T_VARIABLE) {
				$out .= "var " . $this->parse($T) . " = undef;\n";
			}
			else {
				array_shift($T);
			}
		}
		array_shift($T);
		return $out;
	}

	function parse_echo(&$T) {
		$t = array_shift($T);
		return 'echo(' . $this->parse_expr_tail($T) . ')';
	}

	function parse_new(&$T) {
		$this->skip($T, T_WHITESPACE);
		$classname = $this->parse($T);
		return "new $classname __construct";
	}

	function parse_constructor(&$T) {
		$this->expect($T, T_FUNCTION);
		$this->expect($T, T_STRING);
        $out = 'def __construct ';
        $out.= $this->parse_f_args($T);
		$this->expect($T, '{');
		$out .= ':ref = {';
		$out .= $this->parse_block_tail($T);
		$out .= "\nthis;\n}";
		return $out;
	}

	function parse_class(&$T) {
        $this->skip($T, T_WHITESPACE);
        $classname = $this->parse($T);
        $out = "class $classname";

        while($T[0] != '{') {
        	$out .= $this->parse($T);
        }

        $out .= ' extends obj ' . $this->parse($T);
        $body = $this->fetch_block($T);

        while(count($body)) {
        	if($this->match($body[0], T_FUNCTION) && $body[2][VALUE] == $classname) {
                $out .= $this->parse_constructor($body);
        	}
        	else {
        		$out .= $this->parse($body);
        	}
        }
        return $out . "\n}\nobject $classname extends $classname;";
	}

	function parse(&$T) {

		$t = array_shift($T);

		if(!is_array($t)) {
			switch($t) {
				case '[':
					if($T[0] == ']' && $this->peek(array_slice($T,1), '=')) {
						$this->expect($T, ']');
						$this->expect($T, '=');
						return " += ";
					}
					else {
						return '(';
					}

				case ']': return ')';
				case '.': return '+&';

				case '?':
					$choices = $this->parse_expr_tail($T);
					return " |? { if(_) $choices }";

				case ':': return " else ";

				default:
					return $t;
			}
		}

		switch($t[TTYPE]) {

			case T_INLINE_HTML:
				return 'output """' . $t[VALUE] . '"""' . "\n";

			case T_CLOSE_TAG: #     ? > or % >  escaping from HTML
			case T_OPEN_TAG: #  < ?php, < ? or < %  escaping from HTML
				return "";

			case T_FUNCTION: #  function or cfunction   functions
				return $this->parse_function($T);

			case T_OBJECT_OPERATOR: #   ->  classes and objects
				if($this->peek(array_slice($T,1), '=')) {  // assignment
                    return "('" . $this->parse($T) . ")";
                }
                else if($this->peek(array_slice($T,1), '(')) {  // method call
                	return "~&'" . $this->parse($T) . "~>";
                }
                else {
                    return "~>'" . $this->parse($T);
                }


			case T_DOUBLE_COLON: #  ::  see T_PAAMAYIM_NEKUDOTAYIM below
            case T_PAAMAYIM_NEKUDOTAYIM: #  ::  ::. Also defined as T_DOUBLE_COLON.
                return ".";

			case T_VARIABLE: #  $foo    variables
				if($t[VALUE] == '$this') {
					if($T[0][TTYPE] == T_OBJECT_OPERATOR) {
						array_shift($T);
						return "this."; // use static binding (".") instead of dynamic "~>"
					}
					else {
						return 'this';
					}
				} else {
					return substr($t[VALUE], 1); // strip the $
				}

			case T_VAR: #   var     classes and objects
				return $this->parse_vars($T);

			case T_FOR: #   for     for
				return $this->parse_for($T);

			case T_ECHO: #  echo    echo()
			case T_PRINT: #     print()     print()
				return $this->parse_echo($T);

            case T_NEW: #   new     classes and objects
				return $this->parse_new($T);

			case T_CLASS: #     class   classes and objects
				return $this->parse_class($T);

			case T_INC: #   ++  incrementing/decrementing operators
				if($this->peek($T, T_VARIABLE)) {
					$var = $this->parse($T);
					return "($var = $var + 1)";
				}
				else {
					return "++";
				}

            case T_CONSTANT_ENCAPSED_STRING: #  "foo" or 'bar'  string syntax
            	return '"' . substr($t[VALUE], 1, -1) . '"';

            case T_ENCAPSED_AND_WHITESPACE: #   " $a"   constant part of string with variables
				$out = $t[VALUE];
			    while($T[0][TTYPE] == T_VARIABLE) {
			    	$out .= '"+' . $this->parse($T) . '+"';
			    }
			    return $out;

			case T_DOUBLE_ARROW: #  =>  array syntax
				return "->";

			case T_CONCAT_EQUAL: #  .=  assignment operators
				return "+=& (" . $this->parse_expr_tail($T) . ")";

			case T_FOREACH: #   foreach     foreach
			    $this->expect($T, '(');
                $this->skip($T, T_WHITESPACE);
			    $var = $this->parse($T);
			    $this->expect($T, T_AS);
			    $this->skip($T, T_WHITESPACE);
			    $k = $this->parse($T);
			    $this->expect($T, T_DOUBLE_ARROW);
			    $this->skip($T, T_WHITESPACE);
			    $v = $this->parse($T);
			    $this->expect($T, ')');
			    $this->skip($T, T_WHITESPACE);
                $loop = $this->fetch_stmt($T);

                return "$var.foreach{ ($k:ref,$v:ref) => " . $this->parse_all($loop) . " }";

			case T_ARRAY: #     array()     array(), array syntax
				$this->skip($T, '(');
				$list = $this->fetch_expr($T, ')');
				if($this->contains($list, T_DOUBLE_ARROW)) {
					return "array.map(" . $this->parse_all($list) . ")";
				}
				else {
					return "array.list(" . $this->parse_all($list) . ")";
				}

			case T_WHILE: #     while   while, do..while
				$this->expect($T, '(');
				$cond = $this->fetch_expr($T, ')');
				if($this->contains($T, '=')) {
					// assignment in loop condition
					$var = "";
					foreach($cond as $t) {
						if($t[TTYPE] == T_VARIABLE) {
							$U = array($t);
							$var = $this->parse($U);
							break;
						}
					}
					return 'while ({' . $this->parse_all($cond) . "; $var})";
				}
				else {
					// regular loop condition
                    return 'while (' . $this->parse_all($cond) . ')';
				}

			case T_COMMENT: #   // or #, and /* */ in PHP 5     comments
			case T_ABSTRACT: #      abstract    Class Abstraction (available since PHP 5.0.0)
			case T_AND_EQUAL: #     &=  assignment operators
			case T_ARRAY_CAST: #    (array)     type-casting
			case T_AS: #    as  foreach
			case T_BAD_CHARACTER: #         anything below ASCII 32 except \t (0x09), \n (0x0a) and \r (0x0d)
			case T_BOOLEAN_AND: #   &&  logical operators
			case T_BOOLEAN_OR: #    ||  logical operators
			case T_BOOL_CAST: #     (bool) or (boolean)     type-casting
			case T_BREAK: #     break   break
			case T_CASE: #  case    switch
			case T_CATCH: #     catch   Exceptions (available since PHP 5.0.0)
			case T_CHARACTER: #         not used anymore
			case T_CLASS_C: #   __CLASS__   magic constants (available since PHP 4.3.0)
			case T_CLONE: #     clone   classes and objects (available since PHP 5.0.0)
			case T_CONST: #     const   class constants
			case T_CONTINUE: #  continue    continue
			case T_CURLY_OPEN: #    {$  complex variable parsed syntax
			case T_DEC: #   --  incrementing/decrementing operators
			case T_DECLARE: #   declare     declare
			case T_DEFAULT: #   default     switch
			case T_DIR: #   __DIR__     magic constants (available since PHP 5.3.0)
			case T_DIV_EQUAL: #     /=  assignment operators
			case T_DNUMBER: #   0.12, etc   floating point numbers
			case T_DOC_COMMENT: #   /** */  PHPDoc style comments (available since PHP 5.0.0)
			case T_DO: #    do  do..while
			case T_DOLLAR_OPEN_CURLY_BRACES: #  ${  complex variable parsed syntax
			case T_DOUBLE_CAST: #   (real), (double) or (float)     type-casting
			case T_ELSE: #  else    else
			case T_ELSEIF: #    elseif  elseif
			case T_EMPTY: #     empty   empty()
			case T_ENDDECLARE: #    enddeclare  declare, alternative syntax
			case T_ENDFOR: #    endfor  for, alternative syntax
			case T_ENDFOREACH: #    endforeach  foreach, alternative syntax
			case T_ENDIF: #     endif   if, alternative syntax
			case T_ENDSWITCH: #     endswitch   switch, alternative syntax
			case T_ENDWHILE: #  endwhile    while, alternative syntax
			case T_END_HEREDOC: #       heredoc syntax
			case T_EVAL: #  eval()  eval()
			case T_EXIT: #  exit or die     exit(), die()
			case T_EXTENDS: #   extends     extends, classes and objects
			case T_FILE: #  __FILE__    magic constants
			case T_FINAL: #     final   Final Keyword (available since PHP 5.0.0)
			case T_FUNC_C: #    __FUNCTION__    magic constants (available since PHP 4.3.0)
			case T_GLOBAL: #    global  variable scope
			case T_GOTO: #  goto    (available since PHP 5.3.0)
			case T_HALT_COMPILER: #     __halt_compiler()   __halt_compiler (available since PHP 5.1.0)
			case T_IF: #    if  if
			case T_IMPLEMENTS: #    implements  Object Interfaces (available since PHP 5.0.0)
			case T_INCLUDE: #   include()   include()
			case T_INCLUDE_ONCE: #  include_once()  include_once()
			case T_INLINE_HTML: #       text outside PHP
			case T_INSTANCEOF: #    instanceof  type operators (available since PHP 5.0.0)
			case T_INT_CAST: #  (int) or (integer)  type-casting
			case T_INTERFACE: #     interface   Object Interfaces (available since PHP 5.0.0)
			case T_ISSET: #     isset()     isset()
			case T_IS_EQUAL: #  ==  comparison operators
			case T_IS_GREATER_OR_EQUAL: #   >=  comparison operators
			case T_IS_IDENTICAL: #  ===     comparison operators
			case T_IS_NOT_EQUAL: #  != or <>    comparison operators
			case T_IS_NOT_IDENTICAL: #  !==     comparison operators
			case T_IS_SMALLER_OR_EQUAL: #   <=  comparison operators
			case T_LINE: #  __LINE__    magic constants
			case T_LIST: #  list()  list()
			case T_LNUMBER: #   123, 012, 0x1ac, etc    integers
			case T_LOGICAL_AND: #   and     logical operators
			case T_LOGICAL_OR: #    or  logical operators
			case T_LOGICAL_XOR: #   xor     logical operators
			case T_METHOD_C: #  __METHOD__  magic constants (available since PHP 5.0.0)
			case T_MINUS_EQUAL: #   -=  assignment operators
			case T_ML_COMMENT: #    /* and */   comments (PHP 4 only)
			case T_MOD_EQUAL: #     %=  assignment operators
			case T_MUL_EQUAL: #     *=  assignment operators
			case T_NS_C: #  __NAMESPACE__   namespaces. Also defined as T_NAMESPACE (available since PHP 5.3.0)
			case T_NUM_STRING: #    "$a[0]"     numeric array index inside string
			case T_OBJECT_CAST: #   (object)    type-casting
			case T_OLD_FUNCTION: #  old_function    (PHP 4 Only)
			case T_OPEN_TAG_WITH_ECHO: #    <?= or <%=  escaping from HTML
			case T_OR_EQUAL: #  |=  assignment operators
			case T_PLUS_EQUAL: #    +=  assignment operators
			case T_PRIVATE: #   private     classes and objects (available since PHP 5.0.0)
			case T_PUBLIC: #    public  classes and objects (available since PHP 5.0.0)
			case T_PROTECTED: #     protected   classes and objects (available since PHP 5.0.0)
			case T_REQUIRE: #   require()   require()
			case T_REQUIRE_ONCE: #  require_once()  require_once()
			case T_RETURN: #    return  returning values
			case T_SL: #    <<  bitwise operators
			case T_SL_EQUAL: #  <<=     assignment operators
			case T_SR: #    >>  bitwise operators
			case T_SR_EQUAL: #  >>=     assignment operators
			case T_START_HEREDOC: #     <<<     heredoc syntax
			case T_STATIC: #    static  variable scope
			case T_STRING: #    "$a[a]"     string array index inside string
			case T_STRING_CAST: #   (string)    type-casting
			case T_STRING_VARNAME: #    "${a    complex variable parsed syntax
			case T_SWITCH: #    switch  switch
			case T_THROW: #     throw   Exceptions (available since PHP 5.0.0)
			case T_TRY: #   try     Exceptions (available since PHP 5.0.0)
			case T_UNSET: #     unset()     unset()
			case T_UNSET_CAST: #    (unset)     type-casting (available since PHP 5.0.0)
			case T_USE: #   use     namespaces (available since PHP 5.3.0)
			case T_WHITESPACE: #    \t \r\n
			case T_XOR_EQUAL: #     ^=  assignment operators
				return $t[VALUE];

			default:
				throw new Exception("unknown token: " . token_name($t[TTYPE]));
		}
	}

	public function parse_all($T) {
		$out = '';

		while(count($T)) {
			$out .= $this->parse($T);
		}
		return $out;
	}

	public function convert($tag, $code, $file) {

		$objName = preg_replace('/\W/', '_', $file);
        $date = date('c');

		echo <<<EOF
// generated by $tag on $date

import php._;
import scala.Predef.{ any2ArrowAssoc => _ }

object $objName extends php.script {
  override def include {
EOF;

		$T = token_get_all($code);
        echo $this->scan_globals($T);
		echo $this->parse_all($T);
		echo "  }\n}";
	}

	function __construct() {
		define(TTYPE, 0);
		define(VALUE, 1);
	}

	function dump($tokens) { // debugging function
		foreach($tokens as $c) {
			if(is_array($c)) {
				$disp = preg_replace("/[\r\n]+/", "\\n", $c[1]);
				print(token_name($c[0]) . ": '" . $disp . "'\n");
			}
			else {
				print("$c\n");
			}
		}
	}
}
?>

