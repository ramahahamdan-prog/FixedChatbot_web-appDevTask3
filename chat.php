<?php
// ============================================================
// api/chat.php — يستقبل النص من app.js ويستدعي Gemini API بأمان
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../config.php';

// اسمح فقط بطلبات POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'الطريقة غير مسموحة']);
    exit;
}

$rawInput = file_get_contents('php://input');
$input    = json_decode($rawInput, true);

// إصلاح: التحقق من أن الـ JSON القادم من الواجهة صالح فعلاً
// (سابقًا لم يكن هناك تحقق من json_last_error، فأي جسم غير صالح
// كان يمر بصمت وتكون $input = null، مما يسبب خطأ لاحقًا)
if ($rawInput === '' || json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'صيغة الطلب غير صالحة (JSON)']);
    exit;
}

$prompt = isset($input['prompt']) ? trim($input['prompt']) : '';

if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['error' => 'الرجاء إرسال نص صالح في الحقل prompt']);
    exit;
}

// إصلاح رئيسي: كان الشرط القديم يتحقق فقط من القيمة النصية الافتراضية
// 'ضع_مفتاحك_هنا'، لكن config.php يضبط المفتاح كسلسلة فارغة '' في الحالة
// الافتراضية. لذلك عندما يكون المفتاح فارغًا كان هذا الشرط يفشل في اكتشاف
// ذلك، وكان الكود يكمّل وينفّذ طلب cURL بمفتاح فارغ إلى Gemini، فيرجع
// Gemini بخطأ 400/403 ويحوّله chat.php إلى استجابة HTTP 502 — وهذا بالضبط
// ما كان يجعل الواجهة الأمامية تعرض رسالة "حدث خطأ أثناء الاتصال بالخادم".
if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '' || GEMINI_API_KEY === 'ضع_مفتاحك_هنا') {
    http_response_code(500);
    echo json_encode(['error' => 'لم يتم ضبط مفتاح Gemini في config.php بعد. افتح config.php وضع مفتاحك.']);
    exit;
}

// إصلاح: تم إيقاف موديل gemini-2.0-flash نهائيًا من قِبل Google بتاريخ
// 31 مارس 2026. أي طلب باسم هذا الموديل يُرفض الآن من Gemini API مباشرة
// (يظهر كخطأ "رفض Gemini API الطلب" في الواجهة). تم استبداله بموديل مدعوم حاليًا.
$model = 'gemini-3.6-flash';
$url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;

$body = json_encode([
    'contents' => [
        ['parts' => [['text' => $prompt]]],
    ],
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErrNo = curl_errno($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

// بعض حسابات الاستضافة المجانية تُظهر خطأ SSL (cURL error 60).
// إذا واجهت هذا الخطأ فعّل السطرين التاليين مؤقتًا (غير موصى به على المدى الطويل):
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
// curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

if ($response === false) {
    // إصلاح: تسجيل تفاصيل الخطأ في سجل الخادم لتسهيل التشخيص لاحقًا
    error_log("chat.php cURL error #$curlErrNo: $curlErr");
    http_response_code(502);
    echo json_encode(['error' => 'فشل الاتصال بـ Gemini API: ' . $curlErr]);
    exit;
}

$data = json_decode($response, true);

if ($httpCode >= 400) {
    error_log("chat.php Gemini HTTP $httpCode: " . $response);
    http_response_code(502);
    echo json_encode(['error' => 'رفض Gemini API الطلب', 'details' => $data]);
    exit;
}

// إصلاح: بعض الردود قد تُحجب بسبب سياسات الأمان (finishReason = SAFETY)
// وفي هذه الحالة لا يوجد candidates[0].content، فنعرض رسالة واضحة بدل نص فارغ
$reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

if ($reply === null) {
    $finishReason = $data['candidates'][0]['finishReason'] ?? 'unknown';
    error_log("chat.php: no reply text, finishReason=$finishReason, raw=" . $response);
    echo json_encode(['reply' => 'تعذر الحصول على رد من Gemini (السبب: ' . $finishReason . ').'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['reply' => $reply], JSON_UNESCAPED_UNICODE);
