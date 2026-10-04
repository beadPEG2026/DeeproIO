<?php

namespace App\Http\Resources\Page;

use Illuminate\Http\Resources\Json\JsonResource;

class Page extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $localized = \App\Services\Content\LocalizedPage::fields($this->resource, app()->getLocale());
        return [
            'title' => $localized['title'],
            'slug' => $this->slug,
            'content' => \App\Services\Content\SafeHtml::render($this->is_html ? $localized['html_content'] : $localized['content']),
        ];
    }
}
