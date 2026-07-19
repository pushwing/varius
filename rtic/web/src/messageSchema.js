/**
 * 리눅스 데몬 -> 앱 "리턴 메시지" 스키마.
 *
 * LiveKit 데이터 채널 텍스트 스트림 토픽: `rtic.status`
 * 페이로드(JSON): { type: "status" | "info" | "error", message: string, ts: number(유닉스 초) }
 *
 * 데몬 쪽 실제 발신 구현은 이 이슈 범위 밖(향후 별도 이슈) — 여기서는
 * 앱이 수신·표시할 스키마를 확정하고 파싱/검증 로직만 제공한다.
 */

export const RETURN_MESSAGE_TOPIC = 'rtic.status';

export const RETURN_MESSAGE_TYPES = ['status', 'info', 'error'];

export class ReturnMessageParseError extends Error {}

/**
 * @param {string} raw
 * @returns {{type: string, message: string, ts: number}}
 */
export function parseReturnMessage(raw) {
  let payload;
  try {
    payload = JSON.parse(raw);
  } catch {
    throw new ReturnMessageParseError('리턴 메시지가 JSON 형식이 아닙니다.');
  }

  if (payload === null || typeof payload !== 'object') {
    throw new ReturnMessageParseError('리턴 메시지가 JSON 객체가 아닙니다.');
  }

  const { type, message, ts } = payload;

  if (typeof type !== 'string' || !RETURN_MESSAGE_TYPES.includes(type)) {
    throw new ReturnMessageParseError(`알 수 없는 리턴 메시지 type: ${JSON.stringify(type)}`);
  }
  if (typeof message !== 'string') {
    throw new ReturnMessageParseError('리턴 메시지 message 필드가 문자열이 아닙니다.');
  }
  if (typeof ts !== 'number' || !Number.isFinite(ts)) {
    throw new ReturnMessageParseError('리턴 메시지 ts 필드가 숫자가 아닙니다.');
  }

  return { type, message, ts };
}
