<?php

namespace App\Repositories\Page;

use App\Models\Page\Page;
use Auth;

class PageRepository
{
    /**
     * @var Page
     */
    protected $page;

    /**
     * PageRepository constructor.
     *
     */
    public function __construct()
    {
        $this->page = new Page();
    }

    public function get() {

        $pages = Page::query();

        $pages->orderBy('id', 'desc');

        return $pages->paginate(50)->withQueryString();
    }

    public function getPageById($id) {
        return Page::find($id);
    }

    public function getPageBySlug($slug) {

        $page = Page::query();

        $page->whereSlug($slug);

        $page->active();

        return $page->first();
    }

    public function store($data) {

        $data['content'] = strip_tags($data['content'], '<p><b><strong><h1><h2><h3><ul><li><ol><br><img><table><tr><td><thead><th><tbody><tfoot><h4><span><a>');

        $page = $this->page->create($data);

        return $page->fresh();
    }

    public function update($id, $data) {

        $page = Page::find($id);
        $page->update($data);
        return $page->fresh();
    }

    public function delete($id) {

        $page = Page::find($id);
        $page->delete();

        return true;
    }
}
