<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI\commands;


use const FILTER_VALIDATE_IP;
use const PHP_EOL;
use function count;
use function date;
use function filter_var;
use function max;
use function strlen;
use Closure;
use Throwable;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\CLI\Command;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;


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

      try {
         // @
         $context(function () {
            /** @var Server $Server */
            $Server = $this; // @phpstan-ignore-line

            Display::show(Display::MESSAGE);

            $worker = $Server->Process::$index;

            $Server->Logger->log(debug: PHP_EOL . "Worker #{$worker}:" . PHP_EOL);

            foreach (Connections::$Connections as $Connection) {
               $Server->Logger->log(info: 'Connection ID #' . $Connection->id . ':' . PHP_EOL);

               $status = match ($Connection->status) {
                  Connections::STATUS_INITIAL     => 'INITIAL',
                  Connections::STATUS_CONNECTING  => 'CONNECTING',
                  Connections::STATUS_ESTABLISHED => 'ESTABLISHED',
                  Connections::STATUS_CLOSING     => 'CLOSING',
                  Connections::STATUS_CLOSED      => 'CLOSED',
                  default                         => 'UNKNOWN'
               };
               $IP = filter_var($Connection->ip, FILTER_VALIDATE_IP);

               // ! Security boundary: diagnostic output is an explicit scalar
               //   allowlist. Remote buffers are represented only by lengths;
               //   protocol objects only by fixed presence/state labels.
               $metadata = [
                  'status' => $status,
                  'IP' => $IP === false ? 'UNKNOWN' : $IP,
                  'port' => (string) $Connection->port,
                  'encrypted' => $Connection->encrypted ? 'YES' : 'NO',
                  'handshaking' => $Connection->handshaking ? 'YES' : 'NO',
                  'expiration' => $Connection->expiration . ' second(s)',
                  'started' => date('Y-m-d H:i:s', $Connection->started),
                  'used' => date('Y-m-d H:i:s', $Connection->used),
                  'writes' => (string) $Connection->writes,
                  'input bytes' => (string) strlen($Connection->input),
                  'output bytes' => (string) strlen($Connection->output),
                  'pending bytes' => (string) max(
                     0,
                     strlen($Connection->pendingBuffer) - $Connection->pendingOffset,
                  ),
                  'consumed bytes' => (string) $Connection->consumed,
                  'rejected' => $Connection->rejected ? 'YES' : 'NO',
                  'decoder' => $Connection->Decoder === null ? 'DEFAULT' : 'CUSTOM',
                  'encoder' => $Connection->Encoder === null ? 'DEFAULT' : 'CUSTOM',
                  'decoded' => $Connection->decoded === null ? 'NONE' : 'PRESENT',
                  'downloads' => (string) count($Connection->downloading),
                  'uploads' => (string) count($Connection->uploading),
               ];

               foreach ($metadata as $key => $value) {
                  $Server->Logger->log(debug: '@:notice: ' . $key . ': @; ');
                  $Server->Logger->log(debug: $value . PHP_EOL);
               }
            }

            if ( empty(Connections::$Connections) ) {
               $Server->Logger->log(warning: 'No active connection.' . PHP_EOL);
            }

            // @ Fixed completion oracle: callers/tests can distinguish a
            //   complete diagnostic from a safely contained partial failure.
            $Server->Logger->log(debug: 'Connections diagnostic complete.' . PHP_EOL);
         });
      }
      catch (Throwable) {
         // ! A diagnostic command runs from SIGIOT in each worker. Even an
         //   unexpected formatter/runtime failure must not escape that signal
         //   callback and terminate the worker.
         return false;
      }

      return true;
   }
};
