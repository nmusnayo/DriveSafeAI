@echo off
echo Instalando dependencias de Python para DriveSafe AI...
echo.
echo NOTA: Pygame es opcional (solo para alertas sonoras)
echo Si falla la instalacion de pygame, el sistema funcionara sin sonido
echo.
pip install opencv-python mediapipe numpy scikit-learn joblib pandas fastapi uvicorn mysql-connector-python
echo.
echo Dependencias principales instaladas.
echo.
echo Opcional: Instalar pygame para alertas sonoras
pip install pygame
echo.
echo Instalacion completada.
pause
