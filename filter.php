<?php
// filter.php

/**
 * Список колонок, по которым разрешено фильтровать.
 * Ключ — имя поля в БД/массиве, значение — метка в заголовке.
 */
function getAllowedColumns(): array
{
    return [
        'ctkrs'                   => 'ЦТКРС',
        'equipment_type'          => 'Вид оборудования',
        'equipment_name'          => 'Наименование',
        'serial_number'           => 'Заводской номер',
        'release_date'            => 'Дата выпуска',
        'commissioning_date'      => 'Дата ввода',
        'inventory_number'        => 'Инвентарный №',
        'condition_state'         => 'Состояние',
        'location'                => 'Местонахождение',
        // … добавьте остальные колонки
    ];
}

/**
 * Считывает из $_GET все фильтры по разрешённым полям.
 * @return array ['поле' => 'значение', …]
 */
function getColumnFilters(): array
{
    $allowed = array_keys(getAllowedColumns());
    $filters = [];
    foreach ($allowed as $field) {
        if (isset($_GET[$field]) && $_GET[$field] !== '') {
            $filters[$field] = $_GET[$field];
        }
    }
    return $filters;
}

/**
 * Выводит HTML-заголовок <th> с иконкой фильтра.
 * @param string $field — имя поля
 * @param string $label — метка
 * @param array  $filters — текущие фильтры из getColumnFilters()
 */
function renderColumnHeader(string $field, string $label, array $filters): void
{
    $active = isset($filters[$field]) ? ' active-filter' : '';
    echo '<th style="position:relative;">'
       .   htmlspecialchars($label)
       .   ' <span class="filter-icon' . $active . '"'
       .     ' onclick="openFilterPopup(\'' . $field . '\',event)">&#x1F50D;</span>'
       . '</th>';
}

/**
 * Вставляет в страницу HTML для pop‑up окна фильтра.
 * Его можно один раз напечатать внизу <body>.
 */
function renderFilterPopup(): void
{
    ?>
    <div id="filterPopup" class="filter-popup" style="display:none; position:absolute; background:#fff; border:1px solid #ccc; padding:8px; z-index:1000;">
      <input type="text" id="filterInput" placeholder="Значение…" style="margin-bottom:5px;width:150px;"><br>
      <button onclick="applyFilter()">Применить</button>
      <button onclick="clearFilter()">Сбросить</button>
    </div>
    <script>
      let currentField = null;
      function openFilterPopup(field, e) {
        currentField = field;
        const popup = document.getElementById('filterPopup');
        const inp   = document.getElementById('filterInput');
        // подставляем текущее значение фильтра из URL
        inp.value = new URLSearchParams(location.search).get(field) || '';
        // позиционируем popup рядом с иконкой
        popup.style.top  = e.pageY + 'px';
        popup.style.left = e.pageX + 'px';
        popup.style.display = 'block';
        e.stopPropagation();
      }
      function applyFilter() {
        const val = document.getElementById('filterInput').value;
        const params = new URLSearchParams(location.search);
        params.set(currentField, val);
        params.set('page', 1);       // сбрасываем пагинацию
        window.location.search = params.toString();
      }
      function clearFilter() {
        const params = new URLSearchParams(location.search);
        params.delete(currentField);
        params.set('page', 1);
        window.location.search = params.toString();
      }
      // закрываем попап по клику вне его
      document.addEventListener('click', () => {
        document.getElementById('filterPopup').style.display = 'none';
      });
    </script>
    <style>
      .filter-icon { cursor: pointer; color: #555; }
      .filter-icon.active-filter { color: #0288d1; }
    </style>
    <?php
}
