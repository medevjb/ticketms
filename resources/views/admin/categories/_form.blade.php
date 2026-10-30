@php($category = $category ?? null)
<div style="max-width:720px; display:grid; gap:1rem">
    <div>
        <label for="name" class="form-label">Name <span aria-hidden="true">*</span></label>
        <input id="name" name="name" value="{{ old('name', $category?->name) }}" class="form-input @error('name') is-invalid @enderror" maxlength="100" required autocomplete="off" aria-describedby="name-error">
        @error('name')<p id="name-error" class="form-error" role="alert">{{ $message }}</p>@enderror
    </div>
    @if(!$category)
        <div>
            <label for="slug" class="form-label">Slug <span aria-hidden="true">*</span></label>
            <input id="slug" name="slug" value="{{ old('slug') }}" class="form-input @error('slug') is-invalid @enderror" maxlength="100" aria-describedby="slug-help slug-error">
            <small id="slug-help" class="page-description">Leave blank to generate it from the name. Slugs stay reserved after archive.</small>
            @error('slug')<p id="slug-error" class="form-error" role="alert">{{ $message }}</p>@enderror
        </div>
    @else
        <div><label for="slug" class="form-label">Slug</label><input id="slug" class="form-input" value="{{ $category->slug }}" readonly></div>
    @endif
    <div>
        <label for="description" class="form-label">Description</label>
        <textarea id="description" name="description" class="form-input @error('description') is-invalid @enderror" rows="4" maxlength="500" aria-describedby="description-error">{{ old('description', $category?->description) }}</textarea>
        @error('description')<p id="description-error" class="form-error" role="alert">{{ $message }}</p>@enderror
    </div>
</div>
