<?php
namespace Bootgly\CLI;

use function str_repeat;
use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Frame;
use Bootgly\CLI\UX\Dialog;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<TITLE
/* @*:
 * @#green: Bootgly CLI UX - Dialog component @;
 * @#yellow: @@: Demo 49 - Example #1 - modal over covered frames @;
 * {$location}
 */\n\n
TITLE);

// @ Background app — the surface the modal covers
$App = new Frame($Output);
$App->row = 6;
$App->column = 2;
$App->width = 64;
$App->height = 12;
$App->title = 'My dashboard';

$App->Output->write("Requests: 1024/s\n");
$App->Output->write("Latency: 1.2ms\n");
$App->Output->write("Workers: 15\n");
$App->Output->write(str_repeat('▁▂▃▅▂▇', 10) . "\n");
$App->render();

usleep(900_000);

// @ Modal confirm over the dashboard — closing repaints the covered frame
$Dialog = new Dialog($Input, $Output);
$Dialog->width = 44;
$Dialog->height = 7;
$Dialog->Frame->title = 'Deploy';
$Dialog->cover($App);

$deploy = $Dialog->confirm('Deploy the app to production?', default: true);

usleep(600_000);

// @ Modal prompt — the same dialog reopens over the restored dashboard
$tag = $Dialog->prompt('Release tag', default: 'v1.0.0');

usleep(600_000);

// @ Modal alert — acknowledge and restore one last time
$Dialog->alert($deploy === true ? "Deploying {$tag}..." : 'Deploy skipped.');

usleep(600_000);

// @ Standalone session — `$screen` preserves the whole main buffer in the
//   terminal itself (no covered components needed)
$Standalone = new Dialog($Input, $Output);
$Standalone->screen = true;
$Standalone->width = 44;
$Standalone->height = 7;
$Standalone->Frame->title = 'Alternate screen';

$Standalone->alert('This box lives in the alternate screen.');

$deployed = $deploy === true ? 'yes' : 'no';

$Output->Cursor->moveTo(line: 19, column: 1);
$Output->render("@.;@#Green:✔@; Dialog demo complete — deploy: {$deployed}, tag: {$tag}.@.;");
