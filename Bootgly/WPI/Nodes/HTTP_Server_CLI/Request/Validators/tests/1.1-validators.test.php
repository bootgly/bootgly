<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Email;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Extension;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Integer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Maximum;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\MIME;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Minimum;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Regex;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Required;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators\Size;


return new Specification(
   description: 'It should validate request data with rule objects',
   test: new Assertions(Case: function (): Generator {
      // @ Valid source.
      $Validation = new Validation(
         source: [
            'email' => 'user@example.com',
            'age' => '18',
            'name' => 'Bootgly',
            'slug' => 'bootgly-core',
            'avatar' => [
               'name' => 'photo.jpg',
               'size' => 1024,
               'type' => 'image/jpeg',
               'error' => 0,
            ],
         ],
         rules: [
            'email' => [new Required, new Email],
            'age' => [new Required, new Integer, new Minimum(18), new Maximum(120)],
            'name' => [new Required, new Maximum(10)],
            'slug' => [new Regex('/\A[a-z0-9-]+\z/')],
            'avatar' => [new Required, new Size(2048), new MIME('image/jpeg'), new Extension('jpg')],
            'optional' => [new Email],
         ]
      );

      yield new Assertion(description: 'Valid source should pass')
         ->expect($Validation->valid)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'Optional missing field should not create errors')
         ->expect(isset($Validation->errors['optional']))
         ->to->be(false)
         ->assert();

      // @ Invalid source.
      $Validation = new Validation(
         source: [
            'email' => 'invalid',
            'age' => '17',
            'name' => 'BootglyFramework',
            'slug' => 'Bootgly Core',
            'avatar' => [
               'name' => 'shell.php',
               'size' => 4096,
               'type' => 'text/plain',
               'error' => 0,
            ],
         ],
         rules: [
            'email' => [new Required, new Email],
            'age' => [new Required, new Integer, new Minimum(18)],
            'name' => [new Maximum(10)],
            'slug' => [new Regex('/\A[a-z0-9-]+\z/')],
            'avatar' => [new Size(1024), new MIME(['image/jpeg', 'image/png']), new Extension(['jpg', 'png'])],
            'missing' => [new Required],
         ]
      );

      yield new Assertion(description: 'Invalid source should fail')
         ->expect($Validation->valid)
         ->to->be(false)
         ->assert();

      yield new Assertion(description: 'Invalid email should be reported')
         ->expect($Validation->errors['email'][0])
         ->to->be('email must be a valid email address.')
         ->assert();

      yield new Assertion(description: 'Minimum rule should be reported')
         ->expect($Validation->errors['age'][0])
         ->to->be('age must be at least 18.')
         ->assert();

      yield new Assertion(description: 'Maximum rule should be reported')
         ->expect($Validation->errors['name'][0])
         ->to->be('name must be at most 10.')
         ->assert();

      yield new Assertion(description: 'Regex rule should be reported')
         ->expect($Validation->errors['slug'][0])
         ->to->be('slug has an invalid format.')
         ->assert();

      yield new Assertion(description: 'Required rule should be reported')
         ->expect($Validation->errors['missing'][0])
         ->to->be('missing is required.')
         ->assert();

      yield new Assertion(description: 'File size rule should be reported')
         ->expect($Validation->errors['avatar'][0])
         ->to->be('avatar must be at most 1024 bytes.')
         ->assert();

      yield new Assertion(description: 'File MIME rule should be reported')
         ->expect($Validation->errors['avatar'][1])
         ->to->be('avatar must have an allowed MIME type.')
         ->assert();

      yield new Assertion(description: 'File extension rule should be reported')
         ->expect($Validation->errors['avatar'][2])
         ->to->be('avatar must have an allowed extension.')
         ->assert();

   })
);
