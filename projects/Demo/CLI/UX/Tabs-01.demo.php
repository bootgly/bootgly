<?php
namespace Bootgly\CLI;


use const BOOTGLY_TTY;
use function date;
use function sin;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Charts\Graph;
use Bootgly\CLI\UI\Components\Frame\Borders;
use Bootgly\CLI\UI\Components\Table;
use Bootgly\CLI\UX\Tabs;


$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Screen = CLI->Terminal->Screen;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UX - Tabs component @;
 * @#yellow: @@: Demo 47 - Example #1 - btop-like tabbed dashboard @;
 * {$location}
 */\n\n
OUTPUT);

// @ Three tab frames sharing one rectangle — the bar rides the active border;
//   ←/→ and Tab/Shift+Tab cycle, 1-3 jump, `q` quits
$Tabs = new Tabs($Input, $Output);
$Tabs->width = CLI->Terminal::$columns;
$Tabs->height = CLI->Terminal::$lines - 1;

$Log = $Tabs->add('Log');
$CPU = $Tabs->add('CPU');
$CPU->Borders = Borders::Round;
$Board = $Tabs->add('Table');

// @ Regular components bind to a tab frame's isolated Output and just work
$Graph = new Graph($CPU->Output);
$Graph->width = $CPU->columns;
$Graph->height = $CPU->lines;
$Graph->ceiling = 100.0;

// @ Static tab — rendered once into its frame, buffered until visited
$Table = new Table($Board->Output);
$Table->Data->Header->set([['Tab', 'Purpose']]);
$Table->Data->Body->set([
   ['Log',   'tail view — fed every tick'],
   ['CPU',   'braille graph — live values'],
   ['Table', 'static content — rendered once'],
]);
$Table->Data->Footer->set([['3 tabs', 'one shared rectangle']]);
$Table->render();

$Log->Output->render("@#Black:boot@; Tabs ready — feeding every tick\n");

$hint = '@#Black: ←/→ · Tab cycles · 1-3 jump · q quits @;';

if (BOOTGLY_TTY === true) {
   $Screen->open();

   // @ Terminal resizes reflow the shared rectangle
   $Screen->watch(function (int $columns, int $lines)
      use ($Output, $Tabs, $CPU, $Graph, $hint): void {
      $Tabs->resize($columns, $lines - 1);

      $Graph->width = $CPU->columns;
      $Graph->height = $CPU->lines;

      $Output->Cursor->moveTo(line: $lines, column: 1);
      $Output->render($hint);
   });

   $Output->Cursor->moveTo(line: CLI->Terminal::$lines, column: 1);
   $Output->render($hint);
}

// @@ Feed every tab each tick — inactive tabs stay buffered and bounded
$tick = 0;
foreach ($Tabs->switching() as $tab) {
   $tick++;

   $load = 50.0 + 35.0 * sin($tick / 4) + 12.0 * sin($tick / 1.7);
   $rounded = (int) $load;

   $time = date('H:i:s');
   $Log->Output->render("@#Black:{$time}@; load @#yellow:{$rounded}%@; fed row {$tick}\n");

   $CPU->clear();
   $Graph->feed($load);
   $Graph->render();
}

if (BOOTGLY_TTY === true) {
   $Screen->watch(null);
   $Screen->close();
}

$Output->render("@.;@#Green:✔@; Tabs closed.@.;");
