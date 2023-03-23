<?php
namespace Bootgly\CLI;

use Bootgly\CLI;

$Output = CLI::$Terminal->Output;
$Output->reset();
$Output->waiting = 30000;

$Output->Text->colorize('green');
$Output->write(<<<OUTPUT
/*
 * Bootgly CLI Terminal (>) - Text Modifying - Example #1
 */\n\n
OUTPUT);
$Output->Text->colorize();

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
