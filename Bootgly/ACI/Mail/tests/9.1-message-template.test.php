<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ABI\Templates\Template\Exceptions\TemplateException;
use Bootgly\ACI\Mail\Message;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Mail\Message: template-based HTML body (ABI template engine)',
   test: function () {
      // ! Sentinels — the mail render must scope and restore the engine state
      $path = Template::$path;
      $layout = Template::$layout;
      $mails = Message::$path;

      Template::$path = '/sentinel/';
      Template::$layout = 'sentinel-layout';
      Message::$path = __DIR__ . '/fixtures/templates/';

      try {
         // @ Template rendered into the HTML body
         $Message = new Message();
         $Message->from = 'no-reply@bootgly.com';
         $Message->to = 'user@example.net';
         $Message->subject = 'Welcome';
         $Message->text = 'Hello, Ana!';
         $Message->template = 'welcome';
         $Message->data = ['name' => 'Ana'];
         $Message->id = 'fixed@bootgly.com';
         $Message->date = 'Mon, 06 Jul 2026 10:00:00 -0300';
         $Message->boundary = 'templateseed';

         $raw = $Message->render();

         yield assert(
            assertion: str_contains($Message->html, '<p>Hello, Ana!</p>'),
            description: 'the template output is rendered into the html body'
         );
         yield assert(
            assertion: str_contains($raw, 'multipart/alternative'),
            description: 'text + template html compose a multipart/alternative message'
         );
         yield assert(
            assertion: Template::$path === '/sentinel/' && Template::$layout === 'sentinel-layout',
            description: 'the engine base path and default layout are restored after render'
         );
         yield assert(
            assertion: $Message->render() === $raw,
            description: 'template renders are idempotent (same bytes on re-render)'
         );

         // @ Data drives the output deterministically
         $Message->data = ['name' => 'Bia'];
         yield assert(
            assertion: str_contains($Message->render(), 'Hello, Bia'),
            description: 'changing the template data changes the rendered body'
         );

         // @ Missing template fails loudly
         $Message->template = 'missing';
         $caught = false;
         try {
            $Message->render();
         }
         catch (TemplateException) {
            $caught = true;
         }
         yield assert(
            assertion: $caught,
            description: 'a missing template throws TemplateException'
         );

         // @ Invalid (escaping) template name is jailed
         $Message->template = '../welcome';
         $caught = false;
         try {
            $Message->render();
         }
         catch (TemplateException) {
            $caught = true;
         }
         yield assert(
            assertion: $caught,
            description: 'a traversal template name is rejected (jail mirror of the view guard)'
         );

         // @ No mail path and no engine path — resolve() fails closed
         Message::$path = '';
         Template::$path = '';
         $Message->template = 'welcome';
         $caught = false;
         try {
            $Message->render();
         }
         catch (TemplateException) {
            $caught = true;
         }
         yield assert(
            assertion: $caught,
            description: 'no configured template path throws TemplateException'
         );
      }
      finally {
         Template::$path = $path;
         Template::$layout = $layout;
         Message::$path = $mails;
      }
   }
);
