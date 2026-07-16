<?php
namespace Bootgly\CLI;

use function str_repeat;
use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\Terminal\Screen;
use Bootgly\CLI\UI\Components\Alert\Type;
use Bootgly\CLI\UI\Base\Frame;
use Bootgly\CLI\UX\Components\Toasts;
use Bootgly\CLI\UX\Components\Toasts\Positions;

$Output = CLI->Terminal->Output;

// @ Full-screen alternate buffer — a busy interface fills every cell
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
$App->title = '@#Green: Bootgly Dashboard @; — Demo 50.2: Center block, limit 2';

for ($row = 0; $row < $lines - 3; $row++) {
   $App->Output->write(str_repeat('▚▞', ($columns - 4) >> 1) . "\n");
}
$App->render();

// @ Center block — the whole stack centers vertically; limit keeps 2 visible
$Toasts = new Toasts($Output);
$Toasts->Positions = Positions::Center;
$Toasts->limit = 2;
$Toasts->cover($App);

$events = [
   5 => static fn () => $Toasts->add('Deploying release', Type::Default, TTL: 3.0),
   20 => static fn () => $Toasts->add('Migrating database', Type::Attention, TTL: 3.0),
   35 => static fn () => $Toasts->add('Smoke tests passed', Type::Success, TTL: 2.5),
   55 => static fn () => $Toasts->add('Live', Type::Success, TTL: 2.0),
];

for ($tick = 0; $tick <= 100; $tick++) {
   if (isset($events[$tick]) === true) {
      $events[$tick]();
   }

   $Toasts->render();

   usleep(50_000);
}

$Output->Cursor->show();
$Screen->close();

$Output->render("@.;@#Green:✔@; Toasts demo 50.2 (Center) complete.@.;");
