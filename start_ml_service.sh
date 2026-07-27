#!/bin/bash
echo "Iniciando servicio de prediccion ML (FastAPI)..."
cd "$(dirname "$0")"
python predictor_service.py
