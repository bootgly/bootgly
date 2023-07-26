<?php

namespace projects\Bootgly;


use Bootgly\CLI;


$commands = require('CLI/commands/@.php');

foreach ($commands as $Command) {
   CLI::$Commands->register($Command);
}

CLI::$Commands->route();
