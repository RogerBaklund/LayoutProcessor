<?php
# demo 
error_reporting(E_ALL);
ini_set('display_errors',1);

require '../LayoutProcessor.class.php';

# These constants are only used by this demo
define('DEBUG_MODE', 1);
define('SCRIPT_IDENTITY','DemoViewer');  # only used for error messages
define('SCRIPT_EXTENSION','.demo.txt');  
define('LOGFILE','demo.log');

class Demo extends LayoutProcessor {
  const ERR_MSG_INTRO = 'Demo ERROR: ';
  static function load($layout_name) {
    $fn = $layout_name.SCRIPT_EXTENSION;
    if(!file_exists($fn)) return false;
    $content = file_get_contents($fn);
    return array('content'=>$content,'name'=>$fn,'parent'=>basename(__FILE__),'id'=>SCRIPT_IDENTITY);
  }
  static function get($layout_name) {
    if(DEBUG_MODE) {
      global $logger;
      $parent = isset(static::$layouts[$layout_name]) ? 
        static::$layouts[$layout_name]['parent'].':'.
        static::$layouts[$layout_name]['id'] : $layout_name.SCRIPT_EXTENSION;
      $logger('DEBUG',str_repeat('  ',count(static::$scope)).$layout_name.($parent ? " ($parent)" : ''));
    }
    return parent::get($layout_name);
  }
}

# Show errors in HTML format and write them to log file
Demo::on_error(Demo::ERR_HTML | Demo::ERR_LOG);

$logger = function ($context,$msg) {
  error_log(date('Y-m-d H:i:s')." $context: $msg\n",3,LOGFILE);
};

if(!Demo::set_logger($logger)) 
  die('* Could not set a logger, not a valid callable!');

$start = microtime(true);
echo Demo::run_layout('script'); # runs script.demo.txt, see load() method
$stop = microtime(true);

if(DEBUG_MODE)
  $logger('DEBUG',sprintf('Time spent: %.3f s',$stop-$start));
