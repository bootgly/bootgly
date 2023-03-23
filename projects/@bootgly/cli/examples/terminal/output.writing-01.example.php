<?php
namespace Bootgly\CLI;

use Bootgly\CLI;

$Output = CLI::$Terminal->Output;
$Output->reset();

$Output->Text->colorize('green');
$Output->write(<<<OUTPUT
/*
 * Bootgly CLI Terminal > - writing(string \$text) method - Example #1
 */\n\n
OUTPUT);
$Output->Text->colorize();

$Output->writing("Cursor Output: writing method on Bootgly!\n");
$Output->writing("This feature allows writing to be slow and gradual...\n");
$Output->waiting = 10000;
$Output->writing("You can increase the speed using the property `waiting`: `\$Output->waiting = 10000;`\n");
