<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Atoms\Statusbar;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Statusbar component @;
 * @#yellow: @@: Demo 57 - Example #1 - single-row status bars @;
 * {$location}
 */\n\n
OUTPUT);

// @ App-style bar — screen context left, keybinding hints right
$Statusbar = new Statusbar($Output);
$Statusbar->left = ['Dashboard', 'main'];
$Statusbar->right = ['^P palette', '? help', 'q quit'];
$Statusbar->render();

$Output->write("\n");

// @ Custom divider + style (blue bar)
$Statusbar = new Statusbar($Output);
$Statusbar->divider = ' • ';
$Statusbar->style = ['44', '97'];
$Statusbar->left = ['bootgly', 'v1.0.0-beta', 'PHP 8.4'];
$Statusbar->right = ['UTF-8'];
$Statusbar->render();

$Output->write("\n");

// @ Segments carry their own colors — measuring stays escape-aware
$Statusbar = new Statusbar($Output);
$Statusbar->left = ["\e[32m● online\e[97m", 'workers: 15'];
$Statusbar->right = ["\e[33m▲ 2 warnings\e[97m"];
$Statusbar->render();
