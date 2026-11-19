<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected $hidden = ['agent_token_hash'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'is_active' => 'boolean', 'active_target_id' => 'integer'];
    }

    /**
     * Issues a new agent token and returns it in plain text, or null if a job became active meanwhile.
     */
    public function rotateAgentToken(): ?string
    {
        $secret = bin2hex(random_bytes(32));
        $query = static::whereKey($this->id)->whereNull('active_target_id');
        $this->agent_token_hash
            ? $query->where('agent_token_hash', $this->agent_token_hash)
            : $query->whereNull('agent_token_hash');

        return $query->update(['agent_token_hash' => hash('sha256', $secret)]) === 1 ? $this->id.'.'.$secret : null;
    }

    public function targets(): HasMany
    {
        return $this->hasMany(DeploymentTarget::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withPivot('path');
    }
}
