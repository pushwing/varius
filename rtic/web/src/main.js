import './style.css';
import { Room } from 'livekit-client';
import { login, ApiError } from './api.js';
import { parseReturnMessage, RETURN_MESSAGE_TOPIC } from './messageSchema.js';

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8321';

document.querySelector('#app').innerHTML = `
  <h1>rtic — 실시간 인터콤</h1>
  <form id="login-form">
    <label>이메일 <input type="email" name="email" required autocomplete="username" /></label>
    <label>비밀번호 <input type="password" name="password" required minlength="8" autocomplete="current-password" /></label>
    <button type="submit">로그인</button>
    <p id="login-error" class="error" hidden></p>
  </form>
  <p id="status" class="status" hidden></p>
  <button id="mic-toggle" type="button" hidden></button>
  <ul id="message-log"></ul>
`;

const form = document.querySelector('#login-form');
const loginError = document.querySelector('#login-error');
const statusEl = document.querySelector('#status');
const micToggle = document.querySelector('#mic-toggle');
const messageLog = document.querySelector('#message-log');

function appendMessageToLog({ type, message, ts }) {
  const li = document.createElement('li');
  li.dataset.type = type;
  const time = new Date(ts * 1000).toLocaleTimeString();
  li.textContent = `[${time}] ${message}`;
  messageLog.prepend(li);
}

async function connectToRoom(session) {
  const room = new Room();

  room.registerTextStreamHandler(RETURN_MESSAGE_TOPIC, async (reader) => {
    const raw = await reader.readAll();
    try {
      appendMessageToLog(parseReturnMessage(raw));
    } catch (err) {
      console.warn('잘못된 리턴 메시지를 무시했습니다:', err);
    }
  });

  await room.connect(session.livekitUrl, session.accessToken);

  statusEl.hidden = false;
  statusEl.textContent = `연결됨 — room: ${session.room}`;

  micToggle.hidden = false;
  let micEnabled = false;

  micToggle.textContent = '마이크 켜기';
  micToggle.addEventListener('click', async () => {
    micEnabled = !micEnabled;
    await room.localParticipant.setMicrophoneEnabled(micEnabled);
    micToggle.textContent = micEnabled ? '마이크 끄기' : '마이크 켜기';
  });
}

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  loginError.hidden = true;

  const submitButton = form.querySelector('button[type="submit"]');
  submitButton.disabled = true;

  const formData = new FormData(form);
  const email = formData.get('email');
  const password = formData.get('password');

  try {
    const session = await login(API_BASE_URL, email, password);
    form.hidden = true;
    await connectToRoom(session);
  } catch (err) {
    loginError.hidden = false;
    loginError.textContent =
      err instanceof ApiError ? err.message : '로그인 중 오류가 발생했습니다.';
    submitButton.disabled = false;
  }
});
