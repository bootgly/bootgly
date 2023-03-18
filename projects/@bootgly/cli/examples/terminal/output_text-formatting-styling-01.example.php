<?php
namespace Bootgly\CLI;

use Bootgly\CLI;

$Output = CLI::$Terminal->Output;
$Output->reset();
$Output->waiting = 15000;

/* 
 * Terminal Output - Text Formatting - Styling - Example #1
 */
$Output->writing("Writing text in normal style...\n\n");


$Output->Text->stylize('bold');
$Output->writing("Writing text in bold style...\n");

$Output->Text->stylize('italic');
$Output->writing("Writing text in italic style...\n");

$Output->Text->stylize('underline');
$Output->writing("Writing text in underline style...\n");

$Output->Text->stylize('strike');
$Output->writing("Writing text in strike style...\n\n");


$Output->Text->stylize();
$Output->writing("Writing text in default style again...\n");