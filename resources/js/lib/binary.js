// Typed access to a dump held in a Uint8Array, plus map/axis decoding and encoding.

export const typeSize = (t) => ({ u8: 1, s8: 1, u16: 2, s16: 2 })[t] ?? 4;

const ranges = {
    u8: [0, 0xff],
    s8: [-0x80, 0x7f],
    u16: [0, 0xffff],
    s16: [-0x8000, 0x7fff],
    u32: [0, 0xffffffff],
    s32: [-0x80000000, 0x7fffffff],
};

export function readRaw(buf, off, type, be) {
    const dv = new DataView(buf.buffer, buf.byteOffset, buf.byteLength);
    if (off < 0 || off + typeSize(type) > buf.length) return NaN;
    const le = !be;
    switch (type) {
        case 'u8': return dv.getUint8(off);
        case 's8': return dv.getInt8(off);
        case 'u16': return dv.getUint16(off, le);
        case 's16': return dv.getInt16(off, le);
        case 'u32': return dv.getUint32(off, le);
        case 's32': return dv.getInt32(off, le);
        case 'f32': return dv.getFloat32(off, le);
        default: return NaN;
    }
}

export function writeRaw(buf, off, type, be, raw) {
    const dv = new DataView(buf.buffer, buf.byteOffset, buf.byteLength);
    const le = !be;
    if (type === 'f32') {
        dv.setFloat32(off, raw, le);
        return;
    }
    const [min, max] = ranges[type];
    const v = Math.min(max, Math.max(min, Math.round(raw)));
    switch (type) {
        case 'u8': dv.setUint8(off, v); break;
        case 's8': dv.setInt8(off, v); break;
        case 'u16': dv.setUint16(off, v, le); break;
        case 's16': dv.setInt16(off, v, le); break;
        case 'u32': dv.setUint32(off, v, le); break;
        case 's32': dv.setInt32(off, v, le); break;
    }
}

export const toPhys = (raw, factor, offset) => raw * factor + offset;
export const toRaw = (phys, factor, offset) => (phys - offset) / (factor || 1);

/** Byte offset of cell (r, c) of a map. */
export function cellOffset(map, r, c) {
    const idx = map.colMajor ? c * map.rows + r : r * map.cols + c;
    return map.address + idx * typeSize(map.dtype);
}

export function readCell(buf, map, r, c) {
    return toPhys(readRaw(buf, cellOffset(map, r, c), map.dtype, map.be), map.factor, map.offset);
}

/** Physical values as rows x cols. */
export function readGrid(buf, map) {
    const out = [];
    for (let r = 0; r < map.rows; r++) {
        const row = [];
        for (let c = 0; c < map.cols; c++) row.push(readCell(buf, map, r, c));
        out.push(row);
    }
    return out;
}

/** Write a physical value; returns the value actually stored after rounding/clamping. */
export function writeCell(buf, map, r, c, phys) {
    const off = cellOffset(map, r, c);
    writeRaw(buf, off, map.dtype, map.be, toRaw(phys, map.factor, map.offset));
    return readCell(buf, map, r, c);
}

export function readAxis(buf, axis, count) {
    if (!axis) return Array.from({ length: count }, (_, i) => i);
    if (axis.values) return axis.values.slice(0, count);
    const size = typeSize(axis.dtype);
    return Array.from({ length: count }, (_, i) => toPhys(readRaw(buf, axis.address + i * size, axis.dtype, axis.be), axis.factor, axis.offset));
}

export function mapRange(map) {
    return [map.address, map.address + map.rows * map.cols * typeSize(map.dtype)];
}

export function bytesDiffer(a, b, start, end) {
    for (let i = start; i < end; i++) if (a[i] !== b[i]) return true;
    return false;
}

export function countDiffBytes(a, b) {
    let n = 0;
    for (let i = 0; i < a.length; i++) if (a[i] !== b[i]) n++;
    return n;
}

export const hex = (n, pad = 5) => '0x' + Number(n).toString(16).toUpperCase().padStart(pad, '0');

export function fmt(v, decimals = 2) {
    if (!Number.isFinite(v)) return '—';
    return v.toFixed(Math.max(0, Math.min(decimals, 6)));
}
