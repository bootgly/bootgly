<?php

use Bootgly\ABI\Data\__String\Bytes;
use Bootgly\ACI\Logs\Logger;

use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;


switch ($name) {
   // TODO move to Info class?
   case '@stats':
      Logger::$display = Logger::DISPLAY_MESSAGE;

      if ($this->Server === null) {
         return false;
      }

      $worker = \sprintf("%02d", $this->Server->Process::$index);

      $connections = $this->connections;

      $reads = \number_format(self::$reads, 0, '', ',');
      $writes = \number_format(self::$writes, 0, '', ',');

      // @ Format bytes
      $read = Bytes::format(self::$read);
      $written = Bytes::format(self::$written);

      $errors = [];
      $errors[0] = self::$errors['connection'];
      $errors[1] = self::$errors['read'];
      $errors[2] = self::$errors['write'];

      $this->log("@\;==================== @:info: Worker #{$worker} @; ====================@\;");
      if ($connections > 0) {
         $this->log(<<<OUTPUT
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
         $this->log(' -------------------- No data. -------------------- @\;', 2);
      }
      $this->log("====================================================@\\;");

      break;
   case '@stats reset':
      $this->connections = 0;

      self::$reads = 0;
      self::$writes = 0;

      self::$read = 0;
      self::$written = 0;

      self::$errors['connections'] = 0;
      self::$errors['read'] = 0;
      self::$errors['write'] = 0;

      break;

   case '@peers':
      Logger::$display = Logger::DISPLAY_MESSAGE;

      if ($this->Server === null) {
         return false;
      }

      $worker = $this->Server->Process::$index;

      $this->log(PHP_EOL . "Worker #{$worker}:" . PHP_EOL);

      foreach (self::$Connections as $Connection => $info) {
         $this->log('Connection ID #' . $Connection . ':' . PHP_EOL, self::LOG_INFO_LEVEL);

         foreach ($info as $key => $value) {
            // @ Exclude
            switch ($key) {
               case 'Connection':
               case 'Socket':
                  continue 2;
            }

            $this->log('@:notice: ' . $key . ': @; ');

            switch ($key) {
               case 'status':
                  $status = match ($value) {
                     Connections::STATUS_INITIAL     => 'INITIAL',
                     Connections::STATUS_CONNECTING  => 'CONNECTING',
                     Connections::STATUS_ESTABLISHED => 'ESTABLISHED',
                     Connections::STATUS_CLOSING     => 'CLOSING',
                     Connections::STATUS_CLOSED      => 'CLOSED'
                  };

                  $this->log($status . PHP_EOL);
                  break;

               case 'expiration':
                  $this->log($value . ' second(s)' . PHP_EOL);
                  break;

               case 'used':
               case 'started':
                  $this->log(\date('Y-m-d H:i:s', $value) . PHP_EOL);
                  break;

               default:
                  if ( \is_array($value) ) {
                     $value = \count($value);
                  }

                  $this->log($value . PHP_EOL);
            }
         }
      }

      if ( empty(self::$Connections) ) {
         $this->log('No active connection.' . PHP_EOL, self::LOG_WARNING_LEVEL);
      }

      break;
}
