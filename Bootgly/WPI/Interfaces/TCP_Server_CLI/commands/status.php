<?php

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI\commands;


use const BOOTGLY_ROOT_DIR;
use const PHP_EOL;
use const PHP_VERSION;
use function abs;
use function array_map;
use function date;
use function explode;
use function file_get_contents;
use function function_exists;
use function is_dir;
use function sprintf;
use function sys_getloadavg;
use function time;
use Closure;
use ReflectionClass;

use const Bootgly\CLI;
use Bootgly\ABI\Code\__String\Path;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\CLI\Command;
use Bootgly\CLI\UI\Base\Fieldset;
use Bootgly\CLI\UI\Components\Progress;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as Server;


return new class extends Command
{
   // * Config
   public string $name = 'status';
   public string $description = 'Show server status';
   // * Metadata
   // Process
   private static int $stat = -1;
   /** @var array<int,int> */
   private static array $stats = [];
   /**
    * PID that owned each worker index when its CPU sample was taken.
    *
    * @var array<int,int>
    */
   private static array $owners = [];


   public function run (array $arguments = [], array $options = []): bool
   {
       // !
      /** @var null|Closure $context */
      $context = $this->context;
      if ($context === null) {
         return false;
      }

      // * Metadata
      $stat = &self::$stat;
      $stats = &self::$stats;
      $owners = &self::$owners;

      // @
      $context(function ()
      use (&$stat, &$stats, &$owners) {
         /** @var Server $Server */
         $Server = $this; // @phpstan-ignore-line

         $Output = CLI->Terminal->Output;
         if ($Server->Mode === Modes::Monitor) {
            $Output->clear();
            $Output->render('>_ Type `@#Green:CTRL + Z@;` to enter in Interactive mode or `@#Green:CTRL + C@;` to stop the Server.@..;');
         }

         // ! Server
         // @
         $server = (new ReflectionClass($Server))->getName();
         $php = PHP_VERSION;
         // Runtime
         $runtime = [];
         $uptime = time() - $Server->started;
         $runtime['started'] = date('Y-m-d H:i:s', $Server->started);
         // @ uptime (d = days, h = hours, m = minutes)
         if ($uptime > 60) {
            $uptime += 30;
         }
         $runtime['d'] = (int) ($uptime / (24 * 60 * 60)) . 'd ';
         $uptime %= (24 * 60 * 60);
         $runtime['h'] = (int) ($uptime / (60 * 60)) . 'h ';
         $uptime %= (60 * 60);
         $runtime['m'] = (int) ($uptime / 60) . 'm ';
         $uptime %= 60;
         $runtime['s'] = (int) ($uptime) . 's ';
         $uptimes = $runtime['d'] . $runtime['h'] . $runtime['m'] . $runtime['s'];

         // @ System
         // Load Average
         $load = ['-', '-', '-'];
         if ( function_exists('sys_getloadavg') ) {
            $system_load_average = sys_getloadavg() ?: [0, 0, 0];

            $load = array_map('round', $system_load_average, [2, 2, 2]);
         }
         $load = "{$load[0]}, {$load[1]}, {$load[2]}";

         // @ Workers
         // count
         $workers = $Server->workers;

         // @ Socket
         // address
         $address = $Server->socket . ($Server->domain ?? $Server->host) . ':' . $Server->port;

         // Event-loop
         $event = (new ReflectionClass($Server::$Event))->getName();

         // Script
         // TODO

         // SAPI
         $SAPI = SAPI::$production !== ''
            ? Path::relativize(SAPI::$production, BOOTGLY_ROOT_DIR)
            : 'N/A';
         $Decoder = (Server::$Decoder
            ? Server::$Decoder::class
            : 'N/A'
         );
         $Encoder = (Server::$Encoder
            ? Server::$Encoder::class
            : 'N/A'
         );

         // @ Server Status
         $Fieldset = new Fieldset($Output);
         // * Config
         $Fieldset->width = 80;
         // * Data
         $Fieldset->title = '@#Black: Server Status @;';
         $Fieldset->content = <<<OUTPUT
         
         @#Cyan:  Bootgly Server: @; {$server}
         @#Cyan:  PHP version: @; {$php}\t\t\t
         
         @#Cyan:  Started time: @; {$runtime['started']}\t@:i: Uptime: @; {$uptimes}
         @#Cyan:  Workers count: @; {$workers}\t\t\t@:i: Load average: @; {$load}
         @#Cyan:  Socket address: @; {$address}
         
         @#cyan:  Event-loop: @; {$event}
         
         @#yellow:  Server API: @; {$SAPI}
         @#yellow:  Server Decoder: @; {$Decoder}
         @#yellow:  Server Encoder: @; {$Encoder}
         
         OUTPUT;
         $Fieldset->render();

         // @ Workers Load
         $Fieldset2 = new Fieldset($Output);
         // * Config
         $Fieldset2->width = 80;
         // * Data
         $Fieldset2->title = '@#Black: Workers Load (CPU usage) @;';
         $Fieldset2->content = PHP_EOL;
   
         // TODO use only Progress\Bar
         // ! One configured template, cloned per worker inside the loop. It must
         //   NOT be an array keyed by the `PIDs` key: `Process::recover()` unsets
         //   the dead index and `revive()` re-pushes it, which APPENDS to the
         //   ordered hash — so after one crash+refork a 2-worker master iterates
         //   as [1,0] and any `$Progress[$i]` lookup throws `Undefined array key`,
         //   which `Errors::collect()` escalates into a fatal that takes the whole
         //   server down from a read-only command.
         $Template = new Progress($Output);
         // * Config
         $Template->throttle = 0.0;
         $Template->Precision->percent = 0;
         // @ render
         $Template->render = Progress::RETURN_OUTPUT;
         // * Data
         $Template->total = 100;
         // ! Templating
         $Template->template = "[@bar;] @percent;%\n";
         // _ Bar
         $Bar = $Template->Bar;
         // * Config
         $Bar->units = 50;
         // * Data
         $Bar->Symbols->incomplete = '▁';
         $Bar->Symbols->current = '';
         $Bar->Symbols->complete = '▉';

         $PIDs = $Server->Process->Children->PIDs;
         foreach ($PIDs as $i => $PID) {
            // @ Worker
            $id = sprintf('%02d', $i + 1);
            // @ System
            $procPath = "/proc/$PID";

            if ( is_dir($procPath) ) {
               $process_stat = file_get_contents("$procPath/stat") ?: '';
               $process_stats = explode(' ', $process_stat);

               // ? A reforked worker reuses its index with a fresh PID, and its
               //   /proc counters restart at zero — keeping the dead worker's
               //   sample would make the first delta after a refork nonsense.
               //   Both slots are seeded from this same read (never unset, or the
               //   `case 0`/`case 1` arms below would leave one of them missing),
               //   so the first delta for a new worker is exactly zero.
               if (($owners[$i] ?? null) !== $PID) {
                  $owners[$i] = $PID;
                  $stats[$i] = [$process_stats, $process_stats]; // @phpstan-ignore-line
               }

               $stats[$i] ??= [];

               switch ($stat) {
                  case 0:
                     $stats[$i][0] = $process_stats; // @phpstan-ignore-line
                     break;
                  case 1:
                     $stats[$i][1] = $process_stats; // @phpstan-ignore-line
                     break;
                  default:
                     $stats[$i][0] = $process_stats; // @phpstan-ignore-line
                     $stats[$i][1] = $process_stats; // @phpstan-ignore-line
               }

               // CPU time spent in user code
               $utime1 = (float) $stats[$i][0][13];
               // CPU time spent in kernel code
               $stime1 = (float) $stats[$i][0][14];
   
               // CPU time spent in user code
               $utime2 = (float) $stats[$i][1][13];
               // CPU time spent in kernel code
               $stime2 = (float) $stats[$i][1][14];
   
               $userDiff = $utime2 - $utime1;
               $sysDiff = $stime2 - $stime1;

               $workerLoad = (int) abs($userDiff + $sysDiff);

               // @ Output
               $Progress = clone $Template;
               $Progress->start();
               $Progress->advance($workerLoad);

               $CPU_usage = $Progress->output;

               $Fieldset2->content .= " Worker #{$id}: {$CPU_usage}";
            }
            else {
               $Fieldset2->content .= <<<OUTPUT
                Worker #{$id} with PID $PID not found. \n
               OUTPUT;
            }
         }
         $Fieldset2->render();

         $stat = match ($stat) {
            0 => 1,
            1 => 0,
            default => 0
         };
      });

      return true;
   }
};
