from __future__ import annotations

import logging
import os
import sys

from .config import DaemonConfig
from .daemon import run
from .errors import ConfigError


def main() -> int:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )

    try:
        config = DaemonConfig.from_env(os.environ)
    except ConfigError as exc:
        logging.getLogger(__name__).error(str(exc))
        return 1

    # 테스트 전용 우회: 실제 livekitwebrtcsrc 없이 프로세스 수명주기(시그널·
    # 종료 코드·헬스체크)를 검증하기 위한 소스 대체. 운영 배포에서는 설정하지
    # 않으므로 항상 livekitwebrtcsrc를 사용한다.
    source_description = os.environ.get("RTIC_TEST_SOURCE_DESCRIPTION")

    return run(config, source_description)


if __name__ == "__main__":
    sys.exit(main())
