@extends('tyro-dashboard::layouts.admin')
@section('title','Edit calendar draft')
@section('breadcrumb')<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a><span class="breadcrumb-separator">/</span><a href="{{ route('dashboard.calendars.index') }}">Business calendars</a><span class="breadcrumb-separator">/</span><span>Edit draft</span>@endsection
@section('content')
<div class="page-header"><div class="page-header-row"><div><h1 class="page-title">Edit calendar draft · v{{ $calendar->version_no }}</h1><p class="page-description">Timezone is fixed at Asia/Dhaka. Standard hours display as 09:00 AM–06:00 PM; Friday and Saturday are closed by default.</p></div></div></div>
<div class="card"><div class="card-body"><form action="{{ route('dashboard.calendars.update',$calendar) }}" method="POST">@csrf @method('PUT')
<div style="max-width:840px;display:grid;gap:1.25rem"><div><label for="name" class="form-label">Calendar name <span aria-hidden="true">*</span></label><input id="name" name="name" maxlength="100" required class="form-input @error('name') is-invalid @enderror" value="{{ old('name',$calendar->name) }}">@error('name')<p class="form-error" role="alert">{{ $message }}</p>@enderror</div>
<fieldset><legend class="form-label">Weekly business hours</legend><p class="page-description">Select each open day and provide one same-day window. Overnight and multiple daily shifts are not supported.</p>
@php($windowsByDay = $calendar->windows->keyBy('weekday'))
@foreach([1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'] as $weekday=>$dayName)
    @php($index = $weekday - 1)
    @php($savedWindow = $windowsByDay->get($weekday))
    @php($oldWeekday = old("windows.{$index}.weekday"))
    @php($isOpen = $oldWeekday !== null ? (int)$oldWeekday === $weekday : $savedWindow !== null)
    <div style="display:grid;grid-template-columns:minmax(150px,1fr) repeat(2,minmax(130px,180px));gap:.75rem;align-items:end;padding:.75rem 0;border-bottom:1px solid var(--border)">
        <label style="display:flex;gap:.5rem;align-items:center"><input type="checkbox" name="windows[{{ $index }}][weekday]" value="{{ $weekday }}" class="calendar-day-toggle" @checked($isOpen)>{{ $dayName }}</label>
        <div><label class="form-label" for="open-{{ $weekday }}">Opens</label><input id="open-{{ $weekday }}" type="time" name="windows[{{ $index }}][opens_at]" value="{{ old("windows.{$index}.opens_at", substr((string)($savedWindow?->opens_at ?? '09:00:00'),0,5)) }}" class="form-input" @disabled(!$isOpen)></div>
        <div><label class="form-label" for="close-{{ $weekday }}">Closes</label><input id="close-{{ $weekday }}" type="time" name="windows[{{ $index }}][closes_at]" value="{{ old("windows.{$index}.closes_at", substr((string)($savedWindow?->closes_at ?? '18:00:00'),0,5)) }}" class="form-input @error("windows.{$index}.closes_at") is-invalid @enderror" @disabled(!$isOpen)>@error("windows.{$index}.closes_at")<p class="form-error" role="alert">{{ $message }}</p>@enderror</div>
    </div>
@endforeach
@error('windows')<p class="form-error" role="alert">{{ $message }}</p>@enderror
</fieldset>
<fieldset><legend class="form-label">Full-day holidays</legend><p class="page-description">Holiday dates use Dhaka local calendar dates. Adding or removing holidays changes this draft only.</p>
@php($holidayRows = old('holidays') ?? $calendar->holidays->map(fn($holiday)=>['holiday_date'=>$holiday->holiday_date?->format('Y-m-d'),'name'=>$holiday->name])->all())
<div id="holiday-list" style="display:grid;gap:.75rem">@foreach($holidayRows as $index=>$holiday)<div class="holiday-row" style="display:grid;grid-template-columns:repeat(2,minmax(150px,1fr)) auto;gap:.75rem;align-items:end"><div><label class="form-label" for="holiday-date-{{ $index }}">Date</label><input id="holiday-date-{{ $index }}" type="date" name="holidays[{{ $index }}][holiday_date]" value="{{ $holiday['holiday_date'] ?? '' }}" class="form-input @error("holidays.{$index}.holiday_date") is-invalid @enderror">@error("holidays.{$index}.holiday_date")<p class="form-error" role="alert">{{ $message }}</p>@enderror</div><div><label class="form-label" for="holiday-name-{{ $index }}">Holiday name</label><input id="holiday-name-{{ $index }}" name="holidays[{{ $index }}][name]" value="{{ $holiday['name'] ?? '' }}" maxlength="100" class="form-input @error("holidays.{$index}.name") is-invalid @enderror">@error("holidays.{$index}.name")<p class="form-error" role="alert">{{ $message }}</p>@enderror</div><button type="button" class="btn btn-ghost remove-holiday">Remove</button></div>@endforeach</div>
<button id="add-holiday" type="button" class="btn btn-secondary" style="margin-top:.75rem">Add holiday</button>@error('holidays')<p class="form-error" role="alert">{{ $message }}</p>@enderror
</fieldset></div>
<div style="display:flex;gap:.5rem;margin-top:1.25rem"><button type="submit" class="btn btn-primary">Save draft</button><a href="{{ route('dashboard.calendars.index') }}" class="btn btn-ghost">Cancel</a></div></form></div></div>
<script>
document.querySelectorAll('.calendar-day-toggle').forEach((toggle)=>toggle.addEventListener('change',()=>{const row=toggle.closest('div[style]');row.querySelectorAll('input[type="time"]').forEach((field)=>{field.disabled=!toggle.checked;});}));
let holidayIndex={{ count($holidayRows) }};
document.getElementById('add-holiday')?.addEventListener('click',()=>{const index=holidayIndex++;const row=document.createElement('div');row.className='holiday-row';row.style='display:grid;grid-template-columns:repeat(2,minmax(150px,1fr)) auto;gap:.75rem;align-items:end';row.innerHTML=`<div><label class="form-label" for="holiday-date-${index}">Date</label><input id="holiday-date-${index}" type="date" name="holidays[${index}][holiday_date]" class="form-input" required></div><div><label class="form-label" for="holiday-name-${index}">Holiday name</label><input id="holiday-name-${index}" name="holidays[${index}][name]" maxlength="100" class="form-input" required></div><button type="button" class="btn btn-ghost remove-holiday">Remove</button>`;document.getElementById('holiday-list').append(row);});
document.getElementById('holiday-list')?.addEventListener('click',(event)=>{if(event.target.closest('.remove-holiday'))event.target.closest('.holiday-row').remove();});
</script>
@endsection
