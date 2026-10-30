@extends('tyro-dashboard::layouts.admin')
@section('title','Edit priority')
@section('breadcrumb')<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a><span class="breadcrumb-separator">/</span><a href="{{ route('dashboard.priorities.index') }}">Priorities</a><span class="breadcrumb-separator">/</span><span>Edit</span>@endsection
@section('content')
<div class="page-header"><div class="page-header-row"><div><h1 class="page-title">Edit priority</h1><p class="page-description">SLA targets are versioned separately and cannot be changed here.</p></div></div></div>
<div class="card"><div class="card-body"><form action="{{ route('dashboard.priorities.update',$priority) }}" method="POST">@csrf @method('PUT') @include('admin.priorities._form',['priority'=>$priority])<div style="display:flex;gap:.5rem;margin-top:1.25rem"><button class="btn btn-primary" type="submit">Save changes</button><a class="btn btn-ghost" href="{{ route('dashboard.priorities.index') }}">Cancel</a></div></form></div></div>
@endsection
