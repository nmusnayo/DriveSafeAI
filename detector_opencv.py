import cv2
import numpy as np
import math
import time
import os

from config import guardar_alerta, guardar_muestra_ml
from predictor import predecir_fatiga

print("Iniciando DriveSafe AI (OpenCV)...")

# =========================
# SONIDO (OPCIONAL)
# =========================
pygame_disponible = False
sonido_alerta = None
try:
    import pygame
    pygame.mixer.init()
    sonido_alerta = pygame.mixer.Sound("sounds/alerta.wav")
    pygame_disponible = True
    print("[Sistema] Sonido de alerta activado")
except ImportError:
    print("[Sistema] Pygame no disponible - alertas sonoras desactivadas")
except Exception as e:
    print(f"[Sistema] Error inicializando sonido: {e}")

# =========================
# OPENCV HAAR CASCADES
# =========================
# Cargar clasificadores Haar
face_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
eye_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_eye.xml')

# =========================
# CAMARA
# =========================
cap = cv2.VideoCapture(0)

# =========================
# VARIABLES IA
# =========================
contador_bostezos = 0
ojos_cerrados_inicio = None
ojos_abiertos_inicio = None

alarma_activa = False
ultimo_guardado_alerta = 0

fatiga = 0

# 🧠 TIEMPO CONDUCCIÓN
inicio = time.time()

# =========================
# FUNCIONES
# =========================

def eye_ratio_opencv(eyes):
    """Calcula el ratio de apertura de ojos usando detección de OpenCV"""
    if len(eyes) == 0:
        return 0.3  # Valor por defecto si no se detectan ojos
    
    # Usar el ojo más grande detectado
    largest_eye = max(eyes, key=lambda e: e[2] * e[3])
    x, y, w, h = largest_eye
    
    # Ratio simple: altura / ancho
    if w > 0:
        return h / w
    return 0.3

def detectar_bostezo_opencv(face_roi):
    """Detecta bostezo analizando la región de la boca"""
    # Región inferior de la cara (boca)
    height, width = face_roi.shape[:2]
    mouth_region = face_roi[int(height*0.6):, :]
    
    if mouth_region.size == 0:
        return False
    
    # Convertir a escala de grises y aplicar umbral
    gray_mouth = cv2.cvtColor(mouth_region, cv2.COLOR_BGR2GRAY)
    _, thresh = cv2.threshold(gray_mouth, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    
    # Contar píxeles oscuros (boca abierta)
    dark_pixels = np.sum(thresh == 0)
    total_pixels = thresh.size
    
    if total_pixels > 0:
        ratio = dark_pixels / total_pixels
        # Ajustar umbral según pruebas
        return ratio > 0.3
    return False

def obtener_nivel(f):
    if f >= 70:
        return "CRITICO"
    elif f >= 40:
        return "ALTO"
    elif f >= 20:
        return "MEDIO"
    return "BAJO"

def obtener_recomendacion(nivel):
    return {
        "BAJO": "Conducción normal",
        "MEDIO": "Hidratación recomendada",
        "ALTO": "Deténgase pronto",
        "CRITICO": "DETENER VEHICULO"
    }[nivel]

def sonar_alarma():
    global alarma_activa
    if not alarma_activa and pygame_disponible:
        sonido_alerta.play(-1)
        alarma_activa = True

def apagar_alarma():
    global alarma_activa
    if alarma_activa and pygame_disponible:
        sonido_alerta.stop()
        alarma_activa = False

def registrar_alerta_si_corresponde(estado, nivel, fatiga, recomendacion, ojos, bostezos, tiempo):
    global ultimo_guardado_alerta

    ahora = time.time()
    if nivel not in ["ALTO", "CRITICO"]:
        return

    if ahora - ultimo_guardado_alerta < 15:
        return

    evento = "FATIGA_CRITICA" if nivel == "CRITICO" else "FATIGA_ALTA"
    if estado == "BOSTEZO":
        evento = "BOSTEZO"

    guardar_alerta(evento, nivel, fatiga, recomendacion)
    guardar_muestra_ml(evento, nivel, fatiga, ojos, bostezos, tiempo)
    ultimo_guardado_alerta = ahora

# =========================
# LOOP PRINCIPAL
# =========================
while True:
    ret, frame = cap.read()
    if not ret:
        break

    frame = cv2.flip(frame, 1)
    gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)

    estado = "NORMAL"
    color = (0, 255, 0)

    # Detectar cara
    faces = face_cascade.detectMultiScale(gray, 1.3, 5)

    if len(faces) > 0:
        # Usar la cara más grande
        face = max(faces, key=lambda f: f[2] * f[3])
        x, y, w, h = face
        
        # Región de interés (ROI)
        face_roi = frame[y:y+h, x:x+w]
        gray_roi = gray[y:y+h, x:x+w]
        
        # Detectar ojos en la cara
        eyes = eye_cascade.detectMultiScale(gray_roi)
        
        # =========================
        # OJOS
        # =========================
        promedio_ojos = eye_ratio_opencv(eyes)
        
        # =========================
        # BOCA (BOSTEZO)
        # =========================
        es_bostezo = detectar_bostezo_opencv(face_roi)
        
        if es_bostezo:
            contador_bostezos += 1
            estado = "BOSTEZO"
            color = (0, 165, 255)

        # =========================
        # TIEMPO CONDUCCIÓN
        # =========================
        tiempo_conduccion = (time.time() - inicio) / 60

        # =========================
        # 🤖 IA PREDICTIVA
        # =========================
        fatiga = predecir_fatiga(
            promedio_ojos,
            contador_bostezos,
            tiempo_conduccion
        )

        nivel = obtener_nivel(fatiga)
        recomendacion = obtener_recomendacion(nivel)

        # =========================
        # ALERTAS
        # =========================
        if nivel in ["ALTO", "CRITICO"]:
            sonar_alarma()
        else:
            apagar_alarma()

        registrar_alerta_si_corresponde(
            estado,
            nivel,
            fatiga,
            recomendacion,
            promedio_ojos,
            contador_bostezos,
            tiempo_conduccion
        )

        if nivel == "CRITICO":
            cv2.rectangle(frame, (0,0),
                          (frame.shape[1], frame.shape[0]),
                          (0,0,255), 10)

            cv2.putText(frame,
                        "PELIGRO DE FATIGA",
                        (50, 250),
                        cv2.FONT_HERSHEY_SIMPLEX,
                        1.5,
                        (0,0,255),
                        4)

        # Dibujar rectángulo alrededor de la cara
        cv2.rectangle(frame, (x, y), (x+w, y+h), (255, 0, 0), 2)
        
        # Dibujar ojos detectados
        for (ex, ey, ew, eh) in eyes:
            cv2.rectangle(frame, (x+ex, y+ey), (x+ex+ew, y+ey+eh), (0, 255, 0), 2)

        # =========================
        # UI
        # =========================
        cv2.putText(frame,
                    f"Fatiga: {int(fatiga)}",
                    (20, 40),
                    cv2.FONT_HERSHEY_SIMPLEX,
                    1,
                    color, 2)

        cv2.putText(frame,
                    f"Nivel: {nivel}",
                    (20, 80),
                    cv2.FONT_HERSHEY_SIMPLEX,
                    1,
                    color, 2)

        cv2.putText(frame,
                    f"Bostezos: {contador_bostezos}",
                    (20, 120),
                    cv2.FONT_HERSHEY_SIMPLEX,
                    1,
                    color, 2)

        cv2.putText(frame,
                    recomendacion,
                    (20, 160),
                    cv2.FONT_HERSHEY_SIMPLEX,
                    0.7,
                    color, 2)

    cv2.imshow("DriveSafe AI (OpenCV)", frame)

    if cv2.waitKey(1) & 0xFF == 27:
        break

cap.release()
cv2.destroyAllWindows()
if pygame_disponible:
    import pygame
    pygame.quit()
