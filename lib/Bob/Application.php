<?php

namespace Bob;

use Getopt,
    FileUtils;

// Public: The command line application. Contains the heavy lifting
// of everything Bob does.
class Application
{
    // Public: Contains mappings from task name to a task instance.
    var $tasks;

    // Public: The directory where the bob utility was run from.
    // The CWD inside a task refers to the directory where the
    // config file was found.
    var $originalDir;
    var $projectDir;

    // Public: The command line option parser. You can add your own options 
    // when inside a task if you call `addOptions` with the same format as seen here.
    var $opts;

    var $trace = false;

    var $configName = 'bob_config.php';

    // Public: Initialize the application.
    function __construct()
    {
        $this->opts = new Getopt(array(
            array('i', 'init', Getopt::NO_ARGUMENT),
            array('h', 'help', Getopt::NO_ARGUMENT),
            array('t', 'tasks', Getopt::NO_ARGUMENT),
            array('T', 'trace', Getopt::NO_ARGUMENT),
            array('d', 'definition', Getopt::REQUIRED_ARGUMENT)
        ));

        $this->tasks = new TaskRegistry;
    }

    // Public: Parses the arguments list for options and
    // then does something useful depending on what is given.
    //
    // argv - A list of arguments supplied on the CLI.
    //
    // Returns the desired exit status as Integer.
    function run($argv = null)
    {
        if (null === $argv) {
            $argv = $_SERVER['argv'];
            array_shift($argv);
        }

        try {
            $this->opts->parse($argv);
        } catch (\UnexpectedValueException $e) {
            println($this->formatUsage(), STDERR);
            return 1;
        }

        if ($this->opts->getOption('init')) {
            $this->initProject();
            return 0;
        }

        $this->loadConfig();

        if ($this->opts->getOption('help')) {
            println($this->formatUsage(), STDERR);
            return 0;
        }

        if ($this->opts->getOption('tasks')) {
            println($this->formatTasksAndDescriptions(), STDERR);
            return 0;
        }

        if ($this->opts->getOption('trace')) {
            $this->trace = true;
        }

        return $this->runTasks();
    }

    function runTasks()
    {
        $tasks = $this->opts->getOperands() ?: array('default');
        $start = microtime(true);

        foreach ($tasks as $taskName) {
            if (!$task = $this->tasks[$taskName]) {
                throw new \Exception(sprintf('Error: Task "%s" not found.', $taskName));
            }

            FileUtils::chdir($this->projectDir, function() use ($task) {
                return $task->invoke();
            });
        }

        printLn(sprintf('bob: finished in %f seconds', microtime(true) - $start), STDERR);
    }

    function taskDefined($task)
    {
        if (is_object($task) and !empty($task->name)) {
            $task = $task->name;
        }

        return (bool) $this->tasks[$task];
    }

    function defineTask($task)
    {
        $this->tasks[] = $task;
    }

    function initProject()
    {
        if (file_exists(getcwd()."/{$this->configName}")) {
            println('bob: Project already has a bob_config.php', STDERR);
            return;
        }

        $config = <<<'EOF'
<?php

namespace Bob;

task('default', array('example'));

desc('Write Hello World to STDOUT');
task('example', function() {
    println("Hello World!");
    println("To add some tasks open the `bob_config.php` in your project root"
        ." at ".getcwd());
});
EOF;

        @file_put_contents(getcwd()."/{$this->configName}", $config);
        println('Initialized project at '.getcwd());
    }

    // Internal: Looks up the config file path and includes it. Does a 
    // `chdir` to the dirname where the config is located too. So the
    // CWD inside of tasks always refers to the project's root.
    //
    // Returns nothing.
    function loadConfig()
    {
        $configPath = ConfigFile::findConfigFile($this->configName, $_SERVER['PWD']);

        if (false === $configPath) {
            throw new \Exception(sprintf(
                'Error: Filesystem boundary reached. No %s found.', 
                $this->configName
            ));
        }

        include $configPath;

        $this->originalDir = $_SERVER['PWD'];
        $this->projectDir = dirname($configPath);

        // Load tasks from the search dir in "./bob_tasks/"
        if (is_dir($this->projectDir.'/bob_tasks')) {
            $taskSearchDir = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->projectDir.'/bob_tasks')
            );

            foreach ($taskSearchDir as $file) {
                $fileExt = pathinfo($file->getRealpath(), PATHINFO_EXTENSION);

                if ($file->isFile() and $fileExt === 'php') {
                    include $file->getRealpath();
                }
            }
        }
    }

    function formatTasksAndDescriptions()
    {
        $tasks = $this->tasks->getArrayCopy();
        ksort($tasks);

        $text = '';
        $text .= "(in {$this->projectDir}".DIRECTORY_SEPARATOR."{$this->configName})\n";

        foreach ($tasks as $name => $task) {
            if ($name === 'default') {
                continue;
            }

            $text .= $task->usage;

            $text .= "\n";
            if ($task->description) {
                foreach (explode("\n", $task->description) as $line) {
                    $text .= "    ".ltrim($line)."\n";
                }
            }
        }

        return rtrim($text);
    }

    function formatUsage()
    {
        return <<<HELPTEXT
Usage:
  bob.php
  bob.php --init
  bob.php TASK...
  bob.php -t|--tasks
  bob.php -h|--help

Arguments:
  TASK:
    One or more task names to run. Task names can be everything as
    long as they don't contain spaces.

Options:
  -i|--init:
    Creates an empty `bob_config.php` in the current working
    directory if none exists.
  -t|--tasks:
    Displays a fancy list of tasks and their descriptions
  -T|--trace:
    Logs trace messages to STDERR
  -h|--help:
    Displays this message
HELPTEXT;
    }
}
