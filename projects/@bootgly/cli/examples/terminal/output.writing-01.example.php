<?php
namespace Bootgly\CLI;

use Bootgly\CLI;

$Output = CLI::$Terminal->Output;

$Output->writing("Cursor Writing on Bootgly!\n");
$Output->writing("This feature allows writing to be slow and gradual...\n");
$Output->waiting = 10000;
$Output->writing("You can increase the speed using the property `waiting`: `\$Output->waiting = 10000;`\n");
