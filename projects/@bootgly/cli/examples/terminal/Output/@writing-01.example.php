<?php
namespace Bootgly\CLI;

use Bootgly\CLI;

$Output = CLI::$Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal (>) - writing method @;
 * @#yellow: @@ Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);

$Output->writing("Cursor Output: writing method on Bootgly!\n");
$Output->writing("This feature allows writing to be slow and gradual...\n");
$Output->waiting = 10000;
$Output->writing("You can increase the speed using the property `waiting`: `\$Output->waiting = 10000;`\n");
