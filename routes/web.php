<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ExamAIController;

// use Illuminate\Support\Facades\Http;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/exam-ai', [ExamAIController::class, 'index'])->name('exam.ai');
Route::post('/exam-ai', [ExamAIController::class, 'ask'])->name('exam.ai.ask');

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

Route::get('/test-ai', function () {
    // Fetch a sample
    $records = DB::table('exam_results')->limit(2)->get();

    $context = $records->map(function ($r) {
        return "Index: {$r->IndexNumber}, Name: {$r->Surname} {$r->OtherName}, "
             . "Subject: {$r->Subject}, Mark: {$r->ExamMark}, "
             . "Status: {$r->ExamStatus}";
    })->implode("\n");

    $prompt = "
You are testing the AI exam assistant.
Use the following sample data to produce a summary.

$context
";

    $response = Http::post('http://localhost:11434/api/generate', [
        'model'     => 'llama3',
        'prompt'    => $prompt,
    ]);

    return [
        'sample_data' => $records,
        'ai_response' => $response->json('response'),
    ];
});
