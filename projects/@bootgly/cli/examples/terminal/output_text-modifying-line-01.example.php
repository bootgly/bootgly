<?php
namespace Bootgly\CLI;

use Bootgly\CLI;

$Output = CLI::$Terminal->Output;
$Output->reset();
$Output->waiting = 100000; // @ Wait time in miliseconds to "writing" (per character written)

$Output->Text->colorize('green');
$Output->write(<<<OUTPUT
*
* Bootgly Terminal Output - Text Modifying - Line - Example #1
*\n\n
OUTPUT);
$Output->Text->colorize();

$Output->writing("...............\n");
$Output->Cursor->down(1);
$Output->writing("...............\n");

$Output->Cursor->up(2);
$Output->writing("* Inserting 3 lines below: *\n");

$Output->Text->insert(lines: 3);
#$Output->Text->Line->insert(n: 3);

$Output->Cursor->down(4);

$Output->writing("* Deleting lines added above... *\n");
$Output->Cursor->up(5);

$Output->Text->delete(lines: 3);
#$Output->Text->Line->delete(n: 3);

$Output->Cursor->down(3);