<?php

use Illuminate\Support\Facades\Route;

test('web routes only expose the sanctum csrf-cookie endpoint', function () {
    $webRoutes = collect(Route::getRoutes())
        ->filter(fn ($route) => in_array('web', $route->gatherMiddleware(), true))
        ->values();

    expect($webRoutes)->toHaveCount(1);
    expect($webRoutes->first()->uri())->toBe('sanctum/csrf-cookie');
});

test('analyze route is registered and reachable as POST', function () {
    $route = Route::getRoutes()->match(
        \Illuminate\Http\Request::create('/api/complaints/analyze', 'POST')
    );

    expect($route->getActionName())
        ->toBe('App\\Http\\Controllers\\ComplaintAIController@analyze');
});

test('complaints resource routes are registered', function () {
    $showRoute = Route::getRoutes()->match(
        \Illuminate\Http\Request::create('/api/complaints/1', 'GET')
    );

    expect($showRoute->getActionName())
        ->toBe('App\\Http\\Controllers\\ComplaintController@show');
});
