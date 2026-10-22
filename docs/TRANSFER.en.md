# Route transfer

English · **[Русский](TRANSFER.md)**

Routes move between GLPI installations as a JSON file — the same way as
GLPI 11 forms (form import).

## What the file contains

- Routes with stages, conditions and “Запускать, если” (Start if).
- References to GLPI objects: entities, groups and categories — by full name;
  calendar and time to resolve — by name; users — by login; the form, its
  destination, the approval step and the condition questions.

The file has no tickets, route progress or passwords. Not transferred: the
GLPI forms themselves (they have their own export), business rules, profile
rights, images and files from a stage description — only its text.

A format 4 file is read by itilflow 1.13 and later; files of earlier formats
are read as before. An older itilflow cannot read format 4.

## Export

**Administration → Маршруты этапов (Stage routes):** “Экспорт всех” (Export
all) above the list, or select routes and the massive action “Экспорт”
(Export). Read right is needed; only routes you can see go into the file.

## Import

1. “Импорт маршрутов” (Import routes) above the list (Create and Update
   rights) → “Выберите свой файл” (Select your file) → “Предварительный
   просмотр импорта” (Preview import).
2. Each route has a state:
   - “Готов к импорту” (Ready to import); with “Выбрано вручную: N.” (Chosen
     manually: N) if you picked replacements yourself;
   - “Нужно выбрать: N” (N to choose) — pick the replacements in “Устранять
     проблемы”;
   - “Не может быть импортирован” (Cannot be imported) — with a reason;
   - “Маршрут с таким именем уже есть — будет пропущен” (A route with this
     name exists — will be skipped); “Создать копией” (Create as a copy)
     creates it as “… (копия)”.

   Under the name — “Запуск маршрута: …” (Route start) and, if there are
   conditions, “Вопросы запуска: из файла → в выбранной форме” (Start
   questions: from the file → in the chosen form) with a mark on each
   question. The bin removes a route from the import.
3. **“Устранять проблемы”** (Resolve issues) — for each object pick a
   replacement from the GLPI list or “-----” (leave empty):
   - the form is found automatically if it was moved with GLPI form import;
     otherwise choose it and open the preview again;
   - the form destination and approval step are always chosen by hand:
     “Выберите из списка.” (Choose from the list.);
   - map condition questions to questions of the chosen form of the same
     type and with the same options;
   - give an assignee to a stage without a group and user.

   Other objects are looked up as in GLPI form import: by full name in your
   entities, exactly one must match. Otherwise — an object with the same ID
   and name, marked “check”. Stage deadlines and SLAs are checked before
   import: after 18 January 2038 — not imported.
4. **“Импорт”** (Import) creates the routes **disabled**, in one operation: if
   anything fails, nothing is written. Existing routes are not changed.

![Import preview: “Готов к импорту”, “Запуск маршрута: …”, start questions](img/import-preview.png)

## After import

Check each route — the readiness line, the “Этапы” (Stages) tab, “Запуск
маршрута” — and enable it. A route with “Запускать, если” starts from form
answers as soon as it is enabled.

Move the business rules that start the route with the standard GLPI rule
export and import: GLPI finds the action “Запустить маршрут этапов” (Start
stage route) by the route name. Or create the rule again.
