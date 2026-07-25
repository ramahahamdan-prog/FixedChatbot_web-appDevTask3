// ============================================================
// app.js — منطق الشات بوت الصوتي (يعمل في المتصفح)
// ============================================================

const micBtn = document.getElementById("micBtn");
const micIcon = document.getElementById("micIcon");
const chatLog = document.getElementById("chatLog");
const statusText = document.getElementById("statusText");

// عنوان الخادم الخلفي الذي يستدعي Gemini بأمان (انظر api/chat.php)
// مسار نسبي حتى يعمل تلقائيًا مهما كان اسم النطاق على الاستضافة
const BACKEND_URL = "api/chat.php";

// اللغة المستخدمة للتعرف على الصوت وللنطق
const LANG = "ar-SA";

let isListening = false;

// --------------------------------------------------------
// 1) إعداد التعرف على الصوت (Speech-to-Text)
// --------------------------------------------------------
const SpeechRecognitionAPI = window.SpeechRecognition || window.webkitSpeechRecognition;

if (!SpeechRecognitionAPI) {
  statusText.textContent = "متصفحك لا يدعم التعرف على الصوت. جرّب Chrome أو Edge.";
  micBtn.disabled = true;
} else {
  const recognition = new SpeechRecognitionAPI();
  recognition.lang = LANG;
  recognition.interimResults = false;
  recognition.maxAlternatives = 1;

  micBtn.addEventListener("click", () => {
    if (isListening) {
      recognition.stop();
      return;
    }
    try {
      recognition.start();
    } catch (err) {
      console.error("تعذر بدء الاستماع:", err);
    }
  });

  recognition.onstart = () => {
    isListening = true;
    micBtn.classList.add("listening");
    micIcon.textContent = "⏹️";
    statusText.textContent = "أستمع الآن... تحدّث بحرية";
  };

  recognition.onend = () => {
    isListening = false;
    micBtn.classList.remove("listening");
    micIcon.textContent = "🎤";
    statusText.textContent = "اضغط على الميكروفون وابدأ الحديث";
  };

  recognition.onerror = (event) => {
    console.error("خطأ في التعرف على الصوت:", event.error);
    statusText.textContent = "لم أستطع سماعك، حاول مرة أخرى";
  };

  recognition.onresult = async (event) => {
    const userText = event.results[0][0].transcript;
    if (!userText) return;

    addMessage("user", userText);
    const thinkingEl = addMessage("bot", "...يفكر", { thinking: true });

    try {
      const reply = await askGemini(userText);
      thinkingEl.remove();
      addMessage("bot", reply);
      speak(reply);
    } catch (err) {
      console.error(err);
      thinkingEl.remove();
      // نعرض رسالة الخطأ الفعلية القادمة من الخادم إن وجدت لتسهيل تشخيص المشكلة
      const msg = err && err.message ? err.message : "حدث خطأ أثناء الاتصال بالخادم. حاول مجددًا.";
      addMessage("bot", msg);
    }
  };
}

// --------------------------------------------------------
// 2) الاتصال بالخادم الخلفي الذي يستدعي Gemini
//    (لا نستدعي Gemini مباشرة من المتصفح لحماية مفتاح الـ API)
// --------------------------------------------------------
async function askGemini(prompt) {
  let res;
  try {
    res = await fetch(BACKEND_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ prompt }),
    });
  } catch (networkErr) {
    // فشل الشبكة نفسها (لا يوجد اتصال، أو مسار خاطئ، أو CORS)
    throw new Error("تعذر الوصول إلى الخادم. تأكد من رفع ملف api/chat.php على نفس النطاق.");
  }

  let data;
  try {
    data = await res.json();
  } catch (parseErr) {
    throw new Error(`رد الخادم لم يكن بصيغة JSON صالحة (HTTP ${res.status}).`);
  }

  if (!res.ok) {
    // نعرض رسالة الخطأ التي أرجعها chat.php بدلاً من رسالة عامة
    throw new Error(data.error || `فشل الطلب: ${res.status}`);
  }

  return data.reply || "لم يصل رد من الخادم.";
}

// --------------------------------------------------------
// 3) تحويل النص إلى كلام (Text-to-Speech)
// --------------------------------------------------------
function speak(text) {
  if (!("speechSynthesis" in window)) return;

  window.speechSynthesis.cancel(); // إيقاف أي نطق سابق
  const utterance = new SpeechSynthesisUtterance(text);
  utterance.lang = LANG;
  utterance.rate = 1;
  window.speechSynthesis.speak(utterance);
}

// --------------------------------------------------------
// أدوات مساعدة لواجهة الدردشة
// --------------------------------------------------------
function addMessage(role, text, opts = {}) {
  const el = document.createElement("div");
  el.className = `message ${role}${opts.thinking ? " thinking" : ""}`;
  const p = document.createElement("p");
  p.textContent = text;
  el.appendChild(p);
  chatLog.appendChild(el);
  chatLog.scrollTop = chatLog.scrollHeight;
  return el;
}
