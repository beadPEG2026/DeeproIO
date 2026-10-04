<?php
namespace App\Support;

use Illuminate\Support\Facades\Validator;

final class AdminReportFilters
{
    public static function perPage(): int
    {
        $data = Validator::make(request()->only('per_page'), [
            'per_page' => 'sometimes|integer|in:10,50,100,500',
        ])->validate();
        return (int) ($data['per_page'] ?? 100);
    }

    public static function period(): ?array
    {
        $value = request()->input('period');
        if ($value === null || $value === [] || $value === ['', ''] || $value === [null, null]) return null;
        $data = Validator::make(['period' => $value], [
            'period' => 'required|array|size:2',
            'period.0' => 'required|date',
            'period.1' => 'required|date|after_or_equal:period.0',
        ])->validate();
        return $data['period'];
    }
}
