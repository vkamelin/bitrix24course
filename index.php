<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/questions.php';

const QUESTIONS_PER_TEST = 10;

if (!isset($questions) || !is_array($questions)) {
    http_response_code(500);
    echo 'Ошибка: массив вопросов не найден.';
    exit;
}

if (count($questions) < QUESTIONS_PER_TEST) {
    http_response_code(500);
    echo 'Ошибка: недостаточно вопросов для теста.';
    exit;
}

/**
 * @param array<string, mixed> $state
 */
function saveTestState(array $state): void
{
    $_SESSION['test'] = $state;
}

/**
 * @return array<string, mixed>
 */
function getTestState(): array
{
    return is_array($_SESSION['test'] ?? null) ? $_SESSION['test'] : [];
}

/**
 * @return array<int, string>
 */
function selectQuestionIndexes(int $totalQuestions, int $limit): array
{
    $indexes = array_map('strval', range(0, $totalQuestions - 1));
    shuffle($indexes);

    return array_slice($indexes, 0, $limit);
}

/**
 * @param array<int, string> $selectedIndexes
 * @param array<string, string> $answers
 * @param array<int, array<string, mixed>> $allQuestions
 *
 * @return array{correct:int,incorrect:int,percent:float,total:int}
 */
function calculateResult(array $selectedIndexes, array $answers, array $allQuestions): array
{
    $correctCount = 0;

    foreach ($selectedIndexes as $position => $questionIndex) {
        $userAnswer = $answers[$position] ?? null;

        if ($userAnswer === null) {
            continue;
        }

        $question = $allQuestions[(int) $questionIndex] ?? null;

        if (is_array($question) && ($question['correct'] ?? '') === $userAnswer) {
            $correctCount++;
        }
    }

    $total = count($selectedIndexes);
    $incorrect = $total - $correctCount;
    $percent = $total > 0 ? round(($correctCount / $total) * 100, 2) : 0.0;

    return [
        'correct' => $correctCount,
        'incorrect' => $incorrect,
        'percent' => $percent,
        'total' => $total,
    ];
}

function generateFormToken(): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['form_token'] = $token;

    return $token;
}

function verifyFormToken(string $token): bool
{
    $sessionToken = $_SESSION['form_token'] ?? null;

    if (!is_string($sessionToken) || !hash_equals($sessionToken, $token)) {
        return false;
    }

    unset($_SESSION['form_token']);

    return true;
}

$errors = [];
$action = $_POST['action'] ?? null;

if ($action === 'restart') {
    unset($_SESSION['test'], $_SESSION['form_token']);
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim((string) ($_POST['form_token'] ?? ''));

    if (!verifyFormToken($token)) {
        $errors[] = 'Форма уже была отправлена или токен недействителен. Попробуйте ещё раз.';
    } else {
        if ($action === 'start') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $fullName = preg_replace('/\s+/u', ' ', $fullName) ?? '';

            if ($fullName === '') {
                $errors[] = 'Укажите ФИО для начала теста.';
            } elseif (mb_strlen($fullName) > 150) {
                $errors[] = 'ФИО слишком длинное.';
            } else {
                $selectedIndexes = selectQuestionIndexes(count($questions), QUESTIONS_PER_TEST);

                saveTestState([
                    'full_name' => $fullName,
                    'selected_indexes' => $selectedIndexes,
                    'current_question' => 0,
                    'answers' => [],
                    'completed' => false,
                    'result' => null,
                ]);

                header('Location: index.php');
                exit;
            }
        }

        if ($action === 'answer') {
            $state = getTestState();

            if (($state['completed'] ?? false) === true) {
                header('Location: index.php');
                exit;
            }

            $selectedAnswer = strtoupper(trim((string) ($_POST['answer'] ?? '')));
            $validAnswers = ['A', 'B', 'C', 'D'];

            if (!in_array($selectedAnswer, $validAnswers, true)) {
                $errors[] = 'Выберите один вариант ответа, чтобы продолжить.';
            } elseif (!isset($state['selected_indexes'], $state['current_question'], $state['answers'])) {
                $errors[] = 'Сессия теста не найдена. Начните заново.';
            } else {
                $currentQuestion = (int) $state['current_question'];
                $selectedIndexes = $state['selected_indexes'];
                $answers = $state['answers'];

                if (!array_key_exists($currentQuestion, $selectedIndexes)) {
                    $errors[] = 'Некорректный номер вопроса. Начните тест заново.';
                } else {
                    $answers[(string) $currentQuestion] = $selectedAnswer;
                    $nextQuestion = $currentQuestion + 1;

                    if ($nextQuestion >= QUESTIONS_PER_TEST) {
                        $result = calculateResult($selectedIndexes, $answers, $questions);

                        $state['answers'] = $answers;
                        $state['current_question'] = QUESTIONS_PER_TEST;
                        $state['completed'] = true;
                        $state['result'] = $result;

                        saveTestState($state);

                        header('Location: index.php');
                        exit;
                    }

                    $state['answers'] = $answers;
                    $state['current_question'] = $nextQuestion;
                    saveTestState($state);

                    header('Location: index.php');
                    exit;
                }
            }
        }
    }
}

$state = getTestState();
$screen = 'start';
$currentQuestionData = null;
$currentQuestionNumber = 0;

if (($state['completed'] ?? false) === true && isset($state['result'], $state['full_name'])) {
    $screen = 'result';
} elseif (isset($state['selected_indexes'], $state['current_question'], $state['full_name']) && is_array($state['selected_indexes'])) {
    $screen = 'question';

    $currentQuestionNumber = (int) $state['current_question'];
    $questionIndex = (int) ($state['selected_indexes'][$currentQuestionNumber] ?? -1);

    if (!isset($questions[$questionIndex]) || !is_array($questions[$questionIndex])) {
        unset($_SESSION['test']);
        $screen = 'start';
    } else {
        $currentQuestionData = $questions[$questionIndex];
    }
}

$formToken = generateFormToken();

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тест по Битрикс24</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }

            .no-print {
                display: none !important;
            }

            .print-card {
                box-shadow: none !important;
                border: 1px solid #d1d5db !important;
                margin: 0 !important;
                max-width: 100% !important;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 p-4 sm:p-6 lg:p-8 text-slate-900">
<div class="max-w-3xl mx-auto">
    <?php if ($screen === 'start'): ?>
        <section class="bg-white rounded-2xl shadow-xl border border-slate-200 p-6 sm:p-8">
            <h1 class="text-2xl sm:text-3xl font-bold mb-3">Тестирование сотрудников по Битрикс24</h1>
            <p class="text-slate-600 mb-6">Введите ФИО и начните тест.</p>

            <?php if ($errors !== []): ?>
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-red-700">
                    <?php foreach ($errors as $error): ?>
                        <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-4" novalidate>
                <input type="hidden" name="action" value="start">
                <input type="hidden" name="form_token" value="<?= htmlspecialchars($formToken, ENT_QUOTES, 'UTF-8') ?>">

                <label class="block">
                    <span class="block mb-2 font-semibold text-slate-800">ФИО сотрудника</span>
                    <input
                        type="text"
                        name="full_name"
                        maxlength="150"
                        required
                        autocomplete="name"
                        class="w-full rounded-xl border border-slate-300 px-4 py-3 text-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="Иванов Иван Иванович"
                    >
                </label>

                <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center rounded-xl bg-blue-600 px-6 py-3 text-white font-semibold hover:bg-blue-700 transition-colors">
                    Начать
                </button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($screen === 'question' && is_array($currentQuestionData)): ?>
        <?php
            $progress = $currentQuestionNumber + 1;
            $progressPercent = (int) round(($progress / QUESTIONS_PER_TEST) * 100);
        ?>
        <section class="bg-white rounded-2xl shadow-xl border border-slate-200 p-6 sm:p-8">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-slate-500 text-sm sm:text-base">Сотрудник: <span class="font-semibold text-slate-800"><?= htmlspecialchars((string) $state['full_name'], ENT_QUOTES, 'UTF-8') ?></span></p>
                <p class="text-sm sm:text-base font-medium">Вопрос <?= $progress ?> из <?= QUESTIONS_PER_TEST ?></p>
            </div>

            <div class="w-full bg-slate-200 rounded-full h-3 mb-6" aria-hidden="true">
                <div class="bg-blue-600 h-3 rounded-full" style="width: <?= $progressPercent ?>%"></div>
            </div>

            <?php if ($errors !== []): ?>
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-red-700">
                    <?php foreach ($errors as $error): ?>
                        <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h2 class="text-xl sm:text-2xl font-semibold mb-6 leading-relaxed">
                <?= htmlspecialchars((string) ($currentQuestionData['question'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </h2>

            <form method="post" id="question-form" class="space-y-4" novalidate>
                <input type="hidden" name="action" value="answer">
                <input type="hidden" name="form_token" value="<?= htmlspecialchars($formToken, ENT_QUOTES, 'UTF-8') ?>">

                <?php foreach ((array) ($currentQuestionData['answers'] ?? []) as $code => $answerText): ?>
                    <label class="flex gap-3 items-start rounded-xl border border-slate-300 p-4 cursor-pointer hover:border-blue-400 transition-colors">
                        <input type="radio" name="answer" value="<?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?>" class="mt-1 h-5 w-5 text-blue-600" required>
                        <span class="text-base sm:text-lg leading-snug">
                            <span class="font-semibold mr-2"><?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?>.</span>
                            <?= htmlspecialchars((string) $answerText, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </label>
                <?php endforeach; ?>

                <p id="client-error" class="hidden text-red-700 font-medium">Выберите вариант ответа, чтобы перейти дальше.</p>

                <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center rounded-xl bg-blue-600 px-6 py-3 text-white font-semibold hover:bg-blue-700 transition-colors">
                    Далее
                </button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($screen === 'result' && isset($state['result']) && is_array($state['result'])): ?>
        <?php
            $result = $state['result'];
            $percentText = number_format((float) ($result['percent'] ?? 0), 2, '.', '');
        ?>
        <section class="print-card bg-white rounded-2xl shadow-xl border border-slate-200 p-6 sm:p-8">
            <header class="mb-6 border-b border-slate-200 pb-4">
                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Результат тестирования по Битрикс24</h1>
                <p class="text-lg">Сотрудник: <span class="font-semibold"><?= htmlspecialchars((string) $state['full_name'], ENT_QUOTES, 'UTF-8') ?></span></p>
                <p class="text-sm text-slate-500">Дата: <?= date('d.m.Y H:i') ?></p>
            </header>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-base sm:text-lg">
                <div class="rounded-xl border border-slate-200 p-4 bg-slate-50">
                    <p class="text-slate-500">Всего вопросов</p>
                    <p class="text-2xl font-bold"><?= (int) ($result['total'] ?? 0) ?></p>
                </div>
                <div class="rounded-xl border border-green-200 p-4 bg-green-50">
                    <p class="text-green-700">Правильных ответов</p>
                    <p class="text-2xl font-bold text-green-700"><?= (int) ($result['correct'] ?? 0) ?></p>
                </div>
                <div class="rounded-xl border border-red-200 p-4 bg-red-50">
                    <p class="text-red-700">Неправильных ответов</p>
                    <p class="text-2xl font-bold text-red-700"><?= (int) ($result['incorrect'] ?? 0) ?></p>
                </div>
                <div class="rounded-xl border border-blue-200 p-4 bg-blue-50">
                    <p class="text-blue-700">Процент результата</p>
                    <p class="text-2xl font-bold text-blue-700"><?= $percentText ?>%</p>
                </div>
            </div>

            <div class="mt-8 flex flex-wrap gap-3 no-print">
                <button type="button" onclick="window.print()" class="inline-flex items-center justify-center rounded-xl bg-slate-800 px-6 py-3 text-white font-semibold hover:bg-slate-900 transition-colors">
                    Печать
                </button>

                <form method="post">
                    <input type="hidden" name="action" value="restart">
                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-blue-600 px-6 py-3 text-white font-semibold hover:bg-blue-700 transition-colors">
                        Пройти заново
                    </button>
                </form>
            </div>
        </section>
    <?php endif; ?>
</div>

<script>
    (function () {
        const form = document.getElementById('question-form');

        if (!form) {
            return;
        }

        const clientError = document.getElementById('client-error');

        form.addEventListener('submit', function (event) {
            const selected = form.querySelector('input[name="answer"]:checked');

            if (selected) {
                if (clientError) {
                    clientError.classList.add('hidden');
                }
                return;
            }

            event.preventDefault();

            if (clientError) {
                clientError.classList.remove('hidden');
            }
        });
    })();
</script>
</body>
</html>
