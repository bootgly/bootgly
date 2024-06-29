<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI\commands;

use Bootgly\ACI\Logs\Logger;

use Bootgly\CLI\Command;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;


return new class extends Command
{
   // * Config
   public string $name = 'connections';
   public string $description = 'Show server connections';


   public function run (array $arguments = [], array $options = []) : bool
   {
      // !
      // ** @var \Closure $context
      $context = $this->context;

      // @
      $context(function () {
         /** @var Server $Server */
         $Server = $this;

         Logger::$display = Logger::DISPLAY_MESSAGE;
   
         $worker = $Server->Process::$index;
   
         $Server->log(PHP_EOL . "Worker #{$worker}:" . PHP_EOL);
   
         foreach (Connections::$Connections as $Connection => $info) {
            $Server->log('Connection ID #' . $Connection . ':' . PHP_EOL, $Server::LOG_INFO_LEVEL);
   
            foreach ($info as $key => $value) {
               // @ Exclude
               switch ($key) {
                  case 'Connection':
                  case 'Socket':
                     continue 2;
               }
   
               $Server->log('@:notice: ' . $key . ': @; ');
   
               switch ($key) {
                  case 'status':
                     $status = (int) $value;

                     $status = match ($status) {
                        Connections::STATUS_INITIAL     => 'INITIAL',
                        Connections::STATUS_CONNECTING  => 'CONNECTING',
                        Connections::STATUS_ESTABLISHED => 'ESTABLISHED',
                        Connections::STATUS_CLOSING     => 'CLOSING',
                        Connections::STATUS_CLOSED      => 'CLOSED',
                        default                         => 'UNKNOWN'
                     };
   
                     $Server->log($status . PHP_EOL);
                     break;
   
                  case 'expiration':
                     $Server->log($value . ' second(s)' . PHP_EOL);
                     break;
   
                  case 'used':
                  case 'started':
                     $Server->log(\date('Y-m-d H:i:s', $value) . PHP_EOL);
                     break;
   
                  default:
                     if ( \is_array($value) ) {
                        $value = \count($value);
                     }
   
                     $Server->log($value . PHP_EOL);
               }
            }
         }
   
         if ( empty(Connections::$Connections) ) {
            $Server->log('No active connection.' . PHP_EOL, $Server::LOG_WARNING_LEVEL);
         }
      });

      return true;
   }
};
