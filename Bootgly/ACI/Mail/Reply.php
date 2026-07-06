<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Mail;


use function implode;


/**
 * A parsed SMTP reply (single or multiline), as emitted by the Decoder.
 */
final class Reply
{
   // * Data
   /**
    * SMTP reply code (e.g. 250, 354, 535).
    */
   public readonly int $code;
   /**
    * Enhanced status code (RFC 3463) from the first line — '' when absent.
    */
   public readonly string $status;
   /**
    * Reply text lines (any enhanced status prefix kept for fidelity).
    * @var array<int,string>
    */
   public readonly array $lines;

   // * Metadata
   /**
    * All lines joined with a space (virtual, read-only).
    */
   public string $text {
      get => implode(' ', $this->lines);
   }


   /**
    * @param array<int,string> $lines
    */
   public function __construct (int $code, string $status, array $lines)
   {
      // * Data
      $this->code = $code;
      $this->status = $status;
      $this->lines = $lines;
   }
}
