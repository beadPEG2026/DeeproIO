<?php
namespace App\Services\Content;

final class LocalizedPage
{
    public static function fields(object $page, string $locale): array
    {
        $allowed = ['zh-cn','zh-tw','ja','cs','de','es','fr','nl','pt','ro','it'];
        $result = [];
        foreach (['title','content','html_content'] as $field) {
            $text = in_array($locale, $allowed, true) ? $page->{$field.'_'.$locale} ?? null : null;
            // Simplified Chinese was missing from the original CMS schema. Reuse
            // the existing Chinese edition until an editor supplies an explicit one.
            if (!$text && $locale === 'zh-cn' && ($traditional = $page->{$field.'_zh-tw'} ?? null)) {
                $converter = class_exists(\Transliterator::class) ? \Transliterator::create('Traditional-Simplified') : null;
                $text = $converter ? $converter->transliterate($traditional) : $traditional;
            }
            $result[$field] = $text ?: $page->{$field};
        }
        if ($locale === 'zh-cn' && empty($page->{'title_zh-cn'})) {
            $result['title'] = ['about'=>'关于 Deepro','terms'=>'服务条款','privacy-gdpr'=>'隐私政策','disclosure'=>'风险披露声明'][$page->slug] ?? $result['title'];
        }
        return $result;
    }
}
