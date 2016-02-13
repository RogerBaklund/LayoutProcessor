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
- `!` Command
- `<` Markup
- `=` Layout definition
- `$` Variable assignment 
- `"` String output (variables resolved)
- `'` Literal output (variables *not* resolved)

You can add your own prefix or override any existing prefix with the `define_prefix($prefix,$callback)` method.

## Builtin commands

The following commands are builtin:

- `!php` Embedded PHP code 
- `!if`/`!elseif`/`!else` Conditional statements
- `!loop`/`!while`/`!break`/`!continue` Loops
- `!scope` Variable scopes
- `!param` Parameter handling

You can add custom commands with the `define_command($cmd,$callback)` method. 
You can not directly override builtin commands, but you can add aliases with 
the `define_command_alias($alias,$aliased_command)` method, this way you could
make builtin commands an alias for your custom version of that command. 
There are two predefined aliases: `!elif` is an alias for `!elseif` and
`!foreach` is an alias for `!loop`.

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

## Error handling

By default error messages are output where errors are encountered, but the script continues to run. 
You can modify error handling using he `on_error($mode)` method. 

The following error mode flag constants are defined:

- `ERR_TEXT` Error message is output as plain text (default)
- `ERR_HTML` Error message is output as HTML (in a `<p>` element)
- `ERR_LOG` Error messages are sent to a logger callback
- `ERR_EXIT` Error stops execution of the script

When using `ERR_LOG` you must also define a callback for the logger.

You can combine multiple flags, this example outputs HTML messages to screen and also writes
messages to a file named `debug.log`:

```php
LayoutProcessor::on_error(LayoutProcessor::ERR_HTML & LayoutProcessor::ERR_LOG);
LayoutProcessor::set_logger(function($context,$msg) {
  error_log(date('Y-m-d H:i:s')." $context: $msg\n",3,'debug.log');
  });
```

## Dependencies

- Requires PHP 5.3 or later
- Using [Indentation](https://github.com/RogerBaklund/Indentation) for block parsing