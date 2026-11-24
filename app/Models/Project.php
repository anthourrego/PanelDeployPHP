<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'gitea_url', 'owner', 'repository', 'gitea_token', 'preserved_paths', 'ssh_url', 'is_active'];

    protected $hidden = ['gitea_token'];

    protected function casts(): array
    {
        return [
            'gitea_token' => 'encrypted',
            'is_active' => 'boolean',
            'latest_tag_synced_at' => 'datetime',
            'preserved_paths' => 'array',
        ];
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class)->withPivot('path');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function latestDeployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'latest_deployment_id');
    }
}
