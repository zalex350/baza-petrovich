# Файлы кодов клиентов для тестов

`ClientCodesAdminTest::test_files_saved_in_libreoffice_and_google_sheets` — одни и те же данные,
сохранённые разными программами: заголовок в 3-й строке, текстовый код с ведущими нулями
(`00777`), числа (`12345`, `5`), формула (`CONCATENATE` → `HG-B1`), пустая строка, пробелы
вокруг артикула, кириллица, второй лист (не должен читаться).

| Файл | Откуда |
|---|---|
| `source.fods` | Исходная таблица (Flat ODS) |
| `libreoffice.ods` | LibreOffice: `soffice --headless --convert-to ods source.fods` |
| `libreoffice.xlsx` | LibreOffice 7.4.7: `soffice --headless --convert-to xlsx:"Calc MS Excel 2007 XML" source.fods` |
| `libreoffice.csv` | LibreOffice: `--convert-to "csv:Text - txt - csv (StarCalc):44,34,76,1"` — UTF-8, «,» |
| `libreoffice-cp1251.csv` | LibreOffice: `--convert-to "csv:Text - txt - csv (StarCalc):59,34,34,1"` — Windows-1251, «;» |
| `google-sheets.xlsx`, `google-sheets.csv` | `php make-google-sheets.php` — по структуре файлов «Скачать → .xlsx / .csv» из Google Sheets (сохранены без обращения к Google) |

LibreOffice — в одноразовом контейнере:

```bash
docker run --name lo -d debian:bookworm-slim sleep 3600
docker exec lo sh -c 'apt-get update && apt-get install -y --no-install-recommends libreoffice-calc-nogui'
docker cp source.fods lo:/tmp/ && docker exec -w /tmp lo soffice --headless --convert-to … source.fods
docker rm -f lo
```
