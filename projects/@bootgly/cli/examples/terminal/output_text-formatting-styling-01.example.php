<?php
namespace Bootgly\CLI;

use Bootgly\CLI;

$Output = CLI::$Terminal->Output;
$Output->reset();
$Output->waiting = 15000;

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal (>) - Text Formatting - Styling @;
 * @#yellow: @@ Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);
$Output->Text->colorize();

$Output->write(<<<OUTPUT
*
* Unique style
*\n
OUTPUT);
$Output->writing("Writing text in normal style...\n\n");


$Output->Text->stylize('bold');
$Output->writing("Writing text in bold style...\n");
$Output->Text->stylize();

$Output->Text->stylize('italic');
$Output->writing("Writing text in italic style...\n");
$Output->Text->stylize();

$Output->Text->stylize('underline');
$Output->writing("Writing text in underline style...\n");
$Output->Text->stylize();

$Output->Text->stylize('strike');
$Output->writing("Writing text in strike style...\n\n");
$Output->Text->stylize();

$Output->Text->stylize('blink');
$Output->writing("Writing text in blink style...\n\n");
#$Output->Text->stylize();

$Output->Text->stylize();
$Output->writing("Writing text in default style again...\n\n\n");

$Output->write(<<<OUTPUT
*
* Combined styles
*\n
OUTPUT);
$Output->Text->stylize('bold', 'italic');
$Output->writing("Writing text in bold and italic style...\n");
$Output->Text->stylize();

$Output->Text->stylize('underline', 'strike');
$Output->writing("Writing text in underline and strike style...\n");
$Output->Text->stylize();
