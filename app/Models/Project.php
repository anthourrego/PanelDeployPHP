<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = ['name', 'gitea_url', 'owner', 'repository', 'gitea_token', 'preserved_paths', 'ssh_url', 'is_active'];

    protected $hidden = ['gitea_token'];

    protected function casts(): array
    {
        return ['gitea_token' => 'encrypted', 'is_active' => 'boolean', 'preserved_paths' => 'array'];
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class)->withPivot('path');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }
}
