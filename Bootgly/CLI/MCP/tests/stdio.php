<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


/**
 * Child-process fixture of the stdio specs: an MCP server on this process's
 * real stdin/stdout. Its tool prints to stdout on purpose — the transport
 * must move that to stderr. `MCP_STDIO_LINE` sets the line bound;
 * `MCP_STDIO_LEAK` makes the tool try to get output past the capture
 * (`flush`: flush and end the buffers; `exit`: end the process mid-call;
 * `memory`: die of memory exhaustion mid-call). `MCP_STDIO_DISPLAY` sets
 * `display_errors` at runtime, as written (the `-d` parser trims it).
 * `MCP_STDIO_LOG` makes the tool log through a fresh logger, as kit clients
 * do (`break`: a record ending in a line break; `inline`: one without;
 * `flip`: turn the display back on after logging, as a client stopping
 * does; `untap`: clear the live tap after logging, as `@log off` does).
 * `MCP_STDIO_FILE` adds a `File` handler on that path to the logger.
 */

// ! Classes only — the stdio process boots nothing that could write to stdout
spl_autoload_register(static function (string $class): void {
   $root = dirname(__DIR__, 4);
   $file = str_replace('\\', '/', $class);
   if (is_file("{$root}/{$file}.php")) {
      require "{$root}/{$file}.php";
   }
});

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Handlers\File;
use Bootgly\ACI\Logs\Logger;
use Bootgly\API\RPC;
use Bootgly\CLI\MCP;


$Server = new RPC\MCP(
   name: 'Stdio',
   version: '1.0.0',
   Lister: static fn (mixed $context): array => [
      ['name' => 'echo', 'description' => 'Echo the arguments', 'inputSchema' => ['type' => 'object']]
   ],
   Caller: static function (string $name, array $arguments, mixed $context): array {
      echo "a stray echo from the tool\n";

      // # Logging (a kit client's lazy logger: the default `Stream` handler on stdout)
      $log = getenv('MCP_STDIO_LOG');
      if ($log !== false) {
         $Logger = new Logger(channel: 'Kit');
         $file = getenv('MCP_STDIO_FILE');
         if ($file !== false) {
            $Logger->Handlers->push(new File($file));
         }
         $Logger->log(warning: $log === 'inline' ? 'Starting Client... ' : 'Unable to connect!@.;');
         if ($log === 'flip') {
            Display::show(Display::MESSAGE);
         }
         else if ($log === 'untap') {
            Logger::$Tap = null;
         }
      }

      // # Escape attempts
      $leak = getenv('MCP_STDIO_LEAK');
      if ($leak === 'flush') {
         ob_flush();
         echo "after ob_flush\n";
         @ob_end_flush();
         echo "after ob_end_flush\n";
         @ob_end_clean();
         echo "after ob_end_clean\n";
         @ob_get_clean();
         echo "after ob_get_clean\n";
      }
      else if ($leak === 'memory') {
         ini_set('memory_limit', '16M');
         $blocks = [];
         // @@ Until the limit kills the process
         while (true) {
            $blocks[] = str_repeat('x', 1 << 20);
         }
      }
      else if ($leak === 'exit') {
         echo "before exit\n";
         exit(3);
      }

      return [
         'content' => [['type' => 'text', 'text' => (string) json_encode(['arguments' => $arguments, 'context' => $context])]],
         'isError' => false
      ];
   }
);

// ? A display_errors value exactly as written
$display = getenv('MCP_STDIO_DISPLAY');
if ($display !== false) {
   ini_set('display_errors', $display);
}

exit((new MCP($Server, context: getenv('MCP_STDIO_USER') ?: null, line: (int) (getenv('MCP_STDIO_LINE') ?: 4_194_304)))->serving());
