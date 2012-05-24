<?php

require_once __DIR__ . '/../lib/Converter.php';

class ConverterTest extends PHPUnit_Framework_TestCase{
    /**
     * @test
     */
    public function parseClass(){
        $phpCode = <<<PHP
<?php
class Foo{
    private \$privateField;
    private static \$privateStaticField;
    public \$publicField;
    public static \$publicStaticField;
    private const PRIVATE_CONST = 1;
    public const PUBLIC_CONST = "string value";
    protected \$protectedField;
    protected static \$protectedStaticField;
    private function privateFunction(){
    }
    protected static function protectedStaticFunction(){
    }
    public function publicFunction(\$foo,\$bar=null){
    }
    public function functionWithReference(\$value){
    }
}
PHP;
        $converter = new Converter();
        $actual = $converter->convert( $phpCode );
        $expected = <<<SCALA
class Foo extends PHPObject {
     private var privateField : Any = null;
     var publicField : Any = null;
     protected var protectedField : Any = null;
     private def privateFunction(){

    }
     def publicFunction(foo : Any,bar : Any=null){

    }
     def functionWithReference(value : Any){

    }
}


object Foo {
      private var privateStaticField : Any = null;
      var publicStaticField : Any = null;
      private val PRIVATE_CONST = 1;
      val PUBLIC_CONST = "string value";
      protected var protectedStaticField : Any = null;
      protected def protectedStaticFunction(){

    }
}


SCALA;
        $this->assertSame( $expected, $actual );
    }
}
