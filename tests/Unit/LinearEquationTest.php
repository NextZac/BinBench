<?php

namespace Tests\Unit;

use App\Ecu\Xdf\LinearEquation;
use PHPUnit\Framework\TestCase;

class LinearEquationTest extends TestCase
{
    public function test_parses_common_tunerpro_equations(): void
    {
        $this->assertEqualsWithDelta(['factor' => 0.75, 'offset' => 0.0], LinearEquation::parse('X*0.75'), 1e-9);
        $this->assertEqualsWithDelta(['factor' => 0.1, 'offset' => -273.14], LinearEquation::parse('0.100000 * X+ -273.140000'), 1e-9);
        $this->assertEqualsWithDelta(['factor' => 0.75, 'offset' => -48.0], LinearEquation::parse('(X-64)*0.75'), 1e-9);
        $this->assertEqualsWithDelta(['factor' => 3.05176e-05, 'offset' => 0.0], LinearEquation::parse('X*3.05176e-05'), 1e-12);
        $this->assertEqualsWithDelta(['factor' => 0.5, 'offset' => 0.0], LinearEquation::parse('X/2'), 1e-9);
    }

    public function test_rejects_non_linear_equations(): void
    {
        $this->assertNull(LinearEquation::parse('X*X'));
        $this->assertNull(LinearEquation::parse('1/X'));
        $this->assertNull(LinearEquation::parse('sqrt(X)'));
    }
}
