<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Permission extends Model
{
    protected $fillable = [
        'name',
        'guard_name',
        'description',
    ];

    public function roles(): Builder
    {
        return Role::query()
            ->where('guard_name', $this->guard_name)
            ->whereJsonContains('permission_codes', $this->name);
    }
}
