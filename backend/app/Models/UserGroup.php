<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class UserGroup extends Model
{
    use Searchable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_system',
        'is_default',
    ];

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
        ];
    }

    /**
     * Get the name of the index for the model.
     */
    public function searchableAs(): string
    {
        return 'user_groups';
    }

    /**
     * Users belonging to this group.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_group_members', 'group_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Permissions assigned to this group.
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(GroupPermission::class, 'group_id');
    }

    /**
     * Check if this group has a given permission.
     */
    public function hasPermission(string $permission, ?string $resourceType = null, ?int $resourceId = null): bool
    {
        $query = $this->permissions()->where('permission', $permission);

        if ($resourceType !== null) {
            $query->where(function ($q) use ($resourceType, $resourceId) {
                $q->whereNull('resource_type')
                    ->orWhere(function ($q) use ($resourceType, $resourceId) {
                        $q->where('resource_type', $resourceType);
                        if ($resourceId !== null) {
                            $q->where(function ($q) use ($resourceId) {
                                $q->whereNull('resource_id')
                                    ->orWhere('resource_id', $resourceId);
                            });
                        }
                    });
            });
        }

        return $query->exists();
    }
}
