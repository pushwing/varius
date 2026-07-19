class ConfigError(RuntimeError):
    """필수 환경변수가 누락되었거나 값이 유효하지 않을 때 발생한다."""
