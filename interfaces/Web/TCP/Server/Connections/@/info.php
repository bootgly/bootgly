<?php
use Bootgly\Web\TCP\Server\Connections\Connection;

switch ($name) {
   // TODO move to Info class?
   case '@stats':
      if ($this->Server === null) {
         return false;
      }

      $worker = sprintf("%02d", $this->Server->Process::$index);

      $connections = $this->connections;

      $reads = number_format($this->Data->reads, 0, '', ',');
      $writes = number_format($this->Data->writes, 0, '', ',');

      $read = round($this->Data->read / 1024 / 1024, 2);
      $written = round($this->Data->written / 1024 / 1024, 2);

      $errors = [];
      $errors[0] = $this->errors;
      $errors[1] = $this->Data->errors['read'];
      $errors[2] = $this->Data->errors['write'];

      $this->log("@\;==================== @:success: Worker #{$worker} @; ====================@\;");
      if ($connections > 0) {
         $this->log(<<<OUTPUT
         Connections Accepted | @:info: {$connections} connection(s) @;
         Connection Errors    | @:error: {$errors[0]} error(s) @;
            --------------------------------------------------
         Data Reads Count     | @:info: {$reads} time(s) @;
         Data Reads in MB     | @:info: {$read} MB @;
         Data Reads Errors    | @:error: {$errors[1]} error(s) @;
            --------------------------------------------------
         Data Writes Count    | @:info: {$writes} time(s) @;
         Data Writes in MB    | @:info: {$written} MB @;
         Data Writes Errors   | @:error: {$errors[2]} error(s) @;@\;
         OUTPUT);
      } else {
         $this->log(' -------------------- No data. -------------------- @\;', 2);
      }
      $this->log("====================================================@\\;");

      break;
   case '@stats reset':
      $this->connections = 0;
      $this->Data->reads = 0;
      $this->Data->writes = 0;
      $this->Data->read = 0;
      $this->Data->written = 0;
      $this->Data->errors['read'] = 0;
      $this->Data->errors['write'] = 0;
      break;

   case '@peers':
      if ($this->Server === null) {
         return false;
      }

      $worker = $this->Server->Process::$index;

      $this->log(PHP_EOL . "Worker #{$worker}:" . PHP_EOL);

      foreach (self::$Connections as $Connection => $info) {
         $this->log('Connection ID #' . $Connection . ':' . PHP_EOL, self::LOG_INFO_LEVEL);

         foreach ($info as $key => $value) {
            $this->log('@:notice: ' . $key . ': @; ');

            switch ($key) {
               case 'status':
                  $status = match ($value) {
                     Connection::STATUS_INITIAL     => 'INITIAL',
                     Connection::STATUS_CONNECTING  => 'CONNECTING',
                     Connection::STATUS_ESTABLISHED => 'ESTABLISHED',
                     Connection::STATUS_CLOSING     => 'CLOSING',
                     Connection::STATUS_CLOSED      => 'CLOSED'
                  };

                  $this->log($status . PHP_EOL);
                  break;

               case 'expiration':
                  $this->log($value . ' second(s)' . PHP_EOL);
                  break;

               case 'timers':
                  $this->log(count($value) . PHP_EOL);
                  break;

               case 'used':
               case 'started':
                  $this->log(date('Y-m-d H:i:s', $value) . PHP_EOL);
                  break;

               default:
                  $this->log($value . PHP_EOL);
            }
         }
      }

      if ( empty(self::$Connections) ) {
         $this->log('No active connection.' . PHP_EOL, self::LOG_WARNING_LEVEL);
      }

      break;
}