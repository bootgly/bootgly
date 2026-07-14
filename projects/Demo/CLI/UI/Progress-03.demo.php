<?php
namespace Bootgly\CLI;

use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Progress;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Progress component @;
 * @#yellow: @@: Demo 35 - Example #3 - multi-bar grid @;
 * {$location}
 */\n\n
OUTPUT);

// @ Four independent tracks in a 2-column grid
$Progress = new Progress($Output);
$Progress->throttle = 0.05;
$Progress->columns = 2;

$Download = $Progress->Bars->add('Download');
$Download->total = 100;

$Extract = $Progress->Bars->add('Extract ');
$Extract->total = 60;

$Build = $Progress->Bars->add('Build   ');
$Build->total = 80;

$Deploy = $Progress->Bars->add('Deploy  ');
$Deploy->total = 40;

$Progress->start();

// @@ Tracks advance at their own pace; tick() repaints the grid
for ($work = 0; $work < 100; $work++) {
   usleep(40_000);

   $Download->advance(1.0);
   $Extract->advance(0.7);
   $Build->advance(0.9);
   $Deploy->advance(0.5);

   $Progress->tick();
}

$Progress->finish();

$Output->render("@.;@#Green:✔@; All tracks complete.@.;");
