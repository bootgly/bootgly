<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Question;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Question component @;
 * @#yellow: @@: Demo 26 - Example #4 - yes/no confirmation @;
 * {$location}
 */\n\n
OUTPUT);

$Question = new Question($Input, $Output);

// @ Confirm (yes/no — empty answer assumes the default: Y)
$confirmed = $Question->confirm('Do you like Bootgly?', default: true);

$Output->render(
   $confirmed
      ? "@.;You confirmed: @#green:yes@;!@.;"
      : "@.;You confirmed: @#red:no@;.@.;"
);

// @ Refusing default (y/N)
$deployed = $Question->confirm('Deploy to production?');

$Output->render(
   $deployed
      ? "@.;Deploying… @#green:confirmed@;!@..;"
      : "@.;Deploy @#red:skipped@;.@..;"
);
