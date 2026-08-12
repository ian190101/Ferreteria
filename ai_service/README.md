# Servicio Python FastAPI del Chatbot IA

Este servicio es el motor externo preparado para el chatbot propio del ERP/POS.

Modo inicial recomendado:

- `cpu_light`
- mismo servidor durante pruebas
- migrable a VPS o instalacion local

Endpoints:

- `GET /health`
- `POST /chat`
- `POST /transcribe`
- `POST /embed/index`
- `POST /tools/execute`
- `GET /api/v1/status`
- `POST /api/v1/chat`
- `POST /api/v1/transcribe`
- `POST /api/v1/tools`

Instalacion local:

```bash
cd ai_service
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
uvicorn app.main:app --host 0.0.0.0 --port 8010
```

El modelo local real debe configurarse en variables de entorno. El fallback incluido no inventa datos: solo devuelve una respuesta controlada para validar conectividad.
