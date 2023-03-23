<?php
namespace Bootgly\CLI;

use Bootgly\CLI;

$Output = CLI::$Terminal->Output;
$Output->reset();

$Output->Text->colorize('green');
$Output->write(<<<OUTPUT
/*
 * Bootgly CLI Terminal (>) - Cursor Shaping - Example #1
 */\n\n
OUTPUT);
$Output->Text->colorize();


$Output->write("Changing cursor shape to `block`:\n");
$Output->Cursor->shape('block');
sleep(2);

$Output->write("Changing cursor shape to `underline`:\n");
$Output->Cursor->shape('underline');
sleep(2);

$Output->write("Changing cursor shape to `bar`:\n");
$Output->Cursor->shape('bar');
sleep(2);
