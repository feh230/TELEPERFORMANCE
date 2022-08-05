<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class teleperformance extends Controller
{
    public function index(Request $request) {

    $call_id = $request->call_id;
    
    $url = URL::temporarySignedRoute(
        'show',
        now()->addMinutes(3), ['call_id'=>$call_id]
    );
    
    return redirect($url);
}
}
