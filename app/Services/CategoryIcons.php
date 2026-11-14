<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use Illuminate\Support\HtmlString;

/**
 * Иконки категорий. Значение categories.icon:
 *  - «material:имя» — Material Symbols (Outlined, weight 300) из resources/icons/material/ — только
 *    иконки из списка resources/icons/material.json (копирует `npm run icons` из npm-пакета);
 *  - «custom:имя» — свои SVG 24×24 (контур 1.5) из resources/icons/custom/, слова — custom.json;
 *  - «upload» — SVG, загруженная в админке: очищенная разметка в categories.icon_svg (sanitize()).
 * Выводятся inline (render()): цвет — currentColor, размер — классом; своих цветов нет.
 * Неизвестный ключ или пусто — null: категория показывается без иконки.
 */
class CategoryIcons
{
    public const UPLOAD = 'upload';

    /** Своя SVG: не больше, байт */
    public const MAX_UPLOAD = 50 * 1024;

    /** Разрешённые элементы загруженной SVG (без script, style, foreignObject, image, use, a …) */
    private const ELEMENTS = ['svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'title'];

    /** Разрешённые атрибуты; fill / stroke — только none или currentColor */
    private const ATTRIBUTES = [
        'viewbox', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'width', 'height', 'x1', 'y1', 'x2', 'y2',
        'points', 'transform', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
        'stroke-miterlimit', 'fill-rule', 'clip-rule', 'opacity', 'fill-opacity', 'stroke-opacity',
    ];

    /** @var array<string, array{key: string, set: string, name: string, words: string, svg: string}>|null */
    private static ?array $library = null;

    /**
     * Все иконки для выбора: ключ => [set, name, words (слова для поиска), svg (исходник)].
     *
     * @return array<string, array{key: string, set: string, name: string, words: string, svg: string}>
     */
    public function library(): array
    {
        if (self::$library !== null) {
            return self::$library;
        }

        $library = [];

        foreach (['material', 'custom'] as $set) {
            $words = json_decode((string) @file_get_contents(resource_path("icons/{$set}.json")), true) ?: [];

            foreach ($words as $name => $text) {
                $path = resource_path("icons/{$set}/{$name}.svg");

                if (is_file($path)) {
                    $library["{$set}:{$name}"] = [
                        'key' => "{$set}:{$name}",
                        'set' => $set,
                        'name' => $name,
                        'words' => (string) $text,
                        'svg' => trim((string) file_get_contents($path)),
                    ];
                }
            }
        }

        return self::$library = $library;
    }

    public function exists(?string $key): bool
    {
        return $key !== null && isset($this->library()[$key]);
    }

    /**
     * Inline-SVG иконки или null (нет иконки, неизвестный ключ, пустая загрузка).
     */
    public function render(?string $key, ?string $uploaded = null, string $class = 'h-6 w-6'): ?HtmlString
    {
        $svg = match (true) {
            $key === self::UPLOAD => $uploaded,
            $this->exists($key) => $this->library()[$key]['svg'],
            default => null,
        };

        if ($svg === null || trim($svg) === '') {
            return null;
        }

        return new HtmlString($this->decorate($svg, $class));
    }

    /**
     * Корневой svg: без width / height (размер — классом), aria-hidden, у Material — fill="currentColor"
     * (в его файлах цвета нет, браузер закрасил бы чёрным).
     */
    private function decorate(string $svg, string $class): string
    {
        return (string) preg_replace_callback('/<svg\b([^>]*)>/i', function ($m) use ($class) {
            $attrs = preg_replace('/\s(?:width|height|class|aria-hidden|focusable)="[^"]*"/i', '', $m[1]);

            if (! preg_match('/\sfill="/i', $attrs)) {
                $attrs .= ' fill="currentColor"';
            }

            return '<svg'.$attrs.' class="'.e($class).'" aria-hidden="true" focusable="false" data-category-icon>';
        }, $svg, 1);
    }

    /**
     * Загруженная SVG → безопасная разметка для inline-вывода или null (не SVG, нет viewBox и размеров,
     * больше MAX_UPLOAD). Остаются только фигуры (ELEMENTS) и их геометрия (ATTRIBUTES): без скриптов,
     * обработчиков, ссылок, стилей и внешних ресурсов; любой цвет fill / stroke → currentColor.
     */
    public function sanitize(string $raw): ?string
    {
        if ($raw === '' || strlen($raw) > self::MAX_UPLOAD || preg_match('/<!DOCTYPE|<!ENTITY/i', $raw)) {
            return null;
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($raw, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->documentElement;

        if (! $loaded || ! $root || strtolower($root->localName) !== 'svg') {
            return null;
        }

        $viewBox = $root->getAttribute('viewBox');
        if ($viewBox === '') {
            $width = (float) $root->getAttribute('width');
            $height = (float) $root->getAttribute('height');
            if ($width <= 0 || $height <= 0) {
                return null;
            }
            $viewBox = "0 0 {$width} {$height}";
        }

        $clean = new DOMDocument;
        $svg = $clean->createElementNS('http://www.w3.org/2000/svg', 'svg');
        $svg->setAttribute('viewBox', $viewBox);
        $clean->appendChild($svg);

        // Корневые fill / stroke (например fill="none" stroke="#000") сохраняются как currentColor
        foreach (['fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin'] as $attr) {
            if ($root->hasAttribute($attr)) {
                $svg->setAttribute($attr, $this->paint($attr, $root->getAttribute($attr)));
            }
        }

        $this->copyChildren($root, $svg, $clean);

        if (! $svg->hasChildNodes()) {
            return null;
        }

        return $clean->saveXML($svg);
    }

    private function copyChildren(DOMElement $from, DOMElement $to, DOMDocument $doc): void
    {
        foreach ($from->childNodes as $node) {
            if (! $node instanceof DOMElement || ! in_array(strtolower($node->localName), self::ELEMENTS, true)) {
                continue;
            }

            $name = strtolower($node->localName);

            if ($name === 'title') {
                continue;
            }

            // Вложенный svg — как группа
            $copy = $doc->createElementNS('http://www.w3.org/2000/svg', $name === 'svg' ? 'g' : $node->localName);

            foreach (iterator_to_array($node->attributes) as $attr) {
                $attrName = strtolower($attr->name);

                if (! in_array($attrName, self::ATTRIBUTES, true) || $attrName === 'viewbox') {
                    continue;
                }

                $value = $this->paint($attrName, $attr->value);

                // Значения — только числа, буквы команд пути и простые функции transform
                if (preg_match('/[<>"\'&;]|url\s*\(|javascript:|expression/i', $value)) {
                    continue;
                }

                $copy->setAttribute($attr->name, $value);
            }

            $this->copyChildren($node, $copy, $doc);
            $to->appendChild($copy);
        }
    }

    /** Цвет заливки / контура — только none или currentColor */
    private function paint(string $attr, string $value): string
    {
        if (! in_array($attr, ['fill', 'stroke'], true)) {
            return $value;
        }

        return strtolower(trim($value)) === 'none' ? 'none' : 'currentColor';
    }

    /** Для тестов и после правок файлов в одном процессе */
    public static function forget(): void
    {
        self::$library = null;
    }
}
