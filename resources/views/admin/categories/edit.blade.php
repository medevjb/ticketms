@extends('tyro-dashboard::layouts.admin')

@section('title', 'Edit category')

@section('breadcrumb')
<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a><span class="breadcrumb-separator">/</span>
<a href="{{ route('dashboard.categories.index') }}">Categories</a><span class="breadcrumb-separator">/</span><span>Edit</span>
@endsection

@section('content')
<div class="page-header"><div class="page-header-row"><div><h1 class="page-title">Edit category</h1><p class="page-description">Changing the display name does not alter the stable slug used to identify this category.</p></div></div></div>
<div class="card"><div class="card-body">
    <form action="{{ route('dashboard.categories.update', $category) }}" method="POST">
        @csrf @method('PUT')
        @include('admin.categories._form', ['category' => $category])
        <div style="display:flex; gap:.5rem; margin-top:1.25rem"><button class="btn btn-primary" type="submit">Save changes</button><a class="btn btn-ghost" href="{{ route('dashboard.categories.index') }}">Cancel</a></div>
    </form>
</div></div>
@endsection
