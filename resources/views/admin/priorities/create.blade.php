@extends('tyro-dashboard::layouts.admin')
@section('title','Create priority')
@section('breadcrumb')<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a><span class="breadcrumb-separator">/</span><a href="{{ route('dashboard.priorities.index') }}">Priorities</a><span class="breadcrumb-separator">/</span><span>Create</span>@endsection
@section('content')
<div class="page-header"><div class="page-header-row"><div><h1 class="page-title">Create priority</h1><p class="page-description">New priorities start disabled. Add a target in the SLA policy before enabling the priority.</p></div></div></div>
<div class="card"><div class="card-body"><form action="{{ route('dashboard.priorities.store') }}" method="POST">@csrf @include('admin.priorities._form')<div style="display:flex;gap:.5rem;margin-top:1.25rem"><button class="btn btn-primary" type="submit">Create priority</button><a class="btn btn-ghost" href="{{ route('dashboard.priorities.index') }}">Cancel</a></div></form></div></div>
@endsection
