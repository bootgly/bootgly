<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Fakers;


use function count;
use function str_replace;
use function strtolower;

use Bootgly\ACI\Tests\Faker;


/**
 * Email faker built from deterministic names and domain samples.
 */
final class Email extends Faker
{
   /**
    * @var array<int, string>
    */
   public array $domains = [
      'example.com', 'test.org', 'mail.dev', 'demo.net',
   ];


   /**
    * Generate one fake email address.
    */
   public function generate (): string
   {
      $Name = new Name($this->seed);
      $local = strtolower(str_replace(' ', '.', $Name->generate()));

      $domain = $this->domains[$this->Randomizer->getInt(0, count($this->domains) - 1)];

      return "{$local}@{$domain}";
   }
}
