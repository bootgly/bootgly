<?php
namespace Bootgly\CLI;

use const BOOTGLY_ROOT_DIR;

use const Bootgly\CLI;
use Bootgly\CLI\UX\Filepicker;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<TITLE
/* @*:
 * @#green: Bootgly CLI UX - Filepicker component @;
 * @#yellow: @@: Demo 52 - Example #1 - filesystem browser with lazy scans @;
 * {$location}
 */\n\n
TITLE);

// @ Pick a file — Enter drills into directories, only files confirm;
//   directories scan lazily on the first expand
$Filepicker = new Filepicker($Input, $Output);
$Filepicker->prompt = "@*:Pick a file@; @#Black:(↑/↓ move, →/← fold, Enter open/select, Esc cancel)@;";
$Filepicker->root = BOOTGLY_ROOT_DIR . 'projects';
$Filepicker->blink = true;

$picked = $Filepicker->pick();

// @ Result
$result = $picked !== null
   ? "@#Green:✔@; Filepicker demo complete — picked: @#Cyan:{$picked}@;"
   : '@#Yellow:●@; Filepicker demo complete — canceled (no path picked).';

$Output->render("@.;{$result}@.;");
