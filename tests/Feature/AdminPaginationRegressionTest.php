<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class AdminPaginationRegressionTest extends TestCase
{
    public function test_query_driven_pagination_uses_the_real_page_after_an_action_rerender(): void
    {
        $paginator = new LengthAwarePaginator(range(1, 10), 25, 10, 1, [
            'path' => 'https://store.example.test/livewire/update', 'pageName' => 'history_page',
            'query' => ['search' => 'Rose & orchid', 'status' => 'low'],
        ]);
        $html = (string) $paginator->links('filament.admin.partials.pagination', [
            'url' => 'https://store.example.test/admin/inventory', 'context' => ['product' => 15, 'variant' => 3],
        ]);
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $next = (new \DOMXPath($document))->query('//a[@rel="next"]')->item(0)?->getAttribute('href');
        $this->assertNotEmpty($next);
        $this->assertSame('/admin/inventory', parse_url($next, PHP_URL_PATH));
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame(['search' => 'Rose & orchid', 'status' => 'low', 'product' => '15', 'variant' => '3', 'history_page' => '2'], $query);
        $this->assertStringNotContainsString('wire:click', $html);
        $this->assertStringNotContainsString('/livewire/update', $html);
    }

    public function test_query_driven_admin_views_explicitly_select_server_navigation(): void
    {
        foreach (['customer-directory', 'inventory', 'product-variant-drafts', 'returns', 'cms-revisions', 'refunds', 'staff-security'] as $view) {
            $source = file_get_contents(resource_path('views/filament/admin/pages/'.$view.'.blade.php'));
            $this->assertStringNotContainsString('->links()', $source, $view);
            $this->assertStringContainsString('filament.admin.partials.pagination', $source, $view);
        }
    }
}
