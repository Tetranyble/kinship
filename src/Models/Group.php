<?php

namespace Tetranyble\Kinship\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tetranyble\Kinship\Concerns\IsGroup;
use Tetranyble\Kinship\Contracts\Group as GroupContract;
use Tetranyble\Kinship\Database\Factories\GroupFactory;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

/**
 * Conventional package-owned group model. Applications may replace this with
 * any Eloquent model implementing GroupContract; IsGroup supplies the standard
 * Kinship behavior without requiring inheritance from this class.
 */
class Group extends Model implements GroupContract
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    use IsGroup;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'label',
        'description',
        'is_system',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_system' => 'boolean',
    ];

    protected static function newFactory(): GroupFactory
    {
        return GroupFactory::new();
    }

    public function getTable(): string
    {
        return (string) config('kinship.tables.groups', parent::getTable());
    }

    public function getFillable(): array
    {
        $fillable = parent::getFillable();
        $fillable[] = app(WorkspaceConfiguration::class)->groupForeignKey();

        return array_values(array_unique($fillable));
    }
}
