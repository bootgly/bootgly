<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Workables;


use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environments;


/**
 * Client API (CAPI) - Analogous to Server API (SAPI) but for HTTP clients.
 *
 * Used by HTTP_Client_CLI and other client implementations for E2E testing.
 */
class Client
{
   // * Config
   public static Environments $Environment = Environments::Production;

   // * Data
   // # Tests
   public static Suite $Suite;
   /** @var array<string,array<int|string,string>> Test Cases files (paths) */
   public static array $tests = [];

   // * Metadata
   // # Tests
   /** @var array<string,array<int|string,Specification|null>> Test Cases instances (Specification objects) */
   public static array $Tests = [];
}