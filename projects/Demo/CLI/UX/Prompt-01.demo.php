<?php
namespace Bootgly\CLI;


use const BOOTGLY_TTY;
use function array_fill;
use function array_filter;
use function array_values;
use function count;
use function date;
use function implode;
use function preg_split;
use function rand;
use function shell_exec;
use function str_contains;
use function str_starts_with;
use function substr;
use function trim;
use DateTime;
use DateTimeZone;
use Exception;

use const Bootgly\CLI;
use Bootgly\CLI\UX\Components\Prompt;


$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UX - Prompt component @;
 * @#yellow: @@: Demo 40 - Example #1 - bottom-fixed input (mini REPL) @;
 * {$location}
 */\n\n
OUTPUT);

// @ Mini REPL — the input stays fixed at the bottom; content scrolls above in a
//   buffered band: PgUp/PgDn or the mouse wheel scroll it, the scrollbar accepts
//   hover/click/drag and Ctrl+T toggles the selection mode (native select/copy).
//   Type and press Enter (↑/↓ walk rows then history; Shift+Enter multiline; `exit`, Ctrl+D or 2× Ctrl+C quits)
$Prompt = new Prompt($Input, $Output);
$Prompt->prompt = '> ';
$Prompt->top = ['left' => '@#Cyan:Bootgly REPL@;', 'right' => '@#Black:v0.20@;'];
$Prompt->bottom = ['left' => '', 'right' => '@#Black:0 lines@;'];
// ? Shortcut hint slots below the input box — key highlighted, action dim
$Prompt->shortcuts = [
   'Enter' => 'send',
   'Shift+Enter' => 'break',
   'Tab' => 'complete',
   'Esc' => 'close',
   '↑/↓' => 'aim/history',
   'PgUp/PgDn' => 'scroll',
   'Ctrl+T' => 'select',
   'Ctrl+D' => 'quit'
];
// ? Context-menu triggers — `/` lists commands, `@` mentions files (any symbol
//   works); ↑/↓ aim, Tab completes, Esc closes keeping the text
$Prompt->Listbox->blink = true;
// ? An active trigger recolors the input frame and swaps the marker
$Prompt->styles = [
   '/' => ['border' => '@#Cyan:', 'prompt' => '❯ '],
   '@' => ['border' => '@#Green:', 'prompt' => '@ '],
   '!' => ['border' => '@#Red:', 'prompt' => '! ']
];
// ? Only `!` is a mode — its symbol is absorbed into the marker (raw bash);
//   `@` stays literal in the text (several file mentions can compose a prompt)
$Prompt->modes = ['!'];
// ? Slash commands are single-line; `!` keeps the breaks — bash continues a
//   line with a trailing `\`, so multiline commands are legitimate there
$Prompt->breaks = ['/' => false];
$Prompt->triggers = [
   '/' => [
      '/help' => ['description' => 'List the available commands'],
      '/time' => ['skeleton' => '[timezone]', 'description' => 'Tell the current time'],
      '/date' => ['description' => 'Tell the current date'],
      '/echo' => ['skeleton' => '<text>', 'description' => 'Echo the text back'],
      '/clear' => ['description' => 'Clear the content band'],
      '/history' => ['description' => 'Count the submitted lines'],
      '/random' => ['skeleton' => '[max]', 'description' => 'Roll a random number'],
      '/repeat' => ['skeleton' => '<count> <text> [separator]', 'description' => 'Repeat the text N times'],
      '/version' => ['description' => 'Show the REPL version'],
      '/exit' => ['description' => 'Quit the REPL'],
   ],
   '!' => [
      '!ls' => ['skeleton' => '[path]', 'description' => 'List a directory'],
      '!pwd' => ['description' => 'Print the working directory'],
      '!date' => ['description' => 'Run the system date'],
      '!whoami' => ['description' => 'Show the current user'],
      '!uptime' => ['description' => 'Show the system uptime'],
   ],
   '@' => static function (string $query): array {
      $files = [
         '@README.md', '@composer.json', '@bootgly.php', '@docs/CLI.md',
         '@docs/UX.md', '@projects/Demo/CLI/UX/Prompt-01.demo.php',
         '@Bootgly/CLI/UX/Components/Prompt.php'
      ];

      // :
      return array_values(array_filter(
         $files,
         static fn (string $file): bool => str_contains($file, $query)
      ));
   }
];

$Prompt->start();

$Prompt->feed('@#Cyan:Mini REPL@; — type lines; `/` opens the command menu, `@` mentions files (↑/↓ aim, Tab completes, Esc closes); `exit`, Ctrl+D or 2× Ctrl+C quits.');

$submitted = 0;

foreach ($Prompt->prompting() as $line) {
   $line = trim($line);

   // ? `exit` and `/exit` quit
   if ($line === 'exit' || $line === '/exit') {
      break;
   }
   // ? `/help` opens a bottom sheet — the Flyout as a footer overlay with the
   //   command Listbox inside; Enter prefills the input with the pick
   if ($line === '/help') {
      $picked = $Prompt->pick(
         $Prompt->triggers['/'],
         title: '@#Cyan:Commands@;',
         hint: '↑/↓ aim · Enter picks into the input · Esc cancels'
      );

      if ($picked !== null) {
         $Prompt->Lines->load($picked);
      }
      // ? Piped input has no sheet — fall back to the plain list
      elseif (BOOTGLY_TTY === false) {
         $Prompt->feed('@#Cyan:/help@; · @#Cyan:/time@; · @#Cyan:/date@; · @#Cyan:/echo@; · @#Cyan:/history@; · @#Cyan:/random@; · @#Cyan:/version@; · @#Cyan:/exit@; — `@#Cyan:@@file@;` mentions a file');
      }

      continue;
   }
   // ? `/date` tells the date
   if ($line === '/date') {
      $date = date('Y-m-d');
      $Prompt->feed("@#Yellow:{$date}@;");

      continue;
   }
   // ? `/echo <text>` echoes back
   if (str_starts_with($line, '/echo') === true) {
      $text = trim(substr($line, 5));
      $Prompt->feed("@#Green:{$text}@;");

      continue;
   }
   // ? `/clear` clears the content band (buffer included)
   if ($line === '/clear') {
      $Prompt->Scrollarea->clear();

      continue;
   }
   // ? `/history` counts the submitted lines
   if ($line === '/history') {
      $count = count($Prompt->entries);
      $Prompt->feed("@#Cyan:{$count}@; entries in the history");

      continue;
   }
   // ? `/random [max]` rolls a number
   if (str_starts_with($line, '/random') === true) {
      $max = (int) trim(substr($line, 7));
      $rolled = rand(1, $max > 0 ? $max : 100);
      $Prompt->feed("@#Magenta:{$rolled}@;");

      continue;
   }
   // ? `/repeat <count> <text> [separator]` repeats the text
   if (str_starts_with($line, '/repeat') === true) {
      $arguments = preg_split('/\s+/', trim(substr($line, 7)), 3) ?: [];

      $count = (int) ($arguments[0] ?? 0);
      $text = $arguments[1] ?? '';
      $separator = $arguments[2] ?? ' ';

      // ? The count and the text are required — teach the syntax
      if ($count < 1 || $text === '') {
         $Prompt->feed('@#Red:Usage:@; /repeat @#Cyan:<count> <text> [separator]@;');

         continue;
      }

      $repeated = implode($separator, array_fill(0, $count, $text));
      $Prompt->feed("@#Green:{$repeated}@;");

      continue;
   }
   // ? `/version` shows the REPL version
   if ($line === '/version') {
      $Prompt->feed('Bootgly REPL @#Cyan:v0.20@;');

      continue;
   }
   // ? `!<command>` runs a raw terminal command
   if (str_starts_with($line, '!') === true) {
      $command = trim(substr($line, 1));

      if ($command === '') {
         $Prompt->feed('@#Red:Usage:@; !@#Cyan:<command>@;');

         continue;
      }

      // ? A shell `clear` means the band — its escapes could not clear it anyway
      if ($command === 'clear') {
         $Prompt->Scrollarea->clear();

         continue;
      }

      $result = trim((string) shell_exec("{$command} 2>&1"));
      $Prompt->feed($result === '' ? '@#Black:(no output)@;' : $result);

      continue;
   }
   // ? `/time [timezone]` tells the time
   if (str_starts_with($line, '/time') === true) {
      $zone = trim(substr($line, 5));

      try {
         $Time = new DateTime('now', $zone !== '' ? new DateTimeZone($zone) : null);
         $time = $Time->format('H:i:s');

         $suffix = $zone !== '' ? " @#Black:({$zone})@;" : '';
         $Prompt->feed("@#Yellow:{$time}@;{$suffix}");
      }
      catch (Exception) {
         $Prompt->feed("@#Red:Unknown timezone:@; {$zone}");
      }

      continue;
   }

   $submitted++;
   $time = date('H:i:s');

   // @ Fixed bottom-right text updates live
   $Prompt->bottom['right'] = "@#Black:{$submitted} lines@;";

   $Prompt->feed("@#Black:[{$time}]@; echo: @#green:{$line}@;");
}

$Prompt->finish();

$Output->render("@.;@#Green:✔@; REPL closed.@.;");
