<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\DNS\Message;


/**
 * One resource record of a DNS message's answer, authority or additional
 * section (RFC 1035 §4.1.3).
 *
 * `data` holds the RDATA read by type:
 *
 * | Type | `data` |
 * |---|---|
 * | A, AAAA (class IN) | the address (`inet_ntop()` form) |
 * | CNAME, NS, PTR | a name |
 * | TXT | the character-strings, in order (`[]` for empty RDATA) |
 * | MX | `['preference' => int, 'exchange' => name]` |
 * | SRV (class IN) | `['priority' => int, 'weight' => int, 'port' => int, 'target' => name]` |
 * | CAA | `['flags' => int, 'tag' => string, 'value' => string]` |
 * | SOA | `['mname' => name, 'rname' => name, 'serial', 'refresh', 'retry', 'expire', 'minimum' => int]` |
 * | any other (and A, AAAA, SRV outside class IN) | the raw RDATA bytes (RFC 3597) |
 *
 * Names are in presentation form, like `Question::$name`.
 *
 * @phpstan-type MX array{preference: int, exchange: string}
 * @phpstan-type SRV array{priority: int, weight: int, port: int, target: string}
 * @phpstan-type CAA array{flags: int, tag: string, value: string}
 * @phpstan-type SOA array{mname: string, rname: string, serial: int, refresh: int, retry: int, expire: int, minimum: int}
 */
final class Record
{
   // * Config
   /**
    * Owner name, in presentation form.
    */
   public string $name;
   /**
    * Record type (`DNS::TYPE_*`, any 0–65535).
    */
   public int $type;
   /**
    * Record class (`DNS::CLASS_IN` = 1).
    */
   public int $class;
   /**
    * Time to live, in seconds (0 to 2^31 - 1; a TTL received with its top bit
    * set reads as 0, RFC 2181 §8).
    */
   public int $TTL;
   /**
    * RDATA by type (see the class description).
    *
    * @var string|list<string>|MX|SRV|CAA|SOA
    */
   public string|array $data;


   /**
    * @param string $name Owner name in presentation form.
    * @param int $type Record type (`DNS::TYPE_*`).
    * @param int $TTL Time to live, in seconds.
    * @param string|list<string>|MX|SRV|CAA|SOA $data RDATA by type.
    * @param int $class Record class; Internet (`1`) by default.
    */
   public function __construct (string $name, int $type, int $TTL, string|array $data, int $class = 1)
   {
      // * Config
      $this->name = $name;
      $this->type = $type;
      $this->class = $class;
      $this->TTL = $TTL;
      $this->data = $data;
   }
}
