{{--
    Счётчики (админка «Настройки» → Аналитика; App\Support\Analytics::config()). layouts/app:
    position = head — Consent Mode v2 по умолчанию и Google (Tag Manager или gtag.js: GA4 + Ads);
    position = body — noscript GTM и баннер cookie. Яндекс Метрику (с Вебвизором) и Clarity грузит
    resources/js/analytics.js — сразу или после согласия (режим «Согласие»), он же отправляет события.
--}}
@php($analytics = \App\Support\Analytics::config())

@if($analytics && $position === 'head')
    <script>
        window.__analytics = @json($analytics);
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        (function (a) {
            var saved = (document.cookie.match(/(?:^|; ){{ \App\Support\Analytics::CONSENT_COOKIE }}=(granted|denied)/) || [])[1];
            var state = a.consent === 'required' ? (saved === 'granted' ? 'granted' : 'denied') : (saved === 'denied' ? 'denied' : 'granted');
            a.granted = state === 'granted';
            gtag('consent', 'default', {ad_storage: state, ad_user_data: state, ad_personalization: state, analytics_storage: state, functionality_storage: 'granted', security_storage: 'granted', wait_for_update: 500});
            gtag('set', 'ads_data_redaction', state === 'denied');
            gtag('set', 'url_passthrough', true);
        })(window.__analytics);
    </script>
    @if($analytics['gtm'])
        <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer',@json($analytics['gtm']));</script>
    @elseif($analytics['ga4'] || $analytics['ads'])
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $analytics['ga4'] ?: $analytics['ads'] }}"></script>
        <script>
            gtag('js', new Date());
            @if($analytics['ga4']) gtag('config', @json($analytics['ga4'])); @endif
            @if($analytics['ads']) gtag('config', @json($analytics['ads'])); @endif
        </script>
    @endif
@endif

@if($analytics && $position === 'body')
    @if($analytics['gtm'])
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $analytics['gtm'] }}" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    @endif
    @if($analytics['consent'] !== 'off')
        <x-cookie-banner :mode="$analytics['consent']" />
    @endif
@endif
