<?php
namespace Bootgly\CLI;


use function number_format;
use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Atoms\Text\Effects;
use Bootgly\CLI\UI\Components\Spinner;


$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Spinner component @;
 * @#yellow: @@: Demo 32.1 - Example #2 - assistant style (star, shimmer, live status, tips) @;
 * {$location}
 */\n\n
OUTPUT);

// @ Assistant-style spinner — star set, shimmered description, live status and tips
$Spinner = new Spinner($Output);
$Spinner->set = 'star';
$Spinner->effect = Effects::Shimmer;
$Spinner->status = '@elapsed; · ↓ 0.0k tokens';
$Spinner->tips = [
   'Tip: you can control how big a workflow gets in /config.',
   'Tip: press Esc to interrupt the run at any time.'
];
$Spinner->rotation = 2.5;
$Spinner->start('Processing…');

for ($work = 0; $work < 60; $work++) {
   usleep(80_000); // simulated work

   // ? The status segment updates in real time — tokens grow as work happens
   $tokens = number_format($work * 0.7, 1);
   $Spinner->status = "@elapsed; · ↓ {$tokens}k tokens";

   $Spinner->spin();
}

$Spinner->finish('@#Green:✔@; Response ready.');
