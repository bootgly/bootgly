<?php

use Bootgly\ABI\Syntax\Methods;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Methods: one base-form verb — past forms, predicates, plural/third-person forms, nouns, adjectives and snake_case are reported; the -ing loop, contract and protocol names pass',
   test: function () {
      $Methods = new Methods;

      // @ Accepted — base verbs, the -ing loop form, verbs a suffix would misjudge,
      //   PHP-imposed names in an inheriting class, stream wrapper protocol names, functions
      $found = [];
      foreach ($Methods->analyze(__DIR__ . '/fixtures/accepted.php')->issues as $Issue) {
         $found[] = "{$Issue->line}:{$Issue->symbol}";
      }
      yield assert(
         assertion: $found === [],
         description: 'The accepted fixture must report nothing, got: ' . json_encode($found)
      );

      // @ Rejected
      $found = [];
      foreach ($Methods->analyze(__DIR__ . '/fixtures/rejected.php')->issues as $Issue) {
         $found[] = [$Issue->line, $Issue->type, $Issue->symbol];
      }
      $expected = [
         [8, 'verb_form', 'loaded'],
         [9, 'verb_form', 'rendered'],
         [10, 'verb_form', 'built'],
         [11, 'verb_form', 'has'],
         [12, 'verb_form', 'is'],
         [13, 'verb_form', 'exists'],
         [14, 'verb_form', 'items'],
         [15, 'verb_form', 'status'],
         [16, 'verb_form', 'valid'],
         [17, 'verb_form', 'current'],
         [18, 'verb_form', 'available'],
         [19, 'verb_form', 'identity'],
         [20, 'verb_form', 'argument'],
         [21, 'multiword_method', 'render_html'],
         [22, 'multiword_method', 'renderHTML'],
         [23, 'multiword_method', 'stream_custom'],
      ];
      yield assert(
         assertion: $found === $expected,
         description: 'The rejected fixture must report exactly its names, got: ' . json_encode($found)
      );

      // @ The message says why
      $messages = [];
      foreach ($Methods->analyze(__DIR__ . '/fixtures/rejected.php')->issues as $Issue) {
         $messages[$Issue->symbol] = $Issue->message;
      }
      yield assert(
         assertion: str_contains($messages['loaded'], 'is a past form')
            && str_contains($messages['has'], 'is a predicate')
            && str_contains($messages['exists'], 'plural or third-person')
            && str_contains($messages['status'], 'is a noun')
            && str_contains($messages['valid'], 'is an adjective'),
         description: 'Each verb-form message must name the reason, got: ' . json_encode($messages)
      );
   }
);
