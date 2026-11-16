<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EcuFileVersion extends Model
{
    protected $fillable = ['label', 'path', 'sha1', 'checksum_fixed', 'export_state', 'unverified_reason', 'checksums', 'changed_maps', 'changes'];

    protected function casts(): array
    {
        return [
            'checksum_fixed' => 'boolean',
            'checksums' => 'array',
            'changed_maps' => 'array',
            'changes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<EcuFile, $this>
     */
    public function ecuFile(): BelongsTo
    {
        return $this->belongsTo(EcuFile::class);
    }
}
