<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One entry of the append-only audit log. Entries cannot be changed or deleted through the model.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'ecu_file_id', 'action', 'subject', 'meta', 'ip_address'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit log entries are append-only.'));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function record(string $action, ?EcuFile $file = null, array $meta = [], ?User $user = null): self
    {
        $request = request();

        return self::create([
            'user_id' => $user?->id ?? $request->user()?->id ?? $file?->user_id,
            'ecu_file_id' => $file?->id,
            'action' => $action,
            'subject' => $file?->name,
            'meta' => $meta ?: null,
            'ip_address' => $request->ip(),
        ]);
    }
}
