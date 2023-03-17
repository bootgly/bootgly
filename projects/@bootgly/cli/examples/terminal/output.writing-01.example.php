<?php
namespace Bootgly\CLI;

use Bootgly\CLI;

$Output = CLI::$Terminal->Output;
$Output->reset();

/* 
 * Terminal Output - Writing method - Example #1
 */
$Output->writing("Cursor Output: writing method on Bootgly!\n");
$Output->writing("This feature allows writing to be slow and gradual...\n");
$Output->waiting = 10000;
$Output->writing("You can increase the speed using the property `waiting`: `\$Output->waiting = 10000;`\n");
