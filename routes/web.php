<?php

use App\Http\Controllers\TeleperformanceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


// Route::get('/', function () {
//     return view('welcome');
// });

Route::get('/{call_id}', function(Request $request) {
    $call_id = $request->call_id;
        
    $url = URL::temporarySignedRoute(
        'show',
        now()->addMinutes(5), ['call_id'=>$call_id]
    );
    
    return redirect($url);

})->name('index');

Route::get('/{call_id}/show', function (Request $request) {
    $call_id = $request->call_id;
    
    if (!$request->hasValidSignature()) {
        abort(401);
    }

    return view('index', compact('call_id'));
    
})->name('show');