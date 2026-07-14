<?php
namespace Bootgly\CLI;

use const BOOTGLY_TTY;
use const PHP_EOL;
use const Bootgly\CLI;

use Bootgly\CLI\Terminal\Input\Mousestrokes;
use Bootgly\CLI\Terminal\Reporting\Mouse;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal - Mouse Reporting @;
 * @#yellow: @@: Demo 23 - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);

// ? Mouse Reporting requires an interactive terminal (pipes and CI render a notice only)
if (BOOTGLY_TTY === false) {
   $Output->write('Mouse Reporting requires an interactive terminal.' . PHP_EOL);

   return;
}

$Output->render("@#cyan:Move the mouse over the terminal, click, drag, scroll...@;\n");
$Output->render("@#cyan:Right-click to exit.@;\n\n");

$Mouse = new Mouse($Input, $Output);

$Mouse->reporting(function (Mousestrokes $Action, array $coordinate, bool $clicking) use ($Output) {
   [$col, $row] = $coordinate;
   $action = $Action->name;
   $button = $clicking ? 'down' : 'up';

   $Output->write("Mouse {$action} at [{$col}, {$row}], button is {$button}" . PHP_EOL);

   if ($Action === Mousestrokes::RIGHT_CLICK) {
      return false;
   }

   return true;
});

$Output->render("\n@#green:Mouse Reporting disabled. Bye!@;\n");
