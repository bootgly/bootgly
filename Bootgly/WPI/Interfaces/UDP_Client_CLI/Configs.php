<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Client_CLI;


use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;
use function filter_var;
use function preg_match;
use function strpbrk;
use ArgumentCountError;
use InvalidArgumentException;

use Bootgly\ABI\Argument;
use Bootgly\ABI\Configs as Configuring;


/**
 * Transport configuration of a UDP Client.
 *
 * Handed to `UDP_Client_CLI->configure()`, which accepts one Configs per
 * concern and applies them in any order.
 *
 * Named arguments only: `$Named` is a guard slot no named call ever fills, so
 * a positional `new Configs('127.0.0.1', 8080)` is rejected by the engine
 * (and flagged by static analysis) before it can silently bind the wrong
 * values.
 */
class Configs implements Configuring
{
   // * Config
   /**
    * Domain name or IP address to connect to. An IPv6 literal is given bare
    * (`::1`) or bracketed (`[::1]`), with an optional zone id naming the
    * interface of a link-local address (`fe80::1%eth0`).
    */
   public private(set) string $host;
   /** Port number to connect to. */
   public private(set) int $port;
   /** Number of worker processes to fork. */
   public private(set) int $workers;


   /**
    * @param Argument $Named Guard slot — never pass it; it only rejects positional calls.
    *
    * @throws ArgumentCountError When `host` or `port` is missing.
    * @throws InvalidArgumentException When a `host` carrying `:`, `[`, `]`
    *    or `%` is not an IPv6 literal with an optional zone id.
    */
   public function __construct (
      Argument $Named = Argument::Undefined,
      null|string $host = null,
      null|int $port = null,
      int $workers = 0
   )
   {
      // ? Required — the guard slot forces every parameter to be optional, so
      //   the mandatory ones are enforced here instead of by the engine.
      if ($host === null || $port === null) {
         $Configs = static::class;

         throw new ArgumentCountError(
            "{$Configs} requires the named arguments: host, port."
         );
      }

      // ? A host that can only be an IPv6 literal must be one, as a whole:
      //   a port, a path or stray brackets never ride along with it (the
      //   dialer would read a port after `]` instead of the configured one)
      if (strpbrk($host, ':[]%') !== false) {
         $matched = preg_match(
            '/^(\[?)([0-9A-Fa-f:.]+)(?:%([A-Za-z0-9._-]{1,64}))?(\]?)$/D',
            $host,
            $parts
         );
         $balanced = $matched === 1 && ($parts[1] === '[') === ($parts[4] === ']');
         $IPv6 = $matched === 1
            && filter_var($parts[2], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

         if ($balanced === false || $IPv6 === false) {
            $Configs = static::class;

            throw new InvalidArgumentException(
               "{$Configs} host '{$host}' is not an IPv6 literal with an optional zone id (an interface name of letters, digits, '.', '_' or '-')."
            );
         }
      }

      // * Config
      $this->host = $host;
      $this->port = $port;
      $this->workers = $workers;
   }
}
