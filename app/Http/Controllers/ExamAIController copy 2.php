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
/*
        // Extract subject names (simple example)
        if (preg_match('/\b(Math|Science|Biology|Chemistry|English|Nursing|Midwifery)\b/i', $question, $matches)) {
            $keywords['Subject'] = ucfirst(strtolower($matches[1]));
        }
*/
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

        $results = $query->limit(100)->get();
        // $results = $query->get(); // NO LIMIT

        // Build context
        $context = $results->isEmpty()
            ? "No related records found."
            : $results->map(function ($r) {
                return "Index: {$r->IndexNumber}, Name: {$r->Surname} {$r->OtherName}, "
                     . "Subject: {$r->Subject}, Mark: {$r->ExamMark}, Status: {$r->ExamStatus}, "
                     . "Institution: {$r->Institution}, Exam: {$r->ExamName}";
            })->implode("\n");

        // ---------------------------
        // 3. Send to Ollama
        // ---------------------------

        $prompt = "
            You are an AI assistant answering questions using exam record data.

            Question:
            $question

            Relevant database records:
            $context

            Answer clearly using only the information above.
            ";

        // $response = Http::post('http://localhost:11434/api/generate', [
        $response = Http::timeout(180)->post('http://localhost:11434/api/generate', [
            'model'  => 'llama3.2:1b',
            'prompt' => $prompt,
            'stream' => false, // important
        ]);

        // Debug (optional)
        // dump($response);

        if ($response->failed()) {
            return "Error: Ollama request failed";
        }

        // ---------------------------
        // FIXED SECTION: handle streaming or JSON both
        // ---------------------------

        $raw = $response->body(); // ALWAYS get raw body first

        try {
            // Try decode as JSON
            $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $text = $payload['response'] ?? null;
        } catch (\Throwable $e) {
            // Fallback: streamed plain text
            $text = trim($raw);
        }

        if (!$text) {
            return "AI did not return a response.\nRaw: " . $raw;
        }

        // Remove duplicate json() call
        $answer = $text;

        return view('exam-ai', [
            'question' => $question,
            'answer'   => $answer,
            'context'  => $context,
        ]);
    }
}
