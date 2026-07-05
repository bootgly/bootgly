<?php
namespace Bootgly\CLI;

use function date;
use function trim;

use const Bootgly\CLI;
use Bootgly\CLI\UX\Prompt;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UX - Prompt component @;
 * @#yellow: @@: Demo - Example #1 - bottom-fixed input (mini REPL) @;
 * {$location}
 */\n\n
OUTPUT);

// @ Mini REPL — the input stays fixed at the bottom; content scrolls above in a
//   buffered band: PgUp/PgDn or the mouse wheel scroll it, the scrollbar accepts
//   hover/click/drag and Ctrl+T toggles the selection mode (native select/copy).
//   Type and press Enter (↑/↓ recall history; Alt+Enter multiline; `exit`, Ctrl+D or 2× Ctrl+C quits)
$Prompt = new Prompt($Input, $Output);
$Prompt->prompt = '>_ ';
$Prompt->top = ['left' => '@#Cyan:Bootgly REPL@;', 'right' => '@#Black:v0.20@;'];
$Prompt->bottom = ['left' => '@#Black:`exit`, Ctrl+D or 2× Ctrl+C quits · wheel/PgUp/PgDn scroll · Ctrl+T select · ↑/↓ history@;', 'right' => '@#Black:0 lines@;'];

$Prompt->start();

$Prompt->feed('@#Cyan:Mini REPL@; — type lines; wheel scrolls; Ctrl+T toggles text selection; `exit`, Ctrl+D or 2× Ctrl+C quits.');

$submitted = 0;

foreach ($Prompt->prompting() as $line) {
   // ? `exit` quits
   if (trim($line) === 'exit') {
      break;
   }

   $submitted++;
   $time = date('H:i:s');

   // @ Fixed bottom-right text updates live
   $Prompt->bottom['right'] = "@#Black:{$submitted} lines@;";

   $Prompt->feed("@#Black:[{$time}]@; echo: @#green:{$line}@;");
}

$Prompt->finish();

$Output->render("@.;@#Green:✔@; REPL closed.@.;");
