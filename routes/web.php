<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/jobs'));
Route::get('/jobs', fn () => view('jobs'));
