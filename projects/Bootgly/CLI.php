<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Bootgly;


use Bootgly\CLI;


// $Commands, $Scripts, $Terminal availables...

$commands = require('CLI/commands/@.php');

foreach ($commands as $Command) {
   CLI::$Commands->register($Command);
}

CLI::$Commands->route();
