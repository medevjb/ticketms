@php($category = $category ?? null)
<div class="category-editor-fields">
    <div><label for="category-name" class="form-label">Name <span aria-hidden="true">*</span></label><input id="category-name" name="name" value="{{ old('name', $category?->name) }}" class="form-input" maxlength="100" required autocomplete="off"></div>
    <div><label for="category-slug" class="form-label">Slug</label><input id="category-slug" name="slug" value="{{ old('slug', $category?->slug) }}" class="form-input" maxlength="100" aria-describedby="category-slug-help" @readonly($category !== null)><small id="category-slug-help" class="page-description">{{ $category ? 'The existing slug stays fixed to preserve references.' : 'Generated from the name as you type. You can edit it before creating the category.' }}</small></div>
    <div><label for="category-description" class="form-label">Description</label><textarea id="category-description" name="description" class="form-input" rows="4" maxlength="500">{{ old('description', $category?->description) }}</textarea></div>
</div>
