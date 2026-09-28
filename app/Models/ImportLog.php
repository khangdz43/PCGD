<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportLog extends Model
{
    // chỉ có cột created_at ko có updated_at nên ko cần tìm cọt này
    // nhưng nó gây ảnh hường cột create_at sẽ không còn được coi là đối tượng DateTime khi lấy lên
    public const UPDATED_AT = null;
    protected $fillable = [
        'file_name',
        'uploaded_by',
        'total_rows',
        'success_rows',
        'error_rows',
        'status',
        'error_details',
    ];

    // error nó là dạng mảng 
    // create_at bị mất kiểu datetime khi lấy lên
    protected $casts = [
        'error_details' => 'array',
        'created_at' => 'datetime'

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
