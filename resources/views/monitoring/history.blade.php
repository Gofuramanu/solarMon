@extends('layouts.app')

@section('title', 'Grafik Historis | ' . $siteName)
@section('page_title', 'Grafik Historis')
@section('page_subtitle', 'Analisis visual tren tegangan, arus, daya, temperatur, dan intensitas cahaya berdasarkan periode atau tanggal')

@section('content')
    @include('monitoring._charts-grid')
@endsection

@push('scripts')
    @include('monitoring._charts-script')
@endpush
