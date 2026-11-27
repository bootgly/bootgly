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
 * One entry of a DNS message's question section (RFC 1035 §4.1.2).
 */
final class Question
{
   // * Config
   /**
    * Name asked for, in presentation form: dotted, without the trailing dot,
    * `''` for the root; a byte outside `!`–`~`, a `.` or a `\` inside a label
    * is escaped (`\.`, `\\`, `\DDD`).
    */
   public string $name;
   /**
    * Record type asked for (`DNS::TYPE_*`, any 0–65535).
    */
   public int $type;
   /**
    * Record class asked for (`DNS::CLASS_IN` = 1).
    */
   public int $class;


   /**
    * @param string $name Name in presentation form (a trailing dot is accepted).
    * @param int $type Record type (`DNS::TYPE_*`).
    * @param int $class Record class; Internet (`1`) by default.
    */
   public function __construct (string $name, int $type, int $class = 1)
   {
      // * Config
      $this->name = $name;
      $this->type = $type;
      $this->class = $class;
   }
}
