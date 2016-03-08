<?php

require_once('Indentation.class.php');

/**Layout processor

Processing templates called "layouts" using an extensibe script language called Prefix. 
 
@version 1.0.1
@license LGPL
@author Roger Baklund roger@baklund.no
 
Version history:

- 1.0.1 2016-03-07 improved aliases, name patterns, ERR_NO_LOG const, $layout_name_cap,
                   changed $scope['statement_type'], scope stack dump on crash, 
                   no private methods, sharing more with subclass, resolve_statement_type(),
                   improved error handling, !php return
- 1.0 2016-02-10 (initial version)

# TODO (maybe):
- !continue <num>
- !return <num>|to <layout>|with <variables>
- !scope static
- $scope['separator']
- !param blocks

 */
 
abstract class LayoutProcessor {
  const PARAM_PLACEHOLDER = '$$',
    MAX_RECURSION_DEPTH = 255,
    ERR_SILENT = 0,
    ERR_TEXT = 1,
    ERR_HTML = 2,
    ERR_NO_LOG = 0,
    ERR_LOG = 4,
    ERR_CONTINUE = 0,
    ERR_RESUME = 8,
    ERR_EXIT = 16,
    ERR_CANCEL = 32,
    ERR_DIE = 64,
    ERR_MSG_INTRO = 'Layout processing error: ';
  static $error_mode = 1;  # default error mode: TEXT|NO_LOG|CONTINUE
  static $layout_name_cap = 40;  # for error messages 
  static $shutdown_function_registered = false;
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
    '"' => 'string_output',
    "'" => 'literal'
  );
  static $aliases = array('elif' => 'elseif','foreach'=>'loop');
  static $custom_commands = array();
  static $custom_transform_types = array();
  static $name_patterns = array(
    'layout'=>'/^[a-z_][^:]*$/i',
    'command'=>'/^[a-z][a-z0-9_-]*$/i',
    'transform'=>'/^[a-z][a-z0-9_-]*$/i');
  
  static function set_logger($logger) {
    if(!is_callable($logger)) return false;
    static::$logger = $logger;
    return true;
  }
  static function define_prefix($prefix,$callback) {
    if(strlen($prefix) != 1) return false; 
    self::$prefix[$prefix] = is_string($callback) ? array($callback) : $callback; 
    return true;
  }
  static function define_command($cmd,$callback) {
    if(!is_callable($callback)) return false;
    if(!static::is_valid_name('command',$cmd))
      return false;
    self::$custom_commands[$cmd] = $callback; 
    return true;
  }
  static function define_command_alias($alias,$aliased_command) {
    if(!static::is_valid_name('command',$alias))
      return false;
    self::$aliases[$alias] = $aliased_command;
    return true;
  }
  static function add_transform($name,$callback) {
    $name = strtolower($name);
    if(!static::is_valid_name('transform',$name))
      return false;
    if(!is_callable($callback)) return false;
    self::$custom_transform_types[$name] = $callback;
    return true;
  }
  static function name_pattern($nametype,$pattern=false) {
    if(!$pattern) 
      return isset(static::$name_patterns[$nametype]) ? 
                   static::$name_patterns[$nametype] : false;
    else static::$name_patterns[$nametype] = $pattern;
  }
  static function is_valid_name($nametype,$name) {
    if(!isset(static::$name_patterns[$nametype])) 
      return false;
    return preg_match(static::$name_patterns[$nametype],$name);
  }  
  static function on_error($mode) {
    if(!is_numeric($mode)) return false;
    static::$error_mode = $mode;
    self::$error_exit = false; # reset current error state
    return true;
  }
  static function error($msg){
    $scope = & static::current_scope();
    $html_mode = static::$error_mode & self::ERR_HTML;
    $text_mode = static::$error_mode & self::ERR_TEXT;
    if(static::$error_mode & (self::ERR_EXIT|self::ERR_CANCEL|self::ERR_RESUME))
      self::$error_exit = $scope['layout_name'];
    $context = 
      (self::$context ? self::$context.' in ' : '').
      (($scope['layout_name']>'' && 
        $scope['layout_name'].' ' != substr(self::$context,0,strlen($scope['layout_name']) + 1)) 
          ? $scope['layout_name'].' ' : ''). # avoids repeating layout name
      ($scope['line_no'] ? 'line '.$scope['line_no'] : '');
    $logger_output = false;
    if(static::$error_mode & self::ERR_LOG && static::$logger) 
      $logger_output = call_user_func(static::$logger,$context,$msg);
    if(static::$error_mode & self::ERR_DIE) 
      die($logger_output ? $logger_output : 
          '* '.static::ERR_MSG_INTRO.$context.': '.$msg);
    if(!$html_mode && !$text_mode)
      return $logger_output ? $logger_output : '';
    return ($html_mode ? '<p><code>' : '* ').
      static::ERR_MSG_INTRO.$context.':'.
      ($html_mode ? '</code>' : '').' '.
      ($html_mode ? htmlentities($msg) : $msg).
      ($html_mode ? '</p>' : '')."\n";
  }
  static function load($layout_name) {
    static::error('load() is not implemented');
    # !! Override this method!
    # This should return an array with keys: content, name, parent, id
    # 'content' must contain the actual layout, which can be an empty string, but it can 
    # not be NULL. The others are optional and only used for context in error messages. 
  }
  static function get($layout_name) {
    if(!isset(self::$layouts[$layout_name])) {
      $layout_item = static::load($layout_name);
      if(!$layout_item ) 
        return false; 
      if(!is_array($layout_item) || !isset($layout_item['content'])) {
        return false; # ! error in load()
      }
      self::$layouts[$layout_name] = $layout_item;
    } else
      $layout_item = self::$layouts[$layout_name];
    return $layout_item['content'];
  }
  static function & current_scope() {
    return self::$scope[count(self::$scope)-1];
  }
  static function & parent_scope() {
    return self::$scope[count(self::$scope)-2];
  }
  static function & find_scope($layout_name='',$cmd='') {
    $i = 1;
    $null = NULL;
    while($i < count(self::$scope) 
      && (!$layout_name||self::$scope[count(self::$scope)-$i]['layout_name'] != $layout_name) 
      && (!$cmd||self::$scope[count(self::$scope)-$i]['cmd'] != $cmd)) $i++;
    if(  ($layout_name && self::$scope[count(self::$scope)-$i]['layout_name'] != $layout_name) 
      || ($cmd && self::$scope[count(self::$scope)-$i]['cmd'] != $cmd)) return $null;
    return self::$scope[count(self::$scope)-$i];
  }
  static function run_layout($layout_name,$param='') {
    $layout_script = static::get($layout_name);
    return $layout_script ? static::run_script($layout_script,$param,$layout_name) : false;
  }
  static function run_script($layout_script,$param='',$layout_name='[inline]') {
    if(!self::$shutdown_function_registered) {
      register_shutdown_function(function() {
  	    if(($e=error_get_last()) && ($e['message']>'')) {
          echo LayoutProcessor::error($e['message'].
            "\n* Script crashed with error code ".$e['type'].': '.$e['file'].' line '.$e['line'].
            "\n* Scope stack dump:".print_r(LayoutProcessor::$scope,true));
        }
      });
      self::$shutdown_function_registered = true;
    }
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
    if(self::$scope 
       && isset(self::$scope[count(self::$scope)-1]['statement_type'])
       && self::$scope[count(self::$scope)-1]['statement_type'] == 'method command'
       && isset(self::$scope[count(self::$scope)-1]['cmd']) 
       && in_array(self::$scope[count(self::$scope)-1]['cmd'],
                array('if','elseif','else','loop','while'))) {
      $scope['vars'] = & self::$scope[count(self::$scope)-1]['vars'];
      $scope['layout_name'] = self::$scope[count(self::$scope)-1]['layout_name'];
      $line_no_offset = self::$scope[count(self::$scope)-1]['line_no'];
    }
    if(count(self::$scope) >= static::MAX_RECURSION_DEPTH) 
      return static::error('Recursion error when calling '.$layout_name);
    self::$scope[] = & $scope;
    foreach($lines as $linedata) {
      list($scope['line_no'],$line) = $linedata;
      $scope['line_no'] += $line_no_offset;
      if(in_array($prefix = substr($line,0,1),array_keys(self::$prefix))) {
        list($statement_type,$callback) = static::resolve_statement_type($prefix);
        $scope['statement_type'] = $statement_type;
        if($statement_type != 'invalid') {
          $stmt = substr($line,1); # remove prefix
          $output[] = forward_static_call($callback,$stmt);
        } else 
          $output[] = static::error("Prefix $prefix is not callable!");
      } else {
        list($layout_name,$param) = (strpos($line,':') !== false) ? explode(':',$line,2) : array($line,'');
        $scope['statement_type'] = 'layout';
        $layout_script = static::get($layout_name);
        if($layout_script === false) {
          if(strlen($layout_name) > static::$layout_name_cap) # probably indentation error
            $layout_name = substr($layout_name,0,static::$layout_name_cap).'...';
          $output[] = static::error("Undefined layout '$layout_name'");
        } else {
          $layout_item = self::$layouts[$layout_name];
          $remember_context = self::$context;
          self::$context = 
            (isset($layout_item['name']) ? $layout_item['name'] : $layout_name).
            (isset($layout_item['parent']) ? ' #'.$layout_item['parent']:'').
            (isset($layout_item['id']) ? '/'.$layout_item['id']:'');
          $layout_script = str_replace(static::PARAM_PLACEHOLDER,$param,$layout_script);
          $param = Indentation::unindent($param);
          $output[] = static::run_script($layout_script,$param,$layout_name); # ! recursive
          self::$context = $remember_context;
        }
      }
      if($scope['statement_type'] == 'method command') {
        $scope['prev_cmd'] = $scope['cmd'];
      } elseif($scope['statement_type'] == 'method comment') {}
      else unset($scope['cmd'],$scope['prev_cmd']);
      if(self::$error_exit) {
        if(static::$error_mode & self::ERR_CANCEL) 
          return array_pop($output);
        if(static::$error_mode & self::ERR_RESUME) {
          if(self::$error_exit != $scope['layout_name'])
            self::$error_exit = false;
          else break;
        } else break;
      } 
      if(self::$continue_loop || self::$break_counter) break;
      if(self::$return) {
        if(self::$return != $scope['layout_name']) 
          self::$return = false;
        else break;
      }
    }
    array_pop(self::$scope);
    return implode('',$output);  
  }
  static function resolve_statement_type($prefix) {
    $callback = self::$prefix[$prefix];
    $statement_type = 'invalid';
    if(is_array($callback) && count($callback)==1) {
      $callback = $callback[0];
      $statement_type = "function $callback";
    } elseif(is_array($callback) && count($callback)==2) {
      if(is_object($callback[0]))
        $statement_type = 'object '.get_class($callback[0]).'::'.$callback[1];
      else 
        $statement_type = 'static '.$callback[0].'::'.$callback[1];
    } elseif(is_string($callback)) {
      $callback = array(get_called_class(),$callback);
      $statement_type = "method $callback[1]";
    } elseif(is_a($callback,'Closure')) {
      $statement_type = "closure";
    } elseif(is_callable($callback))
      $statement_type = "callable";
    return array($statement_type,$callback);
  }
  # 
  static function split_on_optional_char($haystack,$char=":") {
    $WS = " \r\n\t";
    $str = strtok($haystack,$WS.$char);
    return array($str,ltrim(substr($haystack,strlen($str)),$WS.$char));
  }
  static function split_on_colonLF($str) {
    return preg_split('/:\n/',$str,2);
  }
  # prefix handlers
  static function define_layout($stmt) {
    @list($layout_name,$layout_script) = explode(':',$stmt,2);
    $layout_name = trim($layout_name);
    if(!static::is_valid_name('layout',$layout_name)) 
      return static::error("'$layout_name' is not a valid name for a layout");
    $scope = & static::current_scope();
    self::$layouts[$layout_name] = array(
      'content'=>Indentation::unindent($layout_script),
      'parent'=>$scope['layout_name'],
      'id'=>$scope['line_no']
      );
    return '';
  }
  static function assignment($stmt) {
    ##### NOT supported:
    # $var = & ...  
    # $$varname = ...  Fails because $$ is replaced with $param in run_script()
    # ${$varname} = ...!!workaround: $dummy = ${$varname} = ...
    preg_match('/^[a-z_][a-z0-9_]*/i',$stmt,$m);
    if(!$m) return static::error('bad assignment, identifier expected');
    $scope = & static::current_scope();
    if(!isset($scope['vars'][ $m[0] ]))
      $scope['vars'][ $m[0] ] = NULL;
    list($status,$return_value,$output) = static::eval_expr('assignment',"\$$stmt");
    if($status != 'ok')
      return static::error($return_value); 
    return '';
  }
  static function markup($stmt) {
    return '<'.$stmt; # < was removed in run_script()
  }  
  static function string_output($stmt) {
    list($status,$return_value,$output) = static::eval_string('string output',$stmt);
    if($status != 'ok')
      return static::error($return_value); 
    return $return_value;
  }
  static function literal($stmt) {
    return $stmt;
  }
  static function comment($stmt) {
    return '';
  }
  static function resolve_alias($cmd,$param) {
    $cmd = strtolower($cmd);
    $count = 255;
    $changed = true;
    while($changed && $count) {
      $changed = false;
      foreach(self::$aliases as $alias => $aliased_command) {
        if($cmd == $alias) {
          if(strpos($aliased_command,' ') !== false) {
            list($cmd,$insert) = explode(' ',$aliased_command,2);
            if(strpos($insert,'%s') !== false)
              $param = sprintf($insert,$param);
            else
              $param = $insert.' '.$param;
          } else $cmd = $aliased_command;
          $changed = true;
          break;
        }
      }
      $count--;
    }
    return array((bool)($count > 0),$cmd,$param);
  }
  static function command($stmt) {
    # !<cmd>[:]<param>
    list($cmd,$param) = self::split_on_optional_char($stmt);
    $orig_cmd = $cmd;
    list($accepted,$cmd,$param) = static::resolve_alias($cmd,$param);
    if(!$accepted) return static::error('Could not resolve alias for !'.$orig_cmd.', possibly circular definition');
    $scope = & static::current_scope();
    $scope['cmd'] = $cmd;
    $ColonLF_Exptected = 'colon followed by linefeed and indented code was expected';
    switch ($cmd) {
      case 'php':
        list($status,$return_value,$output) = static::eval_php('!php',$param);
        if($status != 'ok')
          return static::error($return_value); # $output is ignored
        if($return_value === false) # cancel output
          return '';
        elseif(is_null($return_value))
          return $output;
        else return $return_value;  # $output is ignored
      case 'if':
        @list($expr,$code) = self::split_on_colonLF($param);
        if(is_null($code))
          return static::error('Bad syntax for !if, '.$ColonLF_Exptected);
        list($status,$return_value,$output) = static::eval_expr('!if expression',$expr);
        if($status != 'ok')
          return static::error($return_value); 
        $expr_result = $return_value; 
        if($expr_result) {
          $scope['if_state'] = true;
          return static::run_script(Indentation::unindent("\n".$code),'','[!if block]');
        } else
          $scope['if_state'] = false;
        break;
      case 'elseif':     
        if(!isset($scope['prev_cmd']) || 
           !in_array($scope['prev_cmd'],array('if','elseif')))
           return static::error('Bad syntax, !elseif only allowed after !if or !elseif');
        if($scope['if_state'] === true || 
           is_null($scope['if_state'])) # NULL: error in !if expression
          return '';
        @list($expr,$code) = self::split_on_colonLF($param);
        if(is_null($code))
          return static::error('Bad syntax for !elseif, '.$ColonLF_Exptected);
        list($status,$return_value,$output) = static::eval_expr('!elseif expression',$expr);
        if($status != 'ok')
          return static::error($return_value); 
        $expr_result = $return_value; 
        if($expr_result) {
          $scope['if_state'] = true;
          return static::run_script(Indentation::unindent("\n".$code),'','[!elseif block]');
        } else $scope['if_state'] = false;
        break;
      case 'else':        
        if(!isset($scope['prev_cmd']) || 
           !in_array($scope['prev_cmd'],array('if','elseif')))
           return static::error('Bad syntax, !else only allowed after !if or !elseif');
        if($scope['if_state'] === false) {
          # !! because leading WS is removed from $param
          @list($tmp,$param) = explode(':',$stmt,2);
          if(!$param || trim($tmp) != $orig_cmd) 
            return static::error('Bad syntax for !else, '.$ColonLF_Exptected);
          return static::run_script(Indentation::unindent($param),'','[!else block]');
        }
        break;
      case 'loop':
        @list($expr,$code) = self::split_on_colonLF($param);
        if(is_null($code))
          return static::error('Bad syntax for !loop, '.$ColonLF_Exptected);
        if(!preg_match('/^(.+)\s+as\s+\$([a-z_][a-z0-9_]*)(\s*=>\s*\$([a-z_][a-z0-9_]*))?\s*$/i',$expr,$m))
          return static::error('Invalid syntax for !loop');
        $expr = trim($m[1]);
        if($expr && $expr[0] == '[' && substr($expr,-1) == ']')
          $expr = 'array('.trim(substr($expr,1,-1)).')';
        list($status,$return_value,$output) = static::eval_expr('!loop expression',$expr);
        if($status != 'ok')
          return static::error($return_value); 
        $arr = $return_value;
        $varname = isset($m[4]) ? $m[4] : $m[2];
        $keyname = isset($m[4]) ? $m[2] : NULL;
        if(!is_array($arr) && !($arr instanceof Traversable)) 
          return static::error('Array or traversable is required for !loop, got '.gettype($arr));
        $code = Indentation::unindent("\n".$code);
        $loop_output = array();
        foreach($arr as $key => $item) {
          if(!is_null($keyname)) 
            $scope['vars'][$keyname] = $key;
          $scope['vars'][$varname] = $item;
          $loop_output[] = static::run_script($code,'','[!loop block]');
          if(self::$break_counter>0) {
            self::$break_counter--;
            break;
          }
          if(self::$continue_loop) 
            self::$continue_loop = false;
        }
        return implode('',$loop_output);
      case 'while':
        @list($expr,$code) = self::split_on_colonLF($param);
        if(is_null($code))
          return static::error('Bad syntax for !while, '.$ColonLF_Exptected);
        $code = Indentation::unindent("\n".$code);
        $loop_output = array();
        list($status,$return_value,$output) = static::eval_expr('!while expression',$expr);
        if($status != 'ok')
          return static::error($return_value);         
        while($return_value) {
          $loop_output[] = static::run_script($code,'','[!while block]');
          if(self::$break_counter>0) {
            self::$break_counter--;
            break;
          }
          if(self::$continue_loop) 
            self::$continue_loop = false;
          list($status,$return_value,$output) = static::eval_expr('!while expression',$expr);
          if($status != 'ok')
            return static::error($return_value); 
        }
        return implode('',$loop_output);
      case 'break':
        self::$break_counter = $param && is_numeric($param) ? (int) $param : 1;
        break;
      case 'continue':
        self::$continue_loop = true;
        break;
      case 'return':
        # !return [<num>|to <layout>|with <var-list>] 
        self::$return = $scope['layout_name'];
        break;
      case 'scope':
        list($type,$vars) = self::split_on_optional_char($param);
        if(!$vars) 
          return static::error('Bad syntax for !scope, variable name(s) required');
        $type = strtolower($type);
        switch($type) {
          case 'from':
            list($layout_name,$vars) = array_map('trim',explode(':',$vars,2));              
            if(!$vars) 
              return static::error('Bad syntax for !scope, variable name(s) required');
            $fscope = & static::find_scope($layout_name);
            if(is_null($fscope))
              return static::error("Scope '$layout_name' was not found");
            foreach(array_map('trim',explode(',',$vars)) as $var) {
              $var = ltrim($var,'$');
              $scope['vars'][$var] = & $fscope['vars'][$var];
            }
            break;
          #case 'static': # vars stored in $layouts[$layout_name]['vars']
          case 'caller':
          case 'parent':
            if($type == 'parent') {
              $layout_name = $scope['layout_name'];
              if(!isset(self::$layouts[$layout_name])) 
                return static::error("!scope parent: Did not find layout metadata for '$layout_name'");
              if(!isset(self::$layouts[$layout_name]['parent'])) 
                static::error("!scope parent: Did not find parent");
              $parent = self::$layouts[$layout_name]['parent'];
              $pscope = & static::find_scope($parent);
              if(!$pscope)
                return static::error("!scope parent: Did not find scope for '$parent'");              
            } else { # caller
              if(count(self::$scope) < 2) 
                return static::error('There is no caller scope!');
              $pscope = & static::parent_scope();
            }
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
            return static::error("Invalid scope type '$type', expected 'from', 'parent', 'caller' or 'global'");
        }
        break;
      case 'param':
        list($accepted,$res) = static::param($param,$scope['vars']['_param']);
        if(!$accepted)
          return static::error($res);
        $scope['vars']['_param'] = $res;
        break;
      default:
        if(in_array($cmd,array_keys(self::$custom_commands))) {
          $callback = self::$custom_commands[$cmd];
          return call_user_func($callback,$param);
        } else return static::error("Unknown command '!$cmd'");
    }
    return '';
  }
  static function param($paramdef,$param) {
    # !param [<transforms>] [<sep> [(<count>|<min>-<max>)] ] [:<definition>]
    #if(($paramtype=gettype($param))!='string')
    #  return array(false,"Error in !param, parameter is not a string ($paramtype)!");
    $transform_types = array('raw','string','expr','php','layout');
    $separator_types = array(
      'colon'=>':', 'semicolon'=>';', 'comma'=>',', 'dot'=>'.', 'amp'=>'&', 'space'=>' ',
      'line'=>"\n", 'tab'=>"\t", 'pipe'=>'|', 'dash'=>'-', 'plus'=>'+', 'slash'=>'/');
    if($paramdef && $paramdef[0]=='$')
      $paramdef = 'raw:'.$paramdef;
    @list($ptype,$pdef) = array_map('trim',explode(':',$paramdef,2));
    $min_count = $max_count = false;
    if(strpos($ptype,'(') !== false) {  # param count
      list($pt1,$rest) = explode('(',$ptype,2);
      $ptype = rtrim($pt1);
      if(substr($rest,-1) != ')')
        return array(false,'Bad syntax for !param, missing )');
      $pcount = substr($rest,0,-1);
      if(strpos($pcount,'-') !== false) { # range
        list($min_count,$max_count) = array_map('trim',explode('-',$pcount,2));
        $min_count = (int) $min_count;
        $max_count = (int) $max_count;
        if($max_count <= $min_count)
          return array(false,'Error in !param, max count must be larger than min count! '."($min_count-$max_count)");
      } else 
        $min_count = (int) $pcount;
    }
    $ptype = strtolower($ptype);  # not case sensitive
    $ptypes = array_filter(array_map('trim',explode(' ',$ptype)));
    $sep_type = false;
    foreach($ptypes as $pt) {     # validate
      if(in_array($pt,$transform_types)) continue;
      if(in_array($pt,array_keys(self::$custom_transform_types))) continue;
      if(in_array($pt,array_keys($separator_types))) {
        if($sep_type) 
          return array(false,"Error in !param, only one separator allowed, '$pt' conflicts with '$sep_type'");
        $sep_type = $pt;
        continue;
      }
      return array(false,"Unknown parameter type '$pt'");
    }
    $names = array();  # find variable names provided in <definition>
    if($pdef) {
      if(strpos($pdef,':') !== false) {
        $p_store = array();
        $paramlist = array_map('trim',explode("\n",$pdef));   # use Indentation::blocks() ?
        foreach($paramlist as $p) {
          if(!$p || $p[0] == '#') continue; # blank/comment     !! call static::comment() ?
          @list($name,$def) = explode(':',$p,2);
          $p_store[$name] = $def;
        }
        $names = array_keys($p_store);
      } else
        $names = array_map('trim',explode(',',$pdef));
    }
    if($min_count !== false) {
      if($names) {
        if($max_count) { # range
          if(count($names) != $max_count)
            return array(false,'Variable count mismatch in !param, found '.
                            count($names).' variable'.(count($names)==1?'':'s').", expected up to $max_count");
        } else { # exact count
          if(count($names)!=$min_count)
            return array(false,'Variable count mismatch in !param, found '.
                            count($names).' variable'.(count($names)==1?'':'s').", expected $min_count");
        }
      }
    } elseif($names && count($names)>1) # count not provided in definition, set from name count
      $min_count = count($names);
    foreach($ptypes as $pt) {
      if(in_array($pt,$transform_types)) {
        switch($pt) {
          case 'raw': break; # no change
          case 'string': 
            list($status,$return_value,$output) = static::eval_string('!param string',$param,true);
            if($status != 'ok')
              return array(false,$return_value); 
            $param = $return_value; 
            break;
          case 'expr': 
            list($status,$return_value,$output) = static::eval_expr('!param expr',$param,true);
            if($status != 'ok')
              return array(false,$return_value); 
            $param = $return_value;
            break;
          case 'php': 
            list($status,$return_value,$output) = static::eval_php('!param php',$param,true);
            if($status != 'ok')
              return array(false,$return_value); # $output is ignored
            if($return_value === false) # cancel 
              $param = '';
            elseif(is_null($return_value))
              $param = $output;
            else             
              $param = $return_value; # $output is ignored
            break;
          case 'layout': $param = static::run_script($param,'','[!param layout]'); break; # new
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
      if($min_count !== false) {
        if($max_count) { # range
          $param = array_map('trim',explode($sep,$param,$max_count));
          if((count($param) < $min_count) || (count($param) > $max_count))
            return array(false,'Bad parameter count, got '.count($param).', expected '.$min_count.'-'.$max_count); 
        } else { # exact count
          $param = array_map('trim',explode($sep,$param,$min_count));
          if(count($param) != $min_count)
            return array(false,$min_count.' parameters was required, got '.count($param));
        }        
      } else { # no restrictions
        $param = array_map('trim',explode($sep,$param));
      }
    } else if($names) {
      if(count($names) == 1) {
        $param = array($param);
      } else
        return array(false,'A separator specification is needed for the parameters');
    }
    if($min_count > 0) { # !! is this needed? duplicated? see above
      if($max_count) {  # range
        if((count($param) < $min_count) || (count($param) > $max_count))
          return array(false,'Bad parameter count, got '.count($param).', expected '.$min_count.'-'.$max_count);
      } else { # exact count
        if(count($param) != $min_count)
          return array(false,'Bad parameter count, got '.count($param).', expected '.$min_count);
      }
    }
    if($names) {
      $param_copy = $param;
      $scope = & static::current_scope();
      if(isset($p_store)) {
        foreach($p_store as $name=>$def) {
          if($name[0]=='$') $name = substr($name,1);
          # else error?
          list($accepted,$res) = static::param($def,array_shift($param_copy)); # recursive
          if($accepted)
            $scope['vars'][$name] = $res;
          else
            return array(false,$res);
        }
      } else {
        foreach($names as $name) {
          if($name[0]=='$') $name = substr($name,1);
          # else error?
          $val = array_shift($param_copy);
          $scope['vars'][$name] = $val;
        }
      }
    }
    if(!$sep && $names && count($names)==1)
      $param = $param[0];
    return array(true,$param);
  }
  static function eval_string($__context,$__stmt,$__parent=false) {
    return static::eval_php($__context,'return "'.addcslashes($__stmt,'"').'"',$__parent);
  }
  static function eval_expr($__context,$__expr,$__parent=false) {
    return static::eval_php($__context,"return $__expr",$__parent);
  }
  static function eval_php($__context,$__code,$__parent=false) {
    if($__parent && count(self::$scope) > 1) $__scope = & static::parent_scope();
    else $__scope = & static::current_scope();
    extract($__scope['vars'],EXTR_SKIP|EXTR_REFS);
    @trigger_error('');
    ob_start();
    try {
      $res = @eval("$__code;");
    } catch (Exception $e) {
      $code = $e->getCode();
      $line = $e->getLine();
      return array('exception',get_class($e).' in '.$__context.
        ($line != 1 ? ' line '.$line : '').': '.$e->getMessage().
        ($code ? ' ('.$code.')' : ''),ob_get_clean());
    }
    $e = error_get_last();
    if($e && $e['message'] > '') {
      $code_name = static::PHP_error_code_name($e['type']);
      @trigger_error(''); #reset
      return array('error','PHP '.$code_name.' in '.$__context.
        ($e['line'] != 1 ? ' line '.$e['line'] : '').': '.$e['message'],ob_get_clean());
    }     
    return array('ok',$res,ob_get_clean());
  }
  static function PHP_error_code_name($code) {
    switch($code) {
      case E_ERROR: $name = 'Error'; break;
      case E_WARNING: case E_USER_WARNING: $name = 'Warning'; break;
      case E_PARSE: $name = 'Parse error'; break;
      case E_NOTICE: case E_USER_NOTICE: $name = 'Notice'; break;
      default: $name = 'Error('.$e['type'].')'; break;
    }
    return $name;
  }  
}
