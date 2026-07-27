import os
import joblib
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from typing import Optional
from contextlib import asynccontextmanager

HERE = os.path.abspath(os.path.dirname(__file__))
MODEL_PATH = os.path.join(HERE, "modelo_fatiga.pkl")

class PredictRequest(BaseModel):
    ojos: float
    bostezos: int
    tiempo: float

class PredictResponse(BaseModel):
    fatiga: float

@asynccontextmanager
async def lifespan(app: FastAPI):
    # Startup
    global model
    if not os.path.exists(MODEL_PATH):
        raise RuntimeError(f"Modelo no encontrado en {MODEL_PATH}")
    model = joblib.load(MODEL_PATH)
    print(f"[Sistema] Modelo cargado desde {MODEL_PATH}")
    yield
    # Shutdown
    print("[Sistema] Servicio detenido")

app = FastAPI(title="Predictor Fatiga Service", lifespan=lifespan)
model = None

@app.get("/")
def root():
    return {
        "service": "Predictor Fatiga Service",
        "status": "running",
        "endpoints": {
            "predict": {
                "method": "POST",
                "path": "/predict",
                "body": {
                    "ojos": "float (apertura de ojos)",
                    "bostezos": "int (cantidad de bostezos)",
                    "tiempo": "float (tiempo en minutos)"
                },
                "response": {
                    "fatiga": "float (0-100)"
                }
            }
        }
    }

@app.post("/predict", response_model=PredictResponse)
def predict(req: PredictRequest):
    if model is None:
        raise HTTPException(status_code=500, detail="Modelo no cargado")
    try:
        features = [[float(req.ojos), float(req.bostezos), float(req.tiempo)]]
        pred = model.predict(features)
        fatiga = float(pred[0])
        return PredictResponse(fatiga=fatiga)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=8000)
