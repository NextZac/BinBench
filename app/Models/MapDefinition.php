<?php

namespace App\Models;

use App\Ecu\Maps\MapDef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable set of map definitions (e.g. an imported XDF), matched to files by software number.
 */
class MapDefinition extends Model
{
    protected $fillable = ['name', 'sw', 'family_id', 'source_filename', 'map_count', 'maps'];

    protected function casts(): array
    {
        return ['maps' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MapDef[]
     */
    public function mapDefs(): array
    {
        return array_map(fn (array $m): MapDef => MapDef::fromArray($m), $this->maps ?? []);
    }
}
