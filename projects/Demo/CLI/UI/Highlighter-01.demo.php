<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Atoms\Highlighter;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Highlighter component @;
 * @#yellow: @@: Demo 55 - Example #1 - syntax-highlighted PHP in the terminal @;
 * {$location}
 */\n\n
OUTPUT);

// @ Bare colored lines (no gutter) — snippets and embeds
$Highlighter = new Highlighter($Output);
$Highlighter->gutter = false;
$Highlighter->source = <<<'PHP'
// Tagless snippets are colorized as pure PHP
$greeting = 'Hello, Bootgly!';
$count = 3;

echo "{$greeting} x{$count}";
PHP;
$Highlighter->render();

$Output->write("\n");

// @ Guttered excerpt with a marked line — as seen in framework error output
$Highlighter = new Highlighter($Output);
$Highlighter->mark = 6;
$Highlighter->source = <<<'PHP'
namespace App;

use Bootgly\CLI\UI\Atoms\Highlighter;

$Highlighter = new Highlighter($Output);
$Highlighter->mark = 6; // ← the marked line
$Highlighter->render();
PHP;
$Highlighter->render();
