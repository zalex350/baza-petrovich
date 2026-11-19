{{--
    SEO в <head> витрины (layouts/app): description, robots, canonical, Open Graph, коды подтверждения
    Search Console / Вебмастера, JSON-LD (App\Support\Seo — seo() в шаблонах и контроллерах).
    $fallbackTitle — @section('title') страницы.
--}}
@php
    $seo = seo();
    $metaTitle = $seo->fullTitle($fallbackTitle ?? null);
    $metaDescription = $seo->metaDescription();
    $canonical = $seo->canonicalUrl();
@endphp
<title>{{ $metaTitle }}</title>
@if($metaDescription)
    <meta name="description" content="{{ $metaDescription }}">
@endif
<meta name="robots" content="{{ $seo->robots() }}">
@if($seo->isIndexable())
    <link rel="canonical" href="{{ $canonical }}">
@endif
<meta property="og:site_name" content="{{ \App\Support\Seo::siteName() }}">
<meta property="og:locale" content="ru_RU">
<meta property="og:type" content="{{ $seo->type }}">
<meta property="og:title" content="{{ $seo->title ?: $metaTitle }}">
@if($metaDescription)
    <meta property="og:description" content="{{ $metaDescription }}">
@endif
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:image" content="{{ $seo->ogImage() }}">
<meta name="twitter:card" content="summary_large_image">
@if($code = \App\Models\Setting::value('google_site_verification'))
    <meta name="google-site-verification" content="{{ $code }}">
@endif
@if($code = \App\Models\Setting::value('yandex_verification'))
    <meta name="yandex-verification" content="{{ $code }}">
@endif
@foreach($seo->schemaItems() as $item)
    <script type="application/ld+json">{!! json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endforeach
