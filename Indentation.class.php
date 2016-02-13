<?php
# Roger 10. feb. 2014 14:04:00

/**Generic text indentation utilities.

\mainpage Introduction

This class has methods for manipulating indentation in blocks of text. You can
read current indentation without making changes, you can indent or unindent a 
block of text, you can split blocks of text based on the indentation, and you 
can modify indentation for individual lines in a text. 

TAB characters are recognized, but they count as one, which makes it hard
to see the correct level when used in combination with spaces. For this
reason you should use either spaces or `TAB` characters, not both mixed.

The `$lines` argument can be a string with linefeeds (`LF` or `CRLF`) or
an array of lines, such as the one returned from `file()`.
 
**NOTE:** `CR` characters are removed, the output strings will allways use `LF`.

@version 1.3
@license LGPL
@author Roger Baklund roger@baklund.no

Version history:

- 1.3 2016-01-29
  + unindent() return empty string for no/false/NULL input
  + set_indents() $ws parameter was ignored
- 1.2 2014-03-19
  + $ws argument added for set_indents()
- 1.1 2014-03-16
  + Improved line handling
  + Improved documentation
- 1.0 2014-02-10 (initial version)

*/
class Indentation {
  /** Normalize input to array of lines.
   * 
   * Accepts both CRLF and LF line endings. Will return an array unchanged.
   * 
   * This method is private, it used by all the other methods.
   * 
   * @param string|array $lines Multiline string or array of strings
   * @return array Array of strings
   */
  private static function lines($lines) {
    if(is_string($lines))
      $lines = explode("\n",str_replace("\r\n","\n",$lines));
    return $lines;
  }
  /** Concatinate indented line with the previous line.
   *
   * Returns array of arrays with pairs of linenumbers and blocks.
   *
   * NOTE: Will also remove `CR` and blank lines in the process, but these 
   * lines are still counted, the reported line numbers are correct according 
   * to the full input. You can detect removed blank lines by checking the 
   * linenumber plus the number of lines in a block and compare it with the
   * linenumber in the next block.
   *
   * @param string|array $lines Multiline string or array of strings
   * @return array Array of arrays (Linenumber, Lines as one LF concatinated string)
   */   
  static function blocks($lines) {
    $blocks = array();
    $lineno = 0;
    foreach(self::lines($lines) as $line) {
      $lineno = $lineno + 1;
      if(!strlen(trim($line))) continue; # skip blank lines, but accept "0"
      if($blocks && in_array($line[0],array(' ',"\t"))) {
        $blocks[count($blocks)-1][1] .= "\n".rtrim($line); # append to last line
        continue;
      }
      $blocks[] = array($lineno,rtrim($line));
    }
    return $blocks;
  }
  /** Remove unwanted indentation.
   *
   * Removes preceeding spaces equally from all lines until at least one line
   * starts at position one.
   *
   * For practical reasons, the first line is not considered, any whitespace at 
   * the beginning is removed. 
   *
   * @param string|array $lines A string or array of strings 
   * @return string The string unchanged or unindented, some leading spaces removed
   */
  static function unindent($lines) {
    $lines = self::lines($lines);
    if(!$lines) return '';
    $first = array_shift($lines);
    $min = PHP_INT_MAX;
    foreach($lines as $l) { # find smallest indentation
      $ind = strlen($l) - strlen(ltrim($l));
      if($ind < $min)
        $min = $ind;
    }
    foreach($lines as $idx=>$l)
      $lines[$idx] = substr($l,$min);
    return trim($first."\n".implode("\n",$lines));
  }
  /** Indent a multiline string by prepending each line with WS characters.
   *
   * By default it will inject space characters, but you can provide `"\t"` or
   * `chr(9)` as the third parameter if you want to indent with TAB. There is
   * however no special handling of TAB, each character counts as one.
   *
   * @param string|array $lines The string or array of strings to indent
   * @param int $size The size of the indentation, default is 2
   * @param string $ws The whitespace character to use, default space (ASCII 32)
   * @return string The multiline string indented
   */
  static function indent($lines,$size=2,$ws=' ') {
    $lines = self::lines($lines);
    foreach($lines as $idx=>$l)
      $lines[$idx] = str_repeat($ws,$size).$l;
    return implode("\n",$lines);
  }
  /** Set individual indentation for each line.
   *
   * This method is similar to indent(), except you provide an array of int
   * values specifying the indentation for each line in the multiline string.
   * You can append to existing indentation, and you can provide the character 
   * to use,  by default it will be a space.
   *
   * @param string|array $lines A string or array of strings you want to indent
   * @param array $arr Array of integers, specific indentation for each line
   * @param bool $append Set to true if you want to append the given numbers
   *   to existing indentation, be default it will replace existing indentation.
   * @param string $ws The whitespace character to use, default space (ASCII 32)
   * @return string The multiline string indented
   */
  static function set_indents($lines,$arr,$append=false,$ws=' ') {
    $lines = self::lines($lines);
    foreach($lines as $idx=>$l)
      $lines[$idx] = str_repeat($ws,$arr[$idx]).($append?$l:ltrim($l));
    return implode("\n",$lines);
  }
  /** Get the number of leading whitespace for each line.
   * 
   * Will count spaces, tabs and even occurrences of ASCII 0 and ASCII 11.
   *
   * @param string|array $lines A string or array of strings
   * @return array A list of integers, one for each line in the input
   */
  static function get_indents($lines) {
    $res = array();
    foreach(self::lines($lines) as $l)
      $res[] = strlen($l) - strlen(ltrim($l));
    return $res;
  }
}

?>