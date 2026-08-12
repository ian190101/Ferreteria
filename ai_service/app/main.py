import hashlib
import hmac
import os
import time
from typing import Any

from fastapi import FastAPI, Header, HTTPException, Request
from pydantic import BaseModel, Field


app = FastAPI(title="ERP POS AI Assistant", version="0.1.0")
INTERNAL_TOKEN = os.getenv("AI_INTERNAL_TOKEN", "")


class ChatRequest(BaseModel):
    message: str = Field(default="", max_length=4000)
    intent: str | None = None
    tool_result: dict[str, Any] | None = None
    allowed_tools: list[str] = Field(default_factory=list)


class TranscribeRequest(BaseModel):
    audio_path: str


@app.get("/health")
def get_health() -> dict[str, Any]:
    return {"ok": True, "mode": "cpu_light", "model": "fallback_controlado"}


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


@app.post("/health")
async def post_health(request: Request, x_erp_signature: str | None = Header(default=None), x_erp_timestamp: str | None = Header(default=None)) -> dict[str, Any]:
    await verify_internal_request(request, x_erp_signature, x_erp_timestamp)
    return get_health()


@app.post("/chat")
async def chat(payload: ChatRequest, request: Request, x_erp_signature: str | None = Header(default=None), x_erp_timestamp: str | None = Header(default=None)) -> dict[str, Any]:
    await verify_internal_request(request, x_erp_signature, x_erp_timestamp)
    if payload.tool_result:
        return {
            "answer": "Respuesta generada con herramienta segura del ERP. Revisa el resultado estructurado adjunto.",
            "tool_result": payload.tool_result,
        }

    return {
        "answer": "Motor FastAPI conectado. Aun no hay modelo local configurado para esta consulta.",
        "intent": payload.intent,
    }


@app.post("/transcribe")
async def transcribe(payload: TranscribeRequest, request: Request, x_erp_signature: str | None = Header(default=None), x_erp_timestamp: str | None = Header(default=None)) -> dict[str, Any]:
    await verify_internal_request(request, x_erp_signature, x_erp_timestamp)
    return {
        "text": "",
        "message": "Transcripcion local pendiente de configurar con Whisper/faster-whisper.",
        "audio_path": payload.audio_path,
    }


@app.post("/embed/index")
async def index_embeddings(payload: dict[str, Any], request: Request, x_erp_signature: str | None = Header(default=None), x_erp_timestamp: str | None = Header(default=None)) -> dict[str, Any]:
    await verify_internal_request(request, x_erp_signature, x_erp_timestamp)
    documents = payload.get("documents") or []
    return {"ok": True, "indexed": len(documents), "message": "Indexacion recibida en modo scaffold."}


@app.post("/tools/execute")
async def execute_tool(payload: dict[str, Any], request: Request, x_erp_signature: str | None = Header(default=None), x_erp_timestamp: str | None = Header(default=None)) -> dict[str, Any]:
    await verify_internal_request(request, x_erp_signature, x_erp_timestamp)
    return {"ok": False, "message": "Las herramientas productivas se ejecutan desde Laravel, no desde SQL libre."}


def require_external_token(authorization: str | None) -> None:
    if not authorization or not authorization.lower().startswith("bearer "):
        raise HTTPException(status_code=401, detail="Token requerido.")


@app.get("/api/v1/status")
def external_status(authorization: str | None = Header(default=None)) -> dict[str, Any]:
    require_external_token(authorization)
    return get_health()


@app.post("/api/v1/chat")
def external_chat(payload: ChatRequest, authorization: str | None = Header(default=None)) -> dict[str, Any]:
    require_external_token(authorization)
    return {"answer": "API externa FastAPI disponible. La ejecucion productiva de herramientas vive en Laravel.", "intent": payload.intent}


@app.post("/api/v1/transcribe")
def external_transcribe(payload: TranscribeRequest, authorization: str | None = Header(default=None)) -> dict[str, Any]:
    require_external_token(authorization)
    return {"text": "", "message": "Transcripcion externa pendiente de motor local.", "audio_path": payload.audio_path}


@app.post("/api/v1/tools")
def external_tools(payload: dict[str, Any], authorization: str | None = Header(default=None)) -> dict[str, Any]:
    require_external_token(authorization)
    return execute_tool(payload)
