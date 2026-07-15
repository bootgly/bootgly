<?php
namespace Bootgly\CLI;

use function str_repeat;
use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\Terminal\Screen;
use Bootgly\CLI\UI\Components\Alert\Type;
use Bootgly\CLI\UI\Components\Frame;
use Bootgly\CLI\UX\Toasts;
use Bootgly\CLI\UX\Toasts\Positions;

$Output = CLI->Terminal->Output;

// @ Full-screen alternate buffer — a busy interface fills every cell
$Screen = new Screen($Output);
$Screen->open();

[$columns, $lines] = Screen::measure();

// @ Background app — a dashboard sized to the whole terminal
$App = new Frame($Output);
$App->row = 1;
$App->column = 1;
$App->width = $columns;
$App->height = $lines;
$App->title = '@#Green: Bootgly Dashboard @; — Demo 50.3: BottomRight, ragged right-aligned widths';

for ($row = 0; $row < $lines - 3; $row++) {
   $App->Output->write(str_repeat('▁▂▃▄▅▆▇█▇▆▅▄▃▂', 8) . "\n");
}
$App->render();

// @ BottomRight — the newest grow upward; each box right-aligns to the edge, so
//   varied message lengths make a ragged left edge
$Toasts = new Toasts($Output);
$Toasts->Positions = Positions::BottomRight;
$Toasts->cover($App);

$events = [
   5 => static fn () => $Toasts->add('OK', Type::Success, TTL: 3.0),
   20 => static fn () => $Toasts->add('Rate limit approaching', Type::Attention, TTL: 3.0),
   35 => static fn () => $Toasts->add('Upstream 503', Type::Failure, TTL: 2.5),
   55 => static fn () => $Toasts->add('Failover to replica complete', Type::Success, TTL: 2.0),
];

for ($tick = 0; $tick <= 100; $tick++) {
   if (isset($events[$tick]) === true) {
      $events[$tick]();
   }

   $Toasts->render();

   usleep(50_000);
}

$Screen->close();

$Output->render("@.;@#Green:✔@; Toasts demo 50.3 (BottomRight) complete.@.;");
