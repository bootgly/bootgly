<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\Tests\Precedence;


use function assert;
use function json_encode;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Precedence;


return new Test(
   description: 'DNS_Client_CLI Precedence: usable family first, IPv6 before IPv4, answer order kept',
   test: function () {
      $mixed = ['192.0.2.1', '2001:db8::1', '192.0.2.2', '2001:db8::2'];
      $probed = 0;
      $Probe = static function (array $sources) use (&$probed): \Closure {
         return static function (int $family) use ($sources, &$probed): null|string {
            $probed++;

            return $sources[$family] ?? null;
         };
      };

      // @ Both families usable: IPv6 first
      $Both = new Precedence($Probe([4 => '192.0.2.50', 6 => '2001:db8::50']));
      $both = $Both->order($mixed, 1000.0);
      // @ IPv4 only: IPv4 first
      $Four = new Precedence($Probe([4 => '192.0.2.50']));
      $four = $Four->order($mixed, 1000.0);
      // @ A link-local or loopback IPv6 source is not usable for global destinations
      $Link = new Precedence($Probe([4 => '192.0.2.50', 6 => 'fe80::1']));
      $link = $Link->order($mixed, 1000.0);
      yield assert(
         assertion: $both === ['2001:db8::1', '2001:db8::2', '192.0.2.1', '192.0.2.2']
            && $four === ['192.0.2.1', '192.0.2.2', '2001:db8::1', '2001:db8::2']
            && $link === $four,
         description: 'the family with a usable global source first: ' . json_encode([$both, $four, $link])
      );

      // @ Loopback is always usable; ::1 before 127.0.0.1
      $None = new Precedence($Probe([]));
      yield assert(
         assertion: $None->order(['127.0.0.1', '::1'], 1000.0) === ['::1', '127.0.0.1'],
         description: 'loopback destinations need no source probe'
      );

      // @ The probe is cached 60 seconds per family
      $probed = 0;
      $Cached = new Precedence($Probe([4 => '192.0.2.50']));
      $Cached->order($mixed, 1000.0);
      $Cached->order($mixed, 1059.0);
      $within = $probed;
      $Cached->order($mixed, 1061.0);
      yield assert(
         assertion: $within === 2 && $probed === 4,
         description: 'each family is probed once per 60 s: ' . json_encode([$within, $probed])
      );
   }
);
