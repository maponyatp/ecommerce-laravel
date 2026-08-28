<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ app(\App\Support\StoreBranding::class)->current()['name'] }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
@media only screen and (max-width: 600px) {
    .inner-body, .footer { width: 100% !important; }
    .content-cell { padding: 24px !important; }
    .wrapper { padding: 0 12px !important; }
}
</style>
{!! $head ?? '' !!}
</head>
<body>
<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr><td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}
<tr><td class="body" width="100%" style="border:0;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" style="border-radius:12px;border:1px solid #e4e4e7;">
<tr><td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}
{!! $subcopy ?? '' !!}
</td></tr>
</table>
</td></tr>
{!! $footer ?? '' !!}
</table>
</td></tr>
</table>
</body>
</html>
