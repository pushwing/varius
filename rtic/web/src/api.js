export class ApiError extends Error {
  constructor(code, message) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
  }
}

/**
 * CI4 API에 로그인해 LiveKit 접속 정보를 받아온다.
 *
 * @param {string} baseUrl
 * @param {string} email
 * @param {string} password
 * @returns {Promise<{accessToken: string, livekitUrl: string, room: string, expiresIn: number}>}
 */
export async function login(baseUrl, email, password) {
  const response = await fetch(`${baseUrl}/api/v1/tokens`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password }),
  });

  const body = await response.json();

  if (body.status !== 'success') {
    throw new ApiError(body.code ?? 'UNKNOWN_ERROR', body.message ?? '로그인에 실패했습니다.');
  }

  return {
    accessToken: body.data.access_token,
    livekitUrl: body.data.livekit_url,
    room: body.data.room,
    expiresIn: body.data.expires_in,
  };
}
