<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    // cấu hình khóa chính cho bảng
    // mặc định nó coi id là khóa chính
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';


    // 1 tỉnh nhiều xã
    // mặc định thì là tìm province_id bên bảng communce và id bên bảng này
    public function communes(): HasMany{
        return $this->hasMany(Commune::class, 'province_code', 'code');
    }

    public function households(): HasMany {
        return $this->hasMany(Household::class, 'province_code', 'code');   
    }

    public function schools(): HasMany {
        return $this->hasMany(School::class, 'province_code', 'code');
    }





}
