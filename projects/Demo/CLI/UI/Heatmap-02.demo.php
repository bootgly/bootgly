<?php
namespace Bootgly\CLI;

use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Heatmap;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Heatmap component @;
 * @#yellow: @@: Demo 60.1 - Example #2 - live streaming grid @;
 * {$location}
 */\n\n
OUTPUT);

// @ Cells paint as results arrive — start / feed / finish; the labels are
//   plain properties, so the host updates them mid-stream
$expected = 180;

$Heatmap = new Heatmap($Output);
$Heatmap->width = 64;
$Heatmap->heading = '@#White:http@;';
$Heatmap->decoration = true; // force live even when piped (demo)
$Heatmap->throttle = 0.0;    // paint every feed — the sleep paces the demo
$Heatmap->start();

for ($i = 1; $i <= $expected; $i++) {
   $Heatmap->caption = "@#Black:{$i} / {$expected} assertions@;";
   $Heatmap->feed(($i % 23 === 11) ? 'skipped' : 'passed');

   usleep(25000);
}

$Heatmap->finish();
