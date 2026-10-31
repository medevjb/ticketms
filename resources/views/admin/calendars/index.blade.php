@extends('tyro-dashboard::layouts.admin')

@section('title', 'Business calendar')
@section('breadcrumb')<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a><span class="breadcrumb-separator">/</span><span>Business calendar</span>@endsection

@push('styles')
<style>
    .business-calendar-page { display:grid; gap:1rem; width:100%; max-width:none; }
    .business-calendar-page form { width:100%; }
    .business-calendar-heading h1 { margin:0 0 .3rem; }
    .business-calendar-heading p { margin:0; }
    .business-calendar-layout { display:grid; grid-template-columns:minmax(0,1.65fr) minmax(360px,.85fr); gap:1rem; align-items:start; width:100%; }
    .business-calendar-panel { min-width:0; padding:1rem; }
    .business-calendar-panel h2 { margin:0 0 .25rem; color:var(--foreground); font-size:.95rem; font-weight:750; }
    .business-calendar-help { margin:0 0 .85rem; color:var(--muted-foreground); font-size:.75rem; line-height:1.5; }
    .business-calendar-summary { display:flex; flex-wrap:wrap; gap:.5rem; margin-bottom:.85rem; }
    .business-calendar-summary-item { display:inline-flex; align-items:center; gap:.35rem; padding:.35rem .55rem; border:1px solid var(--border); border-radius:999px; background:var(--muted); color:var(--muted-foreground); font-size:.68rem; }
    .business-calendar-summary-item strong { color:var(--foreground); }
    .business-calendar-toolbar { display:flex; align-items:center; justify-content:space-between; gap:.6rem; margin-bottom:.65rem; }
    .business-calendar-toolbar-group { display:flex; align-items:center; gap:.4rem; }
    .business-calendar-toolbar button { min-width:2.1rem; min-height:2.1rem; }
    .business-calendar-toolbar input[type=month] { width:auto; min-width:155px; min-height:2.1rem; padding:.3rem .55rem; }
    .business-calendar-grid { display:grid; grid-template-columns:minmax(0,1fr); gap:.35rem; }
    .business-calendar-week-row { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:.35rem; }
    .business-calendar-weekday { padding:.4rem .2rem; text-align:center; color:var(--muted-foreground); font-size:.66rem; font-weight:750; }
    .business-calendar-day { display:flex; min-width:0; min-height:5.2rem; flex-direction:column; align-items:flex-start; justify-content:space-between; gap:.35rem; padding:.5rem; border:1px solid var(--border); border-radius:var(--radius); background:var(--card); color:var(--foreground); font:inherit; text-align:left; cursor:pointer; }
    .business-calendar-day:hover { border-color:var(--primary); background:var(--accent); }
    .business-calendar-day:focus-visible { outline:2px solid var(--ring); outline-offset:2px; }
    .business-calendar-day.is-outside { opacity:.55; }
    .business-calendar-day.is-working:not(.is-holiday) { border-color:color-mix(in srgb,var(--success),transparent 65%); background:color-mix(in srgb,var(--success),transparent 95%); }
    .business-calendar-day.is-closed:not(.is-holiday) { background:var(--muted); }
    .business-calendar-day.is-holiday:not(.is-selected) { border-color:color-mix(in srgb,var(--warning),transparent 35%); background:color-mix(in srgb,var(--warning),transparent 90%); }
    .business-calendar-day.is-today .business-calendar-day-number { box-shadow:0 0 0 1px var(--primary); }
    .business-calendar-day.is-selected { border-color:var(--primary); background:var(--primary); color:var(--primary-foreground); box-shadow:0 0 0 1px var(--primary); }
    .business-calendar-day-top { display:flex; width:100%; align-items:center; justify-content:space-between; gap:.3rem; }
    .business-calendar-day-number { display:grid; width:1.65rem; height:1.65rem; place-items:center; border-radius:50%; font-size:.76rem; font-weight:750; }
    .business-calendar-day-state { display:block; max-width:100%; overflow:hidden; color:var(--muted-foreground); font-size:.63rem; line-height:1.25; text-overflow:ellipsis; white-space:nowrap; }
    .business-calendar-day.is-selected .business-calendar-day-state { color:var(--primary-foreground); opacity:.9; }
    .business-calendar-day.is-holiday .business-calendar-day-state { color:var(--warning); font-weight:700; }
    .business-calendar-day.is-selected.is-holiday .business-calendar-day-state { color:var(--primary-foreground); }
    .business-calendar-legend { display:flex; flex-wrap:wrap; gap:.85rem; margin:.7rem 0; color:var(--muted-foreground); font-size:.68rem; }
    .business-calendar-legend span { display:inline-flex; align-items:center; gap:.35rem; }
    .business-calendar-legend i { display:block; width:.55rem; height:.55rem; border:1px solid var(--border); border-radius:50%; background:var(--muted); }
    .business-calendar-legend .working-dot { border-color:var(--success); background:var(--success); }
    .business-calendar-legend .holiday-dot { border-color:var(--warning); background:var(--warning); }
    .calendar-day-editor { display:grid; gap:.7rem; margin-top:.8rem; padding:.85rem; border:1px solid var(--border); border-radius:var(--radius); background:var(--muted); }
    .calendar-day-editor-header { display:flex; align-items:flex-start; justify-content:space-between; gap:.75rem; }
    .calendar-day-editor-header strong { color:var(--foreground); font-size:.8rem; }
    .calendar-day-status { flex:none; padding:.25rem .5rem; border-radius:999px; background:color-mix(in srgb,var(--success),transparent 88%); color:var(--success); font-size:.64rem; font-weight:700; }
    .calendar-day-status.is-closed { background:var(--accent); color:var(--muted-foreground); }
    .calendar-day-status.is-holiday { background:color-mix(in srgb,var(--warning),transparent 86%); color:var(--warning); }
    .calendar-day-editor .form-label { margin-bottom:.3rem; }
    .calendar-day-editor-actions { display:flex; flex-wrap:wrap; gap:.45rem; }
    .calendar-holiday-section { margin-top:.85rem; }
    .calendar-holiday-section-heading { display:flex; align-items:center; justify-content:space-between; gap:.5rem; margin-bottom:.45rem; }
    .calendar-holiday-section-heading strong { color:var(--foreground); font-size:.72rem; }
    .calendar-holiday-list { display:grid; max-height:210px; gap:.4rem; overflow:auto; }
    .calendar-holiday-item { display:flex; width:100%; align-items:center; justify-content:space-between; gap:.6rem; padding:.5rem .6rem; border:1px solid var(--border); border-radius:8px; background:var(--card); color:var(--foreground); font:inherit; text-align:left; cursor:pointer; }
    .calendar-holiday-item:hover { border-color:var(--warning); background:var(--accent); }
    .calendar-holiday-item strong { display:block; overflow:hidden; font-size:.7rem; text-overflow:ellipsis; white-space:nowrap; }
    .calendar-holiday-item span { display:block; color:var(--muted-foreground); font-size:.64rem; }
    .calendar-holiday-empty { margin:.45rem 0 0; color:var(--muted-foreground); font-size:.7rem; }
    .calendar-hours-list { display:grid; }
    .calendar-hours-row { display:grid; grid-template-columns:minmax(120px,1fr) repeat(2,minmax(100px,145px)); align-items:center; gap:.55rem; padding:.55rem 0; border-top:1px solid var(--border); }
    .calendar-hours-row:first-child { border-top:0; }
    .calendar-day-toggle { display:flex; align-items:center; gap:.45rem; color:var(--foreground); font-size:.75rem; font-weight:650; }
    .calendar-day-toggle input { accent-color:var(--primary); }
    .calendar-hours-row .form-label { margin-bottom:.25rem; color:var(--muted-foreground); font-size:.64rem; }
    .calendar-hours-row .form-input { min-height:2.25rem; padding:.35rem .5rem; }
    .calendar-hours-row.is-closed { grid-template-columns:minmax(120px,1fr) auto; }
    .calendar-closed-label { display:none; color:var(--muted-foreground); font-size:.7rem; }
    .calendar-hours-row.is-closed .calendar-closed-label { display:block; }
    .calendar-hours-row.is-closed .calendar-time-field { display:none; }
    .calendar-details-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.65rem; margin-bottom:.85rem; }
    .calendar-note { margin:.85rem 0 0; padding:.7rem .8rem; border:1px solid var(--border); border-radius:var(--radius); background:var(--muted); color:var(--muted-foreground); font-size:.72rem; line-height:1.5; }
    .calendar-save-bar { position:sticky; bottom:.5rem; z-index:2; display:flex; justify-content:flex-end; gap:.5rem; margin-top:1rem; padding:.7rem; border:1px solid var(--border); border-radius:var(--radius); background:var(--card); box-shadow:var(--card-shadow); }
    @media(max-width:1150px) { .business-calendar-layout { grid-template-columns:minmax(0,1.3fr) minmax(330px,.9fr); } .calendar-hours-row { grid-template-columns:minmax(110px,1fr) repeat(2,minmax(85px,115px)); gap:.4rem; } }
    @media(max-width:900px) { .business-calendar-layout { grid-template-columns:1fr; } }
    @media(max-width:560px) { .business-calendar-panel { padding:.75rem; } .business-calendar-toolbar { align-items:flex-start; flex-direction:column; } .business-calendar-toolbar-group { width:100%; } .business-calendar-toolbar input[type=month] { flex:1; min-width:0; } .business-calendar-day { min-height:3.7rem; padding:.3rem; } .business-calendar-day-number { width:1.4rem; height:1.4rem; font-size:.69rem; } .business-calendar-day-state { font-size:.56rem; } .calendar-hours-row { grid-template-columns:minmax(95px,1fr) repeat(2,minmax(75px,1fr)); gap:.35rem; } .calendar-hours-row.is-closed { grid-template-columns:minmax(95px,1fr) auto; } .calendar-details-grid { grid-template-columns:1fr; } }
</style>
@endpush

@section('content')
@php
    $calendarWindows = $calendar->windows->keyBy('weekday');
    $hasOldWindows = session()->hasOldInput('windows');
    $holidayRows = session()->hasOldInput('holidays')
        ? old('holidays', [])
        : $calendar->holidays->map(fn ($holiday) => ['holiday_date' => $holiday->holiday_date?->format('Y-m-d'), 'name' => $holiday->name])->all();
@endphp
<div class="business-calendar-page">
    <header class="business-calendar-heading"><h1 class="page-title">Business calendar</h1><p class="page-description">Review working days and hours across the month, then add holidays or closures. Changes save directly and update ticket SLA calculations.</p></header>

    <form action="{{ route('dashboard.calendars.update') }}" method="POST" id="business-calendar-form" data-business-calendar>
        @csrf @method('PUT')
        <script type="application/json" id="calendar-holiday-data">{!! json_encode($holidayRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

        <div class="business-calendar-layout">
            <section class="card business-calendar-panel" aria-labelledby="calendar-month-heading">
                <h2 id="calendar-month-heading">Monthly schedule and holidays</h2>
                <p class="business-calendar-help">Each date shows whether support is open and the hours that apply. Select any date to add, update, or remove a holiday.</p>
                <div class="business-calendar-summary" aria-live="polite">
                    <span class="business-calendar-summary-item"><strong data-calendar-open-days>0</strong> working days each week</span>
                    <span class="business-calendar-summary-item"><strong data-calendar-holiday-count>0</strong> holidays listed</span>
                    <span class="business-calendar-summary-item">Time zone: <strong data-calendar-timezone-label>{{ $calendar->timezone }}</strong></span>
                </div>
                <div class="business-calendar-toolbar">
                    <div class="business-calendar-toolbar-group">
                        <button type="button" class="btn btn-ghost" data-calendar-previous aria-label="Previous month">‹</button>
                        <label for="calendar-month-picker" class="sr-only">Choose month</label>
                        <input id="calendar-month-picker" type="month" class="form-input" data-calendar-month-picker aria-label="Choose month">
                        <button type="button" class="btn btn-ghost" data-calendar-next aria-label="Next month">›</button>
                    </div>
                    <button type="button" class="btn btn-secondary" data-calendar-today>Today</button>
                </div>
                <div class="business-calendar-grid" data-calendar-grid role="grid" aria-label="Monthly business schedule"></div>
                <div class="business-calendar-legend" aria-label="Calendar legend">
                    <span><i class="working-dot"></i> Working day</span>
                    <span><i></i> Closed day</span>
                    <span><i class="holiday-dot"></i> Holiday closure</span>
                    <span><i style="border-color:var(--primary)"></i> Today</span>
                </div>
                <div class="calendar-day-editor" aria-live="polite">
                    <div class="calendar-day-editor-header">
                        <div><strong data-calendar-selected-label>Choose a date</strong><div class="business-calendar-help" style="margin:.2rem 0 0" data-calendar-selected-hours></div></div>
                        <span class="calendar-day-status" data-calendar-day-status>Working day</span>
                    </div>
                    <div><label for="calendar-holiday-name" class="form-label">Holiday name</label><input id="calendar-holiday-name" type="text" class="form-input" maxlength="100" placeholder="For example, public holiday" disabled></div>
                    <div class="calendar-day-editor-actions"><button type="button" class="btn btn-secondary" data-calendar-save-day disabled>Add holiday</button><button type="button" class="btn btn-ghost" data-calendar-remove-day hidden>Remove holiday</button></div>
                </div>
                <div class="calendar-holiday-section">
                    <div class="calendar-holiday-section-heading"><strong>Holiday list</strong><span class="business-calendar-help" style="margin:0">Select an entry to jump to its date.</span></div>
                    <div class="calendar-holiday-list" data-calendar-holiday-list aria-live="polite"></div>
                    <p class="calendar-holiday-empty" data-calendar-no-holidays>No holidays added. Select a date to mark a closure.</p>
                </div>
            </section>

            <section class="card business-calendar-panel" aria-labelledby="calendar-hours-heading">
                <h2 id="calendar-hours-heading">Calendar settings and weekly hours</h2>
                <p class="business-calendar-help">Choose which days count toward SLA time and set the support hours for each working day.</p>
                <div class="calendar-details-grid">
                    <div><label class="form-label" for="calendar-name">Calendar name</label><input id="calendar-name" name="name" class="form-input" maxlength="100" required value="{{ old('name', $calendar->name) }}"></div>
                    <div><label class="form-label" for="calendar-timezone">Time zone</label><input id="calendar-timezone" name="timezone" class="form-input" required value="{{ old('timezone', $calendar->timezone) }}" placeholder="Asia/Dhaka"></div>
                </div>
                <div class="calendar-hours-list">
                    @foreach([1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'] as $weekday => $dayName)
                        @php
                            $index = $weekday - 1;
                            $savedWindow = $calendarWindows->get($weekday);
                            $oldWeekday = old("windows.{$index}.weekday");
                            $isOpen = $hasOldWindows ? (int) $oldWeekday === $weekday : $savedWindow !== null;
                        @endphp
                        <div class="calendar-hours-row {{ $isOpen ? '' : 'is-closed' }}" data-calendar-hours-row>
                            <label class="calendar-day-toggle"><input type="checkbox" name="windows[{{ $index }}][weekday]" value="{{ $weekday }}" data-calendar-day-toggle @checked($isOpen)>{{ $dayName }}</label>
                            <div class="calendar-time-field"><label class="form-label" for="open-{{ $weekday }}">Opens</label><input id="open-{{ $weekday }}" type="time" name="windows[{{ $index }}][opens_at]" value="{{ old("windows.{$index}.opens_at", substr((string) ($savedWindow?->opens_at ?? '09:00:00'), 0, 5)) }}" class="form-input @error("windows.{$index}.opens_at") is-invalid @enderror" data-calendar-time @disabled(!$isOpen)></div>
                            <div class="calendar-time-field"><label class="form-label" for="close-{{ $weekday }}">Closes</label><input id="close-{{ $weekday }}" type="time" name="windows[{{ $index }}][closes_at]" value="{{ old("windows.{$index}.closes_at", substr((string) ($savedWindow?->closes_at ?? '18:00:00'), 0, 5)) }}" class="form-input @error("windows.{$index}.closes_at") is-invalid @enderror" data-calendar-time @disabled(!$isOpen)></div>
                            <span class="calendar-closed-label">Closed</span>
                        </div>
                    @endforeach
                </div>
                <p class="calendar-note">Holidays and non-working days pause the business-time SLA clock. Your display timezone setting does not change this calendar timezone.</p>
            </section>
        </div>
        <div data-calendar-hidden-holidays></div>
        <div class="calendar-save-bar"><button type="submit" class="btn btn-primary">Save business calendar</button></div>
    </form>
</div>

<script>
    (() => {
        const root = document.querySelector('[data-business-calendar]');
        if (!root) return;

        const grid = root.querySelector('[data-calendar-grid]');
        const monthPicker = root.querySelector('[data-calendar-month-picker]');
        const nameInput = root.querySelector('#calendar-holiday-name');
        const selectedLabel = root.querySelector('[data-calendar-selected-label]');
        const selectedHours = root.querySelector('[data-calendar-selected-hours]');
        const dayStatus = root.querySelector('[data-calendar-day-status]');
        const saveDay = root.querySelector('[data-calendar-save-day]');
        const removeDay = root.querySelector('[data-calendar-remove-day]');
        const hiddenFields = root.querySelector('[data-calendar-hidden-holidays]');
        const list = root.querySelector('[data-calendar-holiday-list]');
        const noHolidays = root.querySelector('[data-calendar-no-holidays]');
        const holidayData = document.getElementById('calendar-holiday-data');
        const holidays = new Map(JSON.parse(holidayData?.textContent || '[]').map((holiday) => [holiday.holiday_date, holiday.name]));
        const today = new Date();
        const todayKey = dateKey(today);
        let visibleMonth = new Date(today.getFullYear(), today.getMonth(), 1);
        let selectedDate = todayKey;

        function dateKey(date) {
            return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        }

        const readableDate = (key) => new Intl.DateTimeFormat(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }).format(new Date(`${key}T12:00:00`));
        const toMonthKey = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;

        const weeklySchedule = () => {
            const schedule = new Map();
            root.querySelectorAll('[data-calendar-day-toggle]:checked').forEach((toggle) => {
                const row = toggle.closest('[data-calendar-hours-row]');
                const weekday = Number(toggle.value);
                const [opensAt, closesAt] = row.querySelectorAll('[data-calendar-time]');
                schedule.set(weekday, { opensAt: opensAt.value, closesAt: closesAt.value });
            });
            return schedule;
        };

        const timeRange = (window) => window ? `${window.opensAt}–${window.closesAt}` : 'Closed';

        const syncSummary = () => {
            root.querySelector('[data-calendar-open-days]').textContent = String(weeklySchedule().size);
            root.querySelector('[data-calendar-holiday-count]').textContent = String(holidays.size);
            root.querySelector('[data-calendar-timezone-label]').textContent = root.querySelector('#calendar-timezone').value || 'Select a time zone';
        };

        const renderHolidays = () => {
            hiddenFields.replaceChildren();
            list.replaceChildren();
            [...holidays.entries()].sort(([left], [right]) => left.localeCompare(right)).forEach(([date, name], index) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'calendar-holiday-item';
                button.setAttribute('aria-label', `View ${name}, ${readableDate(date)}`);
                const copy = document.createElement('span');
                const title = document.createElement('strong');
                title.textContent = name;
                const dateText = document.createElement('span');
                dateText.textContent = readableDate(date);
                copy.append(title, dateText);
                const arrow = document.createElement('span');
                arrow.setAttribute('aria-hidden', 'true');
                arrow.textContent = '›';
                button.append(copy, arrow);
                button.addEventListener('click', () => selectDate(date, true));
                list.append(button);

                for (const [field, value] of [['holiday_date', date], ['name', name]]) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `holidays[${index}][${field}]`;
                    input.value = value;
                    hiddenFields.append(input);
                }
            });
            noHolidays.hidden = holidays.size > 0;
            syncSummary();
        };

        const syncSelectedDay = () => {
            const date = new Date(`${selectedDate}T12:00:00`);
            const isoWeekday = (date.getDay() + 6) % 7 + 1;
            const window = weeklySchedule().get(isoWeekday);
            const holiday = holidays.get(selectedDate);

            selectedLabel.textContent = readableDate(selectedDate);
            selectedHours.textContent = holiday ? 'Holiday closure' : window ? `Support hours: ${timeRange(window)}` : 'No support hours scheduled for this day.';
            dayStatus.textContent = holiday ? 'Holiday' : window ? 'Working day' : 'Closed day';
            dayStatus.classList.toggle('is-holiday', Boolean(holiday));
            dayStatus.classList.toggle('is-closed', !holiday && !window);
            nameInput.disabled = false;
            nameInput.value = holiday || '';
            saveDay.disabled = false;
            saveDay.textContent = holiday ? 'Update holiday' : 'Add holiday';
            removeDay.hidden = !holiday;
        };

        function selectDate(key, jumpToMonth = false) {
            selectedDate = key;
            if (jumpToMonth) {
                const date = new Date(`${key}T12:00:00`);
                visibleMonth = new Date(date.getFullYear(), date.getMonth(), 1);
            }
            renderMonth();
            syncSelectedDay();
        }

        const renderMonth = () => {
            monthPicker.value = toMonthKey(visibleMonth);
            grid.replaceChildren();
            const headingRow = document.createElement('div');
            headingRow.className = 'business-calendar-week-row';
            headingRow.setAttribute('role', 'row');
            ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'].forEach((day) => {
                const heading = document.createElement('span');
                heading.className = 'business-calendar-weekday';
                heading.setAttribute('role', 'columnheader');
                heading.textContent = day.slice(0, 3);
                headingRow.append(heading);
            });
            grid.append(headingRow);

            const schedule = weeklySchedule();
            const firstDay = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth(), 1);
            const mondayOffset = (firstDay.getDay() + 6) % 7;
            const start = new Date(firstDay.getFullYear(), firstDay.getMonth(), 1 - mondayOffset);

            for (let dayOffset = 0; dayOffset < 42; dayOffset++) {
                if (dayOffset % 7 === 0) {
                    const weekRow = document.createElement('div');
                    weekRow.className = 'business-calendar-week-row';
                    weekRow.setAttribute('role', 'row');
                    grid.append(weekRow);
                }

                const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + dayOffset);
                const key = dateKey(date);
                const isoWeekday = (date.getDay() + 6) % 7 + 1;
                const window = schedule.get(isoWeekday);
                const holiday = holidays.get(key);
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'business-calendar-day';
                button.dataset.date = key;
                button.setAttribute('role', 'gridcell');
                button.setAttribute('aria-label', `${readableDate(key)}${holiday ? `, holiday: ${holiday}` : window ? `, support hours ${timeRange(window)}` : ', closed day'}`);
                button.setAttribute('aria-pressed', String(key === selectedDate));
                button.tabIndex = key === selectedDate ? 0 : -1;
                button.classList.toggle('is-outside', date.getMonth() !== visibleMonth.getMonth());
                button.classList.toggle('is-today', key === todayKey);
                button.classList.toggle('is-selected', key === selectedDate);
                button.classList.toggle('is-working', Boolean(window) && !holiday);
                button.classList.toggle('is-closed', !window && !holiday);
                button.classList.toggle('is-holiday', Boolean(holiday));

                const top = document.createElement('span');
                top.className = 'business-calendar-day-top';
                const number = document.createElement('span');
                number.className = 'business-calendar-day-number';
                number.textContent = String(date.getDate());
                top.append(number);

                const state = document.createElement('span');
                state.className = 'business-calendar-day-state';
                state.textContent = holiday ? holiday : timeRange(window);
                button.append(top, state);
                button.addEventListener('click', () => selectDate(key));
                grid.lastElementChild.append(button);
            }
        };

        const updateHoursRow = (toggle) => {
            const row = toggle.closest('[data-calendar-hours-row]');
            row.classList.toggle('is-closed', !toggle.checked);
            row.querySelectorAll('[data-calendar-time]').forEach((field) => { field.disabled = !toggle.checked; });
            renderMonth();
            syncSelectedDay();
            syncSummary();
        };

        root.querySelectorAll('[data-calendar-day-toggle]').forEach((toggle) => toggle.addEventListener('change', () => updateHoursRow(toggle)));
        root.querySelectorAll('[data-calendar-time]').forEach((field) => field.addEventListener('input', () => {
            renderMonth();
            syncSelectedDay();
        }));
        root.querySelector('#calendar-timezone').addEventListener('input', syncSummary);
        root.querySelector('[data-calendar-previous]').addEventListener('click', () => {
            visibleMonth = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth() - 1, 1);
            selectDate(dateKey(new Date(visibleMonth.getFullYear(), visibleMonth.getMonth(), 1)));
        });
        root.querySelector('[data-calendar-next]').addEventListener('click', () => {
            visibleMonth = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth() + 1, 1);
            selectDate(dateKey(new Date(visibleMonth.getFullYear(), visibleMonth.getMonth(), 1)));
        });
        monthPicker.addEventListener('change', () => {
            if (!monthPicker.value) return;
            const [year, month] = monthPicker.value.split('-').map(Number);
            visibleMonth = new Date(year, month - 1, 1);
            selectDate(dateKey(visibleMonth));
        });
        root.querySelector('[data-calendar-today]').addEventListener('click', () => selectDate(todayKey, true));
        grid.addEventListener('keydown', (event) => {
            const offsets = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
            const offset = offsets[event.key];
            if (!offset) return;
            event.preventDefault();
            const next = new Date(`${selectedDate}T12:00:00`);
            next.setDate(next.getDate() + offset);
            selectDate(dateKey(next), true);
            grid.querySelector(`[data-date="${selectedDate}"]`)?.focus();
        });
        saveDay.addEventListener('click', () => {
            if (!nameInput.value.trim()) {
                nameInput.focus();
                return;
            }
            holidays.set(selectedDate, nameInput.value.trim());
            renderHolidays();
            renderMonth();
            syncSelectedDay();
        });
        removeDay.addEventListener('click', () => {
            holidays.delete(selectedDate);
            renderHolidays();
            renderMonth();
            syncSelectedDay();
        });
        nameInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                saveDay.click();
            }
        });

        renderHolidays();
        renderMonth();
        syncSelectedDay();
    })();
</script>
@endsection
