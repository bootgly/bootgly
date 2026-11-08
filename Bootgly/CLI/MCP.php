<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


use const PHP_OUTPUT_HANDLER_CLEAN;
use const PHP_OUTPUT_HANDLER_CLEANABLE;
use const PHP_OUTPUT_HANDLER_FLUSHABLE;
use const PHP_OUTPUT_HANDLER_REMOVABLE;
use const STDERR;
use const STDIN;
use const STDOUT;
use function fflush;
use function fgets;
use function function_exists;
use function fwrite;
use function in_array;
use function ini_get;
use function ini_set;
use function is_resource;
use function ltrim;
use function ob_clean;
use function ob_end_flush;
use function ob_get_contents;
use function ob_get_level;
use function ob_start;
use function preg_match;
use function rtrim;
use function str_ends_with;
use function strcmp;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use InvalidArgumentException;

use Bootgly\API\RPC;


/**
 * Model Context Protocol over stdio — the transport a client uses when it
 * launches the server as a child process:
 *
 * ```php
 * exit((new MCP($Server))->serving());
 * ```
 *
 * - stdin carries newline-delimited JSON-RPC, one message (or batch) per
 *   line; each answer is written to stdout as one line. Notifications get
 *   none; blank lines are skipped.
 * - stdout carries protocol messages only. Whatever a call prints (`echo`,
 *   `print`, `var_dump`, a flushed or ended output buffer, a fatal error) is
 *   captured and moved to stderr, the channel of every diagnostic: on the
 *   process's own stdout, the capture cannot be removed, errors PHP
 *   displays are displayed on stderr (hidden ones stay hidden), and output
 *   printed after `serving()` returns lands on stderr too. Loggers writing straight
 *   to the `STDOUT` stream are not captured: point them at `STDERR`.
 * - A line longer than `line` bytes is discarded and answered with
 *   Invalid Request (`id: null`).
 * - End of stdin ends the loop cleanly (exit status `0`); stdout closed by
 *   the client ends it with `1`.
 *
 * The process serves one client, so the loop blocks on stdin; `context`
 * (who the client is — read from the environment, for instance) reaches
 * every application closure of the server core as its `$context`.
 */
final class MCP
{
   // * Config
   /** The MCP server core this process serves. */
   public private(set) RPC\MCP $Server;
   /** Handed to every call as its `$context` (the client's identity…). */
   public private(set) mixed $context;
   /**
    * The longest line accepted, in bytes (newline excluded).
    *
    * @var positive-int
    */
   public private(set) int $line;

   // * Data
   /** @var resource */
   protected $input;
   /** @var resource */
   protected $output;
   /** @var resource */
   protected $error;

   // * Metadata
   /** The output-buffer level of the capture while serving. */
   private int $level = 0;


   /**
    * @param RPC\MCP $Server The MCP server core.
    * @param mixed $context Handed to every call as its `$context`.
    * @param int $line The longest line accepted, in bytes.
    * @param resource $input The message stream (stdin).
    * @param resource $output The answer stream (stdout).
    * @param resource $error The diagnostic stream (stderr).
    *
    * @throws InvalidArgumentException A non-positive line bound or a stream that is not a resource.
    */
   public function __construct (
      RPC\MCP $Server,
      mixed $context = null,
      int $line = 4_194_304,
      $input = STDIN,
      $output = STDOUT,
      $error = STDERR
   )
   {
      // ?
      if ($line < 1) {
         throw new InvalidArgumentException('MCP: the line bound must be positive.');
      }
      if (is_resource($input) === false || is_resource($output) === false || is_resource($error) === false) {
         throw new InvalidArgumentException('MCP: input, output and error must be stream resources.');
      }

      // * Config
      $this->Server = $Server;
      $this->context = $context;
      $this->line = $line;

      // * Data
      $this->input = $input;
      $this->output = $output;
      $this->error = $error;
   }

   /**
    * Serve the client until stdin ends.
    *
    * @return int The exit status: `0` at end of input, `1` when the answer
    *         stream can no longer be written.
    */
   public function serving (): int
   {
      // ! Printed output never reaches the protocol: a capture that writes
      //   whatever is flushed through it to stderr. On the process's own
      //   stdout it cannot be removed — a call ending it would print there.
      $guarded = $this->output === STDOUT;
      // ? A fatal error (memory exhausted…) is displayed past every buffer:
      //   errors already displayed go to stderr; hidden ones stay hidden
      if (
         $guarded
         && function_exists('ini_get')
         && function_exists('ini_set')
         && self::decode((string) ini_get('display_errors')) !== 0
      ) {
         ini_set('display_errors', 'stderr');
      }
      ob_start(
         function (string $buffer, int $phase): string {
            // ? Cleaned output is discarded, never written
            if (($phase & PHP_OUTPUT_HANDLER_CLEAN) === 0 && $buffer !== '') {
               @fwrite($this->error, $buffer);
            }

            return '';
         },
         0,
         PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE | ($guarded ? 0 : PHP_OUTPUT_HANDLER_REMOVABLE)
      );
      $this->level = ob_get_level();

      try {
         // @@ One message per line
         while (($line = fgets($this->input, $this->line + 3)) !== false) {
            // ? Longer than the bound (line ending excluded): drop the rest of it, answer once
            if (strlen(rtrim($line, "\r\n")) > $this->line) {
               // ? Not read to its end yet
               if (str_ends_with($line, "\n") === false) {
                  // @@ Drain the rest of the line
                  while (($rest = fgets($this->input, 65536)) !== false && str_ends_with($rest, "\n") === false);
               }
               @fwrite($this->error, "MCP: a line longer than {$this->line} bytes was discarded\n");
               $answer = '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Invalid Request: message too large"}}';
            }
            else {
               $message = trim($line);
               // ? Blank line
               if ($message === '') {
                  continue;
               }

               $answer = $this->answer($message);
            }

            // ? Notifications only
            if ($answer === null) {
               continue;
            }
            // ?: The client closed stdout
            if ($this->write("{$answer}\n") === false) {
               @fwrite($this->error, "MCP: stdout closed, stopping\n");

               return 1;
            }
         }
      }
      finally {
         // @ An in-memory answer stream releases the capture; the process's
         //   own stdout keeps it until the process ends
         if ($guarded === false && ob_get_level() === $this->level) {
            ob_end_flush();
         }
      }

      // : End of input
      return 0;
   }

   /**
    * Answer one message, moving whatever the call printed to stderr.
    */
   private function answer (string $message): null|string
   {
      try {
         // :
         return $this->Server->handle($message, $this->context);
      }
      finally {
         // @ Every buffer the call left open is folded into the capture
         while (ob_get_level() > $this->level) {
            if (@ob_end_flush() === false) {
               break;
            }
         }
         // @ What the call printed, as one diagnostic block
         if (ob_get_level() === $this->level) {
            $noise = (string) ob_get_contents();
            ob_clean();
            if ($noise !== '') {
               $noise = rtrim($noise, "\n");
               @fwrite($this->error, "{$noise}\n");
            }
         }
      }
   }

   /**
    * The display mode PHP reads from a `display_errors` value: `0` hidden,
    * `1` stdout, `2` stderr. A keyword is matched as written (any case,
    * never trimmed); anything else is the leading number C's `strtol()`
    * reads — saturated at the bounds of a 64-bit long — kept to its low byte.
    */
   private static function decode (string $setting): int
   {
      // ? A keyword
      $keyword = strtolower($setting);
      if ($keyword === 'stderr') {
         return 2;
      }
      if (in_array($keyword, ['on', 'yes', 'true', 'stdout'], true)) {
         return 1;
      }
      // ? No leading number
      if (preg_match('/^[ \t\n\x0B\f\r]*([+-]?)(\d+)/', $setting, $match) !== 1) {
         return 0;
      }

      // !
      $negative = $match[1] === '-';
      $digits = ltrim($match[2], '0');
      // ?: Beyond a 64-bit long: saturated (its low byte: 0xff, or 0x00 below)
      if (strlen($digits) > 19 || (strlen($digits) === 19 && strcmp($digits, '9223372036854775807') > 0)) {
         return $negative ? 0 : 1;
      }
      $value = (int) $digits;
      $mode = ($negative ? -$value : $value) & 0xFF;

      // :
      return match ($mode) {
         0 => 0,
         2 => 2,
         default => 1
      };
   }

   /**
    * Write every byte of one answer line.
    */
   private function write (string $data): bool
   {
      // @@ Pipes accept partial writes
      while ($data !== '') {
         $written = @fwrite($this->output, $data);
         if ($written === false || $written === 0) {
            return false;
         }
         $data = substr($data, $written);
      }

      // :
      return fflush($this->output);
   }
}
