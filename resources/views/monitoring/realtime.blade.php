@extends('layouts.app')

@section('title', 'Monitoring Real-Time | ' . $siteName)
@section('page_title', 'Monitoring Real-Time')
@section('page_subtitle', 'Grafik 5 parameter sensor panel surya dengan pembaruan otomatis tanpa refresh halaman')

@section('content')
    @include('monitoring._charts-grid')
@endsection

@push('scripts')
    @include('monitoring._charts-script')
@endpush
