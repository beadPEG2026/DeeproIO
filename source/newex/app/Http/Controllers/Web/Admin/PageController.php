<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Page\PageFormRequest;
use App\Models\Page\Page;
use App\Repositories\Page\PageRepository;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use App\Services\Content\PagePublication;

class PageController extends Controller
{
    /**
     * @var PageRepository
     */
    protected $pageRepository;

    /**
     * PageController Constructor
     *
     * @param PageRepository $pageRepository
     */
    public function __construct(PageRepository $pageRepository)
    {
        $this->pageRepository = $pageRepository;
    }

    public function index()
    {
        $pages = $this->pageRepository->get();

        return Inertia::render('Admin/Pages/Index', [
            'pages' => $pages,
        ]);
    }

    /**
     * Create new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return Inertia::render('Admin/Pages/Form');
    }

    /**
     * Store new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(PageFormRequest $request)
    {
        $page=app(PagePublication::class)->save(null,$request->only($this->pageFields()),$request->input('mode'),null,$request->user()->id,$request->input('reason'));
        return Redirect::route('admin.pages.edit',$page->id);
    }

    /**
     * Edit resource.
     *
     * @param Page $page
     * @return \Inertia\Response
     */
    public function edit(Page $page)
    {
        $page = $this->pageRepository->getPageById($page->id);

        return Inertia::render('Admin/Pages/Form', [
            'isEdit' => true,
            'page' => $page,
            'publication'=>app(PagePublication::class)->editor($page),
        ]);
    }

    /**
     * Update resource.
     *
     * @param PageFormRequest $request
     * @param Page $page
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(PageFormRequest $request, Page $page)
    {
        app(PagePublication::class)->save($page,$request->only($this->pageFields()),$request->input('mode'),$request->input('revision'),$request->user()->id,$request->input('reason'));
        return Redirect::route('admin.pages.edit',$page->id);
    }

    /**
     * Destroy resource.
     *
     * @param Page $page
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Page $page)
    {
        app(PagePublication::class)->delete($page,request()->user()->id);

        return Redirect::route('admin.pages');
    }

    public function preview(PageFormRequest $request) {
        $page=new Page($request->only($this->pageFields()));
        return response()->json((new \App\Http\Resources\Page\Page($page))->resolve())->header('Cache-Control','private, no-store');
    }

    /**
     * Page form fields.
     *
     * @return array
     */
    protected function pageFields()
    {
        return [
            'seo_title',
            'seo_description',
            'seo_keywords',
            'status',
            'title',
            'slug',
            'content',
            'is_html',
            'html_content',

            'title_zh-cn', 'content_zh-cn', 'html_content_zh-cn',
            'title_zh-tw',
            'content_zh-tw',
            'html_content_zh-tw',

            'title_ja',
            'content_ja',
            'html_content_ja',

            'title_cs',
            'content_cs',
            'html_content_cs',

            'title_de',
            'content_de',
            'html_content_de',

            'title_es',
            'content_es',
            'html_content_es',

            'title_fr',
            'content_fr',
            'html_content_fr',

            'title_nl',
            'content_nl',
            'html_content_nl',

            'title_pt',
            'content_pt',
            'html_content_pt',

            'title_ro',
            'content_ro',
            'html_content_ro',

            'title_it',
            'content_it',
            'html_content_it',

            'seo_title',
            'seo_description',
            'seo_keywords',
            'status',
        ];
    }
}
