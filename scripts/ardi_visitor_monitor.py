#!/usr/bin/env python3
"""Poll privacy-safe visitor events and emit Telegram-ready notifications."""
from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request
from datetime import datetime
from pathlib import Path
from typing import Any

ENDPOINT = "https://pay.ardirentservice.com/visitor_analytics.php"
TOKEN_FILE = Path(__file__).with_name(".visitor_analytics_token")
TIMEOUT_SECONDS = 20


def format_duration(milliseconds: int) -> str:
    seconds = max(0, int(milliseconds) // 1000)
    minutes, seconds = divmod(seconds, 60)
    hours, minutes = divmod(minutes, 60)
    if hours:
        return f"{hours}h {minutes}m {seconds}s"
    if minutes:
        return f"{minutes}m {seconds}s"
    return f"{seconds}s"


def short_session(session_id: str) -> str:
    return str(session_id).split("-", 1)[0].upper()


def local_time(epoch: Any) -> str:
    try:
        return datetime.fromtimestamp(int(epoch)).astimezone().strftime("%I:%M:%S %p")
    except (TypeError, ValueError, OSError):
        return "hora no disponible"


def format_start(item: dict[str, Any]) -> str:
    return (
        "👤 Nueva visita anónima en Ardi Rent & Service\n"
        f"Sesión: {short_session(item.get('session_id', ''))}\n"
        f"Entrada: {local_time(item.get('started_at'))}\n"
        "Se enviará el resumen cuando complete 15 minutos sin actividad."
    )


def format_summary(item: dict[str, Any]) -> str:
    lines = [
        "📊 Resumen de visita anónima — 15 minutos sin actividad",
        f"Sesión: {short_session(item.get('session_id', ''))}",
        f"Tiempo visible total: {format_duration(int(item.get('total_active_ms', 0)))}",
        "",
        "Páginas / ventanas del sitio:",
    ]
    pages = item.get("pages") or []
    if pages:
        for page in pages[:20]:
            path = str(page.get("page_path", "/"))
            duration = format_duration(int(page.get("active_ms", 0)))
            views = int(page.get("views", 0))
            lines.append(f"• {path} — {duration} visible ({views} vista{'s' if views != 1 else ''})")
    else:
        lines.append("• Sin tiempo visible medible")

    lines.extend(["", "Clics permitidos (botones y enlaces):"])
    clicks = item.get("clicks") or []
    if clicks:
        for click in clicks[:30]:
            path = str(click.get("page_path", "/"))
            kind = str(click.get("target_type", "control"))
            label = str(click.get("target_label", "sin etiqueta"))
            count = int(click.get("click_count", 0))
            lines.append(f"• {path} — {kind}: {label} ×{count}")
    else:
        lines.append("• Ningún clic registrado")

    lines.extend([
        "",
        "Privacidad: no se recopilaron nombres, IP, texto escrito, formularios, contraseñas ni pagos.",
    ])
    return "\n".join(lines)


def request_json(token: str, url: str, payload: dict[str, Any] | None = None) -> dict[str, Any]:
    data = None if payload is None else json.dumps(payload).encode("utf-8")
    request = urllib.request.Request(
        url,
        data=data,
        method="GET" if payload is None else "POST",
        headers={
            "Authorization": f"Bearer {token}",
            "Accept": "application/json",
            "Content-Type": "application/json",
            "User-Agent": "ArdiVisitorMonitor/1.0",
        },
    )
    with urllib.request.urlopen(request, timeout=TIMEOUT_SECONDS) as response:
        result = json.load(response)
    if not isinstance(result, dict) or not result.get("ok"):
        raise RuntimeError("Visitor analytics endpoint returned an invalid response")
    return result


def ack(token: str, kind: str, session_ids: list[str]) -> None:
    if session_ids:
        request_json(token, f"{ENDPOINT}?action=ack", {"kind": kind, "session_ids": session_ids})


def run() -> list[str]:
    token = TOKEN_FILE.read_text(encoding="utf-8").strip()
    if len(token) < 32:
        raise RuntimeError("Visitor analytics monitor token is missing or invalid")
    response = request_json(token, f"{ENDPOINT}?action=pending")
    pending = response.get("notifications") or {}
    starts = pending.get("starts") or []
    summaries = pending.get("summaries") or []
    messages = [format_start(item) for item in starts]
    messages.extend(format_summary(item) for item in summaries)
    ack(token, "start", [str(item.get("session_id")) for item in starts])
    ack(token, "summary", [str(item.get("session_id")) for item in summaries])
    return messages


def main() -> int:
    try:
        messages = run()
    except (OSError, ValueError, RuntimeError, urllib.error.URLError) as error:
        print(f"Error del monitor de visitas: {error}", file=sys.stderr)
        return 1
    if messages:
        print("\n\n— — —\n\n".join(messages))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
