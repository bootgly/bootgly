<?php
namespace Bootgly\CLI;

use Bootgly\CLI;

$Output = CLI::$Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal (>) - Cursor Visualizing @;
 * @#yellow: @@ Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);

$Output->write("Hiding cursor for 3 seconds...\n");
$Output->Cursor->hide();
sleep(3);

$Output->write("Showing cursor again...\n");
$Output->Cursor->show();
sleep(2);

$Output->write("Stopping cursor from blinking for 3 seconds...\n");
$Output->Cursor->blink(false);
sleep(3);

$Output->write("Making cursor blink again...\n");
$Output->Cursor->blink(true);
sleep(2);
