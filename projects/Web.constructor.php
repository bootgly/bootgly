<?php
namespace Bootgly\Web;


use Bootgly\Bootgly;
use Bootgly\Debugger;


// ! Global Routes
switch ($Request->host) {
   case 'quasar.bootgly.localhost':
      Bootgly::$Project->vendor = 'Bootgly/';
      Bootgly::$Project->container = 'Web/';

      switch (@$Request->paths[0]) {
         case 'app':
            Bootgly::$Project->type = 'app-vue-quasar/';
            Bootgly::$Project->package = 'quasar-project/';
            Bootgly::$Project->public = 'dist/';
            Bootgly::$Project->version = 'spa/';
            Bootgly::$Project->construct(); // Construct main folder
            Bootgly::$Project->version = 'public/';
            Bootgly::$Project->construct(); // Construct backup folder

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

      Bootgly::$Project->vendor = 'Bootgly/';
      Bootgly::$Project->container = 'Web/';
      Bootgly::$Project->package = 'examples/';
      Bootgly::$Project->version = 'app/';
      Bootgly::$Project->construct();

      $this->App = new App($this);
      $this->App->load();
}
