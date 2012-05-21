<?php

require_once __DIR__ . '/../lib/Converter.php';

class PHPFunctionsTest extends PHPUnit_Framework_TestCase{
    /**
     * @test
     */
    public function echoTest(){
        $converter = new Converter();
        $code = <<<CODE
<?php
echo "Hello World!";

CODE;

        $expected = <<<CODE
php.echo("Hello World!");

CODE;
        $actual = $converter->convert( $code );
        $this->assertSame( $expected, $actual );
    }
}
