<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


// ! Two Middleware classes whose instances are the same when their `$tag` is
abstract class Merge_Tagged implements Middleware
{
   public string $tag;

   public function __construct (string $tag)
   {
      $this->tag = $tag;
   }

   public function process (object $Request, object $Response, Closure $Next): object
   {
      return $Next($Request, $Response);
   }
}
final class Merge_First extends Merge_Tagged
{
}
final class Merge_Second extends Merge_Tagged
{
}


/**
 * What a cold Router pays to merge the Middlewares of a registration an
 * earlier run made too, and to read the file a compared closure is declared
 * in. A Middleware built differently on every run is still kept once per
 * run — nothing a run intercepted is dropped —, but a registration costs a
 * pass over what it keeps, each compared with the free own ones of its class
 * only, not a copy of the list per kept one put back: one merge of four times
 * the kept ones costs about what four merges cost, not four times more. The
 * merged list is the one the earlier merge laid out, whatever the classes.
 * A file larger than 256 KiB is not read: a closure declared there is
 * another one, as one in a file gone since it was loaded.
 */
return new Test(
   description: 'It should bound what a cold Router pays to merge the Middlewares of a registration made again',
   test: new Assertions(Case: function (): Generator {
      $Wrap = static function (mixed $Inner): Middleware {
         return new class ($Inner) implements Middleware {
            public mixed $Inner;

            public function __construct (mixed $Inner)
            {
               $this->Inner = $Inner;
            }

            public function process (object $Request, object $Response, Closure $Next): object
            {
               return $Next($Request, $Response);
            }
         };
      };
      $Handler = static function (Request $Request, Response $Response): Response {
         return $Response;
      };

      // @ A Middleware built from what changes on every run, over many runs
      $runs = 300;
      $Router = new Router;
      for ($run = 1; $run <= $runs; $run++) {
         $Router->rewind();
         $Router->intercept($Wrap((object) ['run' => $run]));
         $Router->route('/admin', $Handler, 'GET');
      }
      $Router->warm();

      yield new Assertion(description: 'a Middleware built differently on each of 300 cold runs is kept once per run')
         ->expect(count($Router->Routes[0]->Middlewares))
         ->to->be($runs)
         ->assert();

      // @ How the merge grows with what a registration keeps: windows of
      //   the same work timed side by side, the best of five each, so a slower host, a coverage driver
      //   or a busy core scales them alike
      $Kept = [];
      for ($index = 0; $index < 8000; $index++) {
         $Kept[] = new Merge_First("kept $index");
      }
      $Chunks = array_chunk($Kept, 2000);
      $Single = [new Merge_First('own')];
      $Router = new Router;
      $Merge = static function (array $Kept, array $Own) use ($Router): void {
         (fn () => $this->merge($Kept, $Own))->call($Router);
      };
      $best = ['whole' => INF, 'chunks' => INF];
      // @@ Five rounds, each timing both windows in turn
      for ($rep = 0; $rep < 5; $rep++) {
         $started = hrtime(true);
         $Merge($Kept, $Single);
         $best['whole'] = min($best['whole'], hrtime(true) - $started);

         $started = hrtime(true);
         foreach ($Chunks as $Chunk) {
            $Merge($Chunk, $Single);
         }
         $best['chunks'] = min($best['chunks'], hrtime(true) - $started);
      }

      yield new Assertion(description: 'one merge of 8000 kept ones costs about what four merges of 2000 cost, not four times more')
         ->expect($best['whole'] / $best['chunks'] < 2.0)
         ->to->be(true)
         ->assert();

      // @ The merged list against the one the earlier merge laid out: each
      //   kept one compared with the free own ones in their order, put back
      //   right after the last own one before it that was matched
      $Reference = static function (Router $Router, array $Kept, array $Own): array {
         $Merged = array_values($Own);
         $free = array_fill(0, count($Merged), true);
         $cursor = 0;
         foreach ($Kept as $Middleware) {
            foreach ($Merged as $index => $Current) {
               $pairs = [];
               $same = (fn () => $this->compare($Current, $Middleware, $pairs))->call($Router);
               if ($free[$index] && $same) {
                  $free[$index] = false;
                  $cursor = max($cursor, $index + 1);

                  continue 2;
               }
            }
            array_splice($Merged, $cursor, 0, [$Middleware]);
            array_splice($free, $cursor, 0, [false]);
            $cursor++;
         }

         return $Merged;
      };
      $Merge = static function (Router $Router, array $Kept, array $Own): array {
         return (fn () => $this->merge($Kept, $Own))->call($Router);
      };
      $Ids = static function (array $Middlewares): array {
         return array_map(spl_object_id(...), $Middlewares);
      };
      $Build = static function (int $seed, int $size): array {
         $Middlewares = [];
         for ($index = 0; $index < $size; $index++) {
            $tag = (string) (($seed * 7 + $index * 13) % 4);
            $Middlewares[] = ($seed + $index) % 3 === 0
               ? new Merge_First($tag)
               : new Merge_Second($tag);
         }

         return $Middlewares;
      };
      $Router = new Router;
      $differ = [];
      // @@ Lists of either class, of 0 to 6 each, with equal ones repeated
      for ($seed = 0; $seed < 400; $seed++) {
         $Kept = $Build($seed, $seed % 7);
         $Own = $Build($seed * 31 + 5, intdiv($seed, 7) % 7);
         // ? One own instance kept too, now and then
         if ($Kept !== [] && $Own !== [] && $seed % 5 === 0) {
            $Own[0] = $Kept[$seed % count($Kept)];
         }
         if ($Ids($Merge($Router, $Kept, $Own)) !== $Ids($Reference($Router, $Kept, $Own))) {
            $differ[] = $seed;
         }
      }

      yield new Assertion(description: 'the merged list is the one the earlier merge laid out, over 400 lists of two classes')
         ->expect($differ)
         ->to->be([])
         ->assert();

      // @ A closure declared in a file around the size limit, and in a large one
      $Same = static function (int $bytes): array {
         $source = "<?php\nreturn static function (string \$role): Closure {\n   return static fn (): bool => \$role === 'staff';\n};\n";
         $source .= str_repeat(' ', $bytes - strlen($source));
         $file = tempnam(sys_get_temp_dir(), 'bootgly-router-');
         try {
            file_put_contents($file, $source);
            $Gate = require $file;
            $Router = new Router;
            memory_reset_peak_usage();
            $before = memory_get_usage();
            $pairs = [];
            $A = $Gate('staff');
            $B = $Gate('staff');
            $same = (fn () => $this->compare($A, $B, $pairs))->call($Router);
            $peak = memory_get_peak_usage() - $before;
         }
         finally {
            unlink($file);
         }

         return [$same, $peak];
      };
      [$limit] = $Same(262144);
      [$over] = $Same(262145);
      [$large, $peak] = $Same(2097152);

      yield new Assertion(description: 'a closure in a file of 256 KiB is compared; one byte more, or 2 MiB, is another one, read without holding the file')
         ->expect([$limit, $over, $large, $peak < 1048576])
         ->to->be([true, false, false, true])
         ->assert();
   })
);
