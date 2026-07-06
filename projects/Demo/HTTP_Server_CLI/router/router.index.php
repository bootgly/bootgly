<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

/**
 * Router index — the manifest read by `Router::load()`.
 *
 * Returns the active route set names. Each name resolves to `routes/<Name>.php`
 * (a generator-closure `(Request, Response, Router): Generator`). List more than one to
 * compose several sets into a single handler. Comment / uncomment to toggle.
 */

return [
   'Database',             // Native async PostgreSQL examples (default)
   'Observability',        // /health + /metrics (ACI Observability collector)
   // 'Authentication',    // Basic, Bearer, JWT authentication
   // 'Authorization',     // Scope, Role, Policy authorization
   // 'Basic',             // OPTIONS catch-all + favicon
   // 'Core',              // Static, dynamic and catch-all routes
   // 'Download',          // Streaming upload-to-disk decoder
   // 'Middlewares',       // Global / group / per-route middleware
   // 'Request',           // Reading Request properties
   // 'Response',          // Response output, View, redirects, caching
   // 'Router',            // All routing cases (closures, params, groups)
   // 'Scheduled',         // Delayed / async responses
   // 'Templating',        // Template engine (raw + file views)
   // 'Validation',        // Input validation examples
   // 'Benchmark_Bootgly', // Benchmark — Bootgly router
   // 'Benchmark_Static',  // Benchmark — static router
];
