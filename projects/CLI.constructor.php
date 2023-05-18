<?php
namespace Bootgly\CLI;


use Bootgly\CLI;


$resource = 'CLI/commands';
$commands = require $resource . '/@.php';

foreach ($commands as $Command) {
   CLI::$Commands->register($Command);
}

if (BOOTGLY_DIR === BOOTGLY_WORKABLES_DIR) {
   CLI::$Commands->route();
}
