"""Log em stdout, uma linha JSON por registro (tech.md)."""
from __future__ import annotations

import json
import logging
import sys
from typing import Any


class _FormatadorJson(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        obj: dict[str, Any] = {
            "ts": self.formatTime(record, "%Y-%m-%dT%H:%M:%S%z"),
            "nivel": record.levelname,
            "logger": record.name,
            "msg": record.getMessage(),
        }
        if record.exc_info:
            obj["exc"] = self.formatException(record.exc_info)
        extra = getattr(record, "extra_json", None)
        if isinstance(extra, dict):
            obj.update(extra)
        return json.dumps(obj, ensure_ascii=False, separators=(",", ":"))


def configurar_log() -> None:
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(_FormatadorJson())
    root = logging.getLogger()
    root.handlers.clear()
    root.addHandler(handler)
    root.setLevel(logging.INFO)
