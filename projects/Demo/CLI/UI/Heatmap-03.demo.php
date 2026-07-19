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
 * @#yellow: @@: Demo 60.2 - Example #3 - live failures + custom palette @;
 * {$location}
 */\n\n
OUTPUT);

// @ Failures land red as the run streams — the summary follows live
$expected = 96;

$Heatmap = new Heatmap($Output);
$Heatmap->width = 64;
$Heatmap->heading = '@#White:websocket@;';
$Heatmap->decoration = true;
$Heatmap->throttle = 0.0;
$Heatmap->start();

$failed = 0;
for ($i = 1; $i <= $expected; $i++) {
   $state = ($i % 17 === 8) ? 'failed' : (($i % 11 === 5) ? 'skipped' : 'passed');
   if ($state === 'failed') {
      $failed++;
   }

   $Heatmap->summary = "@:error:{$failed} failed@;";
   $Heatmap->caption = "@#Black:{$i} / {$expected} assertions@;";
   $Heatmap->feed($state);

   usleep(30000);
}

$Heatmap->finish();

$Output->write("\n");

// @ Custom palette, still live
$expected = 64;

$Heatmap = new Heatmap($Output);
$Heatmap->width = 64;
$Heatmap->heading = '@#White:deploys@;';
$Heatmap->decoration = true;
$Heatmap->throttle = 0.0;
$Heatmap->palette = [
   'ok'   => '#61afef',
   'warn' => '#e5c07b',
   'bad'  => '#e06c75',
];
$Heatmap->start();

for ($i = 1; $i <= $expected; $i++) {
   $Heatmap->caption = "@#Black:{$i} / {$expected} releases@;";
   $Heatmap->feed(($i % 19 === 3) ? 'bad' : (($i % 7 === 2) ? 'warn' : 'ok'));

   usleep(30000);
}

$Heatmap->finish();
