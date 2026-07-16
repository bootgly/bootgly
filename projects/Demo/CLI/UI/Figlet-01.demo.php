<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Atoms\Figlet;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Figlet component @;
 * @#yellow: @@: Demo 58 - Example #1 - large glyph text in the terminal @;
 * {$location}
 */\n\n
OUTPUT);

// @ Letters — the shadow font (absorbed from the retired Header banner)
$Figlet = new Figlet($Output);
$Figlet->text = 'Bootgly';
$Figlet->render();

$Output->write("\n");

// @ Digits share the same font — versions, scores, clocks
$Figlet = new Figlet($Output);
$Figlet->text = 'v1 0 0';
$Figlet->render();
