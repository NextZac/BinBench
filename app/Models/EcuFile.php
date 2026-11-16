<?php

namespace App\Models;

use App\Ecu\Analyzer;
use App\Ecu\Bin;
use App\Ecu\Maps\MapDef;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class EcuFile extends Model
{
    protected $fillable = [
        'name', 'original_filename', 'size', 'sha1', 'path', 'family_id', 'family', 'sw',
        'ident', 'maps', 'checksums', 'map_count', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'ident' => 'array',
            'maps' => 'array',
            'checksums' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<EcuFileVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(EcuFileVersion::class)->latest();
    }

    /**
     * Definitions that apply to this file: imported for it, or for another read with the same
     * software number. Newest first, so a newer import wins where two define the same address.
     *
     * @return Collection<int, MapDefinition>
     */
    public function definitions(?string $sw = null): Collection
    {
        $sw ??= $this->sw;

        return MapDefinition::where('user_id', $this->user_id)
            ->where(function (Builder $query) use ($sw): void {
                $query->where('ecu_file_id', $this->id);
                if ($sw) {
                    $query->orWhere('sw', $sw);
                }
            })
            ->latest()
            ->get();
    }

    public function bin(): Bin
    {
        return new Bin(Storage::disk('local')->get($this->path));
    }

    /**
     * Identify the file, find maps (plus the owner's stored definitions for the same software) and check checksums.
     */
    public function analyze(): self
    {
        $analyzer = new Analyzer;
        $bin = $this->bin();
        $sw = $analyzer->identify($bin)['ident']['sw'] ?? null;

        $known = [];
        foreach ($this->definitions($sw) as $definition) {
            array_push($known, ...$definition->mapDefs());
        }

        $result = $analyzer->analyze($bin, $known);

        $this->fill([
            'family_id' => $result['familyId'],
            'family' => trim(($result['ident']['family'] ?? '').' '.($result['ident']['variant'] ?? '')),
            'sw' => $result['ident']['sw'] ?? null,
            'ident' => $result['ident'],
            'maps' => array_map(fn (MapDef $m): array => $m->toArray(), $result['maps']),
            'checksums' => $result['checksums'],
            'map_count' => count($result['maps']),
        ])->save();

        return $this;
    }
}
