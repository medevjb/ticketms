@extends('tyro-dashboard::layouts.admin')

@section('title', 'Create category')

@section('breadcrumb')
<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a><span class="breadcrumb-separator">/</span>
<a href="{{ route('dashboard.categories.index') }}">Categories</a><span class="breadcrumb-separator">/</span><span>Create</span>
@endsection

@section('content')
<div class="page-header"><div class="page-header-row"><div><h1 class="page-title">Create category</h1><p class="page-description">New categories are enabled for ticket selection by default.</p></div></div></div>
<div class="card"><div class="card-body">
    <form action="{{ route('dashboard.categories.store') }}" method="POST">
        @csrf
        @include('admin.categories._form')
        <div style="display:flex; gap:.5rem; margin-top:1.25rem"><button class="btn btn-primary" type="submit">Create category</button><a class="btn btn-ghost" href="{{ route('dashboard.categories.index') }}">Cancel</a></div>
    </form>
</div></div>
@endsection
