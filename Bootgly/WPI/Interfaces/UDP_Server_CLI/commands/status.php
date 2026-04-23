<?php

namespace Bootgly\WPI\Interfaces\UDP_Server_CLI\commands;


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
use Bootgly\ABI\Data\__String\Path;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\CLI\Command;
use Bootgly\CLI\UI\Components\Fieldset;
use Bootgly\CLI\UI\Components\Progress;
use Bootgly\WPI\Interfaces\UDP_Server_CLI as Server;


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

      // @
      $context(function ()
      use (&$stat, &$stats) {
         /** @var Server $Server */
         $Server = $this; // @phpstan-ignore-line

         $Output = CLI->Terminal->Output;
         if ($Server->Mode === Modes::Monitor) {
            $Output->clear();
            $Output->render('>_ Type `@#Green:CTRL + Z@;` to enter in Interactive mode or `@#Green:CTRL + C@;` to stop the Server.@..;');
         }

         // ! Server
         $server = (new ReflectionClass($Server))->getName();
         $php = PHP_VERSION;
         // Runtime
         $runtime = [];
         $uptime = time() - $Server->started;
         $runtime['started'] = date('Y-m-d H:i:s', $Server->started);
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
         $load = ['-', '-', '-'];
         if ( function_exists('sys_getloadavg') ) {
            $system_load_average = sys_getloadavg() ?: [0, 0, 0];

            $load = array_map('round', $system_load_average, [2, 2, 2]);
         }
         $load = "{$load[0]}, {$load[1]}, {$load[2]}";

         // @ Workers
         $workers = $Server->workers;

         // @ Socket
         $address = $Server->socket . ($Server->domain ?? $Server->host) . ':' . $Server->port;

         // Event-loop
         $event = (new ReflectionClass($Server::$Event))->getName();

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
         $Fieldset2->width = 80;
         $Fieldset2->title = '@#Black: Workers Load (CPU usage) @;';
         $Fieldset2->content = PHP_EOL;

         $Progress = [];
         $Progress[0] = new Progress($Output);
         $Progress[0]->throttle = 0.0;
         $Progress[0]->Precision->percent = 0;
         $Progress[0]->render = Progress::RETURN_OUTPUT;
         $Progress[0]->total = 100;
         $Progress[0]->template = "[@bar;] @percent;%\n";
         $Bar = $Progress[0]->Bar;
         $Bar->units = 50;
         $Bar->Symbols->incomplete = '▁';
         $Bar->Symbols->current = '';
         $Bar->Symbols->complete = '▉';

         $PIDs = $Server->Process->Children->PIDs;
         foreach ($PIDs as $i => $PID) {
            $id = sprintf('%02d', $i + 1);
            $procPath = "/proc/$PID";

            if ( is_dir($procPath) ) {
               $process_stat = file_get_contents("$procPath/stat") ?: '';
               $process_stats = explode(' ', $process_stat);

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

               $utime1 = (float) $stats[$i][0][13];
               $stime1 = (float) $stats[$i][0][14];

               $utime2 = (float) $stats[$i][1][13];
               $stime2 = (float) $stats[$i][1][14];

               $userDiff = $utime2 - $utime1;
               $sysDiff = $stime2 - $stime1;

               $workerLoad = (int) abs($userDiff + $sysDiff);

               $Progress[$i]->start();
               $Progress[$i]->advance($workerLoad);

               $CPU_usage = $Progress[$i]->output;

               $Fieldset2->content .= " Worker #{$id}: {$CPU_usage}";

               $Progress[$i + 1] = clone $Progress[0];
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
