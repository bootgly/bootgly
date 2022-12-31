<?php
namespace Bootgly\__Class;


class Adopted
{
  public ?object $Object = null;

  public function __construct(object $Object)
  {
    $this->Object = $Object;
  }
  public function __get($name)
  {
    return @$this->Object->$name;
  }
}
