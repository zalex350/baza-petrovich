// Копирует из npm-пакета @material-symbols/svg-300 (Outlined, weight 300) только иконки из списка
// resources/icons/material.json в resources/icons/material/ — в проект попадают только они.
// Запуск: docker-compose exec node npm run icons. Новая иконка Material: добавить имя
// (и слова для поиска) в material.json, запустить скрипт, закоммитить SVG.
import { readFileSync, writeFileSync, mkdirSync, readdirSync, unlinkSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const source = join(root, 'node_modules/@material-symbols/svg-300/outlined');
const target = join(root, 'resources/icons/material');
const names = Object.keys(JSON.parse(readFileSync(join(root, 'resources/icons/material.json'), 'utf8')));

if (!existsSync(source)) {
    console.error('Нет пакета @material-symbols/svg-300: npm install');
    process.exit(1);
}

const missing = names.filter((name) => !existsSync(join(source, `${name}.svg`)));
if (missing.length) {
    console.error(`Нет в Material Symbols: ${missing.join(', ')}`);
    process.exit(1);
}

mkdirSync(target, { recursive: true });

// Лишние (удалённые из списка) — убрать
for (const file of readdirSync(target)) {
    if (file.endsWith('.svg') && !names.includes(file.slice(0, -4))) {
        unlinkSync(join(target, file));
    }
}

for (const name of names) {
    writeFileSync(join(target, `${name}.svg`), readFileSync(join(source, `${name}.svg`), 'utf8').trim() + '\n');
}

console.log(`Material Symbols: ${names.length} иконок → resources/icons/material/`);
