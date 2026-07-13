<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tokens;


use function time;
use InvalidArgumentException;


/**
 * Frozen/wall-clock time source for deterministic token expiration checks.
 */
trait Clocked
{
   // * Data
   public private(set) null|int $timestamp = null;

   // * Metadata
   private int $time {
      get => $this->timestamp ?? time();
   }


   /**
    * Freeze token time for deterministic checks.
    */
   public function freeze (int $timestamp): self
   {
      // ?
      if ($timestamp < 0) {
         throw new InvalidArgumentException('Token timestamp must not be negative.');
      }

      $this->timestamp = $timestamp;

      return $this;
   }

   /**
    * Resume wall-clock token time.
    */
   public function resume (): self
   {
      $this->timestamp = null;

      return $this;
   }
}
