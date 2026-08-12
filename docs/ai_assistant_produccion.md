# Chatbot IA ERP/POS - Operacion y API

## Activacion

El chatbot IA solo funciona si `sistemasuperadmin` activa:

- `modules.ai_assistant`
- `capabilities.uses_ai_assistant`
- `feature_flags.ai_assistant_engine`

El perfil actual no cambia si estas llaves no estan activas.

## FastAPI

Variables recomendadas:

- `AI_INTERNAL_TOKEN`: debe coincidir con `ai_assistant.fastapi.internal_token`.
- `AI_DATA_DIR`: carpeta persistente del indice RAG local.
- `AI_STORAGE_ROOT`: ruta compartida hacia `storage/app` si FastAPI corre fuera del monolito.
- `AI_EXTERNAL_API_TOKEN` / `AI_EXTERNAL_API_TOKENS`: solo si se expondran endpoints externos directos de FastAPI.
- `AI_EXTERNAL_API_SCOPES`: scopes habilitados para endpoints externos directos de FastAPI.
- `AI_LLM_PROVIDER`: `rag_local` por defecto, o `openai_compatible` para conectar un modelo local/open-source.
- `AI_LLM_BASE_URL`: base de Ollama, vLLM, LM Studio u otro servidor compatible con OpenAI API.
- `AI_LLM_MODEL`: modelo local activo.
- `AI_LLM_API_KEY`: clave opcional si el servidor local la exige.
- `AI_WHISPER_MODEL_SIZE`: modelo local de transcripcion, recomendado `base` en CPU.
- `AI_WHISPER_DEVICE`: `cpu` por defecto.
- `AI_WHISPER_COMPUTE_TYPE`: `int8` por defecto para reducir consumo.

Laravel firma cada request interna con:

- `Authorization: Bearer <internal_token>`
- `X-ERP-Timestamp`
- `X-ERP-Signature`

FastAPI rechaza firmas vencidas o invalidas cuando `AI_INTERNAL_TOKEN` esta configurado. Tambien bloquea prompts con intentos explicitos de saltar reglas, revelar credenciales o ejecutar SQL.

El indice RAG local se actualiza desde Laravel con:

```text
POST /embed/index
```

Laravel envia productos, servicios y documentos permitidos. FastAPI los guarda en `AI_DATA_DIR/knowledge.jsonl` y responde solo con fuentes recuperadas o herramientas seguras.

Para usar un modelo local real sin depender de OpenAI, configurar FastAPI con:

```text
AI_LLM_PROVIDER=openai_compatible
AI_LLM_BASE_URL=http://localhost:11434/v1
AI_LLM_MODEL=llama3.1:8b
```

El servidor puede ser Ollama, vLLM, LM Studio u otro compatible. Si no esta disponible, FastAPI vuelve al RAG local deterministico sin romper el ERP/POS.

## API Externa Laravel

Los endpoints externos recomendados para otros sistemas son los de Laravel, porque ahi se validan cliente, scopes, permisos, sucursal, perfil y auditoria de negocio.

Base:

```text
/api/ai-assistant/v1
```

Header:

```text
Authorization: Bearer aia_xxx
```

Endpoints:

- `GET /status`
- `POST /chat`
- `POST /transcribe`
- `POST /tools`

Scopes:

- `external_api`: requerido para `status` y `tools`.
- `chat`: requerido para `chat`.
- `voice`: requerido para `transcribe`.
- `reports`: requerido para exportaciones.
- `orders`: requerido para acciones `crear_*`.

Ejemplo `POST /chat`:

```json
{
  "message": "Cuanto vendi este mes?",
  "external_user_ref": "cliente-o-sistema-123"
}
```

## API Externa Directa FastAPI

Solo debe exponerse si hay una razon tecnica real. Requiere `AI_EXTERNAL_API_TOKEN` o `AI_EXTERNAL_API_TOKENS`.

Base:

```text
/api/v1
```

Endpoints:

- `GET /status`
- `POST /chat`
- `POST /transcribe`
- `POST /tools`

FastAPI valida token y scopes configurados por entorno, pero no consulta la base de datos ni ejecuta herramientas operativas. Para ventas, pedidos, cotizaciones, exportaciones o datos sensibles, usar la API Laravel.

Ejemplo `POST /tools`:

```json
{
  "tool": "consultar_ventas",
  "input": {
    "from": "2026-08-01",
    "to": "2026-08-11"
  }
}
```

## Logs y Revocacion

Cada request externa registra cliente API, usuario vinculado, endpoint, scope, estado, codigo HTTP, IP hasheada y user-agent hasheado.

Desde `sistemasuperadmin` se puede revocar un cliente API. Un token revocado deja de funcionar inmediatamente.

## Archivos Generados

Las exportaciones generadas por IA se guardan en `storage/app/ai-assistant`.

La descarga se realiza solo por ruta autenticada:

```text
GET /ai-assistant/files/{message}
```

El usuario solo puede descargar archivos de sus conversaciones, salvo superadministrador.

## Prompt Injection

El guard bloquea solicitudes que intentan saltar permisos, revelar tokens, revelar credenciales o ejecutar SQL libre.

La respuesta queda registrada en la conversacion sin llamar a FastAPI ni ejecutar herramientas.

## Telegram

El webhook soporta texto y audio por `file_id`.

Si el canal tiene `settings.secret_token`, Telegram debe enviar:

```text
X-Telegram-Bot-Api-Secret-Token
```

Para audio:

1. Laravel llama `getFile`.
2. Descarga el archivo desde Telegram.
3. Guarda el audio en storage local.
4. Envia la ruta interna a FastAPI `/transcribe`.
5. FastAPI valida existencia y tamano del archivo. Si corre separado, `AI_STORAGE_ROOT` debe apuntar al storage compartido.

Si `faster-whisper` y el modelo configurado estan disponibles, FastAPI transcribe localmente. Si no hay motor STT instalado o disponible, la transcripcion queda en fallback explicito y el ERP/POS no se rompe.

## WhatsApp / Meta

Endpoints:

- `GET /ai-assistant/webhooks/meta/verify`
- `POST /ai-assistant/webhooks/meta/{channel}`

Si el canal tiene `app_secret`, se valida `X-Hub-Signature-256`.

## Garantias

- Sin SQL libre generado por IA.
- Sin acciones criticas sin confirmacion.
- Sin exportacion sin permiso.
- Sin acceso externo sin token y scopes.
- Sin ruptura del ERP/POS si FastAPI cae: Laravel usa fallback controlado.
