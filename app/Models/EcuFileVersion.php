<?php

namespace App\Models;

use App\Ecu\Bin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class EcuFileVersion extends Model
{
    protected $fillable = ['label', 'path', 'sha1', 'checksum_fixed', 'checksums', 'changed_maps'];

    protected function casts(): array
    {
        return [
            'checksum_fixed' => 'boolean',
            'checksums' => 'array',
            'changed_maps' => 'array',
        ];
    }

    /**
     * @return BelongsTo<EcuFile, $this>
     */
    public function ecuFile(): BelongsTo
    {
        return $this->belongsTo(EcuFile::class);
    }

    public function bin(): Bin
    {
        return new Bin(Storage::disk('local')->get($this->path));
    }
}
