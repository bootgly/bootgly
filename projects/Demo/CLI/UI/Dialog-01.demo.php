<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Dialog;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Dialog component @;
 * @#yellow: @@: Demo - Example #1 - confirm, prompt and alert @;
 * {$location}
 */\n\n
OUTPUT);

$Dialog = new Dialog($Input, $Output);

// @ Confirm (yes/no — empty answer assumes the default)
$confirmed = $Dialog->confirm('Do you like Bootgly?', default: true);

$Output->render(
   $confirmed
      ? "@.;You confirmed: @#green:yes@;!@.;"
      : "@.;You confirmed: @#red:no@;.@.;"
);

// @ Prompt (raw answer with a default)
$name = $Dialog->prompt('What is your name?', default: 'anonymous');

$Output->render("@.;Hello, @#cyan:{$name}@;!@..;");

// @ Alert (renders and waits for Enter on interactive terminals)
$Dialog->alert('This demo is over.');
