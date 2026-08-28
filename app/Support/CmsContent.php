<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class CmsContent
{
    public static function sanitize(?string $html): string
    {
        return (new HtmlSanitizer((new HtmlSanitizerConfig)->allowSafeElements()
            ->allowRelativeLinks()->allowRelativeMedias()->withMaxInputLength(100000)))->sanitize($html ?? '');
    }

    public static function image(?string $path): ?string
    {
        return $path && preg_match('~^cms/pages/[a-zA-Z0-9_./-]+\.(png|jpe?g|gif|webp)$~i', $path)
            && ! str_contains($path, '..') ? $path : null;
    }
}
