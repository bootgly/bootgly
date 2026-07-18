<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Heatmap;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Heatmap component @;
 * @#yellow: @@: Demo 60 - Example #1 - test suite dashboard cards @;
 * {$location}
 */\n\n
OUTPUT);

// @ A green suite — one cell per assertion (skipped cells sprinkled in)
$Heatmap = new Heatmap($Output);
$Heatmap->title = 'http';
$Heatmap->width = 64;
$cells = [];
for ($i = 0; $i < 415; $i++) {
   $cells[] = ($i % 16 === 7) ? 'skipped' : 'passed';
}
$Heatmap->cells = $cells;
$Heatmap->render();

$Output->write("\n");

// @ A suite with failures — red cells stand out against the pink
$Heatmap = new Heatmap($Output);
$Heatmap->title = 'websocket';
$Heatmap->width = 64;
$cells = [];
for ($i = 0; $i < 96; $i++) {
   $cells[] = ($i % 23 === 11) ? 'failed' : (($i % 9 === 4) ? 'skipped' : 'passed');
}
$Heatmap->cells = $cells;
$Heatmap->render();

$Output->write("\n");

// @ Custom palette + custom positive state — any states, any colors
$Heatmap = new Heatmap($Output);
$Heatmap->title = 'deploys';
$Heatmap->width = 64;
$Heatmap->palette = [
   'ok'   => '#7ec699',
   'warn' => '#e5c07b',
   'bad'  => '#e06c75',
];
$Heatmap->positive = 'ok';
$cells = [];
for ($i = 0; $i < 64; $i++) {
   $cells[] = ($i % 19 === 3) ? 'bad' : (($i % 7 === 2) ? 'warn' : 'ok');
}
$Heatmap->cells = $cells;
$Heatmap->render();
