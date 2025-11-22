<!DOCTYPE html>
<html>
<head>
    <title>AI Exam Assistant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light p-4">

<div class="container">
    <h2 class="mb-4">AI Exam Results Assistant</h2>

    <form method="POST" action="{{ route('exam.ai.ask') }}">
        @csrf

        <textarea
            name="question"
            rows="3"
            class="form-control mb-3 @error('question') is-invalid @enderror"
            placeholder="Ask a question, e.g., 'What was the mark of index number 123456 in Biology?'"
        >{{ old('question', $question ?? '') }}</textarea>

        @error('question')
            <div class="text-danger small">{{ $message }}</div>
        @enderror

        <button class="btn btn-primary">Ask AI</button>
    </form>

    @isset($answer)
        <div class="card mt-4">
            <div class="card-header">
                <strong>AI Response</strong>
            </div>
            <div class="card-body">
                <p>{{ $answer }}</p>
            </div>
        </div>
    @endisset

    @isset($context)
        <div class="card mt-4">
            <div class="card-header">
                <strong>Matched Database Context</strong>
            </div>
            <div class="card-body">
                <pre>{{ $context }}</pre>
            </div>
        </div>
    @endisset
</div>

</body>
</html>
