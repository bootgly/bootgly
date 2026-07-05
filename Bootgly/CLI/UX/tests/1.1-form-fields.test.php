<?php

namespace Bootgly\CLI\UX;


use function assert;
use function fopen;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UX\Form\Controls;


return new Specification(
   description: 'It should register declarative fields with control-aware defaults',
   test: function () {
      // ! Form with in-memory streams
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Form = new Form($Input, $Output);

      // @ Register fields
      $Name = $Form->add('Name', required: true);
      $Token = $Form->add('Token', Controls::Secret);
      $Pin = $Form->add('PIN', Controls::Secret, mask: '*');
      $Platform = $Form->add('Platform', Controls::Select, default: 'Console', options: ['Console', 'Web']);

      // @ Valid
      yield assert(
         assertion: $Form->Fields->count === 4,
         description: 'Fields collection counts every registered field'
      );
      yield assert(
         assertion: $Name->Control === Controls::Text && $Name->required === true && $Name->mask === null,
         description: 'Text fields default to no mask'
      );
      yield assert(
         assertion: $Token->mask === '•',
         description: 'Secret fields default to the `•` mask'
      );
      yield assert(
         assertion: $Pin->mask === '*',
         description: 'A custom mask overrides the Secret default'
      );
      yield assert(
         assertion: $Platform->options === ['Console', 'Web'] && $Platform->default === 'Console',
         description: 'Select fields keep their options and default'
      );
      yield assert(
         assertion: $Name->answered === false && $Name->answer === '',
         description: 'Fields start unanswered'
      );

      // @ Field answers are recorded by update()
      $Name->update('Bootgly');

      yield assert(
         assertion: $Name->answered === true && $Name->answer === 'Bootgly',
         description: 'update() records the answer and marks the field as answered'
      );
   }
);
