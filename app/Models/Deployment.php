<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deployment extends Model
{
    protected $fillable = ['project_id', 'user_id', 'ref', 'commit', 'scheduled_at', 'repository_url'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(DeploymentTarget::class);
    }
}
