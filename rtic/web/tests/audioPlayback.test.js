import { describe, expect, it, vi } from 'vitest';
import { handleAudioTrackSubscribed } from '../src/audioPlayback.js';

function fakeTrack(kind) {
  const element = { id: 'attached-el' };
  return {
    kind,
    attach: vi.fn(() => element),
    _element: element,
  };
}

function fakeContainer() {
  return { appendChild: vi.fn() };
}

describe('handleAudioTrackSubscribed', () => {
  it('오디오 트랙을 attach 해 컨테이너에 붙이고 element를 반환한다', () => {
    const track = fakeTrack('audio');
    const container = fakeContainer();

    const el = handleAudioTrackSubscribed(track, container);

    expect(track.attach).toHaveBeenCalledOnce();
    expect(container.appendChild).toHaveBeenCalledWith(track._element);
    expect(el).toBe(track._element);
  });

  it('비디오 등 오디오가 아닌 트랙은 무시하고 null을 반환한다', () => {
    const track = fakeTrack('video');
    const container = fakeContainer();

    const el = handleAudioTrackSubscribed(track, container);

    expect(track.attach).not.toHaveBeenCalled();
    expect(container.appendChild).not.toHaveBeenCalled();
    expect(el).toBeNull();
  });
});
