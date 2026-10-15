<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $status
 * @property string $file_name
 * @property int $success_rows
 * @property int $error_rows
 * @property string|null $file_path
 * @property \Illuminate\Support\Carbon|null $notification_read_at
 */
class ImportLog extends Model
{
    // chỉ có cột created_at ko có updated_at nên ko cần tìm cọt này
    // nhưng nó gây ảnh hường cột create_at sẽ không còn được coi là đối tượng DateTime khi lấy lên
    public const UPDATED_AT = null;

    protected $fillable = [
        'file_name',
        'user_id',
        'file_path',
        'error_file_path',
        'context',
        'notification_read_at',
        'uploaded_by',
        'total_rows',
        'processed_rows',
        'success_rows',
        'error_rows',
        'status',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
        'notification_read_at' => 'datetime',
    ];

    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }

    public function persons(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    public function educations(): HasMany
    {
        return $this->hasMany(PersonEducation::class);
    }
}
