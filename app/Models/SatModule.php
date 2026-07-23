<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A SAT office (módulo) where an appointment can be formed in the virtual queue.
 *
 * The catalog comes from the SAT itself; `sat_id` is the id the bot sends as tcModuloId
 * when forming. Only offices with supports_virtual_queue can be used by the bot.
 */
class SatModule extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sat_id',
        'entidad',
        'name',
        'address',
        'latitude',
        'longitude',
        'supports_virtual_queue',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sat_id' => 'integer',
            'entidad' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'supports_virtual_queue' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Options for a select input: SAT module id => office name.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return self::query()
            ->where('is_active', true)
            ->where('supports_virtual_queue', true)
            ->orderBy('name')
            ->pluck('name', 'sat_id')
            ->all();
    }
}
