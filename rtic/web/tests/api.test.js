import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, login } from '../src/api.js';

function mockFetchOnce(status, body) {
  global.fetch = vi.fn().mockResolvedValue({
    status,
    json: async () => body,
  });
}

afterEach(() => {
  vi.restoreAllMocks();
});

describe('login', () => {
  it('요청 URL과 바디를 올바르게 구성한다', async () => {
    mockFetchOnce(201, {
      status: 'success',
      data: {
        access_token: 'token-abc',
        livekit_url: 'ws://localhost:7880',
        room: 'rtic-home',
        expires_in: 300,
      },
    });

    await login('http://api.local', 'user@example.com', 'password123');

    expect(global.fetch).toHaveBeenCalledWith(
      'http://api.local/api/v1/tokens',
      expect.objectContaining({
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: 'user@example.com', password: 'password123' }),
      }),
    );
  });

  it('성공 응답을 camelCase 필드로 매핑해 반환한다', async () => {
    mockFetchOnce(201, {
      status: 'success',
      data: {
        access_token: 'token-abc',
        livekit_url: 'ws://localhost:7880',
        room: 'rtic-home',
        expires_in: 300,
      },
    });

    const session = await login('http://api.local', 'user@example.com', 'password123');

    expect(session).toEqual({
      accessToken: 'token-abc',
      livekitUrl: 'ws://localhost:7880',
      room: 'rtic-home',
      expiresIn: 300,
    });
  });

  it('실패 응답이면 code/message를 담은 ApiError를 던진다', async () => {
    mockFetchOnce(401, {
      status: 'error',
      code: 'INVALID_CREDENTIALS',
      message: '이메일 또는 비밀번호가 올바르지 않습니다.',
    });

    await expect(login('http://api.local', 'user@example.com', 'wrong')).rejects.toMatchObject({
      name: 'ApiError',
      code: 'INVALID_CREDENTIALS',
      message: '이메일 또는 비밀번호가 올바르지 않습니다.',
    });
  });

  it('ApiError는 Error의 인스턴스다', async () => {
    mockFetchOnce(401, { status: 'error', code: 'INVALID_CREDENTIALS', message: 'x' });

    try {
      await login('http://api.local', 'a@example.com', 'wrong');
      expect.unreachable();
    } catch (err) {
      expect(err).toBeInstanceOf(ApiError);
      expect(err).toBeInstanceOf(Error);
    }
  });
});
