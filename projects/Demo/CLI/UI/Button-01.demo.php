<?php
namespace Bootgly\CLI;


use const BOOTGLY_TTY;
use function count;
use function date;
use function explode;
use function feof;
use function ord;
use function strncmp;
use function substr;
use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Input\Mousestrokes;
use Bootgly\CLI\Terminal\Reporting\Mouse;
use Bootgly\CLI\UI\Atoms\Button;


$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Button atom @;
 * @#yellow: @@: Demo 61 - Example #1 - hover pills + press Actions (mouse + keyboard) @;
 * {$location}
 */\n\n
OUTPUT);

// @ Four buttons — a bare label, a styled pill, a counter and an icon-only
//   power-off; hovering paints the background, a left click (or Enter on the
//   Tab-focused button) fires the Action
$presses = 0;
$running = true;
$Status = static function (string $message) use ($Output): void {
   $Output->Cursor->moveTo(line: 10, column: 1);
   $Output->escape('2K');
   $Output->Cursor->moveTo(line: 10, column: 2);
   $Output->render($message);
};

$Docs = new Button($Output);
$Docs->label = 'Docs';
$Docs->Action = static function (Button $Button) use (&$presses, $Status): void {
   $presses++;
   $Status("@#Cyan:{$Button->label}@; pressed — @#Black:press #{$presses}@;");
};

$Save = new Button($Output);
$Save->icon = '💾';
$Save->label = 'Save';
$Save->style = ['48;5;25', '97'];
$Save->hover = ['48;5;33', '97'];
$Save->Action = static function () use (&$presses, $Status): void {
   $presses++;
   $time = date('H:i:s');
   $Status("@#Green:Saved@; at {$time} — @#Black:press #{$presses}@;");
};

$Count = new Button($Output);
$Count->icon = '➕';
$Count->label = 'Count';
$Count->Action = static function () use (&$presses, $Status): void {
   $presses++;
   $Status("Counted — @#Black:press #{$presses}@;");
};

$Power = new Button($Output);
$Power->icon = '⏻';
$Power->Action = static function () use (&$running, $Status): void {
   $running = false;
   $Status('@#Red:Powering off...@;');
};

$Buttons = [$Docs, $Save, $Count, $Power];

// ? Non-interactive output writes the rows plainly, in flow
if (BOOTGLY_TTY === false) {
   foreach ($Buttons as $Button) {
      $Button->render();
   }

   return;
}

// @ Place the row of buttons — each render derives and stores the width,
//   so the next column chains from it
$column = 2;
foreach ($Buttons as $Button) {
   $Button->row = 8;
   $Button->column = $column;
   $Button->render();

   $column += $Button->width + 2;
}

$Status('@#Black:Waiting for a press...@;');
$Output->Cursor->moveTo(line: 12, column: 2);
$Output->render('@#Black:Hover paints · click presses · Tab cycles · Enter presses · `q` quits@;');

// @ Drive the buttons with the mouse and the keyboard
$Mouse = new Mouse($Input, $Output);
$Mouse->report(true);

$Input->configure(blocking: false, canonical: false, echo: false);
$Output->Cursor->hide();

while ($running === true) {
   $key = $Input->read(1);

   if ($key === false || feof($Input->stream) === true) {
      break;
   }
   if ($key === '') {
      usleep(50000);

      continue;
   }

   // ? Escape sequences: CSI reads until its final byte (mouse = `\e[<...M/m`)
   if ($key === "\e") {
      $next = (string) $Input->read(1);
      $key .= $next;

      if ($next === '[') {
         while (true) {
            $byte = (string) $Input->read(1);
            if ($byte === '') {
               break;
            }

            $key .= $byte;

            $final = ord($byte);
            if ($final >= 0x40 && $final <= 0x7E) {
               break;
            }
         }
      }
   }

   // ? Mouse reports: movement hovers, a left press on a hit fires the Action
   if (strncmp($key, "\e[<", 3) === 0) {
      $state = substr($key, -1);
      $parts = explode(';', substr($key, 3, -1));
      if (count($parts) !== 3) {
         continue;
      }

      [$button, $column, $line] = $parts;
      $column = (int) $column;
      $line = (int) $line;

      $Action = Mousestrokes::tryFrom($button);

      if ($Action === Mousestrokes::NONE_CLICK_WITH_MOVEMENT) {
         foreach ($Buttons as $Button) {
            $Button->hover($Button->hit($column, $line));
         }
      }
      else if ($Action === Mousestrokes::LEFT_CLICK && $state === Mousestrokes::CLICKED->value) {
         foreach ($Buttons as $Button) {
            if ($Button->hit($column, $line) === true) {
               $Button->press();

               break;
            }
         }
      }

      continue;
   }

   // ? Tab cycles the hovered button — the keyboard focus reuses the hover paint
   if ($key === Keystrokes::TAB->value) {
      $hovered = -1;
      foreach ($Buttons as $index => $Button) {
         if ($Button->hovered === true) {
            $hovered = $index;
         }

         $Button->hover(false);
      }

      $Buttons[($hovered + 1) % count($Buttons)]->hover(true);

      continue;
   }

   // ? Enter presses the hovered button
   if ($key === "\r" || $key === Keystrokes::ENTER->value) {
      foreach ($Buttons as $Button) {
         if ($Button->hovered === true) {
            $Button->press();

            break;
         }
      }

      continue;
   }

   // ? `q` (or Ctrl+C via the restore net) quits
   if ($key === 'q' || $key === Keystrokes::CTRL_C->value) {
      break;
   }
}

$Mouse->report(false);
$Input->configure(blocking: true, canonical: true, echo: true);
$Output->Cursor->show();
$Output->Cursor->moveTo(line: 14, column: 1);
$Output->render("@.;@#Green:✔@; Button demo closed — {$presses} presses.@.;");
