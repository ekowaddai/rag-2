<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TextToSqlService
{
    protected string $model = 'llama3.2:1b';

    /**
     * Step 1: Ask Ollama to generate a SAFE SQL query from natural language.
     */
    public function generateSql(string $question): ?string
    {
        $prompt = <<<EOT
You are an expert MySQL query generator.

Your job is to convert a user question into a **single, safe, SELECT-only SQL query**.

RULES:
- Return ONLY the SQL query.
- No explanations, comments, markdown, formatting or text.
- The query MUST start with "SELECT".
- Never include UPDATE, DELETE, INSERT, DROP, ALTER, TRUNCATE or any modification statement.
- Use EXACT table and column names.

DATABASE SCHEMA:
Table: exam_results
Columns:
- id
- IndexNumber
- Surname
- OtherName
- Category
- Subject
- ExamMark
- ExamStatus
- Institution
- ExamName
- created_at
- updated_at

If the question cannot be answered with this table, return:
SELECT 'I do not know' AS message;

USER QUESTION: "$question"

Write ONLY the SQL query:
EOT;

        $response = Http::post('http://localhost:11434/api/generate', [
            'model'  => $this->model,
            'prompt' => $prompt,
            'stream' => false,
        ]);

        if ($response->failed()) {
            return null;
        }

        $sql = trim($response->json()['response'] ?? '');

        return $sql;
    }


    /**
     * Step 2: Validate SQL before execution.
     */
    public function validateSql(string $sql): bool
    {
        // Allow only SELECT statements at the beginning (ignore whitespace)
        if (!preg_match('/^\s*SELECT/i', $sql)) {
            return false;
        }

        // Reject dangerous SQL keywords
        $forbidden = ['UPDATE', 'DELETE', 'INSERT', 'DROP', 'ALTER', 'TRUNCATE', 'REPLACE'];
        foreach ($forbidden as $word) {
            if (preg_match('/\b' . $word . '\b/i', $sql)) {
                return false;
            }
        }

        return true;
    }


    /**
     * Step 3: Execute the SQL query safely.
     */
    public function executeSql(string $sql)
    {
        if (!$this->validateSql($sql)) {
            return [
                ['error' => "Unsafe SQL blocked.", 'sql' => $sql]
            ];
        }

        try {
            return DB::select($sql);
        } catch (\Throwable $e) {
            return [
                ['error' => "SQL execution failed: " . $e->getMessage()]
            ];
        }
    }


    /**
     * Step 4: Explain SQL results using Ollama.
     */
    public function explainResults(string $question, $results): string
    {
        $jsonResult = json_encode($results, JSON_PRETTY_PRINT);

        $prompt = <<<EOT
You are a data analyst.

Below are the results of an SQL query executed on the exam_results table.

QUESTION:
$question

SQL RESULT (JSON):
$jsonResult

Explain the result clearly in plain language.
If the result contains an error, explain the error politely.
EOT;

        $response = Http::post('http://localhost:11434/api/generate', [
            'model'  => $this->model,
            'prompt' => $prompt,
            'stream' => false,
        ]);

        if ($response->failed()) {
            return "Error: Failed to generate explanation from Ollama.";
        }

        return $response->json()['response'] ?? 'No explanation returned.';
    }


    /**
     * Step 5: Full pipeline — Question → SQL → DB → Answer
     */
    public function ask(string $question): array
    {
        // Generate SQL
        $sql = $this->generateSql($question);

        if (!$sql) {
            return [
                'sql' => null,
                'results' => null,
                'answer' => "Failed to generate SQL query."
            ];
        }

        // Execute it
        $results = $this->executeSql($sql);

        // Explain result
        $answer = $this->explainResults($question, $results);

        return [
            'sql'     => $sql,
            'results' => $results,
            'answer'  => $answer,
        ];
    }
}
