<?php
switch ($name) {
   case '@status':
      // ! Server
      // @
      $server = (new \ReflectionClass($this))->getName();
      $version = self::VERSION;
      $php = PHP_VERSION;

      // Runtime
      $runtime = [];
      $uptime = time() - $this->started;
      $runtime['started'] = date('Y-m-d H:i:s', $this->started);
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

      // Load Average
      $load = ['-', '-', '-'];
      if ( function_exists('sys_getloadavg') ) {
         $load = array_map('round', sys_getloadavg(), [2, 2, 2]);
      }
      $load = "{$load[0]}, {$load[1]}, {$load[2]}";

      // Workers
      $workers = $this->workers;

      // Socket
      $address = 'tcp://' . $this->host . ':' . $this->port;

      // Event-loop
      $event = (new \ReflectionClass(self::$Event))->getName();


      $this->log(<<<OUTPUT

      =========================== Server Status ===========================
      @:i: Bootgly Server: @; {$server}
      @:i: Bootgly Server version: @; {$version}\t\t@:i: PHP version: @; {$php}

      @:i: Started time: @; {$runtime['started']}\t@:i: Uptime: @; {$uptimes}
      @:i: Load average: @; $load\t\t@:i: Workers count: @; {$workers}
      @:i: Socket address: @; {$address}

      @:i: Event-loop: @; {$event}
      =====================================================================

      OUTPUT);

      break;
}
