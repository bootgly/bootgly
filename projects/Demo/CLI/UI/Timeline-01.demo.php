<?php
namespace Bootgly\CLI;

use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Timeline;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Timeline component @;
 * @#yellow: @@: Demo 34 - Example #1 - multi-step guided flow @;
 * {$location}
 */\n\n
OUTPUT);

// @ Guided flow — steps transition pending → active → done (or failed)
$Timeline = new Timeline($Output);
$Timeline->add('Resolve');
$Timeline->add('Download');
$Timeline->add('Build');
$Timeline->add('Deploy');

$Timeline->start();
usleep(700_000);

$Timeline->advance('12 packages');
usleep(700_000);

$Timeline->advance('3.2 MB');
usleep(700_000);

$Timeline->advance();
usleep(700_000);

$Timeline->advance('v1.0.0 live');

$Output->render("@.;@#Green:✔@; Flow complete.@.;");
