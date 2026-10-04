<?php
namespace App\Services\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductPublication
{
    public static function fields(Request $request): array
    {
        $data = $request->validate([
            'publication_status' => ['required', 'in:draft,pending,published'],
            'publication_reference' => ['nullable', 'string', 'max:2000', 'required_if:publication_status,published'],
        ]);
        $data['publication_reference'] = trim($data['publication_reference'] ?? '');
        if ($data['publication_status'] === 'published') {
            $name = trim((string) ($request->input('display_name') ?? $request->input('name')));
            if (!$name || preg_match('/^(test|demo|sample|测试|演示)(?:[\s\d_-].*)?$/iu', $name) || mb_strlen($data['publication_reference']) < 20) {
                throw ValidationException::withMessages(['publication_reference' => __('Before publication, use a formal name and describe the reviewed content, data source and period (at least 20 characters).')]);
            }
        }
        $data['publication_reviewed_by'] = $data['publication_status'] === 'published' ? $request->user()->id : null;
        $data['publication_reviewed_at'] = $data['publication_status'] === 'published' ? now() : null;
        return $data;
    }

    public static function save(Request $request, callable $save, ?Model $existing = null): Model
    {
        $fields = self::fields($request);
        return DB::transaction(function () use ($request, $save, $fields, $existing) {
            $revision = 0;
            if ($existing) {
                $request->validate(['publication_revision' => 'required|integer|min:0']);
                $current = $existing->newQuery()->whereKey($existing->getKey())->lockForUpdate()->firstOrFail();
                if ((int)$current->publication_revision !== (int)$request->input('publication_revision')) {
                    throw ValidationException::withMessages(['publication_revision' => __('Product changed. Refresh before saving.')]);
                }
                $revision = (int)$current->publication_revision + 1;
            }
            $model = $save($fields);
            $model->forceFill(['publication_revision' => $revision])->save();
            DB::table('product_publication_events')->insert([
                'product_type' => $model->getTable(), 'product_id' => $model->getKey(), 'actor_id' => $request->user()->id,
                'status' => $fields['publication_status'], 'reference' => $fields['publication_reference'],
                'content_digest' => hash('sha256', json_encode($model->getAttributes())), 'created_at' => now(),
            ]);
            return $model;
        });
    }
}
