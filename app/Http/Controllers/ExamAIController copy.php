<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ExamAIController extends Controller
{
    public function index()
    {
        return view('exam-ai');
    }

    public function ask(Request $request)
    {
        $request->validate([
            'question' => 'required|string|max:5000',
        ]);

        $question = $request->question;

        // ---------------------------
        // 1. Extract keywords using preg_match
        // ---------------------------

        $keywords = [];

        // Extract index number (e.g., 12345678)
        if (preg_match('/\b(\d{6,})\b/', $question, $matches)) {
            $keywords['IndexNumber'] = $matches[1];
        }

        // Extract subject names (simple example)
        if (preg_match('/\b(Math|Science|Biology|Chemistry|English|Nursing|Midwifery)\b/i', $question, $matches)) {
            $keywords['Subject'] = ucfirst(strtolower($matches[1]));
        }

        // Extract surname
        if (preg_match('/surname (\w+)/i', $question, $matches)) {
            $keywords['Surname'] = ucfirst(strtolower($matches[1]));
        }

        // ---------------------------
        // 2. Query database using extracted keywords
        // ---------------------------

        $query = DB::table('exam_results');

        foreach ($keywords as $column => $value) {
            $query->where($column, $value);
        }

        $results = $query->limit(10)->get();

        // Build context for RAG
        $context = $results->isEmpty()
            ? "No related records found."
            : $results->map(function ($r) {
                return "Index: {$r->IndexNumber}, Name: {$r->Surname} {$r->OtherName}, "
                     . "Subject: {$r->Subject}, Mark: {$r->ExamMark}, Status: {$r->ExamStatus}, "
                     . "Institution: {$r->Institution}, Exam: {$r->ExamName}";
            })->implode("\n");

        // ---------------------------
        // 3. Send to local Ollama model (or any HTTP endpoint)
        // ---------------------------

        $prompt = "
You are an AI assistant answering questions using exam record data.

Question:
$question

Relevant database records:
$context

Answer clearly using only the information above.
";

        $aiResponse = Http::post('http://localhost:11434/api/generate', [
            'model' => 'llama3.2:1b',
            'prompt' => $prompt,
            // 'stream' => false
        ]);
dump($aiResponse);
        if ($aiResponse->failed()) {
            return "Error: Ollama request failed";
        }

        $payload = $aiResponse->json();

        // Fix: Ensure we get the final text response
        $text = $payload['response'] ?? null;

        if (!$text) {
            // Debug mode output
            return "AI did not return a response.\nRaw: " . json_encode($payload);
        }

        $answer = $aiResponse->json('response') ?? "AI did not return a response.";

        return view('exam-ai', [
            'question' => $question,
            'answer'   => $answer,
            'context'  => $context,
        ]);
    }

}
