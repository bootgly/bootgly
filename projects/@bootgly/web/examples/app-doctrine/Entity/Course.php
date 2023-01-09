<?php
namespace Bootgly\Examples\Doctrine\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;

#[Entity]
class Course
{
   #[Id, GeneratedValue, Column]
   public int $id;

   #[Column]
   public readonly string $name;

   #[ManyToMany(Student::class, mappedBy: "courses")]
   private Collection $Students;


   public function __get (string $name)
   {
      switch ($name) {
         default:
            return $this->$name;
      }
   }
}