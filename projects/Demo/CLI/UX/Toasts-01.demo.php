<?php
namespace Bootgly\CLI;


use const STR_PAD_LEFT;
use function sprintf;
use function str_pad;
use function str_repeat;
use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Screen;
use Bootgly\CLI\UI\Components\Alert\Type;
use Bootgly\CLI\UI\Components\Frame;
use Bootgly\CLI\UX\Toasts;


$Output = CLI->Terminal->Output;

// @ Full-screen alternate buffer — a busy interface fills every cell, so the
//   toast overlay and its restore are unmistakable against real content
$Screen = new Screen($Output);
$Screen->open();
$Output->Cursor->hide();

[$columns, $lines] = Screen::measure();

// @ Background app — a dashboard sized to the whole terminal
$App = new Frame($Output);
$App->row = 1;
$App->column = 1;
$App->width = $columns;
$App->height = $lines;
$App->title = '@#Green: Bootgly Dashboard @; — Demo 50: corner toast notifications';

// @@ Fill the interior with a live-looking log stream (every row occupied)
$App->Output->write("@#Cyan:Requests@; 1024/s   @#Cyan:Latency@; 1.2ms   @#Cyan:Workers@; 15   @#Cyan:Uptime@; 42h\n");
$App->Output->write(str_repeat('▁▂▃▅▂▇▅▃', 16) . "\n");
$App->Output->write(str_repeat('─', $columns - 4) . "\n");
for ($row = 0; $row < $lines - 8; $row++) {
   $App->Output->write(sprintf(
      "@#Black:%s@; GET /api/v%d/resource/%04d  @#Green:200@;  %dms\n",
      '2026-07-15 12:00:' . str_pad((string) ($row % 60), 2, '0', STR_PAD_LEFT),
      ($row % 3) + 1,
      $row * 7 % 10000,
      ($row * 3 % 40) + 1
   ));
}
$App->render();

// @ Corner stack over the full-screen dashboard — staggered lifetimes, so the
//   mid-stack expiry recompaction (and the covered repaint underneath) is visible
$Toasts = new Toasts($Output);
$Toasts->cover($App);

$Toasts->add('Build started...', TTL: 6.0);

// @@ The app tick loop — toasts join and expire while the dashboard keeps running
$events = [
   10 => static fn () => $Toasts->add('Cache warmed', Type::Success, TTL: 2.0),
   20 => static fn () => $Toasts->add('Slow query detected', Type::Attention, TTL: 3.5),
   30 => static fn () => $Toasts->add('Worker #7 restarted', Type::Failure, TTL: 2.5),
   45 => static fn () => $Toasts->add('Assets published', Type::Success, TTL: 2.0),
];

for ($tick = 0; $tick <= 130; $tick++) {
   if (isset($events[$tick]) === true) {
      $events[$tick]();
   }

   $Toasts->render();

   usleep(50_000);
}

// @ Blocking convenience for linear scripts — paints, waits, restores
$Toasts->flash('Deploy complete. Bye!', Type::Success, TTL: 2.0);

usleep(400_000);

// @ Leave the alternate buffer — the terminal restores the shell untouched
$Output->Cursor->show();
$Screen->close();

$Output->render("@.;@#Green:✔@; Toasts demo complete.@.;");
