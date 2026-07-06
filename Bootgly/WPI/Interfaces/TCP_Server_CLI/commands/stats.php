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


use function number_format;
use function sprintf;
use Closure;

use Bootgly\ABI\Data\__String\Bytes;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\CLI\Command;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;


return new class extends Command
{
   // * Config
   public string $name = 'stats';
   public string $description = 'Show server statistics';


   public function run (array $arguments = [], array $options = []): bool
   {
      // !
      /** @var null|Closure $context */
      $context = $this->context;
      if ($context === null) {
         return false;
      }

      // @
      $context(function ()
      use ($arguments) {
         /** @var Server $Server */
         $Server = $this; // @phpstan-ignore-line

         if ($arguments !== [] && $arguments[0] === 'reset') {
            $Server->Connections->connections = 0;
   
            Connections::$reads = 0;
            Connections::$writes = 0;
      
            Connections::$read = 0;
            Connections::$written = 0;
      
            Connections::$errors['connections'] = 0;
            Connections::$errors['read'] = 0;
            Connections::$errors['write'] = 0;
            return true;
         }

         // @ Lazy activation: collection is off by default (hot-path cost);
         //   first invocation turns it on — counters start from now.
         if (Connections::$stats === false) {
            Connections::$stats = true;

            Display::show(Display::MESSAGE);
            $Server->Logger->log(alert: "@\\;Stats collection was disabled — enabled now. Counters start from this point.@\\;");
         }

         Display::show(Display::MESSAGE);
   
         $worker = sprintf("%02d", $Server->Process::$index);
   
         $connections = $Server->Connections->connections;
   
         $reads = number_format(Connections::$reads, 0, '', ',');
         $writes = number_format(Connections::$writes, 0, '', ',');
   
         // @ Format bytes
         $read = Bytes::format(Connections::$read);
         $written = Bytes::format(Connections::$written);
   
         $errors = [];
         $errors[0] = Connections::$errors['connection'];
         $errors[1] = Connections::$errors['read'];
         $errors[2] = Connections::$errors['write'];
   
         $Server->Logger->log(debug: "@\;==================== @:info: Worker #{$worker} @; ====================@\;");
         if ($connections > 0) {
            $Server->Logger->log(debug: <<<OUTPUT
            Connections Accepted | @:notice: {$connections} connection(s) @;
            Connection Errors    | @:error: {$errors[0]} error(s) @;
             ---------------------------------------------------
            Data Reads Count     | @:notice: {$reads} time(s) @;
            Data Reads Bytes     | @:notice: {$read} @;
            Data Reads Errors    | @:error: {$errors[1]} error(s) @;
             ---------------------------------------------------
            Data Writes Count    | @:notice: {$writes} time(s) @;
            Data Writes Bytes    | @:notice: {$written} @;
            Data Writes Errors   | @:error: {$errors[2]} error(s) @;@\;
            OUTPUT);
         }
         else {
            $Server->Logger->log(alert: ' -------------------- No data. -------------------- @\;');
         }
         $Server->Logger->log(debug: "====================================================@\\;");
      });

      return true;
   }
};
