<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Server_CLI\commands;


use const PHP_EOL;
use function count;
use function date;
use function is_array;
use Closure;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Logger;
use Bootgly\CLI\Command;
use Bootgly\WPI\Interfaces\UDP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;


return new class extends Command
{
   // * Config
   public string $name = 'connections';
   public string $description = 'Show server connections';


   public function run (array $arguments = [], array $options = []): bool
   {
      // !
      /** @var null|Closure $context */
      $context = $this->context;
      if ($context === null) {
         return false;
      }

      // @
      $context(function () {
         /** @var Server $Server */
         $Server = $this; // @phpstan-ignore-line

         Display::$mode = Display::MESSAGE;

         $worker = $Server->Process::$index;

         $Server->Logger->log(debug: PHP_EOL . "Worker #{$worker}:" . PHP_EOL);

         /** @var Connections $Connection */
         foreach (Connections::$Connections as $peer => $Connection) {
            $Server->Logger->log(info: 'Peer #' . $peer . ':' . PHP_EOL);

            $Connection = (array) $Connection;
            foreach ($Connection as $key => $value) {
               // @ Exclude
               switch ($key) {
                  case 'Connection':
                  case 'Logger':
                  case 'Socket':
                     continue 2;
               }

               $Server->Logger->log(debug: '@:notice: ' . $key . ': @; ');

               switch ($key) {
                  case 'status':
                     $status = (int) $value; // @phpstan-ignore-line

                     $status = match ($status) {
                        Connections::STATUS_INITIAL     => 'INITIAL',
                        Connections::STATUS_CONNECTING  => 'CONNECTING',
                        Connections::STATUS_ESTABLISHED => 'ESTABLISHED',
                        Connections::STATUS_CLOSING     => 'CLOSING',
                        Connections::STATUS_CLOSED      => 'CLOSED',
                        default                         => 'UNKNOWN'
                     };

                     $Server->Logger->log(debug: $status . PHP_EOL);
                     break;

                  case 'expiration':
                     $Server->Logger->log(debug: $value . ' second(s)' . PHP_EOL); // @phpstan-ignore-line
                     break;

                  case 'used':
                  case 'started':
                     $Server->Logger->log(debug: date('Y-m-d H:i:s', $value) . PHP_EOL); // @phpstan-ignore-line
                     break;

                  default:
                     // @phpstan-ignore-next-line
                     if ( is_array($value) ) {
                        $value = count($value);
                     }

                     $Server->Logger->log(debug: $value . PHP_EOL); // @phpstan-ignore-line
               }
            }
         }

         if ( empty(Connections::$Connections) ) {
            $Server->Logger->log(warning: 'No active peer.' . PHP_EOL);
         }
      });

      return true;
   }
};
