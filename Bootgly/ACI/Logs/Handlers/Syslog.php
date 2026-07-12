<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Handlers;


use const LOG_PID;
use const LOG_USER;
use function closelog;
use function openlog;
use function preg_replace;
use function syslog;

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatter;
use Bootgly\ACI\Logs\Handler;


class Syslog extends Handler
{
   // ANSI escape sequence matcher (syslog stores plain text).
   private const string ANSI = '/\x1b\[[0-9;?]*[ -\/]*[@-~]/';

   // * Config
   public string $ident;
   public int $facility;


   /**
    * Write records to the system logger via PHP's syslog functions.
    *
    * @param string $ident Identifier prepended to every message.
    * @param int $facility Syslog facility (defaults to LOG_USER).
    * @param null|Formatter $Formatter Output formatter (defaults to a Line formatter).
    * @param Levels $Level Minimum severity this handler accepts.
    */
   public function __construct (
      string $ident = 'bootgly',
      int $facility = LOG_USER,
      null|Formatter $Formatter = null,
      Levels $Level = Levels::Debug
   )
   {
      parent::__construct($Formatter, $Level);

      // * Config
      $this->ident = $ident;
      $this->facility = $facility;
   }

   /**
    * Map the record severity to a syslog priority and emit the plain message.
    *
    * @param string $formatted The formatted record.
    * @param Record $Record The source record (its level sets the syslog priority).
    * @return bool True on success.
    */
   protected function write (string $formatted, Record $Record): bool
   {
      // @ RFC5424 backing value (1..8) maps to syslog priority (0..7)
      $priority = $Record->Level->value - 1;

      // @ Strip ANSI styling
      $message = preg_replace(self::ANSI, '', $formatted) ?? $formatted;

      // @ Emit
      openlog($this->ident, LOG_PID, $this->facility);
      $written = syslog($priority, $message);
      closelog();

      // :
      return $written;
   }
}
