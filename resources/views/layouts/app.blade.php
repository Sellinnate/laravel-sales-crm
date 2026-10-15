<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) && $title !== '' ? $title.' · ' : '' }}{{ config('sales-crm.ui.brand', 'Sales CRM') }}</title>
    <x-sales-crm::styles />
    @livewireStyles
</head>
<body class="h-full" style="margin: 0">
    {{ $slot }}
    @livewireScripts
</body>
</html>
