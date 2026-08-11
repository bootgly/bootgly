<?php
namespace Bootgly\CLI;


use const BOOTGLY_TTY;
use function count;
use function explode;
use function feof;
use function max;
use function min;
use function ord;
use function strncmp;
use function substr;
use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Input\Mousestrokes;
use Bootgly\CLI\Terminal\Reporting\Mouse;
use Bootgly\CLI\UI\Atoms\Scrollbar;


$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Scrollbar atom @;
 * @#yellow: @@: Demo 62 - Example #1 - standalone strip (wheel + hover + drag) @;
 * {$location}
 */\n\n
OUTPUT);

// @ A placed strip beside a numbered window over 100 rows — the wheel scrolls,
//   movement hovers the thumb, a left press on the strip aims and drags
$total = 100;
$height = 12;
$first = 0;

$Scrollbar = new Scrollbar($Output);
$Scrollbar->height = $height;
$Scrollbar->total = $total;

// ? Non-interactive output writes the strip rows plainly, in flow
if (BOOTGLY_TTY === false) {
   $Scrollbar->render();

   return;
}

$Scrollbar->row = 8;
$Scrollbar->column = 40;

$View = static function () use ($Output, $Scrollbar, &$first, $total, $height): void {
   // @ The visible window rows
   for ($index = 0; $index < $height; $index++) {
      $item = $first + $index + 1;

      $Output->Cursor->moveTo(line: 8 + $index, column: 1);
      $Output->escape('2K');
      $Output->render("@#Black:#{$item}@; Item {$item}");
   }

   // @ The strip follows the view
   $Scrollbar->first = $first;
   $Scrollbar->render();

   // @ Status line
   $last = min($first + $height, $total);
   $Output->Cursor->moveTo(line: 21, column: 1);
   $Output->escape('2K');
   $Output->render("@#Black:rows {$first}..{$last} of {$total} · wheel scrolls · drag the thumb · `q` quits@;");
};

$View();

// @ Drive the strip with the mouse and the keyboard
$Mouse = new Mouse($Input, $Output);
$Mouse->report(true);

$Input->configure(blocking: false, canonical: false, echo: false);
$Output->Cursor->hide();

$bottom = $total - $height;
$dragging = false;

while (true) {
   $key = $Input->read(1);

   if ($key === false || feof($Input->stream) === true) {
      break;
   }
   if ($key === '') {
      usleep(50000);

      continue;
   }

   // ? Escape sequences: CSI reads until its final byte (PgUp = `\e[5~`, mouse = `\e[<...M/m`)
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

   // ? Mouse reports: the wheel scrolls; the strip accepts hover, click and drag
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

      if ($Action === Mousestrokes::SCROLL_UP || $Action === Mousestrokes::SCROLL_DOWN) {
         $first = $Action === Mousestrokes::SCROLL_UP
            ? max(0, $first - 3)
            : min($bottom, $first + 3);

         $View();
      }
      else if ($dragging === true) {
         if ($state === Mousestrokes::UNCLICKED->value) {
            $dragging = false;
         }
         else {
            $first = $Scrollbar->aim($line);

            $View();
         }
      }
      else if ($Action === Mousestrokes::LEFT_CLICK && $state === Mousestrokes::CLICKED->value) {
         $hit = $Scrollbar->hit($column, $line);

         if ($hit !== null) {
            if ($hit === 'track') {
               $first = $Scrollbar->aim($line);

               $View();
            }

            $dragging = true;
         }
      }
      else if ($Action === Mousestrokes::NONE_CLICK_WITH_MOVEMENT) {
         $Scrollbar->hover($Scrollbar->hit($column, $line) === 'thumb');
      }

      continue;
   }

   // ? PgUp/PgDn page the window
   if ($key === Keystrokes::PAGEUP->value || $key === Keystrokes::PAGEDOWN->value) {
      $first = $key === Keystrokes::PAGEUP->value
         ? max(0, $first - ($height - 1))
         : min($bottom, $first + ($height - 1));

      $View();

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
$Output->Cursor->moveTo(line: 22, column: 1);
$Output->render("@.;@#Green:✔@; Scrollbar demo closed.@.;");
