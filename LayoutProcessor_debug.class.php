<?php

require_once('LayoutProcessor.class.php');
$DEBUG_LOGFILE = 'debug.log';

/** Layout processor debug extension

@version 1.0.1
@license LGPL
@author Roger Baklund roger@baklund.no

# TODO: override shutdown function, dump $scope and/or $layouts to files on error

*/
class LayoutProcessor_debug extends LayoutProcessor {
  const 
    CLOCK_PRECISION = 3,    # number of decimals for fractions of seconds, 3 = milliseconds, 6 = microseconds
    
    DEBUG_LEVEL_BASICS = 1,           # run_layout, run_script, load, error
    DEBUG_LEVEL_CONFIGURATION = 2,    # get, set_logger, on_error, define_layout, assignment
    DEBUG_LEVEL_CUSTOMIZATION = 3,    # define_prefix, define_command, define_command_alias, add_transform, name_pattern
    DEBUG_LEVEL_OPERATIONS = 4,       # command, param
    DEBUG_LEVEL_OUTPUT = 5,           # markup, string_output, literal, 
    DEBUG_LEVEL_COMMENTS = 6,         # comment
    DEBUG_LEVEL_EVALUATIONS = 7,      # eval_string, eval_expr, eval_php, is_valid_name, resolve_alias, resolve_statement_type
    DEBUG_LEVEL_SCOPE_LOOKUP = 8,     # parent_scope, find_scope
    DEBUG_LEVEL_SCOPE_INSPECTION = 9; # current_scope 
    
  static $debug_level = self::DEBUG_LEVEL_OUTPUT;   # default debug level
  static $debug_level_names = array('','BASICS','CONFIGURATION','CUSTOMIZATION',
    'OPERATIONS','OUTPUT','COMMENTS','EVALUATIONS','SCOPE_LOOKUP','SCOPE_INSPECTION');
  static $statement_cap = 60;  # clip statements longer than this
  static $watch_list = array(); # store for variables to watch
  static $profiling = false;
  static $calls = array();
  
  # these are used by parent class
  static $logger = array(__CLASS__,'logger');  # use logger() method for error messages
  static $error_mode = 6; # HTML+LOG+CONTINUE

  # logger method used by parent class and debug() method
  static function logger($context,$msg) {
    list($ms,$ts) = explode(' ',microtime(),2);
    $ts = date('Y-m-d H:i:s',$ts).','.substr($ms,2,static::CLOCK_PRECISION);
    error_log("$ts $context: $msg\n",3,$GLOBALS['DEBUG_LOGFILE']);
  }
  
  # get/set debug level
  static function debug_level($level=NULL) {
    if(is_null($level)) return self::$debug_level;
    if(is_string($level) && in_array($level,self::$debug_level_names))
      $level = array_search($level,self::$debug_level_names);
    $level = (int) $level;
    if(!$level || $level >= count(self::$debug_level_names))
      self::debug('debug_level(): Illegal level'.
        ', expected 1-'.(count(self::$debug_level_names)-1).
        ', got '.$level.
        ', using DEBUG_LEVEL_'.self::$debug_level_names[self::$debug_level].
        ' (='.self::$debug_level.')');
    else {
      self::$debug_level = $level;
      self::debug('debug_level(DEBUG_LEVEL_'.self::$debug_level_names[$level].')');
    }
  }
  
  # watch variables
  static function watch($varnames) {
    if(!is_array($varnames)) 
      $varnames = array_map('trim',explode(',',$varnames));
    foreach($varnames as $varname) {
      $varname = ltrim($varname,'$');
      self::$watch_list[$varname] = NULL;
    }
    $varnames = implode(',',$varnames);
    self::debug("watch($varnames)");
    if(self::$debug_level < self::DEBUG_LEVEL_SCOPE_INSPECTION)
      self::debug("NOTE: variables will not be watched when debug level < SCOPE_INSPECTION");
  }
  
  # Enable/disable profiling or check if it is enabled
  static function profiling($enable=NULL) {
    if(is_null($enable)) return self::$profiling;
    self::$profiling = $enable ? true : false;
    self::debug('Profiling '.($enable?'enabled':'disabled'));
  }
  
  # output message to debug log
  static function debug($msg,$level=0) {
    if($level>self::$debug_level) return;
    if(static::$scope) {
      $scope = static::$scope[count(static::$scope)-1];
      $context = $scope['layout_name'].':'.$scope['line_no'].' ';
    } else $context = '';
    self::logger('DEBUG',str_repeat('  ',count(static::$scope)).$context.$msg);
  }
  
  # shorten messages
  static function cap($stmt,$cap=NULL) {
    if(is_null($cap)) $cap = static::$statement_cap;
    $stmt = (strlen($stmt)>$cap?substr($stmt,0,$cap).'...':$stmt);
    $stmt = str_replace("\n",'\\n',$stmt);
    return $stmt;
  }
  
  #### debug utilities
  
  # write all variables to debug log file
  static function dump_vars($scopename=false,$cap=NULL) {
    if($scopename)
      $scope = parent::find_scope($scopename);
    else
      $scope = parent::current_scope();
    if(!$scope)
      return self::logger('DEBUG','dump_vars() did not find scope!');
    self::logger('DEBUG','* Variables in '.$scope['layout_name'].':');
    foreach($scope['vars'] as $var=>$value) {
      $type = gettype($value);
      if($type == 'object')
        $rep = '<'.get_class($value).' object>';
      elseif($type == 'resource')
        $rep = '<'.get_resource_type($value).'>';
      elseif($type == 'array')
        $rep = '<array('.count($value).')>';
      else $rep = ($type == 'string') ? '"'.self::cap($value,$cap).'"' : $value;
      self::logger('DEBUG','$'.$var.' = '.$rep);
    }
  }
  
  # write current scope stack to debug log file
  static function scope_stack() {
    self::logger('DEBUG','* Current scope stack:');
    foreach(static::$scope as $idx=>$scope) {
      $scopeline = $idx.' '.$scope['layout_name'].':'.$scope['line_no'];
      if(!in_array($scope['statement_type'],array('method command','layout'))) 
        $scopeline .= ' <'.$scope['statement_type'].'>';
      if($scope['statement_type'] == 'method command')
        $scopeline .= ' !'.$scope['cmd'];
      $varcount = count($scope['vars']);
      if($varcount > 1) # ignore _param
        $scopeline .= ' '.($varcount-1).' variable'.($varcount>2?'s':'');
      if(!is_null($scope['if_state']))
        $scopeline .= ' if_state='.($scope['if_state']?'true':'false');
      self::logger('DEBUG',$scopeline);
    }
  }

  # write layout info to debug log file
  static function layout_info() {
    self::logger('DEBUG','* Loaded layouts:');
    $max_name_len = strlen('Layout');
    $max_parent_len = strlen('Parent/id');
    foreach(static::$layouts as $layout_name=>$layout) {
      $L = strlen($layout_name);
      if(isset($layout['name']) && $layout['name'] != $layout_name)
        $L += strlen($layout['name']) + 2;
      if($L > $max_name_len) $max_name_len = $L;
      $L = isset($layout['parent']) ? strlen($layout['parent']) : 0;
      $L += isset($layout['id']) ? strlen($layout['id']) + 1 : 0;
      if($L > $max_parent_len) $max_parent_len = $L;
    }
    $fmt = "%-{$max_name_len}s %-{$max_parent_len}s %6s %6s %s";
    self::logger('DEBUG',sprintf($fmt,'Layout','Parent/id','Size','Calls','Avg time'));
    self::logger('DEBUG',sprintf($fmt,'------','---------','----','-----','--------'));
    foreach(static::$layouts as $layout_name=>$layout) {
      $call_count = '';
      $avg_time = '';
      if(self::$calls && isset(self::$calls[$layout_name])) {
        $call_count = self::$calls[$layout_name][0];
        $avg_time = sprintf('%.'.static::CLOCK_PRECISION.'f',
                  self::$calls[$layout_name][1]/self::$calls[$layout_name][0]);
      }
      $size = strlen($layout['content']);
      if($size > 8 * 1024) $size = round($size/1024).' K';
      $parent = isset($layout['parent']) ? $layout['parent'] : '';
      if(isset($layout['id']))
        $parent .= '/'.$layout['id'];
      $name = $layout_name;
      if(isset($layout['name']) && $layout['name'] != $layout_name)
        $name .= '('.$layout['name'].')';
      $layout_info = sprintf($fmt,$name,$parent,$size,$call_count,$avg_time);
      self::logger('DEBUG',$layout_info);
    }
    self::logger('DEBUG',str_repeat('-',$max_name_len+$max_parent_len+6+6+8+4));
  }  
  
  ##### The following mehods are overrides from parent class
  
  static function set_logger($logger) {
    self::debug("set_logger()",self::DEBUG_LEVEL_CONFIGURATION);
    return parent::set_logger($logger);
  }
  static function define_prefix($prefix,$callback) {
    self::debug("define_prefix($prefix)",self::DEBUG_LEVEL_CUSTOMIZATION);
    return parent::define_prefix($prefix,$callback);
  }
  static function define_command($cmd,$callback) {
    self::debug("define_command($cmd)",self::DEBUG_LEVEL_CUSTOMIZATION);
    return parent::define_command($cmd,$callback);
  }
  static function define_command_alias($alias,$aliased_command) {
    self::debug("define_command_alias($alias,$aliased_command)",self::DEBUG_LEVEL_CUSTOMIZATION);
    return parent::define_command_alias($alias,$aliased_command);
  }
  static function add_transform($name,$callback) {
    self::debug("add_transform($name)",self::DEBUG_LEVEL_CUSTOMIZATION);
    return parent::add_transform($name,$callback);
  }
  static function name_pattern($nametype,$pattern=false) {
    self::debug("name_pattern($nametype".($pattern?",$pattern":'').")",self::DEBUG_LEVEL_CUSTOMIZATION);
    return parent::name_pattern($nametype,$pattern);
  } 
  static function is_valid_name($nametype,$name) {
    self::debug("is_valid_name($nametype,$name)",self::DEBUG_LEVEL_EVALUATIONS);
    return parent::is_valid_name($nametype,$name);
  }
  static function on_error($mode) {
    $flags = array();
    if($mode&self::ERR_TEXT) $flags[] = 'ERR_TEXT';
    if($mode&self::ERR_HTML) $flags[] = 'ERR_HTML';
    if($mode&self::ERR_LOG) $flags[] = 'ERR_LOG';
    if($mode&self::ERR_RESUME) $flags[] = 'ERR_RESUME';
    if($mode&self::ERR_EXIT) $flags[] = 'ERR_EXIT';
    if($mode&self::ERR_CANCEL) $flags[] = 'ERR_CANCEL';
    if($mode&self::ERR_DIE) $flags[] = 'ERR_DIE';
    $flags = implode('|',$flags);
    self::debug("on_error($mode): $flags",self::DEBUG_LEVEL_CONFIGURATION);
    return parent::on_error($mode);
  }
  static function error($msg){
    self::debug("error($msg)",self::DEBUG_LEVEL_BASICS);
    return parent::error($msg);
  }
  static function load($layout_name) {
    self::debug("load($layout_name)",self::DEBUG_LEVEL_BASICS);
    return parent::load($layout_name);
  }
  static function get($layout_name) {
    self::debug("get($layout_name)",self::DEBUG_LEVEL_CONFIGURATION);
    return parent::get($layout_name);
  }
  static function & current_scope() {
    $scope = & parent::current_scope();
    if(self::$debug_level < self::DEBUG_LEVEL_SCOPE_INSPECTION)
      return $scope;
    # Scope inspection, checking if any watched var is changed
    if($scope && isset($scope['vars'])) {
      foreach($scope['vars'] as $k=>$v) {
        foreach(self::$watch_list as $var=>$current) {
          if($var == $k) {
            if($v != $current) {
              self::$watch_list[$var] = $v;
              self::debug("WATCH: \${$var}=".self::cap($v));
            }
            break;
          }
        }
      }
    }
    # current_scope() is called a lot, usually not useful in debug output
    #self::debug("current_scope()",self::DEBUG_LEVEL_SCOPE_INSPECTION);
    return $scope;
  }
  static function & parent_scope() {
    self::debug("parent_scope()",self::DEBUG_LEVEL_SCOPE_LOOKUP);
    return parent::parent_scope();
  }
  static function & find_scope($layout_name='',$cmd='') {
    self::debug("find_scope($layout_name,$cmd)",self::DEBUG_LEVEL_SCOPE_LOOKUP);
    return parent::find_scope($layout_name,$cmd);
  }  
  static function run_layout($layout_name,$param='') {
    $trace = debug_backtrace();
    $trace_info = 'called from '.basename($trace[0]['file']).' line '.$trace[0]['line'];
    self::debug("run_layout($layout_name".(strlen($param)?",$param":'').") $trace_info",self::DEBUG_LEVEL_BASICS);
    return parent::run_layout($layout_name,$param);
  }
  static function run_script($layout_script,$param='',$layout_name='[inline]') {
    $size = strlen($layout_script);
    self::debug("run_script($layout_name) [$size bytes]",self::DEBUG_LEVEL_BASICS);
    if(!self::$profiling)
      return parent::run_script($layout_script,$param,$layout_name);
    else {
      $start = microtime(true);
      $res = parent::run_script($layout_script,$param,$layout_name);
      $time_spent = microtime(true) - $start;
      if(!isset(self::$calls[$layout_name]))
        self::$calls[$layout_name] = array(1,$time_spent);
      else {
        self::$calls[$layout_name][0] += 1;
        self::$calls[$layout_name][1] += $time_spent;
      }
      return $res;
    }
  }
  static function resolve_statement_type($prefix) {
    list($statement_type,$callback) = parent::resolve_statement_type($prefix);
    self::debug("resolve_statement_type($prefix): $statement_type",self::DEBUG_LEVEL_EVALUATIONS);
    return array($statement_type,$callback);
  }
  static function define_layout($stmt) {
    @list($layout_name,$layout_script) = explode(':',$stmt,2);
    $size = strlen($layout_script);
    self::debug("define_layout($layout_name) [$size bytes]",self::DEBUG_LEVEL_CONFIGURATION);
    return parent::define_layout($stmt);
  }
  static function assignment($stmt) {
    $stmt_part = static::cap($stmt);
    self::debug("assignment($stmt_part)",self::DEBUG_LEVEL_CONFIGURATION);
    $res = parent::assignment($stmt);
    if(self::$debug_level < self::DEBUG_LEVEL_SCOPE_INSPECTION)
      return $res;
    $var_pattern = parent::name_pattern('variable');
    preg_match("/^$var_pattern/i",$stmt,$m);
    if($m && in_array($m[0],array_keys(static::$watch_list))
          && in_array($m[0],array_keys(static::$scope[count(static::$scope)-1]['vars']))) {
      $v = static::$scope[count(static::$scope)-1]['vars'][$m[0]];
      self::$watch_list[$m[0]] = $v;
      self::debug('WATCH: $'.$m[0].'='.self::cap($v));
    }
    return $res;
  }
  static function markup($stmt) {
    $stmt_part = '<'.static::cap($stmt);
    self::debug("markup($stmt_part)",self::DEBUG_LEVEL_OUTPUT);
    return parent::markup($stmt);
  }
  static function string_output($stmt) {
    $stmt_part = static::cap($stmt);
    self::debug("string_output($stmt_part)",self::DEBUG_LEVEL_OUTPUT);
    return parent::string_output($stmt);
  }
  static function literal($stmt) {
    $stmt_part = static::cap($stmt);
    self::debug("literal($stmt_part)",self::DEBUG_LEVEL_OUTPUT);
    return parent::literal($stmt);
  }
  static function comment($stmt) {
    $stmt_part = static::cap($stmt);
    self::debug("comment($stmt_part)",self::DEBUG_LEVEL_COMMENTS);
    return parent::comment($stmt);
  }
  static function resolve_alias($cmd,$param) {
    $param_part = static::cap($param);
    self::debug("resolve_alias($cmd,$param_part)",self::DEBUG_LEVEL_EVALUATIONS);
    return parent::resolve_alias($cmd,$param);
  }
  static function command($stmt) {
    $stmt_part = static::cap($stmt);
    self::debug("command($stmt_part)",self::DEBUG_LEVEL_OPERATIONS);
    return parent::command($stmt);
  } 
  static function param($paramdef,$param) {
    self::debug("param($paramdef)",self::DEBUG_LEVEL_OPERATIONS);
    return parent::param($paramdef,$param);
  }
  static function eval_string($__context,$__stmt,$__parent=false) {
    $part = static::cap($__stmt);
    $parent = $__parent ? ' in parent scope' : '';
    self::debug("eval_string($__context,$part)$parent",self::DEBUG_LEVEL_EVALUATIONS);
    return parent::eval_string($__context,$__stmt,$__parent);
  }
  static function eval_expr($__context,$__code,$__parent=false) {
    $part = static::cap($__code);
    $parent = $__parent ? ' in parent scope' : '';
    self::debug("eval_expr($__context,$part)$parent",self::DEBUG_LEVEL_EVALUATIONS);
    return parent::eval_expr($__context,$__code,$__parent);
  }
  static function eval_php($__context,$__code,$__parent=false) {
    $part = static::cap($__code);
    $parent = $__parent ? ' in parent scope' : '';
    self::debug("eval_php($__context,$part)$parent",self::DEBUG_LEVEL_EVALUATIONS);
    return parent::eval_php($__context,$__code,$__parent);
  }
}
