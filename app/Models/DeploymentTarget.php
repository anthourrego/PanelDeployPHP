<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentTarget extends Model
{
    protected $fillable = ['server_id', 'path', 'preserved_paths'];

    protected function casts(): array
    {
        return ['preserved_paths' => 'array'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
