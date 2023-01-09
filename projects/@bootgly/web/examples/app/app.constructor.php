<?php
namespace Bootgly\Web;

# $this->Template = new Template($this->Web);

$Web->Router->boot([
   'routes.requesting',
   'routes.responsing',
   'routes.templating'
]);
