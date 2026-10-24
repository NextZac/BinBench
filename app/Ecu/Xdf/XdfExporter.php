<?php

namespace App\Ecu\Xdf;

use App\Ecu\Bin;
use App\Ecu\Maps\Axis;
use App\Ecu\Maps\MapDef;

/**
 * Writes MapDefs as a TunerPro XDF (format 1.60).
 */
class XdfExporter
{
    /**
     * @param  MapDef[]  $maps
     */
    public function export(array $maps, string $title, int $binSize, string $description = ''): string
    {
        $categories = array_values(array_unique(array_map(fn (MapDef $m): string => $m->category, $maps)));
        $catIndex = array_flip($categories);

        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $out .= '<!--Written by BinBench-->'."\n";
        $out .= '<XDFFORMAT version="1.60">';
        $out .= '<XDFHEADER><flags>0x1</flags><fileversion>1.0</fileversion>';
        $out .= '<deftitle>'.$this->esc($title).'</deftitle>';
        $out .= '<description>'.$this->esc($description).'</description>';
        $out .= '<author>BinBench</author><BASEOFFSET offset="0" subtract="0" />';
        $out .= '<DEFAULTS datasizeinbits="16" sigdigits="2" outputtype="1" signed="0" lsbfirst="1" float="0" />';
        $out .= sprintf('<REGION type="0xFFFFFFFF" startaddress="0x0" size="0x%X" regionflags="0x0" name="Binary File" desc="" />', $binSize);
        foreach ($categories as $i => $name) {
            $out .= sprintf('<CATEGORY index="0x%X" name="%s" />', $i, $this->esc($name));
        }
        $out .= "</XDFHEADER>\n";

        $uid = 0x100;
        foreach ($maps as $map) {
            $cat = $catIndex[$map->category] + 1;
            if ($map->kind() === 'value') {
                $out .= sprintf('<XDFCONSTANT uniqueid="0x%X"><title>%s</title><description>%s</description><CATEGORYMEM index="0" category="%d" />',
                    $uid++, $this->esc($map->name), $this->esc($map->description), $cat);
                $out .= $this->embedded($map->dtype, $map->be, $map->address);
                $out .= sprintf('<units>%s</units><decimalpl>%d</decimalpl><datatype>0</datatype><unittype>0</unittype>', $this->esc($map->unit), $map->decimals);
                $out .= $this->math($map->factor, $map->offset)."</XDFCONSTANT>\n";

                continue;
            }

            $out .= sprintf('<XDFTABLE uniqueid="0x%X" flags="0x0"><title>%s</title><description>%s</description><CATEGORYMEM index="0" category="%d" />',
                $uid++, $this->esc($map->name), $this->esc($map->description), $cat);
            $out .= $this->axis('x', $map->x, $map->cols);
            $out .= $this->axis('y', $map->y, $map->rows);
            $out .= '<XDFAXIS id="z">';
            $out .= $this->embedded($map->dtype, $map->be, $map->address, $map->rows, $map->cols, $map->colMajor);
            $out .= sprintf('<units>%s</units><decimalpl>%d</decimalpl><outputtype>1</outputtype>', $this->esc($map->unit), $map->decimals);
            $out .= $this->math($map->factor, $map->offset)."</XDFAXIS></XDFTABLE>\n";
        }

        return $out.'</XDFFORMAT>';
    }

    private function axis(string $id, ?Axis $axis, int $count): string
    {
        $out = sprintf('<XDFAXIS id="%s" uniqueid="0x0">', $id);
        if ($axis && $axis->address !== null) {
            $out .= $this->embedded($axis->dtype, $axis->be, $axis->address);
            $out .= sprintf('<units>%s</units><indexcount>%d</indexcount><decimalpl>%d</decimalpl><datatype>0</datatype><unittype>0</unittype><DALINK index="0" />',
                $this->esc($axis->unit), $count, $axis->decimals);
            $out .= $this->math($axis->factor, $axis->offset);
        } else {
            $out .= sprintf('<EMBEDDEDDATA mmedelementsizebits="16" mmedmajorstridebits="-32" mmedminorstridebits="0" /><indexcount>%d</indexcount><datatype>0</datatype><unittype>0</unittype><DALINK index="0" />', $count);
            $values = $axis?->values ?? range(0, $count - 1);
            foreach (array_values($values) as $i => $v) {
                $out .= sprintf('<LABEL index="%d" value="%s" />', $i, $v);
            }
            $out .= '<MATH equation="X"><VAR id="X" /></MATH>';
        }

        return $out.'</XDFAXIS>';
    }

    private function embedded(string $dtype, bool $be, int $address, int $rows = 0, int $cols = 0, bool $colMajor = false): string
    {
        $bits = Bin::typeSize($dtype) * 8;
        $flags = (str_starts_with($dtype, 's') ? 0x01 : 0) | (! $be && $bits > 8 ? 0x02 : 0) | ($colMajor ? 0x04 : 0) | ($dtype === 'f32' ? 0x10000 : 0);
        $dims = $rows ? sprintf(' mmedrowcount="%d" mmedcolcount="%d"', $rows, $cols) : '';

        return sprintf('<EMBEDDEDDATA mmedtypeflags="0x%02X" mmedaddress="0x%X" mmedelementsizebits="%d"%s mmedmajorstridebits="0" mmedminorstridebits="0" />',
            $flags, $address, $bits, $dims);
    }

    private function math(float $factor, float $offset): string
    {
        return sprintf('<MATH equation="%s"><VAR id="X" /></MATH>', $this->esc(LinearEquation::format($factor, $offset)));
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
