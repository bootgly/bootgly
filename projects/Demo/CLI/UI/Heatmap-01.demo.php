<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Heatmap;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Heatmap component @;
 * @#yellow: @@: Demo 60 - Example #1 - state-colored cell grids @;
 * {$location}
 */\n\n
OUTPUT);

// @ A bare grid — one cell per entry, wrapped by the width
$Heatmap = new Heatmap($Output);
$Heatmap->width = 64;
$cells = [];
for ($i = 0; $i < 415; $i++) {
   $cells[] = ($i % 16 === 7) ? 'skipped' : 'passed';
}
$Heatmap->cells = $cells;
$Heatmap->render();

$Output->write("\n");

// @ Corner labels — heading/summary above, caption/note below (markup ok)
$Heatmap = new Heatmap($Output);
$Heatmap->width = 64;
$cells = [];
$failed = 0;
for ($i = 0; $i < 96; $i++) {
   $state = ($i % 23 === 11) ? 'failed' : (($i % 9 === 4) ? 'skipped' : 'passed');
   if ($state === 'failed') {
      $failed++;
   }
   $cells[] = $state;
}
$Heatmap->cells = $cells;
$Heatmap->heading = '@#White:websocket@;';
$Heatmap->summary = "@:error:{$failed} failed@;";
$Heatmap->caption = '@#Black:96 assertions@;';
$Heatmap->note = '@#Black:suite 54@;';
$Heatmap->render();

$Output->write("\n");

// @ Custom palette — any states, any colors
$Heatmap = new Heatmap($Output);
$Heatmap->width = 64;
$Heatmap->palette = [
   'ok'   => '#61afef',
   'warn' => '#e5c07b',
   'bad'  => '#e06c75',
];
$cells = [];
for ($i = 0; $i < 64; $i++) {
   $cells[] = ($i % 19 === 3) ? 'bad' : (($i % 7 === 2) ? 'warn' : 'ok');
}
$Heatmap->cells = $cells;
$Heatmap->heading = '@#White:deploys@;';
$Heatmap->caption = '@#Black:last 64 releases@;';
$Heatmap->render();
