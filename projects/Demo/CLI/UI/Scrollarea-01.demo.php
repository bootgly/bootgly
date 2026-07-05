<?php
namespace Bootgly\CLI;


use const BOOTGLY_TTY;
use function count;
use function explode;
use function feof;
use function ord;
use function range;
use function strncmp;
use function substr;
use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Input\Mousestrokes;
use Bootgly\CLI\Terminal\Reporting\Mouse;
use Bootgly\CLI\UI\Components\Scrollarea;


$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Scrollarea component @;
 * @#yellow: @@: Demo - Example #1 - buffered content band (keyboard + mouse scroll) @;
 * {$location}
 */\n\n
OUTPUT);

// @ A content band pinned below the header — 60 fed rows, 12 visible;
//   PgUp/PgDn or the mouse wheel scroll the window; the scrollbar accepts
//   hover, click and drag; `q` quits
$Scrollarea = new Scrollarea($Output);
$Scrollarea->row = 8;
$Scrollarea->rows = 12;

$levels = ['@#green:info @;', '@#yellow:warn @;', '@#red:error@;'];
foreach (range(1, 60) as $index) {
   $level = $levels[$index % 3];
   $Scrollarea->feed("@#Black:#{$index}@; {$level} Fed row {$index} — the band buffers rows and follows the newest ones.");
}

// ? Non-interactive output already wrote the rows plainly
if (BOOTGLY_TTY === false) {
   return;
}

$Output->Cursor->moveTo(line: 21, column: 1);
$Output->render("@#Black:PgUp/PgDn or wheel scroll · drag the scrollbar · `q` quits@;");

// @ Drive the band with the keyboard and the mouse
$Mouse = new Mouse($Input, $Output);
$Mouse->report(true);

$Input->configure(blocking: false, canonical: false, echo: false);
$Output->Cursor->hide();

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

   // ? Mouse reports: the wheel scrolls; the scrollbar accepts hover, click and drag
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

      match ($Action) {
         Mousestrokes::SCROLL_UP => $Scrollarea->scroll(-3),
         Mousestrokes::SCROLL_DOWN => $Scrollarea->scroll(+3),
         default => null
      };

      if ($dragging === true) {
         $state === Mousestrokes::UNCLICKED->value
            ? $dragging = false
            : $Scrollarea->aim($line);
      }
      else if ($Action === Mousestrokes::LEFT_CLICK && $state === Mousestrokes::CLICKED->value) {
         $hit = $Scrollarea->hit($column, $line);

         if ($hit === 'thumb' || $hit === 'track') {
            if ($hit === 'track') {
               $Scrollarea->aim($line);
            }

            $dragging = true;
         }
      }
      else if ($Action === Mousestrokes::NONE_CLICK_WITH_MOVEMENT) {
         $Scrollarea->hover($Scrollarea->hit($column, $line) === 'thumb');
      }

      continue;
   }

   match ($key) {
      Keystrokes::PAGEUP->value => $Scrollarea->scroll(-11),
      Keystrokes::PAGEDOWN->value => $Scrollarea->scroll(+11),
      default => null
   };

   // ? `q` (or Ctrl+C via the restore net) quits
   if ($key === 'q' || $key === Keystrokes::CTRL_C->value) {
      break;
   }
}

$Mouse->report(false);
$Input->configure(blocking: true, canonical: true, echo: true);
$Output->Cursor->show();
$Output->Cursor->moveTo(line: 22, column: 1);
$Output->render("@.;@#Green:✔@; Scrollarea closed.@.;");
