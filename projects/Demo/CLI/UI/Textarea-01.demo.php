<?php
namespace Bootgly\CLI;

use function substr_count;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Textarea;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Textarea component @;
 * @#yellow: @@: Demo 39 - Example #1 - multiline editor @;
 * {$location}
 */\n\n
OUTPUT);

// @ Multiline editor — Enter breaks lines, arrows navigate, Ctrl+D submits
$Textarea = new Textarea($Input, $Output);
$Textarea->prompt = 'Commit message';
$Textarea->rows = 5;

$message = $Textarea->ask();

$lines = substr_count($message, "\n") + 1;
$Output->render("@.;Message captured (@#cyan:{$lines}@; lines):@.;{$message}@.;");
