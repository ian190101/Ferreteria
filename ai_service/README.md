# Servicio Python FastAPI del Chatbot IA

Este servicio es el motor externo preparado para el chatbot propio del ERP/POS.

Modo inicial recomendado:

- `cpu_light`
- mismo servidor durante pruebas
- migrable a VPS o instalacion local
- RAG local persistente en archivo JSONL
- herramientas productivas ejecutadas solo por Laravel

## Instalacion local

```bash
cd ai_service
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
uvicorn app.main:app --host 0.0.0.0 --port 8010
```

## Variables de entorno

- `AI_INTERNAL_TOKEN`: token compartido con Laravel para firma HMAC.
- `AI_MODEL_MODE`: modo visible en health, por defecto `cpu_light`.
- `AI_MODEL_NAME`: nombre visible del motor, por defecto `rag_ligero_local`.
- `AI_LLM_PROVIDER`: `rag_local` por defecto, o `openai_compatible` para Ollama/vLLM/LM Studio.
- `AI_LLM_BASE_URL`: base del servidor local compatible, por ejemplo `http://localhost:11434/v1`.
- `AI_LLM_API_KEY`: clave opcional del servidor local compatible.
- `AI_LLM_MODEL`: modelo a usar, por ejemplo `llama3.1:8b`.
- `AI_DATA_DIR`: carpeta para el indice local, por defecto `ai_service/.data`.
- `AI_STORAGE_ROOT`: raiz de storage compartido si FastAPI corre separado.
- `AI_MAX_AUDIO_BYTES`: limite de audio, por defecto 15 MB.
- `AI_WHISPER_MODEL_SIZE`: modelo STT, por defecto `base`.
- `AI_WHISPER_DEVICE`: `cpu` por defecto.
- `AI_WHISPER_COMPUTE_TYPE`: `int8` por defecto para CPU.
- `AI_EXTERNAL_API_TOKEN` o `AI_EXTERNAL_API_TOKENS`: tokens externos directos de FastAPI.
- `AI_EXTERNAL_API_SCOPES`: scopes externos directos permitidos, por defecto `status,chat,voice,tools`.

## Seguridad interna

Si se configura `AI_INTERNAL_TOKEN`, Laravel debe firmar cada request con:

- `Authorization: Bearer <token>`
- `X-ERP-Timestamp`
- `X-ERP-Signature`

La firma usa HMAC SHA-256 sobre `timestamp.body`. Esto evita llamadas internas falsificadas.

## Endpoints internos

- `GET /health`
- `POST /health`
- `POST /chat`
- `POST /transcribe`
- `POST /embed/index`
- `POST /tools/execute`

## Endpoints externos preparados

- `GET /api/v1/status`
- `POST /api/v1/chat`
- `POST /api/v1/transcribe`
- `POST /api/v1/tools`

En produccion, la validacion fuerte de permisos, sucursal, auditoria y herramientas vive en Laravel. FastAPI valida sus propios tokens externos si se exponen sus endpoints `/api/v1/*`, pero no ejecuta SQL libre ni acciones directas sobre la base de datos.

## Modelo local

El modo incluido usa recuperacion local sobre documentos indexados por Laravel mediante `/embed/index`. No inventa datos internos: responde desde herramienta segura, desde el indice RAG local o pide reindexar/usar herramienta ERP.

Para conectar un modelo local/open-source sin depender de OpenAI, levantar un servidor compatible con OpenAI API, por ejemplo Ollama, vLLM o LM Studio, y configurar:

```bash
set AI_LLM_PROVIDER=openai_compatible
set AI_LLM_BASE_URL=http://localhost:11434/v1
set AI_LLM_MODEL=llama3.1:8b
```

El prompt del sistema obliga a responder en espanol, usar solo contexto permitido, no ejecutar SQL y no revelar secretos.

Para transcripcion real se usa `faster-whisper`. En CPU ligero se recomienda `AI_WHISPER_MODEL_SIZE=base`, `AI_WHISPER_DEVICE=cpu` y `AI_WHISPER_COMPUTE_TYPE=int8`. Si el paquete o modelo no estan disponibles, el servicio valida existencia/tamano del audio y devuelve fallback explicito sin romper el ERP/POS.
