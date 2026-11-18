<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Server extends Model
{
    protected $fillable = ['name'];

    protected $hidden = ['agent_token_hash'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'active_target_id' => 'integer'];
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withPivot('path');
    }
}
