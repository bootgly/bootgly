<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI;


use Bootgly\ABI\Data\DNS\Message\Record;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Answer\Outcomes;


/**
 * The result of one typed DNS lookup (`DNS_Client_CLI->query()`).
 */
final class Answer
{
   // * Config
   /** How the lookup ended. */
   public private(set) Outcomes $Outcome;
   /** The canonical name the records belong to — the asked name, or the end of its CNAME chain. */
   public private(set) string $name;
   /**
    * Records of the asked type at the canonical name, in answer order (empty unless `Answered`).
    *
    * @var list<Record>
    */
   public private(set) array $Records;
   /** Seconds this answer stays valid (the cache's remaining lifetime when it came from the cache). */
   public private(set) int $TTL;


   /**
    * @param Outcomes $Outcome How the lookup ended.
    * @param string $name The canonical name (no trailing dot).
    * @param list<Record> $Records Records of the asked type at `$name`.
    * @param int $TTL Seconds this answer stays valid.
    */
   public function __construct (Outcomes $Outcome, string $name, array $Records = [], int $TTL = 0)
   {
      // * Config
      $this->Outcome = $Outcome;
      $this->name = $name;
      $this->Records = $Records;
      $this->TTL = $TTL;
   }
}
