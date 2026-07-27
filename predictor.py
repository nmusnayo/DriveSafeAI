import joblib
import numpy as np
import sys
import os

# Cargar modelo relativo al directorio del script (funciona aunque el proceso se ejecute desde otra carpeta)
MODEL_PATH = os.path.join(os.path.dirname(__file__), "modelo_fatiga.pkl")
modelo = joblib.load(MODEL_PATH)

def predecir_fatiga(ojos, bostezos, tiempo):
    datos = np.array([[ojos, bostezos, tiempo]])
    return float(modelo.predict(datos)[0])


if __name__ == "__main__":
    # Permite invocar desde la línea de comandos: python predictor.py <ojos> <bostezos> <tiempo>
    if len(sys.argv) >= 4:
        try:
            ojos = float(sys.argv[1])
            bostezos = float(sys.argv[2])
            tiempo = float(sys.argv[3])
            print(predecir_fatiga(ojos, bostezos, tiempo))
        except Exception as e:
            print("0.0")
    else:
        print("0.0")