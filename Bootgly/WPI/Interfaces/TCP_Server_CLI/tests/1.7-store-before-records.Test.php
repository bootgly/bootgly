<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use function assert;
use function file;
use function php_strip_whitespace;
use function strpos;
use function substr;
use function var_export;
use ReflectionMethod;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\UDP_Server_CLI as UDPServer;


/**
 * start() installs — or withholds — the log sinks before its first record.
 *
 * A project registers its sinks in boot(), before start(); on a root launch
 * with a runtime `user` the very first record start() writes would otherwise
 * create the log file root-owned, and the demoted daemon could never write it
 * again. The order is a contract of both server interfaces: store() precedes
 * the first `Logger->log()` in start(). Pinned on the comment-stripped source
 * of the method, so a commented-out call cannot satisfy it.
 */
return new Test(
   description: 'start() calls store() before the first record it logs, in the TCP and the UDP server',
   test: function () {
      foreach ([TCPServer::class, UDPServer::class] as $class) {
         $Method = new ReflectionMethod($class, 'start');
         $file = (string) $Method->getFileName();
         $start = (int) $Method->getStartLine();
         $end = (int) $Method->getEndLine();
         // ! Comments stripped: only code counts
         $stripped = (string) php_strip_whitespace($file);
         $signature = 'public function start ()';
         $from = strpos($stripped, $signature);
         $body = $from === false ? '' : substr($stripped, $from);
         $store = strpos($body, '$this->store();');
         $record = strpos($body, '$this->Logger->log(');

         yield assert(
            assertion: $from !== false && $start > 0 && $end > $start
               && $store !== false && $record !== false && $store < $record,
            description: "$class::start() withholds the sinks before its first record (store at "
               . var_export($store, true) . ', first log at ' . var_export($record, true) . ')'
         );

         // @@ And the transport adopt() — where the runtime identity becomes
         //    known — stores before it returns, so a record logged between
         //    configure() and start() is held too
         $Adopt = new ReflectionMethod($class, 'adopt');
         $adopted = strpos($stripped, 'protected function adopt (');
         $adopt = $adopted === false ? '' : substr($stripped, $adopted);
         $adopt = substr($adopt, 0, strpos($adopt, 'public function ') ?: null);
         $held = strpos($adopt, '$this->store();');
         $back = strpos($adopt, 'return;');

         yield assert(
            assertion: $Adopt->getDeclaringClass()->getName() === $class
               && $held !== false && $back !== false && $held < $back,
            description: "$class::adopt() stores before returning from the transport branch (store at "
               . var_export($held, true) . ', return at ' . var_export($back, true) . ')'
         );
      }
   }
);
