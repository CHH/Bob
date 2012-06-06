<?php

namespace Bob;

use Symfony\Component\Process\Process;

class BuildFailedException extends \Exception
{}

# Public: Lets the build fail with an Exception.
#
# msg - The Exception message as String.
#
# Returns nothing.
function fail($msg)
{
    throw new BuildFailedException((string) $msg);
}

# Public: Defines the callback as a task with the given name.
#
# name          - Task Name.
# prerequisites - List of Dependency names.
# callback      - The task's action, can be any callback.
#
# Examples
#
#     task('hello', function() {
#         echo "Hello World\n";
#     });
#
# Returns nothing.
function task($name, $prerequisites = null, $callback = null)
{
    return Task::defineTask($name, $prerequisites, $callback);
}

# Public: Config file function for creating a task which is only run
# when the target file does not exist, or the prerequisites were modified.
#
# target        - Filename of the resulting file, this is set as task name. Use
#                 paths relative to the CWD (the CWD is always set to the root
#                 of your project for you).
# prerequisites - List of files which are needed to generate the target. The callback
#                 which generates the target is only run when one of this files is newer
#                 than the target file. You can access this list from within the task via
#                 the task's `prerequisites` property.
# callback      - Place your logic needed to generate the target here. It's only run when
#                 the prerequisites were modified or the target does not exist.
#
# Returns a Task instance.
function fileTask($target, $prerequisites = array(), $callback)
{
    return FileTask::defineTask($target, $prerequisites, $callback);
}

# Copies the file only when it doesn't exists or was updated.
#
# source - Source file. This file is watched for changes.
# dest   - Destination.
#
# Returns a Task instance.
function copyTask($from, $to)
{
    return FileTask::defineTask($to, array($from), function($task) {
        println("bob: copyTask('{$task->prerequisites[0]}' => '{$task->name}')", STDERR);

        if (false === copy($task->prerequisites[0], $task->name)) {
            fail("Failed copying '{$task->prerequisites[0]}' => '{$task->name}'");
        }
    });
}

# Public: Defines the description of the subsequent task.
#
# desc - Description text, should explain in plain sentences
#        what the task does.
#
# Examples
#
#     desc('Says Hello World to NAME');
#     task('greet', function($task) {
#         $operands = Bob::$application->opts->getOperands();
#         $name = $operands[1];
#
#         echo "Hello World $name!\n";
#     });
#
# Returns nothing.
function desc($desc)
{
    TaskRegistry::$lastDescription = $desc;
}

# Public: Appends an End-Of-Line character to the given
# text and writes it to a stream.
#
# line   - Text to write.
# stream - Resource to write the text to (optional). By
#          default the text is printed to STDOUT via `echo`
#
# Examples
#
#   # Print something to STDERR (uses fwrite)
#   println('Error', STDERR);
#
# Returns Nothing.
function println($line, $stream = null)
{
    $line .= PHP_EOL;

    if (is_resource($stream)) {
        fwrite($stream, $line);
    } else {
        echo "$line";
    }
}

# Public: Renders a PHP template.
#
# file - Template file, this must be a valid PHP file.
#
# Examples
#
#   # template.phtml
#   Hello <?= $name ? >
#
#   # test.php
#   $t = template('template.phtml');
#   echo $t(array('name' => 'Christoph'));
#   # => Hello Christoph
#
# Returns an anonymous function of the variables, which returns
# the rendered String.
function template($file)
{
    if (!file_exists($file)) {
        throw \InvalidArgumentException(sprintf(
            'File %s does not exist.', $file
        ));
    }

    $__file = $file;

    $template = function($__vars) use ($__file) {
        extract($__vars);
        unset($__vars);

        ob_start();
        include($__file);
        return ob_get_clean();
    };

    return $template;
}

# Public: Runs a system command
#
# cmd      - Command with arguments as String or List. Lists get joined by a single space.
# callback - A callback which receives the success as Boolean
#            and the Process instance as second argument (optional).
# timeout  - Timeout for the process, defaults to 60 seconds.
#
# Examples
#
#   # Triggers the default behaviour, the command's output is
#   # displayed on STDOUT and the build fails when the exit code
#   # was greater than zero.
#   sh('ls -l');
#
#   # When a callback is passed as second argument, then the callback
#   # receives the success status ($ok) as Boolean and a process instance
#   # as second argument. The default behaviour is prevented too.
#   sh('ls -A', function($ok, $process) {
#       $ok or fwrite($process->getErrorOutput(), STDERR);
#   });
#
# Returns nothing.
function sh($cmd, $callback = null, $timeout = 60)
{
    $cmd = join(' ', (array) $cmd);
    $showCmd = strlen($cmd) > 42 ? substr($cmd, 0, 42).'...' : $cmd;

    println("bob: sh($showCmd)", STDERR);

    $process = new Process($cmd);
    $process->setTimeout($timeout);

    $process->run(function($type, $output) {
        $type == 'err' ? fwrite(STDERR, $output) : print($output);
    });

    $process->isSuccessful() or fail("Command failed with status ({$process->getExitCode()}) [$showCmd]");

    if ($callback !== null) {
        call_user_func($callback, $process->isSuccessful(), $process);
    }
}

# Public: Run a PHP Process with the given arguments.
#
# argv     - The argv either as Array or String. Arrays get joined by a single space.
# callback - See sh().
# timeout  - See sh().
#
# Examples
#
#   # Runs a PHP dev server on `localhost:4000` with the document root
#   # `public/` and the router script `public/index.php` (requires PHP >= 5.4).
#   php(array('-S', 'localhost:4000', '-t', 'public/', 'public/index.php'));
#
# Returns nothing.
function php($argv, $callback = null, $timeout = 60)
{
    $execFinder = new \Symfony\Component\Process\PhpExecutableFinder;
    $php = $execFinder->find();

    $argv = (array) $argv;
    array_unshift($argv, $php);

    return sh($argv, $callback, $timeout);
}

# Public: Takes a list of expressions and joins them to
# a list of paths.
#
# patterns - List of shell file patterns.
#
# Returns a list of paths.
function fileList($patterns)
{
    $patterns = (array) $patterns;
    $finder = new \Symfony\Component\Finder\Finder;
    $finder->files();

    foreach ($patterns as $p) {
        $finder->name($p);
    }

    return $finder;
}

