<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Services\PagePublishingService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CmsPageController extends Controller
{
    public function preview(Page $page, int $version): Response
    {
        Gate::authorize('view', $page);
        $data = $version === 0 && $page->editor_version === 0 ? $page->only(PagePublishingService::FIELDS)
            : $page->revisions()->where('version', $version)->firstOrFail()->data;
        $previewPage = new Page($data);

        return response()->view('cms.page', ['page' => $previewPage, 'isPreview' => true, 'previewVersion' => $version]);
    }

    /** Render only pages explicitly published by an administrator. */
    public function show(string $slug): View|Response
    {
        $page = Page::query()
            ->published()
            ->where('slug', $slug)
            ->first();

        if ($page) {
            return view('cms.page', compact('page'));
        }

        // Keep the original storefront pages live until CMS replacements are
        // published. Once a matching CMS page is published it takes priority.
        if (in_array($slug, ['about', 'contact', 'shop'], true)) {
            return response()->view($slug);
        }

        abort(404);
    }
}
