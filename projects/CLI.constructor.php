<?php

use Bootgly\CLI;


$commands = require('Bootgly/CLI/commands/@.php');

foreach ($commands as $Command) {
   CLI::$Commands->register($Command);
}

CLI::$Commands->route();
