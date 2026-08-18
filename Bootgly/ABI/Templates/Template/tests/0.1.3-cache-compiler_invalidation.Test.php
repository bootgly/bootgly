<?php

use Bootgly\ABI\Templates\Directives;
use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should invalidate a compiled cache when the compiler changes',
   test: function () {
      // ! Directives is a process-wide static and extend() mutates it in place with no
      //   way back, so the shipped set is rebuilt on the way out — every later test
      //   must see the directives it expects
      try {
         // ! A token no shipped directive matches
         $source = '@probe; and text';

         // @ Valid
         $Template1 = new Template($source);
         $Template1->render();

         yield assert(
            assertion: $Template1->output === '@probe; and text',
            description: "An unregistered token must stay literal: \n`{$Template1->output}`"
         );

         // Registering a directive changes what this very source compiles to, so the
         // entry cached a moment ago must not be reused for it (TPL-15)
         Template::$Directives->extend(
            '/@probe;/',
            static fn (): string => '<?php echo "PROBED"; ?>',
            'probe'
         );

         $Template2 = new Template($source);
         $Template2->render();

         yield assert(
            assertion: $Template2->output === 'PROBED and text',
            description: 'Registering a directive must recompile the same source, got: '
               . "\n`{$Template2->output}`"
         );

         // @ Neutral
         // The identity itself: registering changes it, and the files that define what
         // a directive emits are part of it
         $Fresh = new Directives();
         $fingerprint = $Fresh->fingerprint;

         yield assert(
            assertion: is_string($fingerprint) && $fingerprint !== '',
            description: 'A directive set must carry a fingerprint, got: '
               . get_debug_type($fingerprint)
         );

         $Fresh->extend('/@another;/', static fn (): string => '', 'another');

         yield assert(
            assertion: $Fresh->fingerprint !== $fingerprint,
            description: 'extend() must change the fingerprint'
         );

         // Registering the same pattern twice is a no-op, so it must not move it either
         $stable = $Fresh->fingerprint;
         $Fresh->extend('/@another;/', static fn (): string => 'other', 'another');

         yield assert(
            assertion: $Fresh->fingerprint === $stable,
            description: 'A duplicate registration must not change the fingerprint'
         );

         // @ Invalid
         // ...
      }
      finally {
         // ! Restore the shipped directive set
         Template::$Directives = new Directives();
      }
   }
);
