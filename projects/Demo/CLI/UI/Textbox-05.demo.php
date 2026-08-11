<?php
namespace Bootgly\CLI;

use function array_filter;
use function array_values;
use function stripos;
use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Textbox;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<TITLE
/* @*:
 * @#green: Bootgly CLI UI - Textbox component @;
 * @#yellow: @@: Demo 53 - Example #5 - search with a dynamic source @;
 * {$location}
 */\n\n
TITLE);

// @ Static options — typing filters (case-insensitive), ↑/↓ aim, Enter confirms
//   the aimed one (strict), Esc closes the list; key = returned value, item = shown label
$Textbox = new Textbox($Input, $Output);
$Textbox->prompt = '@*:Search a component@;';
$Textbox->hint = '(type to filter, ↑/↓ aim, Enter confirm, Esc close the list)';
$Textbox->options = [
   'alert' => 'Alert',
   'dialog' => 'Dialog',
   'filepicker' => 'Filepicker',
   'progress' => 'Progress',
   'prompt' => 'Prompt',
   'select' => 'Select',
   'textbox' => 'Textbox',
   'toasts' => 'Toasts',
   'tree' => 'Tree',
   'wizard' => 'Wizard'
];
$Textbox->viewport = 6;
$Textbox->strict = true;

$found = $Textbox->ask();

// @ Result
$result = $found !== ''
   ? "@#Green:✔@; You found: @#Cyan:{$found}@;"
   : '@#Yellow:●@; Nothing found.';

$Output->render("@.;{$result}@.;");

$Output->write("\n");

// @ Dynamic source — the Closure receives the query on every edit and filters
//   by itself (the static filter is bypassed); int keys return the label itself
$extensions = [
   'bcmath', 'curl', 'dom', 'fileinfo', 'gd', 'iconv', 'intl', 'json',
   'libxml', 'mbstring', 'mysqli', 'opcache', 'openssl', 'pcntl', 'pcre',
   'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'phar', 'posix', 'readline',
   'session', 'sockets', 'sodium', 'xdebug', 'xml', 'zip', 'zlib'
];

$Textbox = new Textbox($Input, $Output);
$Textbox->prompt = '@*:Search an extension@;';
$Textbox->hint = '(dynamic source — the lookup runs per keystroke)';
$Textbox->source = static function (string $query) use ($extensions): array {
   // @ Simulate a slow lookup
   usleep(80_000);

   // ? An empty query looks everything up
   if ($query === '') {
      // :
      return $extensions;
   }

   // :
   return array_values(array_filter(
      $extensions,
      static fn (string $extension): bool => stripos($extension, $query) !== false
   ));
};
$Textbox->strict = true;

$found = $Textbox->ask();

// @ Result
$result = $found !== ''
   ? "@#Green:✔@; You found: @#Cyan:{$found}@;"
   : '@#Yellow:●@; Nothing found.';

$Output->render("@.;{$result}@.;");
