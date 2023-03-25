<?php
namespace Bootgly\CLI;

use Bootgly\CLI;

$Output = CLI::$Terminal->Output;
$Output->reset();
$Output->waiting = 100000; // @ Wait time in miliseconds to "writing" (per character written)

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal (>) - Text Modifying - Line - Inline @;
 * @#yellow: @@ Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);

$Output->writing("Trim all text to the right of cursor... ->!@#$%^&");
$Output->Cursor->left(7);

sleep(2);

$Output->Text->trim(right: true);


$Output->Cursor->down(1)->Cursor->moveTo(column: 1);

$Output->writing("!@#$%^&<- Trim all text to the left of cursor...");
$Output->Cursor->moveTo(column: 7);

sleep(2);

$Output->Text->trim(left: true, right: false);
$Output->write("\n");


$Output->Cursor->down(1)->Cursor->moveTo(column: 1);

$Output->writing("Trim all text to the left of cursor... -><- and to the right of cursor...");
$Output->Cursor->moveTo(column: 42);

sleep(2);

$Output->Text->trim(left: true, right: true);
$Output->write("\n");
