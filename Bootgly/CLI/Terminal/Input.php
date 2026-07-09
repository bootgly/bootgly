<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal;


use const SIGINT;
use const SIGTERM;
use const STDIN;
use const STDOUT;
use function cli_set_process_title;
use function defined;
use function feof;
use function fopen;
use function fread;
use function function_exists;
use function fwrite;
use function getenv;
use function intdiv;
use function ord;
use function pcntl_async_signals;
use function pcntl_fork;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function pcntl_waitpid;
use function posix_getpid;
use function posix_kill;
use function register_shutdown_function;
use function stream_get_meta_data;
use function stream_isatty;
use function stream_set_blocking;
use function stream_set_timeout;
use function strlen;
use function substr;
use function system;
use function time;
use Closure;
use Generator;
use Throwable;

use Bootgly\ABI\IO\IPC\Pipe;
use Bootgly\ACI\Process\State;
use Bootgly\API\Projects;
use Bootgly\CLI\Terminal\Input\Roles;


class Input
{
   // * Config
   /**
    * Self-echo bytes consumed by scan() back to the terminal — the line
    * discipline of emulated TTYs (real TTYs echo in the kernel; pipes never echo).
    */
   public bool $echo;

   // * Data
   /** @var resource */
   public $stream;
   /** @var resource Echo target — the terminal write side used when $echo is enabled */
   public $output;
   // # Terminal Client/Server API
   // Role and duplex channel for embedded runtimes (one role per process);
   // when both stay null and process control exists, reading() forks natively.
   public null|Roles $role;
   /** @var array{0: resource, 1: resource}|null Duplex channel override (read, write) */
   public null|array $channel = null;

   // * Metadata
   /** Terminal restore net armed? */
   private bool $armed;


   /**
    * @param resource $stream
    */
   public function __construct ($stream = STDIN)
   {
      // * Config
      // ? Emulated TTYs (BOOTGLY_TTY forced by env) have no kernel line discipline
      $this->echo = getenv('BOOTGLY_TTY') === '1' && stream_isatty($stream) === false;

      // * Data
      $this->stream = $stream;
      $this->output = STDOUT;
      // # Terminal Client/Server API
      $this->role = Roles::tryFrom((string) getenv('BOOTGLY_TERMINAL_ROLE'));

      // * Metadata
      $this->armed = false;
   }

   /**
    * Configures the input stream settings.
    *
    * @param bool $blocking Whether to set the stream to blocking or non-blocking mode. Default is true (blocking).
    * @param bool $canonical Whether to enable or disable canonical input processing mode. Default is true (enabled).
    * @param bool $echo Whether to enable or disable echoing of input characters. Default is true (enabled).
    * @param bool $signals Whether the terminal generates signals (Ctrl+C = SIGINT, ...). When disabled,
    *                      those keys arrive as raw bytes (`\x03`, ...) readable by the consumer. Default is true.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function configure (
      bool $blocking = true, bool $canonical = true, bool $echo = true, bool $signals = true
   ): self
   {
      stream_set_blocking($this->stream, $blocking);

      // ? Terminal modes require an interactive TTY (pipes and embedded runtimes cannot fork stty)
      if (stream_isatty($this->stream) === false) {
         // :
         return $this;
      }

      // ? Entering raw mode arms the terminal restore net (once)
      if ($canonical === false) {
         $this->arm();
      }

      $canonical
         ? system('stty icanon')
         : system('stty -icanon');

      $echo
         ? system('stty echo')
         : system('stty -echo');

      $signals
         ? system('stty isig')
         : system('stty -isig');

      return $this;
   }

   /**
    * Arms the terminal restore net (once): a shutdown restore of the terminal modes
    * and the cursor, plus INT/TERM handlers that exit through the normal shutdown
    * sequence — so component destructors and `finish()` paths run on Ctrl+C.
    *
    * @return void
    */
   private function arm (): void
   {
      // ?
      if ($this->armed === true) {
         return;
      }

      $this->armed = true;

      // @ Restore the terminal modes, the mouse reporting and the cursor on any exit
      $output = $this->output;
      $stream = $this->stream;
      register_shutdown_function(static function () use ($output, $stream): void {
         stream_set_blocking($stream, true);
         system('stty icanon echo isig 2>/dev/null');

         // Disable mouse reporting (a leaked tracking floods the shell with escapes)
         // and show the cursor — components may die between hide() and show()
         fwrite($output, "\e[?1003l\e[?1002l\e[?1000l\e[?1006l\e[?25h");
      });

      // ? Signal handling requires process control
      if (function_exists('pcntl_signal') === false) {
         return;
      }

      // @ Exit through the shutdown sequence — destructors restore the terminal state
      pcntl_async_signals(true);
      pcntl_signal(SIGINT, static function (): void {
         exit(130);
      });
      pcntl_signal(SIGTERM, static function (): void {
         exit(143);
      });
   }

   /**
    * Reads a specified number of bytes from the input stream.
    *
    * @param int $length The number of bytes to read.
    *
    * @return string|false Returns the read data as a string, or false if an error occurred.
    */
   public function read (int $length): string|false
   {
      // ?
      if ($length < 1) {
         return false;
      }

      // @
      if (function_exists('pcntl_signal_dispatch') === true) {
         pcntl_signal_dispatch();
      }

      try {
         $read = @fread($this->stream, $length);
      }
      catch (Throwable) {
         $read = false;
      }

      if ($read === false) {
         // TODO check errors
      }

      return $read;
   }

   /**
    * Reads a single line from the input stream.
    * Bytes are consumed until a line terminator (`\n` or `\r`) or EOF is reached.
    * Acts as the line discipline of the stream: erase keys (Backspace / Delete)
    * edit the buffer and, when self-echo is enabled, input is echoed back as typed.
    *
    * @param null|string $mask When set, self-echo writes this mask once per completed
    *                          character instead of the typed character (secret input).
    *
    * @return string|false Returns the line without the terminator, or false on immediate EOF.
    */
   public function scan (null|string $mask = null): string|false
   {
      // ! Line buffer
      $line = '';
      // ! Echo buffer — echo whole UTF-8 characters only, never partial byte sequences
      $pending = '';

      // @@ Consume bytes until a line terminator or EOF
      while (true) {
         $byte = $this->read(1);

         // ? EOF or read failure
         if ($byte === false || $byte === '') {
            break;
         }
         // ? Line terminator
         if ($byte === "\n" || $byte === "\r") {
            if ($this->echo === true) {
               fwrite($this->output, "\n");
            }

            // :
            return $line;
         }
         // ? Erase (Backspace / Delete)
         if ($byte === "\x08" || $byte === "\x7F") {
            if ($line === '') {
               continue;
            }

            // @ Chop the last UTF-8 character from the buffer
            $offset = strlen($line) - 1;
            while ($offset > 0 && (ord($line[$offset]) & 0xC0) === 0x80) {
               $offset--;
            }
            $line = substr($line, 0, $offset);

            if ($this->echo === true) {
               fwrite($this->output, "\x08 \x08");
            }

            continue;
         }

         $line .= $byte;

         // ? Self-echo characters as their byte sequences complete
         if ($this->echo === true) {
            $pending .= $byte;

            $lead = ord($pending[0]);
            $length = match (true) {
               $lead < 0x80 => 1,
               $lead >= 0xF0 => 4,
               $lead >= 0xE0 => 3,
               $lead >= 0xC0 => 2,
               default => 1
            };

            if (strlen($pending) >= $length) {
               fwrite($this->output, $mask ?? $pending);
               $pending = '';
            }
         }
      }

      // ?: EOF with no buffered bytes
      if ($line === '') {
         return false;
      }

      // : Last line without terminator
      return $line;
   }

   /**
    * Initiates a bidirectional communication between a Terminal Client API and a Terminal Server API.
    * The function forks a child process to handle the Terminal Client API and communicates with the parent process using a Pipe.
    *
    * @param Closure $CAPI The Terminal Client API function. It should accept two parameters: a callable to read from the input stream and a callable to write to the Pipe.
    * @param Closure $SAPI The Terminal Server API function. It should accept one parameter: a callable to read from the Pipe.
    *
    * @return void
    *
    * @throws Throwable If an error occurs during the communication or process management.
    */
   public function reading (Closure $CAPI, Closure $SAPI): void
   {
      // ? Embedded runtimes run a single role wired to an injected duplex channel
      if ($this->role !== null) {
         $this->relay($this->role, $CAPI, $SAPI);

         return;
      }

      // ? Forking the Client/Server pair requires process control
      if (function_exists('pcntl_fork') === false) {
         return;
      }

      $Pipe = new Pipe;
      $Pipe->blocking = false;
      $Pipe->open();

      // @ Arm the terminal restore net (stty + blocking + mouse + cursor on any exit)
      $this->arm();

      // @ Fork process
      $pid = pcntl_fork();

      // @ Save PID state for show/stop visibility
      // ? Non-server instances are qualified by master PID (servers use the
      //   bound port) — multiple TUI instances stay individually stoppable.
      $stateId = defined('BOOTGLY_PROJECT') ? Projects::encode(BOOTGLY_PROJECT->folder) : self::class;
      $State = new State(id: $stateId, instance: (string) posix_getpid());

      if ($pid === 0) { // @ Child (Client)
         cli_set_process_title("BootglyCLI: Client");

         // Watch for a signal from the parent process to terminate
         pcntl_signal(SIGTERM, function () {
            exit(0);
         });

         // Disable canonical input processing mode and echo return
         system('stty -icanon -echo');
         // Set non-blocking for data stream
         stream_set_blocking($this->stream, false);

         try {
            // @ Call Terminal Client API passing the Pipe write method
            $CAPI([$this, 'read'], [$Pipe, 'write']);
         }
         catch (Throwable) {
            // ...
         }

         // Close Client API
         exit(0);
      }
      else if ($pid > 0) { // @ Parent (Server)
         cli_set_process_title("BootglyCLI: Server");

         // @ Handle SIGTERM: kill child before exiting
         pcntl_signal(SIGTERM, function () use ($pid, $State) {
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
            $State->clean();
            exit(0);
         });

         // @ Save PID state
         $State->save([
            'master'  => posix_getpid(),
            'workers' => [$pid],
            'type'    => 'CLI-IPC',
            'started' => time()
         ]);

         try {
            // @ Call Terminal Server API passing the Pipe reading method
            $SAPI([$Pipe, 'reading']);
         }
         catch (Throwable) {
            // ...
         }

         // Send signal to terminate child process
         posix_kill($pid, SIGTERM);

         // Wait for child process to exit
         pcntl_waitpid($pid, $status);

         // @ Clean PID state
         $State->clean();
      }
      else if ($pid === -1) {
         die('Could not fork process!');
      }
   }

   /**
    * Runs a single Terminal Client/Server API role wired to a duplex channel.
    * Embedded runtimes (e.g. WASM workers) run one role per process and provide
    * the channel as a pair of streams — natively reading() forks both roles.
    *
    * @param Roles $Role The role this process assumes (Client or Server).
    * @param Closure $CAPI The Terminal Client API function.
    * @param Closure $SAPI The Terminal Server API function.
    *
    * @return void
    */
   private function relay (Roles $Role, Closure $CAPI, Closure $SAPI): void
   {
      // ! Duplex channel (read, write) — injected or resolved from the environment
      $channel = $this->channel;
      if ($channel === null) {
         // ?
         $uri = getenv('BOOTGLY_TERMINAL_CHANNEL');
         if ($uri === false || $uri === '') {
            return;
         }

         $read = fopen($uri, 'r');
         $write = fopen($uri, 'w');
         // ?
         if ($read === false || $write === false) {
            return;
         }

         $channel = [$read, $write];
      }

      switch ($Role) {
         case Roles::Client:
            // @ Terminal Client API: reads this Input, writes to the channel
            $CAPI(
               [$this, 'read'],
               static function (string $data) use ($channel): int|false {
                  return fwrite($channel[1], $data);
               }
            );

            break;
         case Roles::Server:
            // @ Terminal Server API: consumes data read from the channel
            $SAPI(
               static function (int $length = 1024, null|int $timeout = null) use ($channel): Generator {
                  // ?
                  if ($length < 1) {
                     $length = 1024;
                  }

                  // @ Arm the read timeout — paced reads yield null on expiry,
                  // mirroring Pipe::reading (the timeout is the frame pacing)
                  if ($timeout !== null) {
                     stream_set_timeout(
                        $channel[0],
                        intdiv($timeout, 1000000),
                        $timeout % 1000000
                     );
                  }

                  // @@ Read the channel until it closes or fails
                  while (true) {
                     $data = fread($channel[0], $length);

                     // ?: Data
                     if ($data !== false && $data !== '') {
                        yield $data;

                        continue;
                     }
                     // ?: Read timeout — tick time (sockets report false + timed_out
                     // metadata; userland wrappers report an empty read)
                     if (
                        $timeout !== null
                        && feof($channel[0]) === false
                        && (
                           $data === ''
                           || stream_get_meta_data($channel[0])['timed_out'] === true
                        )
                     ) {
                        yield null;

                        continue;
                     }

                     // ?: Channel closed or failed
                     yield false;

                     break;
                  }
               }
            );

            break;
      }
   }
}
