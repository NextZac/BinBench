<?php

namespace Tests\Unit;

use App\Ecu\Bin;
use App\Ecu\Dtc\DtcCatalog;
use App\Ecu\Dtc\DtcService;
use App\Ecu\Dtc\DtcTable;
use App\Ecu\Dtc\FaultPath;
use PHPUnit\Framework\TestCase;

class DtcServiceTest extends TestCase
{
    private function table(): DtcTable
    {
        return new DtcTable([
            new FaultPath(0, [['slot' => 'min', 'address' => 0x10, 'code' => 0x0325], ['slot' => 'max', 'address' => 0x12, 'code' => 0x0325]],
                [['key' => 'class', 'label' => 'Error class', 'address' => 0x40, 'size' => 1, 'value' => 3, 'offValue' => 0]]),
            new FaultPath(1, [['slot' => 'min', 'address' => 0x14, 'code' => 0x0420]],
                [['key' => 'class', 'label' => 'Error class', 'address' => 0x41, 'size' => 1, 'value' => 3, 'offValue' => 0]]),
        ]);
    }

    public function test_formats_and_describes_p_codes(): void
    {
        $this->assertSame('P0301', DtcCatalog::format(0x0301));
        $this->assertSame('P2A00', DtcCatalog::format(0x2A00));

        $knock = DtcCatalog::describe(0x0325);
        $this->assertSame('Knock sensor 1 circuit', $knock['name']);
        $this->assertSame('Ignition, misfire and engine position', $knock['system']);
        $this->assertFalse($knock['emission']);
    }

    public function test_flags_emissions_monitoring_codes(): void
    {
        foreach ([0x0420, 0x0401, 0x0136, 0x0171, 0x0301, 0x0441, 0x2002, 0x0411] as $code) {
            $this->assertTrue(DtcCatalog::isEmission($code), DtcCatalog::format($code));
        }
        foreach ([0x0101, 0x0234, 0x0325, 0x0601, 0x0571, 0x0480] as $code) {
            $this->assertFalse(DtcCatalog::isEmission($code), DtcCatalog::format($code));
        }
    }

    public function test_marks_emissions_paths_as_protected(): void
    {
        $paths = (new DtcService)->describe($this->table())['paths'];

        $this->assertFalse($paths[0]['protected']);
        $this->assertTrue($paths[1]['protected']);
        $this->assertSame('P0325', $paths[0]['codes'][0]['code']);
        $this->assertSame(0x0325, $paths[0]['codes'][0]['value']);
    }

    public function test_detects_changes_to_protected_paths_only(): void
    {
        $original = new Bin(str_repeat("\0", 0x80));
        $service = new DtcService;

        $knockOff = $original->withPatch(0x10, "\0\0\0\0")->withPatch(0x40, "\0");
        $this->assertSame([], $service->protectedChanges($original, $knockOff, $this->table()));

        $catOff = $original->withPatch(0x41, "\x07");
        $this->assertSame(['P0420'], $service->protectedChanges($original, $catOff, $this->table()));
    }

    public function test_decodes_the_sae_letter_and_ignores_unused_slots(): void
    {
        $this->assertSame('U0120', DtcCatalog::format(0xC120));
        $this->assertSame('C0001', DtcCatalog::format(0x4001));
        $this->assertTrue(DtcCatalog::isUnused(0xFFFF));
        $this->assertNull(DtcCatalog::emissionReason(0xFFFF));
        $this->assertSame([0x0325], (new FaultPath(0, [['slot' => 'a', 'address' => 0, 'code' => 0xFFFF], ['slot' => 'b', 'address' => 2, 'code' => 0x0325]]))->pcodes());
    }

    public function test_vag_manufacturer_ranges_only_apply_to_vag_software(): void
    {
        $railPressure = new FaultPath(8, [['slot' => 'spc', 'address' => 0, 'code' => 0x1190]]);
        $service = new DtcService;

        $this->assertNotNull($service->protectionReason($railPressure, 'me7'));
        $this->assertNull($service->protectionReason($railPressure, 'edc15'));
    }

    public function test_protects_emissions_paths_by_name_when_the_code_is_manufacturer_specific(): void
    {
        $nox = new FaultPath(37, [['slot' => 'spc', 'address' => 0, 'code' => 0x1480]], name: 'NOx catalyst fuel dosing (NOX)');
        $fan = new FaultPath(30, [['slot' => 'spc', 'address' => 2, 'code' => 0x0480]], name: 'Cooling fan (MLS)');

        $this->assertSame('Emissions system (NOx)', (new DtcService)->protectionReason($nox, 'edc15'));
        $this->assertNull((new DtcService)->protectionReason($fan, 'edc15'));
    }

    public function test_reports_unavailable_without_a_table(): void
    {
        $this->assertFalse((new DtcService)->describe(null)['available']);
        $this->assertNull((new DtcService)->table(new Bin(str_repeat("\xFF", 1024)), 'generic'));
    }
}
