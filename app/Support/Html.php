<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Очистка HTML из редактора (Trix) перед выводом на сайте: остаются только разметка текста
 * (абзацы, заголовки, списки, жирный/курсив, цитата, ссылки, таблицы, картинки), атрибуты — только href
 * у ссылок (http, https, mailto, tel или адрес сайта), src и alt у картинок (https или адрес сайта).
 * Скрипты, стили, обработчики событий удаляются.
 */
class Html
{
    private const TAGS = ['p', 'div', 'br', 'strong', 'b', 'em', 'i', 'u', 'del', 's', 'a', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'blockquote', 'pre',
        'img', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr', 'th', 'td'];

    /** Удаляются вместе с содержимым */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math', 'form'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('root');

        if (! $root) {
            return '';
        }

        self::walk($root);

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);

                continue;
            }

            self::walk($child);

            if (! in_array($tag, self::TAGS, true)) {
                // Неизвестный тег — остаётся только его содержимое
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            $href = $tag === 'a' ? trim($child->getAttribute('href')) : null;
            $src = $tag === 'img' ? trim($child->getAttribute('src')) : null;
            $alt = $tag === 'img' ? trim($child->getAttribute('alt')) : null;

            foreach (iterator_to_array($child->attributes) as $attribute) {
                $child->removeAttribute($attribute->nodeName);
            }

            // Картинка — только с адресом https:// или сайта; без него удаляется
            if ($tag === 'img') {
                if (! preg_match('#^(https://|/(?!/))#i', (string) $src)) {
                    $node->removeChild($child);

                    continue;
                }

                $child->setAttribute('src', $src);
                $child->setAttribute('alt', (string) $alt);
                $child->setAttribute('loading', 'lazy');

                continue;
            }

            if ($href !== null && self::safeUrl($href)) {
                $child->setAttribute('href', $href);

                if (preg_match('#^https?://#i', $href)) {
                    $child->setAttribute('rel', 'noopener');
                }
            }
        }
    }

    private static function safeUrl(string $url): bool
    {
        return (bool) preg_match('#^(https?://|mailto:|tel:|/(?!/)|\#)#i', $url);
    }
}
