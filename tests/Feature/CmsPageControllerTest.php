<?php

namespace Tests\Feature;

use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CmsPageControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_published_page_is_rendered_by_its_slug(): void
    {
        Page::create([
            'title' => 'Delivery information',
            'slug' => 'delivery-information',
            'content' => '<p>Delivered with care.</p>',
            'status' => Page::STATUS_PUBLISHED,
        ]);

        $this->get('/delivery-information')
            ->assertOk()
            ->assertSee('Delivery information')
            ->assertSee('Delivered with care');
    }

    public function test_a_draft_page_is_not_publicly_available(): void
    {
        Page::create([
            'title' => 'Draft page',
            'slug' => 'draft-page',
            'content' => '<p>Not ready.</p>',
            'status' => Page::STATUS_DRAFT,
        ]);

        $this->get('/draft-page')->assertNotFound();
    }
}
