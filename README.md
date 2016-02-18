# LayoutProcessor

PHP class for template processing with extensible scripting language.

This class can process templates conforming to a simple syntax based on indented blocks of lines. 

The first character of a line is used to determine what kind of block it is, this first character 
is called the prefix. If it is a space (or TAB) character, it is an indented line and it belongs 
to the same block as the previous line. Blank lines are ignored.

A set of prefix characters has builtin support, but you can override these and/or add your own prefixes.

The main building blocks of a script is the "layout", which is similar to procedures in other languages.
You define layous and call them by their name, optionally with parameters. Layout names may contain spaces 
and other special characters, *except* colon, which is used to separate the parameter from the layout name.

## Builtin prefixes

The following prefixes are builtin:

- [`#` Comments](#comments)
- [`$` Variable assignments](#assignments)
- [`"` String output](#string-output) (variables resolved)
- [`'` Literal output](#literal-output) (variables *not* resolved)
- [`=` Layout definitions](#layout-definitions)
- [`<` Markup](#markup)
- [`!` Commands](#commands)

You can add your own prefix or override any existing prefix with the `define_prefix($prefix,$callback)` method.

## Builtin commands

The following commands are builtin:

- [`!php` Embedded PHP code](#php-embedded-php-code) 
- [`!if`/`!elseif`/`!else` Conditional statements](#ifelseifelse-conditional-statements)
- [`!loop`/`!while`/`!break`/`!continue` Loops](#loopwhilebreakcontinue-loops)
- [`!scope` Variable scopes](#scope-variable-scopes)
- [`!param` Parameter handling](#param-parameter-handling)
- [`!return` Exit current layout](#return-exit-current-layout)

You can add custom commands with the `define_command($cmd,$callback)` method. You can not directly override 
builtin commands, but you can add aliases with the `define_command_alias($alias,$aliased_command)` method, 
this way you could define a builtin command to be an alias for your custom version of that command. 
Overriding loop commands or conditonal statements will probably not work well because of how these are 
handled internally.

There are two predefined aliases: `!elif` is an alias for `!elseif` and `!foreach` is an alias for `!loop`.

## Basic examples

```php
# Hello world
echo LayoutProcessor::run_script('"Hello world\n');

# Hello world using layout
$script = <<<'EOD'
=Hello:"Hello $$\n
Hello:world
EOD;
echo LayoutProcessor::run_script($script);

# Hello world using layout and variable
$script = <<<'EOD'
=Hello:
  !param string:$who
  "Hello $who\n
$who = 'world'
Hello:$who
EOD;
echo LayoutProcessor::run_script($script);

# Because 'Hello' is now defined we can call it directly:
echo LayoutProcessor::run_layout('Hello','world');
```

## Extending the class

In general you would not use the `LayoutProcessor` class directly like in the basic examples above. 
Instead you would write a subclass and extend it to fit more closely with your own application.
There are some parts you most likely would want to override.

### Class constants

The following class constants can be overridden.

#### ERR_MSG_INTRO

This is a constant which is used to prefix all error messages. The default value is `'Layout processing error: '`,
you can change it to include your application name, like `'MyApp ERROR: '` or similar. Note that this constant 
is used both for `ERR_TEXT` and `ERR_HTML` output (see [error handling](#error-handling)), it should **not** 
contain any HTML. It is not used for the message sent to the logger callback.

#### PARAM_PLACEHOLDER

The placeholder for literal parameters is defined in this constant, default value is `'$$'`. There is 
usually no reason to override this, but you can.

#### MAX_RECURSION_DEPTH

The default maximum recursion depth is set to 255, this should be plenty in most cases, but you can change 
it if you need to. You will get a 'Recursion error' message when this limit is exceeded. It usually means 
there is a logical error in the code resulting in a loop: some layout A is calling B, which again is 
calling A.

### Methods

The following methods can be overridden.

#### load($layout_name)

This is the most important method to override. 

This method is executed when an undefined layout is called. It should be aware of the current context of 
the application and load the layout from a repository (file system or database) depending on this context.
For instance if a web application has current URL `/foo/bar/` you should first look for the layout in `/foo/bar`, 
if not found you should look in `/foo` and if it is not found there either you should look in `/` (root). 
This way any layout can be overridden for different parts of the application.

If the layout is not found this method must return false, an error message is generated from the 
`run_script()` method. When the layout is found this method must return an associative array with the 
following keys:

- `content` - the actual layout as a string (required)
- `name` - usually the layout name, could be a file name or other name (optional)
- `parent` - context information, file path or DB reference (optional)
- `id` - DB identifier, numeric or string (optional)

The optional parts of this array is only used for context for error messages. You can add more meta 
information in this layout if you need to, it is stored in the `static::$layouts` array with the 
layout name as key.

For layouts which are defined within other layouts 'parent' gets the name of the layout in which it 
is defined and 'id' gets the line number.

#### get($layout_name)

This method is responsible for finding the layout when it is called. It calls `load()` if it can not find
the layout in memory. If `load()` fails (returns false) then `get()` also must return `false`, otherwise 
it must return the layout as a string, this will normally be the `content` of the array returned from `load()`.

You can override this if you want to insert debugging (see example below) or if you want to handle some layouts 
differently from others. If this method returns false an error will be triggered in `run_script()`.

### Extension example

```php
define('DEBUG_MODE',0);

class MyApp extends LayoutProcessor {
  const ERR_MSG_INTRO = 'MyApp error: ';
  static function current_context() {
    # return current application context object
  }
  static function load($layout_name) {
    $context = static::current_context();
    $layout_item = $context->get_layout($layout_name);
    while(!$layout_item && $context = $context->parent_context()) 
      $layout_item = $context->get_layout($layout_name);
    if(!$layout_item) return false;
    return array(
      'content' => $layout_item->content,
      'name' => $layout_item->name,
      'parent' => $layout_item->parent,
      'id' => $layout_item->id);
  }
  static function get($layout_name) {
    if(DEBUG_MODE)
      MyLoggerClass::log('DEBUG',str_repeat('  ',count(static::$scope)).$layout_name);
    return parent::get($layout_name);
  }
}

$error_mode = MyApp::ERR_LOG;

if(DEBUG_MODE) # enable HTML errors
  $error_mode |= MyApp::ERR_HTML;

MyApp::on_error($error_mode);
MyApp::set_logger(array('MyLoggerClass','log'));
MyApp::run_layout('_init'); # setup, no output
echo MyApp::run_layout('_main'); # main application start 
```

In this example it is presumed that you have a `MyLoggerClass` with a static `log()` method 
and a context object with `get_layout()` and `parent_context()` methods. What exactly a 
context is depends on the application, for instance it can be based on a file path or from 
a hierarchy stored in a database. Other criteria can also be used to define a context, like
if the user is logged in or not, if it is an admin user or not, what time of day it is, the 
IP address of the user and so on.

The `'_init'` and `'_main'` layouts are also just examples, you can call them anything and 
it does not have to be two separate startup layouts like this, it could be three or one. 
The point of having more than one is that it gives you more flexibility when it comes to 
overriding defaults. For instance `'_init'` could be used to load required PHP libraries 
and to set global variables and similar, and `'_main'` could be used to define the page 
template. You would allways use the same `'_init'`, but `'_main'` could be overridden 
for different types of pages (web/mobile/ajax etc). Both could contain additional layouts 
which may be overridden for different pages or different parts of the application.

---

## Standard prefixes

### Comments

Comments are prefixed with a `#` character. They produce no output, they are just used to 
document the script/template.

    # This is a comment.
      Comments can span multiple lines if the lines are indented.

In some cases you can have single line comments on the same line as other statements, for 
instance after assignments if you use a semicolon after the expression:

    $foo = 'bar';  # this is a valid comment

### Assignments

The `$` prefix is used for variable assignment. Any valid PHP syntax can be used, 
the semicolon to end the statement is optional unless it is followed by a comment.
Big expressions can span multiple lines, just make sure they are indented.

    $x = 100
    $y = 200;  # comment allowed here
    $z = SOME_CONSTANT
    $foo = functionCall()
    $bar = $obj->method()
    $str = "x=$x".
      ($foo ? ' foo='.$foo : '').
      '<br>'
    $coord = array($x,$y)
    $coord = [$x,$y];  # requires PHP 5.4+
    $avg = ($x+$y) / 2
    $obj->prop = 1
    $arr[] = 'new item'
    $arr[2] = 'changed'
    $str[0] = 'X'

Some statements which are not "normal" assignments are also allowed, for instance:

    $x++
    $x *= 2
    $str .= 'more'

The following two special cases are also allowed, they execute but any return value is **ignored**:

    $func()
    $obj->method()

If you need the return values you must assign them to a variable, like this:

    $res = $func()
    "$res
    $res = $obj->method()
    !if $res:
      DoSomething
    
    
### String output

The `"` prefix is used for string output. The block is output after resolving variables and escape 
sequences. This works very similar to PHP double quoted strings, except you do not have to escape 
double quotes inside the string, and there is no double quote at the end. 

    "Hello world\n
    "<div style="width:$width">
      $content
      </div>
    "$var
    
### Literal output

The `'` prefix is used for literal output. The block is output as is, without resolving variables
or escape sequences. It can contain anything including unescaped `'` characters.

    'This is literal output, '$foo' is just '$foo', variables are not resolved
    'The output can span multiple 
      lines if they are indented.
      The indentation is kept in the output.

### Layout definitions

The `=` prefix is used to define new or to override existing layouts. A layout can be very simple, 
defined on a singe line, or it can be quite complex and large. There is no defined limit to the size. 
You can define layouts within other layouts, but they are all global, just like PHP functions.

    =greeting:"Hello!\n
    =alert:!param string unhtml: $msg
      <div class="alert alert-danger">
      <span class="glyphicon glyphicon-exclamation-sign" style="font-size:150%;color:red"></span>
      " $msg
      </div>

A layout with a single statement can be defined on one line, but care must be taken when it is a single 
multiline statement. This will fail:

    =foo:<p>This paragraph 
            spans two lines</p>

The parser can not distinguish between a single multiline statement and multiple statements. It will treat 
the second line as a statement and fail with the message `Undefined layout "spans two lines</p>"`

The solution is to start the multiline statement on a new indented line:

    =foo:
      <p>This paragraph 
         spans two lines</p>

When there are multiple statements this is not a problem. This works:

    =foo:<p>This paragraph 
            spans two lines</p>
         <p>Another paragraph</p>

It works because the second line is indented more than the third. This would have failed:

    =foo:<p>This paragraph 
         spans two lines</p>
         <p>Another paragraph</p>

         
### Markup

The `<` prefix is used to output markup. This is similar to `'` (literal output), it does **not** 
resolve variables or escape sequences. Though it is quite primitive, it is very useful when writing 
scripts which produce HTML or XML output.

    =MainMenu:
      <ul class="menu">
      MenuItems
      </ul>
    <div class="MenuContainer">
    MainMenu
    </div>
    <!-- This comment will be visible in the HTML source -->
    # This comment will not be visible
    <p class="example">
      This is a paragraph.
      It can be split into multiple indented lines, 
      the browser will format it as a normal paragraph
      depending on the available width on screen.
    </p>

See also the `=alert` example above.
    
### Commands

The `!` prefix is used for commands. Some commands are builtin, and you can add your own using 
the `define_command($cmd,$callback)` method.

#### `!php` Embedded PHP code

This command is used to embed native PHP code in your script.  It can be a single statement 
or a larger block of code, for instance a function call or a class or function definition.

    !php var_dump($var);
    !php include($php_file);
    !php function foo() { return 'bar'; }
    !php
      class FooBar {
        function paragraph($param) {
          return "<p>$param</p>";
        }
      }

Variables in the current layout is automatically made available in the `!php` block, but variables
created inside the block is not automatically exported to the layout.

#### `!if`/`!elseif`/`!else` Conditional statements

These commands are used for conditional execution. `!elseif` and `!else` can only be used immediatly after 
an `!if` or an `!elseif`. You can only have one `!else`. You can have nested `!if` inside another `!if`. 
How deep you can nest is limited only by `MAX_RECURSION_DEPTH` (default 255). Unlike PHP the expression 
to evaluate does not need to be in parentheses, but it must be a valid PHP expression. 
Long expressions can be broken on multiple lines, but they must of course be indented. 

After the expression a colon and a new line is required. Even for short single statement blocks you can 
**not** put the statement on the same line as the condition. Indentation is (as always) also required.

The `!else` statement has no condition, it allows a statement on the same line, and the colon is optional. 
See examples of this below.

    !if $foo == 'bar':
      FooBar
      
    !if $obj->method():
      "Ok!
    !else: "Failed!
    
    !if $height > 400:
      !if $width > 800:
        HighVeryWideOutput
      !elseif $width > 400:
        HighWideOutput
      !else: HighNarrowOutput
    !else:
      !if $width > 400:
        WideOutput
      !else SmallOutput

**NOTE:** `!elif` is defined as an alias for `!elseif`. You can use either, but if there are errors the
error messages will always report it as error in `!elseif`.
      
#### `!loop`/`!while`/`!break`/`!continue` Loops

These statements are used for making loops. The `!loop` is similar to the PHP foreach statement,
it takes an array expression followed by a variable assignment or a key/value assignment. 
Like `!if` and `!elseif` the expression must end with colon and the code block must start on a new line.

    # count to 5
    !loop [1,2,3,4,5] as $i:
      " $i
    # count to 5
    !loop range(1,5) as $i:
      " $i
    # list posted key/value pairs
    !loop $_POST as $k => $v:
      "$k = $v<br>
    # output a table
    <table>
    !loop $rows as $row:
      <tr>
      !loop $row as $col:
        "<td>$col</td>
      </tr>
    </table>

**NOTE:** `!foreach` is defined as an alias for `!loop`. You can use either, but if there are errors the
error messages will always report it as error in `!loop`.
    
The `!while` command takes an expression as first argument and continues to execute the code block until the 
expression is false. Like `!loop`, `!if` and `!elseif` the expression must end with colon and the code 
block must start on a new line.

    # count down from 5
    $x = 5
    !while $x:
      " $x
      $x--

The `!break` command is used to exit a loop, for nested loops you can provide a number to exit more than
one loop. `!continue` skips back to the start of the current loop and continues with the next iteration.

    # output: ab123  
    !loop ['a','b','c'] as $x:
      "$x
      !if $x == 'a':
        !continue
      !loop range(1,5) as $y:
        "$y
        !if $x == 'b' && $y == 3:
          !break 2
      
#### `!scope` Variable scopes

This command is used to import variables from other variable scopes. Each layout has a separate variable 
scope, which means variables are by default local to the current layout. With `!scope` you can access
variables which belongs to a different layout.

There are three variants of the `!scope` command. 

- `!scope global` Access global variables
- `!scope caller` Access variables from the calling layout
- `!scope from ...` Access variables from any active layout

Example using `!scope caller`:

    =Greeting:
      !scope caller: $who
      "Hello $who\n
    $who = 'world'
    Greeting
    =GreetJane:
      $who = 'Jane'
      Greeting
    GreetJane

Output of the above would be:

    Hello world
    Hello Jane

Example using `!scope from ...`:
    
    =Start:Step1
    =Step1: 
      $x = 42
      $y = 1
      Step2
      "end of Step1: y=$y\n
    =Step2:
      !scope caller: $y
      $y++
      Step3
    =Step3:
      !scope from Step1: $x,$y
      "Step3: x=$x y=$y\n
      $y++
    Start

Output:

    Step3: x=42 y=2
    end of Step1: y=3

Note that y is 2 in Step3 because Step2 modified it before Step3 was executed, and so did Step3 so 
it is 3 at the end of Step1.

You can only fetch variables from active layouts, for instance in the example above Step1 could 
not fetch anything from Step3 because it is not active while Step1 is running: it has not started 
before Step2 is called, and it is finished when Step2 returns.
    
#### `!param` Parameter handling

This command can split and transform a parameter into one or more variables. 

For simple layouts with only one parameter which is always handled literally (no variables) 
you can use the placeholder `$$` to indicate where the parameter goes. The first 
[=Hello](#basic-examples) example above shows this usage. When using this no `!param` 
command is needed for that layout.

In addition to the `$$` placeholder all layouts have a "magic" variable named `$_param` which 
holds the parameter used when the layout was called. In many cases you can just use this variable, 
but sometimes you need to use the `!param` command to manipulate the parameter. A common and simple
usage is `!param string` which is used to resolve variables in the parameter, you can see a couple
of examples of that above. (The second [=Hello](#basic-examples) at the start and the 
[=alert](#layout-definition) example for layout definitions.)

`string` is one of many predefined transformation types. You can provide multiple transformation 
types in the same `!param` call, each is executed in order.

The formal syntax for the `!param` command is like this:

    !param [<transformations>] [<separator> [(<count>|<min>-<max>)] ] [ : <variables>]

There can be zero or more transformations. If there is only one parameter, there is no separator. 
For multiple parameters there can be only one main separator, but there can be an 
additional separator for each part separated by the main separator, defined in the `<variables>` 
part of the command. When a `<separator>` is provided you can provide a `<count>` or a range 
in parentheses. If it is a count it defines how many parameters are required. If it is a range it 
means there are some optional parameters; the layout must be called with at least `<min>` and at 
most `<max>` parameters.

The `<variables>` part has two formats: either it is a simple comma separated list of variable names,
or it is a line separated list of variable names with separate parameter definitions.

Some examples might clarify:

    # Variables are resolved in $_param
    !param string
    
    # Variables are resolved and stored in local variable $str
    !param string:$str
    
    # Resolve variables and transform to upper case letters
    !param string UPPER:$str
    
    # Split parameter on colon, $_param is an array
    !param colon
    
    # Split parameter on colon, accept 0-2 parameters stored in 
      local variables $a and $b. 
      Use isset() to check if parameters are provided
    !param colon(0-2):$a,$b
    
    # Split on colon into 3 parts. 
      The first part is split on the | character and stored in an array named $color, 
        the first item is stored in $fg, 
        the second item is optional and stored in $bg if provided.
      The second part can be a variable (using 'string') 
        the resolved result is stored in $msg.
      The third part is split on comma and stored in an array named $dim, 
        the two members are stored in variables $width and $height
      Example parameter: red|black:$msg:200,30 
    !param colon(3):
      $color: pipe(1-2):$fg,$bg
      $msg: string
      $dim: comma(2):$width,$height

**Predefined transformations:**

- `raw` - No transformation (default)
- `string` - Resolve variables
- `expr` - Resolve variables and PHP expressions
- `unhtml` - Escape HTML characters (`<` becomes `&lt;` and so on)
- `urlify` - Escape characters for use in URL
- `upper` - Transform to UPPER case letters
- `lower` - Transform to lower case letters
- `ucfirst` - Transform first letter to UPPER case 
- `ucwords` - Transform first letter of each word to UPPER case 

You can add custom transformations using the `add_transform($name,$callback)` method.

**Separators:**

- `colon` - Split on the `:` character
- `semicolon` - Split on the `;` character
- `comma` - Split on the `,` character
- `dot` - Split on the `.` character
- `amp` - Split on the `&` character
- `space` - Split on the space character
- `line` - Split on new line (`\n`)
- `tab` - Split on TAB (`\t`)
- `pipe` - Split on the `|` character
- `dash` - Split on the `-` character
- `plus` - Split on the `+` character
- `slash` - Split on the `/` character

#### `!return` Exit current layout

This command exits the current layout and resumes execution with the next statement in the caller layout. 
Unlike the PHP `return` statement it can **not** return a value.

    =HandlePost:
      !if !isset($_POST['email']):
        !return
      $email = $_POST['email']
      NewsletterSubscribe:$email


---
      
## Error handling

By default error messages are output where errors are encountered, but the script continues to run. 
You can configure the error handling using the `on_error($mode)` method.

The default mode is useful for a system under development. When it goes to production you should 
disable the visual error messages and instead use `ERR_LOG` to write the messages to a file or a 
database table. 

The following error mode constants controls output of the error messages:

- `ERR_SILENT` No error message is output unless the logger returns a message
- `ERR_TEXT` Error message is output as plain text (default)
- `ERR_HTML` Error message is output as HTML (in a `<p>` element)
- `ERR_LOG` Error messages are sent to a logger callback

Use either `ERR_SILENT`, `ERR_TEXT` or `ERR_HTML`. When more than one is used `ERR_SILENT` is ignored,
if `ERR_HTML` is used `ERR_TEXT` is ignored and HTML messages are output. When none of them are used 
`ERR_SILENT` is the default and no error message is output, unless `ERR_LOG` is used **and*' the logger 
returns a message, **or** if `ERR_DIE` is used (see below). Using `ERR_SILENT` combined with `ERR_LOG` 
is recommended for an application in production, you don't want to show errors to the users.

The following error mode constants controls program flow when an error is encountered:

- `ERR_CONTINUE` Error handled according to output/log settings, continues running the script (default)
- `ERR_RESUME` Exits the current layout, then continues running the script
- `ERR_EXIT` Stops execution of `run_script()`, already produced results is returned
- `ERR_CANCEL` Stops execution of `run_script()`, only the error message is returned
- `ERR_DIE` Stops execution of the **PHP script**, only the error message is output

Use one of `ERR_CONTINUE`, `ERR_RESUME`, `ERR_EXIT`, `ERR_CANCEL`, `ERR_DIE` or none of them. 
If they are combined the most severe action will be taken, for instance if `ERR_DIE` is enabled 
it will die, if any of the others are enbled `ERR_CONTINUE` is ignored, and so on. 

`ERR_DIE` outputs a text error message regardless of `ERR_TEXT` or `ERR_HTML` settings. 
It also exits the PHP script. It is usually better to use  `ERR_CANCEL`, which returns control 
to the script which called the `run_script()` method. It can return the error message if `ERR_TEXT`
or `ERR_HTML` is enabled, but you can also check the `LayoutProcessor::$error_exit` static variable,
it will contain the name of the layout which failed. It will be `false` if there was no error.

When using `ERR_LOG` you must also define a callback for the logger using the `set_logger($callback)` method.
The callback expects two parameters, the context for the error and the message.

You can combine multiple flags using the | operator, this example outputs HTML messages to 
screen, exits the layout with the error but resumes running the script, and also writes messages 
to a file named `debug.log`:

```php
class LP extends LayoutProcessor {}
LP::on_error(LP::ERR_HTML | LP::ERR_RESUME | LP::ERR_LOG);
LP::set_logger(function($context,$msg) {
  error_log(date('Y-m-d H:i:s')." $context: $msg\n",3,'debug.log');
  });
```

Note that the logger callback does not need to write to a log file, it can do anything, for instance 
send an email and/or write a user friendly message on a designated area of the screen. 
You can also use it to override the default error message by **not** using `ERR_TEXT` or `ERR_HTML`
but instead return the formatted error message from the logger callback.

## Dependencies

- Requires PHP 5.3 or later
- Using [Indentation](https://github.com/RogerBaklund/Indentation) for block parsing