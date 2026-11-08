<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage\Changes;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Coverage — Changes parses unified=0 diffs into new-side changed lines',

   test: new Assertions(Case: function (): Generator {
      // ! A work tree with the files the diff names
      $root = Temporaries::reserve('coverage-changes-parse');
      mkdir("{$root}/src");
      $names = ['src/A.php', 'src/B.php', 'src/Context.php', 'src/Forged.php', 'src/Renamed.php', 'src/With space.php', "src/Tab\tname.php"];
      foreach ($names as $name) {
         file_put_contents("{$root}/{$name}", "<?php\n");
      }
      $real = static fn (string $name): string => str_replace('\\', '/', (string) realpath("{$root}/{$name}"));

      $diff = <<<'DIFF'
      diff --git a/src/A.php b/src/A.php
      index 1111111..2222222 100644
      --- a/src/A.php
      +++ b/src/A.php
      @@ -3 +3 @@ class A
      -old
      +new
      @@ -10,0 +11,3 @@ class A
      +one
      +++ b/src/Forged.php
      +three
      @@ -20,2 +23,0 @@ class A
      -gone
      -gone
      diff --git a/src/Deleted.php b/src/Deleted.php
      deleted file mode 100644
      --- a/src/Deleted.php
      +++ /dev/null
      @@ -1,2 +0,0 @@
      -<?php
      -$x = 1;
      diff --git a/src/Old.php b/src/Renamed.php
      similarity index 90%
      rename from src/Old.php
      rename to src/Renamed.php
      --- a/src/Old.php
      +++ b/src/Renamed.php
      @@ -5 +5,2 @@
      -a
      +b
      +c
      diff --git a/src/With space.php b/src/With space.php
      --- a/src/With space.php<TAB>
      +++ b/src/With space.php<TAB>
      @@ -0,0 +1 @@
      +<?php
      diff --git a/src/Context.php b/src/Context.php
      --- a/src/Context.php
      +++ b/src/Context.php
      @@ -1,6 +1,7 @@
       <?php
       $a = 1;
      -$b = 2;
      +$b = 20;
      +$c = 3;
       $d = 4;
       
       $e = 5;
      diff --git "a/src/Tab\tname.php" "b/src/Tab\tname.php"
      --- "a/src/Tab\tname.php"
      +++ "b/src/Tab\tname.php"
      @@ -1,0 +2,2 @@
      +x
      \ No newline at end of file
      +y
      diff --git a/src/notes.txt b/src/notes.txt
      --- a/src/notes.txt
      +++ b/src/notes.txt
      @@ -1 +1 @@
      -a
      +b
      diff --git a/src/B.php b/src/B.php
      Binary files a/src/B.php and b/src/B.php differ
      DIFF;
      // ! git ends an unquoted path holding a space with one TAB
      $diff = str_replace('<TAB>', "\t", $diff);

      try {
         $lines = Changes::parse($diff, $root);

         yield (new Assertion(description: 'a one-line hunk and a counted hunk give their new-side lines'))
            ->expect($lines[$real('src/A.php')] ?? null)
            ->to->be([3 => true, 11 => true, 12 => true, 13 => true])
            ->assert();

         yield (new Assertion(description: 'a "+++ b/" content line inside a hunk is not read as a file header'))
            ->expect(isset($lines[$real('src/Forged.php')]))
            ->to->be(false)
            ->assert();

         yield (new Assertion(description: 'context rows advance the line count but are not changes'))
            ->expect($lines[$real('src/Context.php')] ?? null)
            ->to->be([3 => true, 4 => true])
            ->assert();

         yield (new Assertion(description: 'a renamed file keys its new path with the added lines'))
            ->expect($lines[$real('src/Renamed.php')] ?? null)
            ->to->be([5 => true, 6 => true])
            ->assert();

         yield (new Assertion(description: 'paths with spaces (TAB-terminated) and C-quoted paths resolve'))
            ->expect([
               $lines[$real('src/With space.php')] ?? null,
               $lines[$real("src/Tab\tname.php")] ?? null,
            ])
            ->to->be([[1 => true], [2 => true, 3 => true]])
            ->assert();

         yield (new Assertion(description: 'deleted, missing non-PHP and binary files contribute nothing'))
            ->expect(count($lines))
            ->to->be(5)
            ->assert();

         $thrown = '';
         try {
            Changes::parse("diff --git a/src/Vanished.php b/src/Vanished.php\n--- a/src/Vanished.php\n+++ b/src/Vanished.php\n@@ -1 +1 @@\n-a\n+b\n", $root);
         }
         catch (RuntimeException $Exception) {
            $thrown = $Exception->getMessage();
         }
         yield (new Assertion(description: 'a changed PHP file missing from the work tree throws instead of vanishing'))
            ->expect(str_contains($thrown, "'src/Vanished.php' is changed by the range but not found"))
            ->to->be(true)
            ->assert();

         $thrown = false;
         try {
            Changes::parse("diff --cc src/A.php\nindex 1,2..3\n@@@ -1,1 -1,1 +1,2 @@@\n++x\n", $root);
         }
         catch (RuntimeException) {
            $thrown = true;
         }
         yield (new Assertion(description: 'a combined (merge) diff throws instead of being misread'))
            ->expect($thrown)
            ->to->be(true)
            ->assert();

         // ! A blank context row printed without its leading space
         $empty = "diff --git a/src/A.php b/src/A.php\n--- a/src/A.php\n+++ b/src/A.php\n@@ -1,4 +1,4 @@\n <?php\n\n-\$x = 1;\n+\$x = 2;\n \$y = 3;\n";
         yield (new Assertion(description: 'an empty hunk row counts as a context line'))
            ->expect(Changes::parse($empty, $root)[$real('src/A.php')] ?? null)
            ->to->be([3 => true])
            ->assert();

         yield (new Assertion(description: 'an empty diff gives no lines'))
            ->expect(Changes::parse('', $root))
            ->to->be([])
            ->assert();

         yield (new Assertion(description: 'CRLF output parses like LF'))
            ->expect(Changes::parse(str_replace("\n", "\r\n", $diff), $root) === $lines)
            ->to->be(true)
            ->assert();
      }
      finally {
         foreach ($names as $name) {
            @unlink("{$root}/{$name}");
         }
         @rmdir("{$root}/src");
         @rmdir($root);
      }
   })
);
