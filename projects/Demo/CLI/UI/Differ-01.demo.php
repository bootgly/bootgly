<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Atoms\Differ;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Differ component @;
 * @#yellow: @@: Demo 59 - Example #1 - unified and side-by-side diff views @;
 * {$location}
 */\n\n
OUTPUT);

$from = <<<'TEXT'
server:
   host: localhost
   port: 8080
   workers: 4
   timeout: 30

TEXT;
$to = <<<'TEXT'
server:
   host: 0.0.0.0
   port: 8443
   workers: 4
   timeout: 30
   tls: true

TEXT;

// @ Unified view (default) — labeled headers, numbered hunks
$Differ = new Differ($Output);
$Differ->fromFile = 'a/server.yaml';
$Differ->toFile = 'b/server.yaml';
$Differ->from = $from;
$Differ->to = $to;
$Differ->render();

$Output->write("\n");

// @ Side-by-side view — two line-numbered columns, intra-line highlight
$Differ = new Differ($Output);
$Differ->split = true;
$Differ->fromFile = 'a/server.yaml';
$Differ->toFile = 'b/server.yaml';
$Differ->from = $from;
$Differ->to = $to;
$Differ->render();

$Output->write("\n");

// @ Context control — tight hunks on large files
$before = '';
$after = '';
for ($line = 1; $line <= 12; $line++) {
   $before .= "function step{$line} () {}\n";
   $after  .= $line === 6
      ? "function stepSix () {}\n"
      : "function step{$line} () {}\n";
}

$Differ = new Differ($Output);
$Differ->context = 1;
$Differ->fromFile = 'steps.php';
$Differ->toFile = 'steps.php';
$Differ->from = $before;
$Differ->to = $after;
$Differ->render();
