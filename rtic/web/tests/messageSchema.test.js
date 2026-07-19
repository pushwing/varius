import { describe, expect, it } from 'vitest';
import { ReturnMessageParseError, parseReturnMessage } from '../src/messageSchema.js';

describe('parseReturnMessage', () => {
  it('유효한 status 메시지를 파싱한다', () => {
    const raw = JSON.stringify({ type: 'status', message: '스피커 연결됨', ts: 1700000000 });

    expect(parseReturnMessage(raw)).toEqual({
      type: 'status',
      message: '스피커 연결됨',
      ts: 1700000000,
    });
  });

  it.each(['status', 'info', 'error'])('type=%s를 허용한다', (type) => {
    const raw = JSON.stringify({ type, message: 'm', ts: 1 });
    expect(parseReturnMessage(raw).type).toBe(type);
  });

  it('JSON이 아니면 ReturnMessageParseError를 던진다', () => {
    expect(() => parseReturnMessage('not-json')).toThrow(ReturnMessageParseError);
  });

  it('JSON이지만 객체가 아니면 던진다', () => {
    expect(() => parseReturnMessage('"just a string"')).toThrow(ReturnMessageParseError);
    expect(() => parseReturnMessage('null')).toThrow(ReturnMessageParseError);
  });

  it('알 수 없는 type이면 던진다', () => {
    const raw = JSON.stringify({ type: 'unknown', message: 'm', ts: 1 });
    expect(() => parseReturnMessage(raw)).toThrow(/type/);
  });

  it('message가 문자열이 아니면 던진다', () => {
    const raw = JSON.stringify({ type: 'status', message: 123, ts: 1 });
    expect(() => parseReturnMessage(raw)).toThrow(/message/);
  });

  it('ts가 숫자가 아니면 던진다', () => {
    const raw = JSON.stringify({ type: 'status', message: 'm', ts: '1700000000' });
    expect(() => parseReturnMessage(raw)).toThrow(/ts/);
  });

  it('필드가 아예 없으면 던진다', () => {
    expect(() => parseReturnMessage('{}')).toThrow(ReturnMessageParseError);
  });
});
