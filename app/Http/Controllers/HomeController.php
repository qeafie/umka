<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Home', [
            'emergencyGuides' => config('emergency-guides'),
            'resident' => $request->user() ? [
                'name' => $request->user()->name,
                'role' => $request->user()->role,
            ] : null,
        ]);
    }
}
