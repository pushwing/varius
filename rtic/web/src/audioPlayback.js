/**
 * 리눅스 데몬(자택 마이크) -> 앱 역방향 오디오 재생.
 *
 * 데몬이 LiveKit에 퍼블리시한 오디오 트랙을 앱에서 수신·재생한다(이슈 #11).
 * `livekit-client`의 `RoomEvent.TrackSubscribed`로 받은 트랙을 attach 해
 * 컨테이너(보통 document.body)에 붙이면 브라우저가 자동 재생한다.
 */

// LiveKit `Track.Kind.Audio`의 값. 라이브러리 enum에 의존하지 않고 순수
// 단위 테스트가 가능하도록 문자열 상수로 둔다.
export const AUDIO_TRACK_KIND = 'audio';

/**
 * 구독한 트랙이 오디오면 attach 해 컨테이너에 붙인다.
 *
 * @param {{kind: string, attach: () => HTMLMediaElement}} track
 * @param {{appendChild: (el: HTMLMediaElement) => void}} container
 * @returns {HTMLMediaElement | null} 붙인 media element, 오디오가 아니면 null
 */
export function handleAudioTrackSubscribed(track, container) {
  if (track.kind !== AUDIO_TRACK_KIND) {
    return null;
  }

  const element = track.attach();
  container.appendChild(element);
  return element;
}
