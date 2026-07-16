<?php
namespace Bootgly\CLI;

use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Tree;
use Bootgly\CLI\UI\Components\Tree\Node;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<TITLE
/* @*:
 * @#green: Bootgly CLI UI - Tree component @;
 * @#yellow: @@: Demo 51 - Example #1 - hierarchical picker with lazy children @;
 * {$location}
 */\n\n
TITLE);

// @ Build a project structure — some branches folded, one lazy
$Tree = new Tree($Input, $Output);

$Root = $Tree->add('@#Cyan:bootgly@;', value: '.');

$Bootgly = $Root->add('Bootgly', value: 'Bootgly');
$CLI = $Bootgly->add('CLI', value: 'Bootgly/CLI');
$CLI->add('Terminal', value: 'Bootgly/CLI/Terminal')->collapse();
$UI = $CLI->add('UI', value: 'Bootgly/CLI/UI');
$Components = $UI->add('Components', value: 'Bootgly/CLI/UI/Components');
$Components->add('Menu.php', value: 'Bootgly/CLI/UI/Components/Menu.php');
$Components->add('Tree.php', value: 'Bootgly/CLI/UI/Components/Tree.php');
$Bootgly->add('WPI', value: 'Bootgly/WPI')->collapse();

$Projects = $Root->add('projects', value: 'projects');
$Projects->add('Demo', value: 'projects/Demo');
$Projects->collapse();
// Programmable Enter — the action runs instead of confirming (explorer-style
// folding); return false from an action to confirm and finish
$Projects->action = static fn (Node $Node): Node => $Node->toggle();

// @ Lazy branch — children resolve on the first expand (press → on vendor)
$Vendor = $Root->add('vendor', value: 'vendor', resolver: static function (Node $Node): void {
   // Simulates a slow directory scan
   usleep(400_000);

   $Node->add('bootgly', value: 'vendor/bootgly');
   $Node->add('phpstan', value: 'vendor/phpstan');
   $Node->add('autoload.php', value: 'vendor/autoload.php');
});
// Per-node glyph override — any string works, emojis included
$Vendor->glyph = '📦';

$Composer = $Root->add('composer.json', value: 'composer.json');
$Composer->glyph = '📄';

// @ Static render — the same tree works as plain report output
$Output->render("@#Black:Static render — expanded branches only:@;\n");
$Tree->render();
$Output->write("\n");

usleep(900_000);

// @ Interactive picker — ↑/↓ aim, → expand, ← collapse, Space toggle,
//   Enter selects any node, Esc cancels
$Tree->prompt = "@*:Pick a path@; @#Black:(↑/↓ move, →/← fold, Enter select, Esc cancel)@;";
$Tree->viewport = 12;
$Tree->blink = true;

$Selected = $Tree->navigate();

// @ Result
$picked = $Selected !== null
   ? "@#Green:✔@; Tree demo complete — picked: @#Cyan:{$Selected->value}@;"
   : '@#Yellow:●@; Tree demo complete — canceled (no node picked).';

$Output->render("@.;{$picked}@.;");
