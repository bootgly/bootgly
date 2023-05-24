<?php
use Bootgly\CLI;


$resource = 'Bootgly/CLI/commands';
$commands = require $resource . '/@.php';

foreach ($commands as $Command) {
   CLI::$Commands->register($Command);
}

CLI::$Commands->route();
