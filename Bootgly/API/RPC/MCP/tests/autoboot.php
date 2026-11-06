<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\RPC\MCP;


use Bootgly\ACI\Tests\Suite;


return new Suite(
   // * Config
   autoBoot: __DIR__,
   autoInstance: true,
   autoReport: true,
   autoSummarize: true,
   exitOnFailure: true,
   // * Data
   suiteName: __NAMESPACE__,
   tests: [
      '1.1-mcp-initialize',
      '1.2-mcp-negotiate',
      '1.3-mcp-lifecycle',
      '1.4-mcp-tools',
      '1.5-mcp-resources',
      '1.6-mcp-prompts',
      '1.7-mcp-stateless',
      '1.8-mcp-dispatcher',
      '1.9-mcp-flow',
      '1.10-mcp-context',
      '1.11-mcp-fibers',
      '1.12-mcp-batch-screen',
   ]
);
