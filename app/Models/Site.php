<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'type',
        'name',
        'code',
        'domain_name',
        'db_name',
        'google_sheet',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
