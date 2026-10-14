<?php
namespace App\Support;
use InvalidArgumentException;
final class Decimal {
 public static function scaled(string $value,int $scale):int {
  if(!preg_match('/^\d{1,9}(?:\.\d{1,'.$scale.'})?$/D',$value))throw new InvalidArgumentException('Invalid decimal');
  [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');return (int)$whole*(10**$scale)+(int)str_pad($fraction,$scale,'0');
 }
 public static function total(int $quantityMilli,int $ratePaisa):int {return intdiv($quantityMilli*$ratePaisa+500,1000);}
}
