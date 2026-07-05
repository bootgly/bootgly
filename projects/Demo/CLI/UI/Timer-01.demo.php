<?php
namespace Bootgly\CLI;

use function number_format;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Timer;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Timer component @;
 * @#yellow: @@: Demo - Example #1 - countdown with callback @;
 * {$location}
 */\n\n
OUTPUT);

// @ 5-second countdown — the Handler fires once at zero
$Timer = new Timer($Output);
$Timer->seconds = 5.0;
$Timer->template = '⏳ @remaining;s (@percent;%) @description;';
$Timer->Handler = static function (Timer $Timer) use ($Output): void {
   $elapsed = number_format($Timer->elapsed, 2);

   $Output->render("@.;@#Green:✔@; Countdown finished after @#cyan:{$elapsed}@;s.@.;");
};

$Timer->run('Launching...');
