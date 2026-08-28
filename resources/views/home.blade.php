@extends('layouts.app')

@php($settings = app(\App\Settings\GeneralSettings::class))

@section('title', $settings->site_name . ' | ' . $settings->hero_title)
@section('meta_description', $settings->seo_description)

@section('content')
    @foreach ($settings->homepage_sections as $section)
        @continue(empty($section['enabled']))
        @continue(! in_array($section['section'] ?? null, ['hero', 'categories', 'products', 'promotion'], true))

        @includeIf('home.sections.' . $section['section'])
    @endforeach
@endsection
