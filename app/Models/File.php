<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class File extends Model
{
    /** @use HasFactory<\Database\Factories\FileFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
        'expires_at',
        'deletion_reason',
        'scan_status',
        'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'scanned_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }
}
