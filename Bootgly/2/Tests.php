<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


abstract class Tests
{
   // * Config
   // auto
   public string $autoBoot;
   public mixed $autoInstance;
   public bool $autoResult;
   public bool $autoSummarize;
   // exit
   public static bool $exitOnFailure = false;

   // * Data
   public array $tests;
   public array $specifications;

   // * Meta
   public int $failed;
   public int $passed;
   public int $skipped;
   // @ Stats
   public int $total;
   // @ Time
   public float $started;
   public float $finished;
   // @ Screen? Output?
   public int $width;


   abstract public function __construct (array &$tests);

   abstract public function test (? array &$specifications) : object|false;
}