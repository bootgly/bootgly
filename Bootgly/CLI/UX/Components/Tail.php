<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UX\Components;


use const SIG_DFL;
use const SIGINT;
use const SIGWINCH;
use function function_exists;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function pcntl_signal_get_handler;
use function register_shutdown_function;
use function usleep;
use Iterator;

use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\Terminal\Screen;
use Bootgly\CLI\UI\Components\Logs as LogsViewer;


/**
 * Full-screen live log follower — the client-side sibling of the server Monitor.
 *
 * Consumes an NDJSON chunk source (live tap sockets, followed files) and renders it
 * through the same LogsViewer the Monitor uses: identical screen, filters and keys.
 */
class Tail
{
   // * Config
   /** Frame budget in microseconds (~30 fps by default). */
   public int $rate = 30000;

   // * Data
   public Input $Input;
   public Output $Output;
   public LogsViewer $Viewer;

   // * Metadata
   private bool $following = false;


   public function __construct (Input $Input, Output $Output, null|LogsViewer $Viewer = null)
   {
      // * Data
      $this->Input = $Input;
      $this->Output = $Output;
      $this->Viewer = $Viewer ?? new LogsViewer($Input, $Output);
   }

   /**
    * Follow the source until it ends, the viewer quits (q/Esc) or SIGINT arrives.
    *
    * Enters the alternate screen in raw non-blocking input mode and always restores
    * the terminal — the exact discipline of the server Monitor loop.
    *
    * @param Iterator<int,string> $Source NDJSON chunk source; '' chunks mean "idle".
    */
   public function run (Iterator $Source): void
   {
      $Input = $this->Input;
      $Output = $this->Output;

      // ! Enter full-screen TUI (alternate screen buffer)
      $Output->write("\e[?1049h\e[2J\e[H");
      $Output->Cursor->hide();
      $Input->configure(blocking: false, canonical: false, echo: false);

      // ! Always restore the terminal, even on an exit that skips `finally`
      //   (a non-pcntl Ctrl+C, a fatal): the same net the server Monitor arms
      register_shutdown_function(function () use ($Input, $Output): void {
         if ($this->following === true) {
            $Input->configure(blocking: true, canonical: true, echo: true);
            $Output->Cursor->show();
            $Output->write("\e[?1049l");
         }
      });

      // ! Detach cleanly on Ctrl+C; refresh geometry on resize — keeping the
      //   embedding flow's handlers to restore on exit
      $this->following = true;
      $SIGINTHandler = null;
      $SIGWINCHHandler = null;
      if (function_exists('pcntl_signal') === true) {
         $SIGINTHandler = pcntl_signal_get_handler(SIGINT);
         $SIGWINCHHandler = pcntl_signal_get_handler(SIGWINCH);
         pcntl_signal(SIGINT, function (): void {
            $this->following = false;
         });
         pcntl_signal(SIGWINCH, static function (): void {
            [$columns, $lines] = Screen::measure();

            Terminal::$width = $columns;
            Terminal::$height = $lines;
         });
      }

      try {
         // @@ Feed → key → render, at the configured frame budget
         //   ($following flips inside the SIGINT closure — invisible to static narrowing)
         while ($this->following === true && $Source->valid() === true) { // @phpstan-ignore-line
            if (function_exists('pcntl_signal_dispatch') === true) {
               pcntl_signal_dispatch();
            }

            $chunk = (string) $Source->current();
            if ($chunk !== '') {
               $this->Viewer->feed($chunk);
            }

            // @ One keystroke per frame (non-blocking)
            $key = $Input->read(8);
            if ($key !== false && $key !== '') {
               if ($this->Viewer->control($key) === false) {
                  break;
               }
            }

            $this->Viewer->render();
            usleep($this->rate);
            $Source->next();
         }
      }
      finally {
         // ! Always restore the terminal — and the embedding flow's handlers
         $this->following = false;
         if (function_exists('pcntl_signal') === true) {
            pcntl_signal(SIGINT, $SIGINTHandler ?? SIG_DFL);
            pcntl_signal(SIGWINCH, $SIGWINCHHandler ?? SIG_DFL);
         }
         $Input->configure(blocking: true, canonical: true, echo: true);
         $Output->Cursor->show();
         $Output->write("\e[?1049l");
      }
   }
}
