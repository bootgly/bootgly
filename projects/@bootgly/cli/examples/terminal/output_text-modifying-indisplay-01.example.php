<?php
namespace Bootgly\CLI;

use Bootgly\CLI;

$Output = CLI::$Terminal->Output;
$Output->reset();
$Output->waiting = 50000; // @ Wait time in miliseconds to "writing" (per character written)

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal (>) - Text Modifying - Line - In Display @;
 * @#yellow: @@ Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);

$Output->writing("Writing something here...\n");
$Output->writing("---------------------\n");
$Output->writing("Writing again here...\n");
$Output->Cursor->up(2);
$Output->Cursor->moveTo(column: 1);
sleep(2);
$Output->Text->clear(down: true);
sleep(3);

$Output->writing("---------------------\n");
$Output->writing("Bootgly Bootgly Bootgly...\n");
$Output->Cursor->up(2);
$Output->Cursor->right(21);
sleep(2);
$Output->Text->clear(up: true);
