@extends('layouts.app')
@section('title', 'Vehicle map')
@section('content')
<div class="page-heading"><div><h1>Vehicle map</h1><p class="muted">Latest reported locations of your assigned vehicles.</p></div></div>
@include('partials.tracking-map')
@endsection
