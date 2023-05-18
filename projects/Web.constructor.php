<?php
namespace Bootgly\Web;


use Bootgly\Bootgly;
use Bootgly\Debugger;


// ! Global Routes
switch ($Request->host) {
   case 'example.com':
      Bootgly::$Project->vendor = 'example.com/';
      Bootgly::$Project->container = 'examples/';

      switch (@$Request->paths[0]) {
         case 'app':
            Bootgly::$Project->package = 'app/';
            Bootgly::$Project->public = 'dist/';
            Bootgly::$Project->version = 'spa/';
            Bootgly::$Project->setPath(); // Set main folder
            Bootgly::$Project->version = 'public/';
            Bootgly::$Project->setPath(); // Set backup folder

            $App = new App($this);
            $App->pathbase = '/app/';
            $App->template = 'static';
            $App->load();
            break;
         default:
            // $Response->redirect('//example.com/app/');
      }

      break;
   default: // @ bootgly
      // TODO with $Router
      #$Router(['host' => 'bootgly.slayer.tech'], function () {});

      error_reporting(E_ALL); ini_set('display_errors', 'On');

      #phpinfo(); exit;

      #xdebug_break();

      Bootgly::$Project->vendor = '@bootgly/';
      Bootgly::$Project->container = 'Web/';
      Bootgly::$Project->package = 'examples/';
      Bootgly::$Project->version = 'app/';
      Bootgly::$Project->setPath();

      $this->App = new App($this);
      $this->App->load();
}
