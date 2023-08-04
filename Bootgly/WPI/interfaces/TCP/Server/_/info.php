<?php
switch ($name) {
   case '@status':
      $this->log('>_ Type `CTRL + Z` to enter in Interactive mode or `CTRL + C` to stop the Server.@\;');

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

      // Input
      // TODO

      $this->log(<<<OUTPUT

      =============================== Server Status ===============================
      @:i: Bootgly Server: @; {$server}
      @:i: PHP version: @; {$php}\t\t\t@:i: Bootgly Server version: @; {$version}

      @:i: Started time: @; {$runtime['started']}\t@:i: Uptime: @; {$uptimes}
      @:i: Workers count: @; {$workers}\t\t\t@:i: Load average: @; {$load}
      @:i: Socket address: @; {$address}

      @:i: Event-loop: @; {$event}
      =============================================================================

      OUTPUT);

      break;
}
