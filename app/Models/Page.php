<?php

namespace App\Models;

use App\Services\PagePublishingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasFactory;

    const STATUS_DRAFT = 'draft';

    const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'title',
        'slug',
        'content',
        'meta_title',
        'meta_description',
        'featured_image',
        'parent_page_id',
        'status',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected $casts = ['draft_data' => 'array', 'editor_version' => 'integer', 'published_version' => 'integer', 'published_at' => 'datetime'];

    protected $hidden = ['draft_data', 'editor_version'];

    public function revisions()
    {
        return $this->hasMany(PageRevision::class);
    }

    public function publicUrl(): string
    {
        return route('cms.pages.show', ['slug' => $this->slug]);
    }

    public function publicationLabel(): string
    {
        if (! $this->isPublished()) {
            return 'Draft — not live';
        }

        return $this->draft_data !== null && $this->draft_data !== $this->only(PagePublishingService::FIELDS)
            ? 'Live · draft changes' : 'Live';
    }

    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_PUBLISHED => 'Published',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopePublished(Builder $query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function isPublished()
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isDraft()
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
