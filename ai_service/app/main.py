import hashlib
import hmac
import json
import os
import re
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

from fastapi import FastAPI, Header, HTTPException, Request
from pydantic import BaseModel, Field


app = FastAPI(title="ERP POS AI Assistant", version="0.2.0")

INTERNAL_TOKEN = os.getenv("AI_INTERNAL_TOKEN", "")
EXTERNAL_TOKENS = [token.strip() for token in os.getenv("AI_EXTERNAL_API_TOKENS", os.getenv("AI_EXTERNAL_API_TOKEN", "")).split(",") if token.strip()]
EXTERNAL_SCOPES = {scope.strip() for scope in os.getenv("AI_EXTERNAL_API_SCOPES", "status,chat,voice,tools").split(",") if scope.strip()}
DATA_DIR = Path(os.getenv("AI_DATA_DIR", str(Path(__file__).resolve().parent.parent / ".data")))
KNOWLEDGE_PATH = DATA_DIR / "knowledge.jsonl"
MAX_AUDIO_BYTES = int(os.getenv("AI_MAX_AUDIO_BYTES", str(15 * 1024 * 1024)))
STORAGE_ROOT = Path(os.getenv("AI_STORAGE_ROOT", "")).resolve() if os.getenv("AI_STORAGE_ROOT") else None
WHISPER_MODEL_SIZE = os.getenv("AI_WHISPER_MODEL_SIZE", "base")
WHISPER_DEVICE = os.getenv("AI_WHISPER_DEVICE", "cpu")
WHISPER_COMPUTE_TYPE = os.getenv("AI_WHISPER_COMPUTE_TYPE", "int8")
LLM_PROVIDER = os.getenv("AI_LLM_PROVIDER", "rag_local")
LLM_BASE_URL = os.getenv("AI_LLM_BASE_URL", "").rstrip("/")
LLM_API_KEY = os.getenv("AI_LLM_API_KEY", "")
LLM_MODEL = os.getenv("AI_LLM_MODEL", "local-model")
_WHISPER_MODEL: Any | None = None

STOPWORDS = {
    "a",
    "al",
    "con",
    "de",
    "del",
    "el",
    "en",
    "la",
    "las",
    "los",
    "para",
    "por",
    "que",
    "un",
    "una",
    "y",
}

PROMPT_INJECTION_PATTERNS = [
    r"ignora\s+(las\s+)?instrucciones",
    r"olvida\s+(las\s+)?reglas",
    r"ejecuta\s+sql",
    r"\bdrop\s+table\b",
    r"\btruncate\b",
    r"\bdelete\s+from\b",
    r"muestra\s+(tokens?|credenciales|password|secretos?)",
    r"revela\s+(tokens?|credenciales|password|secretos?)",
    r"bypass",
    r"sin\s+permiso",
]


class ChatRequest(BaseModel):
    message: str = Field(default="", max_length=4000)
    intent: str | None = None
    tool_result: dict[str, Any] | None = None
    allowed_tools: list[str] = Field(default_factory=list)


class TranscribeRequest(BaseModel):
    audio_path: str = Field(max_length=500)


def ensure_data_dir() -> None:
    DATA_DIR.mkdir(parents=True, exist_ok=True)


def normalize_text(value: str) -> str:
    return re.sub(r"\s+", " ", value.lower()).strip()


def tokens(value: str) -> set[str]:
    return {
        token
        for token in re.findall(r"[a-z0-9áéíóúñü]+", normalize_text(value))
        if len(token) > 2 and token not in STOPWORDS
    }


def blocked_prompt(message: str) -> str | None:
    text = normalize_text(message)
    for pattern in PROMPT_INJECTION_PATTERNS:
        if re.search(pattern, text):
            return "La solicitud fue bloqueada por seguridad. No puedo saltar permisos, revelar secretos ni ejecutar SQL libre."
    return None


def load_documents() -> list[dict[str, Any]]:
    if not KNOWLEDGE_PATH.exists():
        return []

    documents: list[dict[str, Any]] = []
    for line in KNOWLEDGE_PATH.read_text(encoding="utf-8").splitlines():
        if not line.strip():
            continue
        try:
            row = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(row, dict):
            documents.append(row)

    return documents


def save_documents(documents: list[dict[str, Any]]) -> None:
    ensure_data_dir()
    deduplicated = {str(document.get("id", "")): document for document in load_documents()}

    for document in documents:
        document_id = str(document.get("id", "")).strip()
        if not document_id:
            continue
        content = str(document.get("content", "")).strip()
        title = str(document.get("title", document_id)).strip()
        deduplicated[document_id] = {
            "id": document_id,
            "title": title,
            "content": content,
            "metadata": document.get("metadata") or {},
            "indexed_at": int(time.time()),
        }

    rows = [json.dumps(document, ensure_ascii=False) for document in deduplicated.values()]
    KNOWLEDGE_PATH.write_text("\n".join(rows) + ("\n" if rows else ""), encoding="utf-8")


def retrieve_documents(message: str, limit: int = 4) -> list[dict[str, Any]]:
    query_tokens = tokens(message)
    if not query_tokens:
        return []

    scored: list[tuple[float, dict[str, Any]]] = []
    for document in load_documents():
        haystack = f"{document.get('title', '')} {document.get('content', '')}"
        document_tokens = tokens(haystack)
        if not document_tokens:
            continue
        overlap = query_tokens.intersection(document_tokens)
        if not overlap:
            continue
        score = len(overlap) / max(len(query_tokens), 1)
        scored.append((score, document))

    scored.sort(key=lambda item: item[0], reverse=True)
    return [document for _, document in scored[:limit]]


def summarize_tool_result(payload: dict[str, Any]) -> str:
    tool = str(payload.get("tool", "herramienta"))
    if payload.get("status") == "requires_confirmation":
        return str(payload.get("message", "La accion requiere confirmacion desde el ERP."))

    if tool == "buscar_productos":
        items = payload.get("items") or []
        if not items:
            return "No encontre productos activos con ese criterio."
        names = "; ".join(f"{item.get('name')} Bs {item.get('sale_price')}" for item in items[:6])
        return f"Productos encontrados: {names}."

    if tool == "consultar_stock":
        return f"Stock permitido: {payload.get('products_count', 0)} productos, disponible total {payload.get('available_total', 0)}."

    if tool == "consultar_ventas":
        return f"Ventas del {payload.get('from')} al {payload.get('to')}: {payload.get('sales_count', 0)} documentos por Bs {payload.get('sales_total', 0)}."

    if tool == "consultar_ganancias":
        return f"Resultado: ventas Bs {payload.get('sales_total', 0)}, ganancia estimada Bs {payload.get('profit', 0)} y margen {payload.get('margin_percent', 0)}%."

    if payload.get("path"):
        return "Archivo generado correctamente. Descargalo desde la ruta autenticada del ERP."

    return "Respuesta generada con herramienta segura del ERP. Revisa el resultado estructurado adjunto."


def openai_compatible_chat(message: str, documents: list[dict[str, Any]], intent: str | None) -> dict[str, Any] | None:
    if LLM_PROVIDER != "openai_compatible" or not LLM_BASE_URL:
        return None

    context = "\n\n".join(
        f"Fuente: {document.get('title', 'sin titulo')}\n{str(document.get('content', ''))[:1200]}"
        for document in documents[:4]
    )
    system_prompt = (
        "Eres el asistente del ERP/POS. Responde en espanol, usa solo el contexto y herramientas recibidas, "
        "no inventes datos, no ejecutes SQL y no reveles secretos. Si falta informacion, dilo claramente."
    )
    payload = {
        "model": LLM_MODEL,
        "messages": [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": f"Intencion: {intent or 'desconocida'}\nContexto permitido:\n{context}\n\nPregunta:\n{message}"},
        ],
        "temperature": 0.2,
    }

    request = urllib.request.Request(
        f"{LLM_BASE_URL}/chat/completions",
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            **({"Authorization": f"Bearer {LLM_API_KEY}"} if LLM_API_KEY else {}),
        },
        method="POST",
    )

    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            body = json.loads(response.read().decode("utf-8"))
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError):
        return None

    answer = str(((body.get("choices") or [{}])[0].get("message") or {}).get("content") or "").strip()
    if not answer:
        return None

    return {
        "answer": answer,
        "model": LLM_MODEL,
        "provider": LLM_PROVIDER,
        "sources": [{"id": document.get("id"), "title": document.get("title")} for document in documents],
    }


@app.get("/health")
def get_health() -> dict[str, Any]:
    return {
        "ok": True,
        "mode": os.getenv("AI_MODEL_MODE", "cpu_light"),
        "model": os.getenv("AI_MODEL_NAME", LLM_MODEL if LLM_PROVIDER == "openai_compatible" else "rag_ligero_local"),
        "llm_provider": LLM_PROVIDER,
        "knowledge_documents": len(load_documents()),
    }


async def verify_internal_request(request: Request, signature: str | None, timestamp: str | None) -> None:
    if not INTERNAL_TOKEN:
        return
    if not signature or not timestamp:
        raise HTTPException(status_code=401, detail="Firma interna requerida.")
    try:
        request_time = int(timestamp)
    except ValueError as exc:
        raise HTTPException(status_code=401, detail="Timestamp interno invalido.") from exc
    if abs(int(time.time()) - request_time) > 300:
        raise HTTPException(status_code=401, detail="Timestamp interno vencido.")
    body = await request.body()
    expected = "sha256=" + hmac.new(INTERNAL_TOKEN.encode(), timestamp.encode() + b"." + body, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature):
        raise HTTPException(status_code=403, detail="Firma interna no valida.")


def require_external_token(authorization: str | None, required_scope: str) -> None:
    if required_scope not in EXTERNAL_SCOPES:
        raise HTTPException(status_code=403, detail="Scope externo no habilitado.")
    if not EXTERNAL_TOKENS:
        raise HTTPException(status_code=503, detail="API externa FastAPI no configurada.")
    if not authorization or not authorization.lower().startswith("bearer "):
        raise HTTPException(status_code=401, detail="Token requerido.")

    token = authorization.split(" ", 1)[1].strip()
    valid = any(hmac.compare_digest(token, allowed) for allowed in EXTERNAL_TOKENS)
    if not valid:
        raise HTTPException(status_code=403, detail="Token externo no valido.")


def resolve_audio_path(audio_path: str) -> Path | None:
    candidate = Path(audio_path)
    candidates = [candidate]

    if STORAGE_ROOT and not candidate.is_absolute():
        candidates.append(STORAGE_ROOT / candidate)

    for path in candidates:
        try:
            resolved = path.resolve()
        except OSError:
            continue
        if resolved.exists() and resolved.is_file():
            return resolved
    return None


def transcribe_with_whisper(audio_file: Path) -> dict[str, Any]:
    global _WHISPER_MODEL

    try:
        from faster_whisper import WhisperModel
    except ImportError:
        return {
            "ok": True,
            "text": "",
            "message": "Audio validado. Instala faster-whisper para activar transcripcion real.",
            "audio_path": str(audio_file),
            "bytes": audio_file.stat().st_size,
            "engine": "fallback_without_stt",
        }

    if _WHISPER_MODEL is None:
        _WHISPER_MODEL = WhisperModel(
            WHISPER_MODEL_SIZE,
            device=WHISPER_DEVICE,
            compute_type=WHISPER_COMPUTE_TYPE,
        )

    segments, info = _WHISPER_MODEL.transcribe(str(audio_file), beam_size=1, vad_filter=True)
    text = " ".join(segment.text.strip() for segment in segments if segment.text.strip()).strip()

    return {
        "ok": True,
        "text": text,
        "message": "Transcripcion local completada." if text else "No se detecto voz clara en el audio.",
        "audio_path": str(audio_file),
        "bytes": audio_file.stat().st_size,
        "engine": "faster_whisper",
        "language": getattr(info, "language", None),
        "duration": getattr(info, "duration", None),
    }


@app.post("/health")
async def post_health(request: Request, x_erp_signature: str | None = Header(default=None), x_erp_timestamp: str | None = Header(default=None)) -> dict[str, Any]:
    await verify_internal_request(request, x_erp_signature, x_erp_timestamp)
    return get_health()


@app.post("/chat")
async def chat(payload: ChatRequest, request: Request, x_erp_signature: str | None = Header(default=None), x_erp_timestamp: str | None = Header(default=None)) -> dict[str, Any]:
    await verify_internal_request(request, x_erp_signature, x_erp_timestamp)

    if message := blocked_prompt(payload.message):
        return {"ok": False, "blocked": True, "answer": message}

    if payload.tool_result:
        return {
            "answer": summarize_tool_result(payload.tool_result),
            "tool_result": payload.tool_result,
            "sources": [{"type": "erp_tool", "tool": payload.tool_result.get("tool")}],
        }

    documents = retrieve_documents(payload.message)
    if documents:
        llm_response = openai_compatible_chat(payload.message, documents, payload.intent)
        if llm_response:
            return llm_response

        source_titles = ", ".join(str(document.get("title", "sin titulo")) for document in documents)
        snippets = " ".join(str(document.get("content", ""))[:240] for document in documents[:2])
        return {
            "answer": f"Con la informacion indexada encontre: {snippets}",
            "intent": payload.intent,
            "sources": [{"id": document.get("id"), "title": document.get("title")} for document in documents],
            "source_summary": source_titles,
        }

    return {
        "answer": "No encontre informacion suficiente en el indice local. Puedo responder mejor si Laravel ejecuta una herramienta segura o si se reindexa el catalogo.",
        "intent": payload.intent,
        "sources": [],
    }


@app.post("/transcribe")
async def transcribe(payload: TranscribeRequest, request: Request, x_erp_signature: str | None = Header(default=None), x_erp_timestamp: str | None = Header(default=None)) -> dict[str, Any]:
    await verify_internal_request(request, x_erp_signature, x_erp_timestamp)

    audio_file = resolve_audio_path(payload.audio_path)
    if audio_file is None:
        return {
            "ok": False,
            "text": "",
            "message": "Audio no encontrado desde FastAPI. Configura AI_STORAGE_ROOT si corre separado.",
            "audio_path": payload.audio_path,
        }
    if audio_file.stat().st_size > MAX_AUDIO_BYTES:
        return {
            "ok": False,
            "text": "",
            "message": "Audio rechazado por exceder el tamano maximo permitido.",
            "audio_path": payload.audio_path,
        }

    return transcribe_with_whisper(audio_file)


@app.post("/embed/index")
async def index_embeddings(payload: dict[str, Any], request: Request, x_erp_signature: str | None = Header(default=None), x_erp_timestamp: str | None = Header(default=None)) -> dict[str, Any]:
    await verify_internal_request(request, x_erp_signature, x_erp_timestamp)
    documents = payload.get("documents") or []
    if not isinstance(documents, list):
        raise HTTPException(status_code=422, detail="documents debe ser una lista.")

    normalized_documents = [document for document in documents if isinstance(document, dict)]
    save_documents(normalized_documents)
    return {
        "ok": True,
        "indexed": len(normalized_documents),
        "total_documents": len(load_documents()),
        "message": "Indice RAG local actualizado.",
    }


@app.post("/tools/execute")
async def execute_tool(payload: dict[str, Any], request: Request, x_erp_signature: str | None = Header(default=None), x_erp_timestamp: str | None = Header(default=None)) -> dict[str, Any]:
    await verify_internal_request(request, x_erp_signature, x_erp_timestamp)
    return {"ok": False, "message": "Las herramientas productivas se ejecutan desde Laravel, no desde SQL libre."}


@app.get("/api/v1/status")
def external_status(authorization: str | None = Header(default=None)) -> dict[str, Any]:
    require_external_token(authorization, "status")
    return get_health()


@app.post("/api/v1/chat")
def external_chat(payload: ChatRequest, authorization: str | None = Header(default=None)) -> dict[str, Any]:
    require_external_token(authorization, "chat")
    if message := blocked_prompt(payload.message):
        return {"ok": False, "blocked": True, "answer": message}

    documents = retrieve_documents(payload.message)
    llm_response = openai_compatible_chat(payload.message, documents, payload.intent)
    if llm_response:
        return llm_response

    return {
        "answer": documents[0]["content"][:500] if documents else "Consulta recibida. Para datos internos usa la API Laravel con scopes y permisos.",
        "intent": payload.intent,
        "sources": [{"id": document.get("id"), "title": document.get("title")} for document in documents],
    }


@app.post("/api/v1/transcribe")
def external_transcribe(payload: TranscribeRequest, authorization: str | None = Header(default=None)) -> dict[str, Any]:
    require_external_token(authorization, "voice")
    audio_file = resolve_audio_path(payload.audio_path)
    if audio_file is None:
        raise HTTPException(status_code=404, detail="Audio no encontrado.")
    return transcribe_with_whisper(audio_file)


@app.post("/api/v1/tools")
def external_tools(payload: dict[str, Any], authorization: str | None = Header(default=None)) -> dict[str, Any]:
    require_external_token(authorization, "tools")
    return {"ok": False, "message": "FastAPI no ejecuta herramientas directas. Usa Laravel para validar permisos y auditoria."}
