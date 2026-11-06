<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class SchoolYear extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'start_time',
        'end_time',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'date',
            'end_time' => 'date',
            'status' => 'boolean',
        ];
    }

    public static function findByLabel(string $label): ?self
    {
        $years = self::yearsFromLabel($label);
        if ($years === null) {
            return null;
        }

        return static::query()
            ->whereYear('start_time', $years[0])
            ->whereYear('end_time', $years[1])
            ->first();
    }

    public static function resolveOrCreateFromLabel(string $label): self
    {
        $years = self::yearsFromLabel($label);
        if ($years === null) {
            throw new InvalidArgumentException("Năm học '{$label}' không đúng định dạng.");
        }

        $schoolYear = self::findByLabel($label);
        if ($schoolYear !== null) {
            return $schoolYear;
        }

        $startTime = Carbon::create($years[0], 8, 1)->toDateString();
        $endTime = Carbon::create($years[1], 7, 31)->toDateString();

        return static::query()->create([
            'start_time' => $startTime,
            'end_time' => $endTime,
            'status' => now()->toDateString() >= $startTime && now()->toDateString() <= $endTime,
        ]);
    }

    public function label(): string
    {
        return $this->start_time->format('Y') . '-' . $this->end_time->format('Y');
    }

    public function personEducations(): HasMany
    {
        return $this->hasMany(PersonEducation::class, 'school_year_id');
    }

    /**
     * @return array{int, int}|null
     */
    private static function yearsFromLabel(string $label): ?array
    {
        if (! preg_match('/^(\d{4})-(\d{4})$/', $label, $matches)) {
            return null;
        }

        $startYear = (int) $matches[1];
        $endYear = (int) $matches[2];

        return $endYear === $startYear + 1
            ? [$startYear, $endYear]
            : null;
    }
}
