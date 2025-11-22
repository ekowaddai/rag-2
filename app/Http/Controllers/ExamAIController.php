<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ExamAIController extends Controller
{
    protected string $model = 'llama3.2:1b';
    // protected string $model = 'gemma3:1b';

    public function index()
    {
        return view('exam-ai');
    }

    
    // ---------------------------
    // Helper: Generate safe SQL from question
    // ---------------------------
    protected function generateSafeSql(string $question): ?string
    {
        // - Return only the SQL query, no explanations.
        $prompt = <<<EOT
You are an expert SQL generator. 

Given a user question, write a **single, safe SELECT query** for the MySQL table `exam_results`. 

Rules:
- Use **only SELECT** statements.
- Do NOT modify the table (no UPDATE, DELETE, INSERT, DROP, ALTER, TRUNCATE, etc.).
- Use exact column names: 
  id, IndexNumber, Surname, OtherName, Category, Subject, ExamMark, ExamStatus, Institution, ExamName, created_at, updated_at
- Return the SQL query, with explanations
- Use LIKE for count and count disctinct(IndexNumber) when asked to count students
- If the question cannot be answered with this table, return:
  SELECT 'I do not know' AS message;

User question: "$question"

Write ONLY the SQL query.
EOT;

        $response = Http::timeout(120)->post('http://localhost:11434/api/generate', [
            'model'  => $this->model,
            'prompt' => $prompt,
            'stream' => false,
        ]);

        if ($response->failed()) {
            return null;
        }

        $raw = $response->body();

        try {
            $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $sql = $payload['response'] ?? null;
        } catch (\Throwable $e) {
            $sql = trim($raw);
        }

        // ---------------------------
        // SQL Safety Check
        // ---------------------------
        if (!$sql || !preg_match('/^\s*SELECT/i', $sql)) {
            return null; // invalid SQL
        }

        $forbidden = ['UPDATE', 'DELETE', 'INSERT', 'DROP', 'ALTER', 'TRUNCATE', 'REPLACE'];
        foreach ($forbidden as $word) {
            if (preg_match('/\b' . $word . '\b/i', $sql)) {
                return null; // unsafe SQL
            }
        }

        return $sql;
    }

    public function ask(Request $request)
    {
        $request->validate([
            'question' => 'required|string|max:5000',
        ]);

        $question = $request->question;

        // ---------------------------
        // 1. Generate safe SQL from user question
        // ---------------------------
        $sql = $this->generateSafeSql($question);

        dump($sql);

        if (!$sql) {
            return view('exam-ai', [
                'question' => $question,
                'answer'   => "Could not generate a safe SQL query for your question.",
                'context'  => '',
            ]);
        }

        // ---------------------------
        // 2. Execute SQL safely
        // ---------------------------
        try {
            $results = DB::select($sql);
            // dd($results);
        } catch (\Throwable $e) {
            return view('exam-ai', [
                'question' => $question,
                'answer'   => "Failed to execute SQL: " . $e->getMessage(),
                'context'  => '',
            ]);
        }

        // ---------------------------
// 3. Build context for RAG (flexible)
// ---------------------------
if (empty($results)) {
    $context = "No related records found.";
} else {
    $contextLines = [];

    foreach ($results as $row) {
        // Convert stdClass to array
        $rowArray = (array) $row;

        // Build a readable line dynamically
        $lineParts = [];
        foreach ($rowArray as $column => $value) {
            $lineParts[] = "$column: $value";
        }

        $contextLines[] = implode(", ", $lineParts);
    }

    $context = implode("\n", $contextLines);
}

        // // ---------------------------
        // // 3. Build context for RAG
        // // ---------------------------
        // $context = empty($results)
        //     ? "No related records found."
        //     : collect($results)->map(function($r) {
        //         return "Index: {$r->IndexNumber}, Name: {$r->Surname} {$r->OtherName}, "
        //              . "Subject: {$r->Subject}, Mark: {$r->ExamMark}, Status: {$r->ExamStatus}, "
        //              . "Institution: {$r->Institution}, Exam: {$r->ExamName}";
        //     })->implode("\n");


        // ---------------------------
        // 4. Send to Ollama AI
        // ---------------------------
        $prompt = "
You are an AI assistant answering questions using exam record data.

Question:
$question

Relevant database records:
$context

Answer clearly using only the information above.
";

        $aiResponse = Http::timeout(120)->post('http://localhost:11434/api/generate', [
            'model'  => $this->model,
            'prompt' => $prompt,
            'stream' => false,
        ]);

        if ($aiResponse->failed()) {
            return view('exam-ai', [
                'question' => $question,
                'answer'   => "Error: Ollama request failed.",
                'context'  => $context,
            ]);
        }

        // ---------------------------
        // 5. Handle JSON or streamed response
        // ---------------------------
        $raw = $aiResponse->body();

        try {
            $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $answer = $payload['response'] ?? trim($raw);
        } catch (\Throwable $e) {
            $answer = trim($raw);
        }

        if (!$answer) {
            $answer = "AI did not return a response.\nRaw: " . $raw;
        }

        return view('exam-ai', [
            'question' => $question,
            'answer'   => $answer,
            'context'  => $context,
        ]);
    }

}
