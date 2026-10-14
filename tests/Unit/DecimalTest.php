<?php
namespace Tests\Unit;
use App\Support\Decimal;
use PHPUnit\Framework\TestCase;
class DecimalTest extends TestCase {
 public function test_money_is_exact():void{$this->assertSame(1001,Decimal::scaled('10.01',2));$this->assertSame(1230,Decimal::scaled('1.23',3));}
 public function test_material_cost_rounds_half_up():void{$this->assertSame(1001,Decimal::total(1000,1001));$this->assertSame(1,Decimal::total(500,1));}
 public function test_scientific_notation_is_rejected():void{$this->expectException(\InvalidArgumentException::class);Decimal::scaled('1e5',2);}
 public function test_excess_precision_is_rejected():void{$this->expectException(\InvalidArgumentException::class);Decimal::scaled('0.001',2);}
}
