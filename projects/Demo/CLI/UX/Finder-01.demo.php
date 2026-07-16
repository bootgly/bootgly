<?php
namespace Bootgly\CLI;

use function array_filter;
use function array_values;
use function stripos;
use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\UX\Components\Finder;

$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<TITLE
/* @*:
 * @#green: Bootgly CLI UX - Finder component @;
 * @#yellow: @@: Demo 53 - Example #1 - live search selector @;
 * {$location}
 */\n\n
TITLE);

// @ Static options — typing filters (case-insensitive), ↑/↓ aim, Enter confirms,
//   Esc cancels; key = returned value, item = shown label
$Finder = new Finder($Input, $Output);
$Finder->prompt = '@*:Search a component@;';
$Finder->hint = '(type to filter, ↑/↓ aim, Enter confirm, Esc cancel)';
$Finder->options = [
   'alert' => 'Alert',
   'dialog' => 'Dialog',
   'filepicker' => 'Filepicker',
   'finder' => 'Finder',
   'menu' => 'Menu',
   'progress' => 'Progress',
   'prompt' => 'Prompt',
   'toasts' => 'Toasts',
   'tree' => 'Tree',
   'wizard' => 'Wizard'
];
$Finder->viewport = 6;
$Finder->blink = true;

$found = $Finder->find();

// @ Result
$result = $found !== null
   ? "@#Green:✔@; You found: @#Cyan:{$found}@;"
   : '@#Yellow:●@; Canceled (nothing found).';

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

$Finder = new Finder($Input, $Output);
$Finder->prompt = '@*:Search an extension@;';
$Finder->hint = '(dynamic source — the lookup runs per keystroke)';
$Finder->source = static function (string $query) use ($extensions): array {
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

$found = $Finder->find();

// @ Result
$result = $found !== null
   ? "@#Green:✔@; You found: @#Cyan:{$found}@;"
   : '@#Yellow:●@; Canceled (nothing found).';

$Output->render("@.;{$result}@.;");
