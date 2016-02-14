# LayoutProcessor

PHP class for template processing with extensible scripting language.

This class can process templates conforming to a simple syntax based on indented blocks of lines. 

The first character of a line is used to determine what kind of block it is, this first character 
is called the prefix. If it is a space (or TAB) character, it is an indented line and it belongs to 
the same block as the previous line. Blank lines are ignored.

A set of prefix characters has builtin support, but you can override these and/or add your own prefixes.

The main building blocks of a script is the "layout", which is similar to procedures in other languages.
You define layous and call them by their name, optionally with parameters. Layout names may contain spaces 
and other special characters, *except* colon, which is used to separate the parameter from the layout name.

## Builtin prefixes

The following prefixes are builtin:

- `#` Comment
- `$` Variable assignment 
- `"` String output (variables resolved)
- `'` Literal output (variables *not* resolved)
- `=` Layout definition
- `<` Markup
- `!` Command

You can add your own prefix or override any existing prefix with the `define_prefix($prefix,$callback)` method.

## Builtin commands

The following commands are builtin:

- `!php` Embedded PHP code 
- `!if`/`!elseif`/`!else` Conditional statements
- `!loop`/`!while`/`!break`/`!continue` Loops
- `!scope` Variable scopes
- `!param` Parameter handling
- `!return` Exit current layout

You can add custom commands with the `define_command($cmd,$callback)` method. You can not directly override 
builtin commands, but you can add aliases with the `define_command_alias($alias,$aliased_command)` method, 
this way you could define a builtin command to be an alias for your custom version of that command. 

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

## Standard prefixes

### Comments

Comments are prefixed with a `#` character. They produce no output, they are just used to document 
the script/template.

    # This is a comment.
      Comments can span multipe lines if the lines are indented.

In some cases you can have comments on the same line as other statements, for instance after assignements
if you use a semicolon after the expression:

    $foo = 'bar';  # this is a valid comment

### Assignments

The `$` prefix is used for variable assignment. Any valid PHP syntax can be used, 
the semicolon to end the statement is optional unless it is followed by a comment.
Big expressions can span multiple lines, just make sure they are indented.

    $x = 100
    $y = 200;  # comment allowed here
    $foo = functionCall()
    $bar = $obj->method()
    $str = "x=$x".
      ($foo?' foo='.$foo:'').
      "<br>"
    $coord = array($x,$y)
    $coord = [$x,$y];  # requires PHP 5.4+
    $avg = ($x+$y) / 2
    $obj->prop = 1
    $arr[] = 'new item'
    $arr[2] = 'changed'
    $str[0]= 'X'

Some statements which are not "normal" assignments are also allowed, for instance:

    $x++
    $x *= 2
    $str .= 'more'

The following two special cases are also allowed, they execute but any return value is **ignored**:

    $func()
    $obj->method()
    
### String output

The `"` prefix is used for string output. The block is output after resolving variables and escape sequences.
This works very similar to PHP double quotes strings, except you do not have to escape double quotes inside
the string, and there is no double quote at the end. 

    "Hello world\n
    "<div style="width:$width">
      $content
      </div>
    "$var
    
### Literal output

The `'` prefix is used for literal output. The block is output as is, without resolving variables or escape sequences.

    'This is literal output, $foo is just $foo, variables are not resolved
    'The output can span multiple 
      lines if it is indented.
      The indentation is kept in the output.

### Layout definition

The `=` prefix is used to define new or to override existing layouts. A layout can be very simple, defined 
on a singe line, or it can be quite complex and large. There is no defined limit to the size. You can define 
layouts within other layouts, but they are all global, just like PHP functions.

    =greeting:"Hello!\n
    =alert:!param string unhtml: $msg
      <div class="alert alert-danger">
      <span class="glyphicon glyphicon-exclamation-sign" style="font-size:150%;color:red"></span>
      " $msg
      </div>
    
### Markup

The `<` prefix is used to output markup. This is similar to `'` (literal output), it does **not** 
resolve variables or escape sequences. Though it is very simple, it is very useful when writing 
scripts which produce HTML or XML output.

    =MainMenu:
      <ul class="menu">
      MenuItems
      </ul>
    <div class="MenuContainer">
    MainMenu
    </div>

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

Variables in the current layout is automaticaly made available in the `!php` block, but variables
created inside the block is not automatically exported to the layout.

#### `!if`/`!elseif`/`!else` Conditional statements

These commands are used for conditional execution. `!elseif` and `!else` can only be used immediatly after 
an `!if` or an `!elseif`. You can only have one `!else`. You can have nested `!if` inside another `!if`. 
How deep you can nest is limited only by `MAX_RECURSION_DEPTH` (default 255). Unlike PHP the expression 
to evluate does not need to be in parentheses, but it must be a valid PHP expression. 
Long expressons can be broken on multiple lines, but they must of course be indented. 

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
      !if $width > 400:
        HighWideOutput
      !else: HighNarrowOutput
    !else:
      !if $width > 400:
        WideOutput
      !else SmallOutput

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

The `!while` takes an expression as first argument and continues to execute the code block until the 
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

There are three variants of the !scope` command. 

- `!scope global` Access global variables
- `!scope caller` Access variables from the calling layout
- `!scope from ...` Access variables from any active layout

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
    
    # fetch variable from any previous scope
    =Step1: 
      $x = 42
      $y = 1
      Step2
      "end of Step1: y=$y\n
    =Step2:
      !scope parent: $y
      $y++
      Step3
    =Step3:
      !scope from Step1: $x,$y
      "Step3: x=$x y=$y\n
      $y++
    Step1

Output:

    Step3: x=42 y=2
    end of Step1: y=3

Note that $y==2 in Step3 because Step2 modified it before Step3 was executed.
    
#### `!param` Parameter handling

#### `!return` Exit current layout
    
## Error handling

By default error messages are output where errors are encountered, but the script continues to run. 
You can configure the error handling using the `on_error($mode)` method. 

The following error mode flag constants are defined:

- `ERR_TEXT` Error message is output as plain text (default)
- `ERR_HTML` Error message is output as HTML (in a `<p>` element)
- `ERR_LOG` Error messages are sent to a logger callback
- `ERR_EXIT` Error stops execution of the script

When using `ERR_LOG` you must also define a callback for the logger.

You can combine multiple flags, this example outputs HTML messages to screen and also writes
messages to a file named `debug.log`:

```php
LayoutProcessor::on_error(LayoutProcessor::ERR_HTML | LayoutProcessor::ERR_LOG);
LayoutProcessor::set_logger(function($context,$msg) {
  error_log(date('Y-m-d H:i:s')." $context: $msg\n",3,'debug.log');
  });
```

## Dependencies

- Requires PHP 5.3 or later
- Using [Indentation](https://github.com/RogerBaklund/Indentation) for block parsing