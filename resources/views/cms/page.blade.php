@extends('layouts.app')

@section('title', e($page->meta_title ?: $page->title . ' | ' . app(\App\Settings\GeneralSettings::class)->site_name))
@section('meta_description', e($page->meta_description ?: ''))
@php($featuredImage = \App\Support\CmsContent::image($page->featured_image))
@if($featuredImage)
    @section('og_image', e(asset('storage/' . $featuredImage)))
@endif
@push('styles')
    @if($isPreview ?? false)<meta name="robots" content="noindex, nofollow, noarchive">@else<link rel="canonical" href="{{ $page->publicUrl() }}">@endif
    <style>
        .cms-content { color:#27272a; line-height:1.8; overflow-wrap:anywhere; }
        .cms-content h1,.cms-content h2,.cms-content h3,.cms-content h4 { color:#18181b; font-weight:700; line-height:1.3; margin:1.75em 0 .7em; }
        .cms-content h1 { font-size:2rem; } .cms-content h2 { font-size:1.65rem; } .cms-content h3 { font-size:1.3rem; } .cms-content h4 { font-size:1.1rem; }
        .cms-content > :first-child { margin-top:0; }
        .cms-content p,.cms-content ul,.cms-content ol,.cms-content blockquote,.cms-content pre,.cms-content figure { margin:0 0 1.2em; }
        .cms-content ul { list-style:disc; padding-left:1.5em; } .cms-content ol { list-style:decimal; padding-left:1.5em; }
        .cms-content li { margin:.3em 0; } .cms-content li > p { margin:0; }
        .cms-content a { color:inherit; text-decoration:underline; text-underline-offset:.2em; }
        .cms-content a:focus-visible { outline:2px solid currentColor; outline-offset:3px; }
        .cms-content blockquote { border-left:3px solid #d4d4d8; padding-left:1.25em; color:#52525b; }
        .cms-content img { max-width:100%; height:auto; border-radius:.75rem; margin:1.5em 0; }
        .cms-content table { display:block; max-width:100%; overflow-x:auto; border-collapse:collapse; margin:1.5em 0; }
        .cms-content th,.cms-content td { border:1px solid #d4d4d8; padding:.65em .9em; text-align:left; }
        .cms-content th { font-weight:700; background:#f4f4f5; }
        .cms-content pre { overflow-x:auto; background:#f4f4f5; padding:1em; border-radius:.5rem; }
        .cms-content hr { border-top:1px solid #d4d4d8; margin:2em 0; }
    </style>
@endpush

@section('content')
    <article class="mx-auto w-full max-w-4xl px-4 py-16 sm:px-6 lg:px-8">
        @if($isPreview ?? false)
            <aside role="status" style="padding:1rem;margin-bottom:2rem;border:1px solid #c7d2fe;background:#eef2ff;color:#312e81;border-radius:.75rem">Private preview · saved revision {{ $previewVersion }}. This view does not publish changes. Return to the editor to publish the saved draft.</aside>
        @endif
        @if($featuredImage)
            <img src="{{ asset('storage/' . $featuredImage) }}" alt="" class="mb-10 aspect-[2/1] w-full object-cover">
        @endif
        <h1 class="mb-8 text-4xl font-black uppercase tracking-tight text-zinc-950 sm:text-5xl">{{ $page->title }}</h1>
        <div class="cms-content">
            {!! \App\Support\CmsContent::sanitize($page->content) !!}
        </div>
    </article>
@endsection
