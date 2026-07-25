# FixedChatbot_web-appDevTask3
# Voice Assistant (Voice Chatbot powered by Gemini API)

## Overview

A browser-based voice assistant. The user speaks into the microphone, the
browser converts speech to text, that text is sent to a PHP backend which
securely calls Google's Gemini API (the API key is never exposed to the
browser), and the reply is displayed in the chat log and read aloud using
text-to-speech.

## Features

- Voice input via the browser's built-in Speech-to-Text (no external
  library needed)
- Text-to-Speech playback of the assistant's reply
- Arabic-first UI (RTL layout, `ar-SA` language for recognition/speech)
- API key kept server-side only, never sent to or stored in the browser
- Visual "listening" and "thinking" states in the chat UI
- Works on any PHP hosting — no Node.js required

## Technologies Used

| Layer | Technology |
|---|---|
| Frontend structure | HTML5 |
| Styling | CSS3 (custom properties / variables) |
| Frontend logic | Vanilla JavaScript — Web Speech API (`SpeechRecognition`, `SpeechSynthesis`), `fetch` |
| Backend | PHP 7.4+ with cURL |
| AI model | Google Gemini API (`generateContent` endpoint, `gemini-flash-latest` alias) |
| Security | `.htaccess` (blocks direct access to `config.php`), `.gitignore` (keeps the real API key out of GitHub) |

## How It Works

1. The user clicks the microphone button. `app.js` starts the browser's
   `SpeechRecognition` and shows a "listening" state.
2. Once speech ends, the browser transcribes it to text and `app.js` posts
   that text as JSON to `api/chat.php` via `fetch`.
3. `chat.php` validates the request, reads the Gemini API key from
   `config.php`, and forwards the prompt to Google's Gemini API
   (`generateContent`) over cURL.
4. Gemini's reply is extracted from the JSON response and sent back to the
   browser as `{ "reply": "..." }`.
5. `app.js` displays the reply as a chat bubble and speaks it out loud using
   `SpeechSynthesis`.

```
Browser (mic) → SpeechRecognition → fetch(POST) → chat.php → cURL → Gemini API
                                                      ↓
Browser (chat bubble + voice) ← JSON reply ← chat.php ← Gemini API response
```

## What the Fix Is

Testing the original code surfaced a chain of real, separate bugs — each
one masked by the next until it was tested directly against Google's API:

1. **Broken API key check.** The original condition only compared the key
   against the placeholder text `'ضع_مفتاحك_هنا'`, but the default key in
   `config.php` was an **empty string**. An empty key slipped past the
   check, so the code called Gemini with no key at all, Gemini rejected it,
   and `chat.php` returned a generic 502 — which the frontend showed as
   "server connection error", hiding the real cause.
   *Fix:* the check now also rejects an empty string.

2. **No validation of the incoming JSON body.** A malformed request body
   silently became `null` and failed later with no clear error.
   *Fix:* added `json_last_error()` validation with a clear 400 response.

3. **No server-side error logging.** Failures gave no trail to investigate.
   *Fix:* added `error_log()` calls on cURL failures and Gemini rejections.

4. **Blocked/safety-filtered Gemini replies** produced a blank or unclear
   result. *Fix:* the code now detects a missing reply and reports the
   `finishReason` explicitly.

5. **Deprecated model name.** `gemini-2.0-flash` was retired by Google on
   March 31, 2026, causing every request to be rejected. It was updated to
   `gemini-2.5-flash`, but direct testing then showed that model is also no
   longer available to new API users (Google returns an explicit 404 for
   it). *Final fix:* switched to Google's rolling alias
   `gemini-flash-latest`, which always points to the current stable Flash
   model (currently `gemini-3.6-flash`, released July 21, 2026) — so this
   class of failure won't recur every time Google retires a model.

6. **Outdated `.htaccess` syntax.** `Order Allow,Deny` / `Deny from all` is
   Apache 2.2 syntax; on Apache 2.4 hosts without `mod_access_compat` it can
   throw a 500 error for the whole folder. *Fix:* rewritten with
   `<IfModule>` to support both Apache versions.

## Improvements

- `app.js` now surfaces the **actual error message** returned by
  `chat.php` (including Gemini's raw `details` field) instead of one
  generic message for every failure, making issues diagnosable from the
  chat window itself.
- Added `config.example.php` and `.gitignore` so the real API key is never
  committed to a public GitHub repository.
- Documented full deployment steps for both local (XAMPP/WAMP) and live
  hosting environments.

## Challenges

- **Silent failures gave no clear signal.** Several of the bugs above (the
  key check, the deprecated model) all produced the *same* generic
  front-end error message, so the real cause had to be isolated by testing
  the Gemini API directly with `curl`/`Invoke-RestMethod` outside the app,
  bypassing the PHP layer entirely.
- **Fast-moving API surface.** Google retires and renames Gemini models
  frequently (two model names became invalid during this same debugging
  session), which is why the final fix uses a rolling alias instead of a
  pinned model name.
- **Cross-platform terminal differences.** Verifying the API key directly
  required adapting the test command between Command Prompt, PowerShell,
  and an online `curl` tool, since quoting/escaping rules differ between
  them.
- **Apache version differences across hosts.** The `.htaccess` fix had to
  support both older (2.2) and newer (2.4+) Apache configurations, since
  the target hosting environment wasn't guaranteed in advance.

---

## Project Structure

```
project/
├── index.html          # Frontend page
├── style.css            # Styling
├── app.js               # Speech recognition + text-to-speech + backend calls
├── config.php           # Where the Gemini API key is set (protected by .htaccess)
├── config.example.php   # Safe placeholder version to commit to GitHub
├── .htaccess             # Blocks direct browser access to config.php
├── .gitignore            # Excludes the real config.php from git
└── api/
    └── chat.php          # Bridge between the frontend and the Gemini API
```

## Deployment

### Local server (XAMPP / WAMP)
1. Copy the `project` folder into `C:\xampp\htdocs\project` (or WAMP's
   `www` folder).
2. Start Apache from the control panel.
3. Open `http://localhost/project/index.html`.
