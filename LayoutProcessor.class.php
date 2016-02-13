<?php

require_once('Indentation.class.php');

/**Layout processor

Processing templates called "layouts" using an extensibe script language called Prefix. 
 
@version 1.0
@license LGPL
@author Roger Baklund roger@baklund.no
 
Version history:

- 1.0 2016-02-10 (initial version)

# TODO:
- improve parameter error handling
- Error resume or similar
- !continue <num>
  
 */
abstract class LayoutProcessor {
  const PARAM_PLACEHOLDER = '$$',
    MAX_RECURSION_DEPTH = 255,
    ERR_HTML = 1,
    ERR_TEXT = 2,
    ERR_LOG = 4,
    ERR_EXIT = 8,
    ERR_MSG_INTRO = 'Layout processing error: ';
  static $error_mode = 1;
  static $logger = false;
  static $layouts = array();
  static $context = NULL;
  static $error_exit = false;
  static $break_counter = 0;
  static $continue_loop = false;
  static $return = false;
  static $scope = array();
  static $prefix = array(
    '#' => 'comment',
    '!' => 'command',
    '<' => 'markup',
    '=' => 'define_layout',
    '$' => 'assignment',
    '"' => 'eval_string',
    "'" => 'literal'
  );
  static $aliases = array('elif' => 'elseif','foreach'=>'loop');
  static $custom_commands = array();
  static $custom_transform_types = array();
  
  static function set_logger($logger) {
    if(!is_callable($logger)) return false;
    self::$logger = $logger;
    return true;
  }
  static function define_prefix($prefix,$callback) {
    if(!strlen($prefix) == 1) return false; 
    self::$prefix[$prefix] = $callback; 
    return true;
  }
  static function define_command($cmd,$callback) {
    if(!is_callable($callback)) return false;
    self::$custom_commands[$cmd] = $callback; 
    return true;
  }
  static function define_command_alias($alias,$aliased_command) {
    self::$aliases[$alias] = $aliased_command;
  }
  static function add_transform($name,$callback) {
    $name = strtolower($name);
    if(!is_callable($callback)) return false;
    self::$custom_transform_types[$name] = $callback;
    return true;
  }
  static function on_error($mode) {
    if(!is_numeric($mode)) return false;
    self::$error_mode = $mode;
    return true;
  }
  static function error($msg){
    $scope = & self::current_scope();
    $html_mode = self::$error_mode & self::ERR_HTML;
    $text_mode = self::$error_mode & self::ERR_TEXT;
    if(self::$error_mode & self::ERR_EXIT)
      self::$error_exit = true;
    $context = 
      (self::$context ? self::$context.' in ' : '').
      ($scope['layout_name'] ? $scope['layout_name'].' ':'').
      ($scope['line_no'] ? 'line '.$scope['line_no'] : '');
    if(self::$error_mode & self::ERR_LOG && self::$logger) 
      call_user_func(self::$logger,$context,$msg);
    if(!$html_mode && !$text_mode)
      return '';
    return ($html_mode ? '<p><code>' : '* ').
      static::ERR_MSG_INTRO.$context.':'.
      ($html_mode ? '</code>' : '').' '.
      ($html_mode ? htmlentities($msg) : $msg).
      ($html_mode ? '</p>' : '')."\n";
  }
  static function load($layout_name) {
    self::error('load() is not implemented');
    # This should return an array with keys: name, parent, id, content
    # 'content' must contain the actual layout, the others are only
    # used for context in error messages. See get() method.
  }
  static function get($layout_name) {
    if(!isset(self::$layouts[$layout_name])) {
      $layout_item = static::load($layout_name);
      if(!$layout_item) return false;
      self::$layouts[$layout_name] = $layout_item;
      self::$context = $layout_item['name'].' #'.$layout_item['parent'].'/'.$layout_item['id'];
    }
    return self::$layouts[$layout_name]['content'];
  }
  static function & current_scope() {
    return self::$scope[count(self::$scope)-1];
  }
  static function & parent_scope() {
    return self::$scope[count(self::$scope)-2];
  }
  static function & find_scope($layout_name='',$cmd='') {
    $i = 1;
    while($i<count(self::$scope) && 
      (!$layout_name||self::$scope[count(self::$scope)-$i]['layout_name'] != $layout_name) &&
      (!$cmd||self::$scope[count(self::$scope)-$i]['cmd'] != $cmd)) $i++;
    if(($layout_name && self::$scope[count(self::$scope)-$i]['layout_name'] != $layout_name) ||
       ($cmd && self::$scope[count(self::$scope)-$i]['cmd'] != $cmd)) return NULL;
    return self::$scope[count(self::$scope)-$i];
  }
  static function run_layout($layout_name,$param='') {
    $layout_script = self::get($layout_name);
    return $layout_script ? self::run_script($layout_script,$param,$layout_name) : false;
  }
  static function run_script($layout_script,$param='',$layout_name='[inline]') {    
    $lines = Indentation::blocks($layout_script);
    $line_no_offset = 0;
    $output = array();
    $scope = array(
      'layout_name' => $layout_name,
      'statement_type'=>'',
      'line_no' => 0,
      'vars' => array('_param'=>$param),
      'if_state' => NULL,
    );
    if(self::$scope && isset(self::$scope[count(self::$scope)-1]['cmd']) && 
       in_array(self::$scope[count(self::$scope)-1]['cmd'],
                array('if','elseif','else','loop','while'))) {
      $scope['vars'] = & self::$scope[count(self::$scope)-1]['vars'];
      $scope['layout_name'] = self::$scope[count(self::$scope)-1]['layout_name'];
      $line_no_offset = self::$scope[count(self::$scope)-1]['line_no'];
    }
    if(count(self::$scope) >= static::MAX_RECURSION_DEPTH) 
      return self::error('Recursion error when calling '.$layout_name);
    self::$scope[] = & $scope;
    foreach($lines as $linedata) {
      list($scope['line_no'],$line) = $linedata;
      $scope['line_no'] += $line_no_offset;
      if(in_array($prefix = substr($line,0,1),array_keys(self::$prefix))) {
        $stmt = substr($line,1); # remove prefix        
        $scope['statement_type'] = $callback = self::$prefix[$prefix];
        if(!is_array($callback))
          $callback = array(__CLASS__,$scope['statement_type']);
        else 
          $scope['statement_type'] = $callback[1];
        $output[] = forward_static_call($callback,$stmt);        
      } else {
        @list($layout_name,$param) = explode(':',$line,2);
        $scope['statement_type'] = 'layout';
        $layout_script = self::get($layout_name);
        if($layout_script === false) {
          if(strlen($layout_name) > 40)
            $layout_name = htmlentities(substr($layout_name,0,40)).'...';
          $output[] = self::error('Undefined layout "'.$layout_name.'"');
        } else {
          $layout_script = str_replace(static::PARAM_PLACEHOLDER,$param,$layout_script);
          $param = Indentation::unindent($param);
          $output[] = self::run_script($layout_script,$param,$layout_name); # ! recursive
        }
      }
      if($scope['statement_type'] == 'command') {
        $scope['prev_cmd'] = $scope['cmd'];
      } elseif($scope['statement_type'] == 'comment') {}
      else unset($scope['cmd'],$scope['prev_cmd']);
      if(self::$error_exit) break;
      if(self::$continue_loop) break;
      if(self::$break_counter) break;
      if(self::$return) {
        if(self::$return != $scope['layout_name']) 
          self::$return = false;
        else break;
      }
    }
    array_pop(self::$scope);
    return implode('',$output);
  }
  # 
  private static function split_on_optional_char($haystack,$char=":") {
    $WS = " \r\n\t";
    $str = strtok($haystack,$WS.$char);
    return array($str,ltrim(substr($haystack,strlen($str)),$WS.$char));
  }
  private static function split_on_colonLF($str) {
    return preg_split('/:\n/',$str,2);
  }
  # prefix handlers
  private static function define_layout($stmt) {
    @list($layout_name,$layout_script) = array_map('trim',explode(':',$stmt,2));
    $scope = & self::current_scope();
    self::$layouts[$layout_name] = array(
      'content'=>Indentation::unindent($layout_script),
      'parent'=>$scope['layout_name'],
      'id'=>$scope['line_no']
      );
    return '';
  }
  private static function assignment($stmt) {
    # Supported syntax:
    # $var = ...
    # $var .= ...
    # $var += ...
    # $var -= ...
    # $var *= ...
    # $var /= ...
    # $var[] = ...
    # $arr[$idx] = ... 
    # $str[$idx] = ...
    # $arr[$key] = ...
    # $obj->prop = ... 
    # $var++
    # $var--
    ## Executes but does NOT output return values
    # $func()
    # $obj->method()
    #
    ##### NOT supported:
    # $var = & ...     !!workaround: $var = $dummy = & ...
    # $$varname = ...  !!workaround: $dummy = $$varname = ...
    # ${$varname} = ...!!workaround: $dummy = ${$varname} = ...
    preg_match('/^[a-z_][a-z0-9_]*/i',$stmt,$m);
    if(!$m) return self::error('bad assignment, identifier expected');
    $scope = & self::current_scope();
    if(!isset($scope['vars'][ $m[0] ]))
      $scope['vars'][ $m[0] ] = NULL;
    @trigger_error('');
    $res = self::eval_expr("\$$stmt");
    if($res === false) {      
      $e = error_get_last();
      if($e['message'] > '')
        return self::error($e['message']);
    }
    return '';
  }
  private static function markup($stmt) {
    return '<'.$stmt; # < was removed in run_script()
  }
  private static function literal($stmt) {
    return $stmt;
  }
  private static function comment($stmt) {
    return '';
  }
  private static function command($stmt) {
    list($cmd,$param) = self::split_on_optional_char($stmt);
    $cmd = strtolower($cmd);
    foreach(self::$aliases as $alias => $aliased_command)
      if($cmd == $alias) $cmd = $aliased_command;
    $scope = & self::current_scope();
    $scope['cmd'] = $cmd;
    switch ($cmd) {
      case 'php':
        return self::eval_php($param);
      case 'if':
        list($expr,$code) = self::split_on_colonLF($param);
        $scope['if_state'] = true;
        if(self::eval_expr($expr))
          return self::run_script(Indentation::unindent($code));
        else
          $scope['if_state'] = false;
        break;
      case 'elseif':     
        if(!isset($scope['prev_cmd']) || 
           !in_array($scope['prev_cmd'],array('if','elseif')))
           return self::error('Illegal syntax, !elseif only allowed after !if or !elseif');
        if($scope['if_state'] === true) 
          return '';
        list($expr,$code) = self::split_on_colonLF($param);
        if(self::eval_expr($expr)) {
          $scope['if_state'] = true;
          return self::run_script(Indentation::unindent($code));
        } else $scope['if_state'] = false;
        break;
      case 'else':        
        if(!isset($scope['prev_cmd']) || 
           !in_array($scope['prev_cmd'],array('if','elseif')))
           return self::error('Illegal syntax, !else only allowed after !if or !elseif');
        if($scope['if_state'] === false) 
          return self::run_script(Indentation::unindent($param));
        break;
      case 'loop':
        list($expr,$code) = self::split_on_colonLF($param);
        if(!preg_match('/^(.+)\s+as\s+\$([a-z_][a-z0-9_]*)(\s*=>\s*\$([a-z_][a-z0-9_]*))?\s*$/i',$expr,$m))
          return self::error('Invalid syntax for !loop');
        $expr = trim($m[1]);
        if($expr && $expr[0] == '[' && substr($expr,-1) == ']')
          $expr = 'array('.trim(substr($expr,1,-1)).')';
        $arr = self::eval_expr($expr);
        $varname = isset($m[4]) ? $m[4] : $m[2];
        $keyname = isset($m[4]) ? $m[2] : NULL;
        if(!is_array($arr)) 
          return self::error('An array is required for !loop, got '.gettype($arr));
        $code = Indentation::unindent($code);
        $loop_output = array();
        foreach($arr as $key => $item) {
          if(!is_null($keyname)) 
            $scope['vars'][$keyname] = $key;
          $scope['vars'][$varname] = $item;
          $loop_output[] = self::run_script($code);
          if(self::$break_counter>0) {
            self::$break_counter--;
            break;
          }
          if(self::$continue_loop) 
            self::$continue_loop = false;
        }
        return implode('',$loop_output);
      case 'while':
        list($expr,$code) = self::split_on_colonLF($param);
        $code = Indentation::unindent($code);
        $loop_output = array();
        while(self::eval_expr($expr)) {
          $loop_output[] = self::run_script($code);
          if(self::$break_counter>0) {
            self::$break_counter--;
            break;
          }
          if(self::$continue_loop) 
            self::$continue_loop = false;
        }
        return implode('',$loop_output);
      case 'break':
        self::$break_counter = $param && is_numeric($param) ? (int) $param : 1;
        break;
      case 'continue':
        self::$continue_loop = true;
        break;
      case 'return':
        self::$return = $scope['layout_name'];
        break;
      case 'scope':
        list($type,$vars) = self::split_on_optional_char($param);
        if(!$vars) 
          return self::error('Bad syntax for scope command, variable names required');
        $type = strtolower($type);
        switch($type) {
          case 'from':
            list($layout_name,$vars) = self::split_on_optional_char($vars);
            if(!$vars) 
              return self::error('Bad syntax for scope command, variable names required');
            $fscope = & self::find_scope($layout_name);
            if(is_null($fscope))
              return self::error('Scope "'.$layout_name.'" was not found');
            foreach(array_map('trim',explode(',',$vars)) as $var) {
              $var = ltrim($var,'$');
              $scope['vars'][$var] = & $fscope['vars'][$var];
            }
            break;
          case 'caller':
          case 'parent':
            if(count(self::$scope) < 2) 
              return self::error('There is no parent scope!');
            $pscope = & self::parent_scope();
            foreach(array_map('trim',explode(',',$vars)) as $var) {
              $var = ltrim($var,'$');
              $scope['vars'][$var] = & $pscope['vars'][$var];
            }
            break;
          case 'global':
            foreach(array_map('trim',explode(',',$vars)) as $var) {
              $var = ltrim($var,'$');
              $scope['vars'][$var] = & $GLOBALS[$var];
            }
            break;
          default:
            return self::error('Invalid scope type "'.$type.'", expected "parent" or "global"');
        }
        break;
      case 'param':
        $tmp = self::param($param,$scope['vars']['_param']);
        # !chk for error
        $scope['vars']['_param'] = $tmp;
        break;
      default:
        if(in_array($cmd,array_keys(self::$custom_commands))) {
          $callback = self::$custom_commands[$cmd];
          return call_user_func($callback,$param);
        } else return self::error('Unknown command: "!'.$cmd.'"');
    }
    return '';
  }
  private static function param($paramdef,$param) {
    # !param <transforms> [<sep> [(<count>|<min>-<max>)] ] [:<definition>]
    $transform_types = array(
      'raw','string','expr','unhtml','urlify','upper','lower','ucfirst','ucwords');
    $separator_types = array(
      'colon'=>':', 'semicolon'=>';', 'comma'=>',', 'dot'=>'.', 'amp'=>'&', 'space'=>' ',
      'line'=>"\n", 'tab'=>"\t", 'pipe'=>'|', 'dash'=>'-', 'plus'=>'+', 'slash'=>'/');
    @list($ptype,$pdef) = array_map('trim',explode(':',$paramdef,2));
    $min_count = $max_count = false;
    if(strpos($ptype,'(') !== false) {  # param count
      list($pt1,$rest) = explode('(',$ptype,2);
      $ptype = rtrim($pt1);
      if(substr($rest,-1) != ')')
        return self::error('Bad syntax for !param, missing )');
      $pcount = substr($rest,0,-1);
      if(strpos($pcount,'-') !== false) { # range
        list($min_count,$max_count) = array_map('trim',explode('-',$pcount,2));
        $min_count = (int) $min_count;
        $max_count = (int) $max_count;
        if($max_count <= $min_count)
          return self::error('Error in !param, max count must be larger than min count! '."($min_count-$max_count)");
      } else {
        $min_count = (int) $pcount;
      }
    }
    $ptype = strtolower($ptype);  # not case sensitive
    $ptypes = array_filter(array_map('trim',explode(' ',$ptype)));
    $sep_type = false;
    foreach($ptypes as $pt) {     # validate
      if(in_array($pt,$transform_types)) continue;
      if(in_array($pt,array_keys(self::$custom_transform_types))) continue;
      if(in_array($pt,array_keys($separator_types))) {
        if($sep_type) 
          return self::error('Bad parameter type for !param, only one separator allowed');
        $sep_type = $pt;
        continue;
      }
      return self::error('Unknown parameter type: '.$pt);
    }
    $names = array();  # find variable names provided in definition
    if($pdef) {
      if(strpos($pdef,"\n")!==false) {
        $p_store = array();
        $paramlist = array_map('trim',explode("\n",$pdef));
        foreach($paramlist as $p) {
          if(!$p || $p[0] == '#') continue; # blank/comment
          @list($name,$def) = explode(':',$p,2);
          $p_store[$name] = $def;
        }
        $names = array_keys($p_store);
      } else
        $names = array_map('trim',explode(',',$pdef));
    }
    if($min_count) {
      if($names) {
        if($max_count) { # range
          if(count($names) != $max_count)
            return self::error('Variable count mismatch in !param, found '.
                            count($names)." variables, but expected up to $max_count");
        } else { # exact count
          if(count($names)!=$min_count)
            return self::error('Variable count mismatch in !param, found '.
                            count($names)." variables, but expected $min_count");
        }
      }
    } else # count not provided in definition, set from name count
      $min_count = count($names);
    foreach($ptypes as $pt) {
      if(in_array($pt,$transform_types)) {
        switch($pt) {
          case 'raw': break; # no change
          case 'string': $param = self::eval_string($param,true); break;
          case 'expr': $param = self::eval_expr($param,true); break;
          case 'unhtml': $param = htmlspecialchars($param); break;
          case 'urlify': $param = urlencode($param); break;
          case 'upper': $param = strtoupper($param); break;
          case 'lower': $param = strtolower($param); break;
          case 'ucfirst': $param = ucfirst($param); break;
          case 'ucwords': $param = ucwords($param); break;
        }
      } elseif(in_array($pt,array_keys(self::$custom_transform_types))) {
        $trans = self::$custom_transform_types[$pt];
        $param = call_user_func($trans,$param);
      }
    }
    $sep = false; 
    if($sep_type && isset($separator_types[$sep_type]))
      $sep = $separator_types[$sep_type];
    if($sep) {
      if($min_count) {
        if($max_count) { # range
          $param = array_map('trim',explode($sep,$param,$max_count));
          if((count($param) < $min_count) || (count($param) > $max_count))
            return self::error('Bad parameter count, got '.count($param).', expected '.$min_count.'-'.$max_count); 
        } else { # exact count
          $param = array_map('trim',explode($sep,$param,$min_count));
          if(count($param) != $min_count)
            return self::error($min_count.' parameters was required, got '.count($param));
        }        
      } else { # no restrictions
        $param = array_map('trim',explode($sep,$param));
      }
    } else if($names) {
      if(count($names) == 1) {
        $param = array($param);
      } else
        return self::error('A separator specification is needed for the parameters');
    }
    if($min_count) { # !! is this needed? duplicated? see above
      if($max_count) {  # range
        if((count($param) < $min_count) || (count($param) > $max_count))
          return self::error('Bad parameter count, got '.count($param).', expected '.$min_count.'-'.$max_count);
      } else { # exact count
        if(count($param) != $min_count)
          return self::error('Bad parameter count, got '.count($param).', expected '.$min_count);
      }
    }
    if($names) {
      $param_copy = $param;
      $scope = & self::current_scope();
      if(isset($p_store)) {
        foreach($p_store as $name=>$def) {
          if($name[0]=='$') $name = substr($name,1);
          $scope['vars'][$name] = self::param($def,array_shift($param_copy)); # recursive
        }
      } else {
        foreach($names as $name) {
          if($name[0]=='$') $name = substr($name,1);
          $val = array_shift($param_copy);
          $scope['vars'][$name] = $val;
        }
      }
    }
    return $param;
  }
  private static function eval_string($__stmt,$__parent=false) {
    if($__parent && count(self::$scope) > 1) $__scope = & self::parent_scope();
    else $__scope = & self::current_scope();
    extract($__scope['vars'],EXTR_SKIP|EXTR_REFS);
    $__stmt = addcslashes($__stmt,'"');
    @trigger_error('');
    $res = @eval('return "'.$__stmt.'";');
    if($res === false) {
      $e = error_get_last();
      if($e && $e['message'] > '')
        return self::error($e['message']);
    }
    return $res;
  }
  private static function eval_expr($__code,$__parent=false) {
    if($__parent && count(self::$scope) > 1) $__scope = & self::parent_scope();
    else $__scope = & self::current_scope();
    extract($__scope['vars'],EXTR_SKIP|EXTR_REFS);
    return @eval("return $__code;");    
  }
  private static function eval_php($__code) {
    $__scope = & self::current_scope();
    extract($__scope['vars'],EXTR_SKIP|EXTR_REFS);
    ob_start();
    eval("$__code;");
    return ob_get_clean();
  }
}
