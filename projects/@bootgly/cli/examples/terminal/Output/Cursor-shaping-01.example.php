<?php
namespace Bootgly\CLI;

use Bootgly\CLI;

$Output = CLI::$Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI Terminal (>) - Cursor Shaping @;
 * @#yellow: @@ Demo - Example #1 @;
 * {$location}
 */\n\n
OUTPUT);


$Output->write("Changing cursor shape to `block`:\n");
$Output->Cursor->shape('block');
sleep(2);

$Output->write("Changing cursor shape to `underline`:\n");
$Output->Cursor->shape('underline');
sleep(2);

$Output->write("Changing cursor shape to `bar`:\n");
$Output->Cursor->shape('bar');
sleep(2);
