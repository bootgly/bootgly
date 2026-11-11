<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Events\Timer\Registry;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;


/**
 * The worker-side tickers of the TCP server — the master watchdog, the
 * recycle gate, the drain poll — have one owner per worker: the event loop
 * when it offers recurring timers, the static `Timer` otherwise. Every one of
 * them is registered through the private `repeat()`, the only place of the
 * class that still adds a static Timer task.
 */
return new Test(
   description: 'The worker tickers of the TCP server should run on the loop that offers recurring timers, on the static Timer otherwise, through one registration point',
   test: function () {
      $Reflection = new ReflectionClass(Select::class);
      $tick = $Reflection->getMethod('tick');
      $Repeaters = $Reflection->getProperty('Repeaters');
      // ! A loop with no timers of its own
      $Legacy = new class implements Events, Loops, Scheduler {
         public int $asked = 0;
         public function add ($Socket, int $flag, $payload): bool { return true; }
         public function del ($Socket, int $flag): bool { return true; }
         public function loop (): void {}
         public function destroy (): void {}
         public function schedule (Fiber $Fiber, mixed $value = null, int $flag = self::SCHEDULE_READ): bool { return false; }
         public function defer (float|int $deadline, Closure $Callback): int { $this->asked++; return 0; }
         public function cancel (int $ID): bool { $this->asked++; return false; }
         public function interrupt (Fiber $Fiber, Throwable $Throwable): bool { return false; }
      };
      // ! A sleep a signal cannot cut short
      $wait = static function (int $until): void {
         while (($left = $until - (int) hrtime(true)) > 0) {
            usleep((int) max(1, min(20_000, $left / 1_000)));
         }
      };
      $Evidence = ['error' => '', 'loop' => null, 'runs' => null, 'legacy' => null, 'pin' => null, 'sweep' => null];
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $oldContext = isset(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $NS = 1_000_000_000;

      try {
         // ! Hermetic timer and server statics
         Timer::del();
         TCP_Server_CLI::$context = [];
         $Server = new TCP_Server_CLI;
         $Collection = new Connections($Server);
         $Loop = new Select($Collection);
         $Repeat = new ReflectionMethod(TCP_Server_CLI::class, 'repeat');

         // @ A) The loop owns the ticker: no static task, so no alarm is armed
         TCP_Server_CLI::$Event = $Loop;
         $runs = 0;
         $registered = (int) hrtime(true);
         $Repeat->invoke($Server, 1, static function () use (&$runs): void {
            $runs++;
         });
         $Evidence['loop'] = [
            array_values(array_column($Repeaters->getValue($Loop), 'interval')),
            Registry::snapshot(),
         ];

         // @ B) It recurs: once per interval, for as long as the loop lives
         $wait($registered + (int) (0.5 * $NS));
         $tick->invoke($Loop);
         $early = $runs;
         $wait($registered + (int) (1.05 * $NS));
         $tick->invoke($Loop);
         $first = $runs;
         $wait($registered + (int) (2.05 * $NS));
         $tick->invoke($Loop);
         $Evidence['runs'] = [$early, $first, $runs, count($Repeaters->getValue($Loop))];

         // @ C) A loop without the capability: one persistent static Timer task
         TCP_Server_CLI::$Event = $Legacy;
         $Repeat->invoke($Server, 30, static function (): void {});
         $Evidence['legacy'] = [count(Registry::snapshot()), $Legacy->asked, count($Repeaters->getValue($Loop))];
         Timer::del();

         // @ D) Pin: `repeat()` is the one place that adds a static Timer task
         $source = (string) file_get_contents(new ReflectionClass(TCP_Server_CLI::class)->getFileName());
         $Tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($Token): bool => is_array($Token) === false
               || in_array($Token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === false
         ));
         $method = '';
         $depth = 0;
         $opened = 0;
         $adders = [];
         $callers = [];
         $sweepers = [];
         foreach ($Tokens as $index => $Token) {
            $text = is_array($Token) ? $Token[1] : $Token;
            if ($text === '{' || (is_array($Token) && in_array($Token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
               $depth++;
            }
            else if ($text === '}') {
               $depth--;
               if ($method !== '' && $depth < $opened) {
                  $method = '';
               }
            }
            else if (is_array($Token) && $Token[0] === T_FUNCTION && $method === '' && $depth >= 1
               && is_array($Tokens[$index + 1]) && $Tokens[$index + 1][0] === T_STRING
            ) {
               $method = $Tokens[$index + 1][1];
               $opened = $depth + 1;
            }
            // ? `Timer::add(`
            else if ($text === 'Timer' && ($Tokens[$index + 1][0] ?? null) === T_DOUBLE_COLON
               && ($Tokens[$index + 2][1] ?? null) === 'add'
            ) {
               $adders[] = $method;
            }
            // ? `$this->repeat(`
            else if ($text === '$this' && ($Tokens[$index + 1][0] ?? null) === T_OBJECT_OPERATOR
               && ($Tokens[$index + 2][1] ?? null) === 'repeat' && ($Tokens[$index + 3] ?? null) === '('
            ) {
               $callers[] = $method;
            }
            // ? `Connections::sweep(` — with its nesting inside the method
            else if ($text === 'Connections' && ($Tokens[$index + 1][0] ?? null) === T_DOUBLE_COLON
               && ($Tokens[$index + 2][1] ?? null) === 'sweep' && ($Tokens[$index + 3] ?? null) === '('
            ) {
               $sweepers[] = [$method, $depth - $opened];
            }
         }
         $Evidence['pin'] = [$adders, $callers];
         $Evidence['sweep'] = $sweepers;
      }
      catch (Throwable $Throwable) {
         $Evidence['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         Timer::del();
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
      }

      $observed = json_encode($Evidence);

      yield assert(
         assertion: $Evidence['error'] === '' && $Evidence['loop'] === [[1 * $NS], []],
         description: "on a loop with recurring timers a worker ticker is one recurring loop timer and no static Timer task — observed: {$observed}"
      );
      yield assert(
         assertion: $Evidence['runs'] === [0, 1, 2, 1],
         description: "the ticker runs once per interval and stays registered — observed: {$observed}"
      );
      yield assert(
         assertion: $Evidence['legacy'] === [1, 0, 1],
         description: "on a loop without recurring timers the ticker is one static Timer task and the loop is asked for nothing — observed: {$observed}"
      );
      yield assert(
         assertion: $Evidence['pin'] === [['repeat'], ['work', 'work', 'drain']],
         description: "repeat() is the only method of the class that adds a static Timer task; the watchdog and the recycle gate (work) and the drain poll (drain) register through it — observed: {$observed}"
      );
      yield assert(
         assertion: $Evidence['sweep'] === [['work', 1], ['work', 0]],
         description: "the worker's ticker runs the connections' supervision sweep (inside its closure) and work() owns the sweep before the first connection (one direct call), so no connection arms a second loop timer — observed: {$observed}"
      );
   }
);
