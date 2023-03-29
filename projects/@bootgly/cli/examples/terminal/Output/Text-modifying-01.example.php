<?php
namespace Bootgly\CLI;

use Bootgly\CLI;

$Output = CLI::$Terminal->Output;
$Output->reset();
$Output->waiting = 30000;

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal (>) - Text Modifying @;
 * @#yellow: @@ Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);

$Output->writing("|Inserting 3 spaces at the current cursor position...\n");
$Output->Cursor->up(1);
$Output->Cursor->moveTo(column: 1);

$Output->Text->space(3);

$Output->Cursor->down(2);


$Output->writing("|Deleting 3 characters at the current cursor position...\n");
$Output->Cursor->up(1);
$Output->Cursor->moveTo(column: 1);

$Output->Text->delete(3);

$Output->Cursor->down(2);


$Output->writing("|Erasing 3 characters from the current cursor position...\n");
$Output->Cursor->up(1);
$Output->Cursor->moveTo(column: 1);

$Output->Text->erase(3);

$Output->Cursor->down(2);
