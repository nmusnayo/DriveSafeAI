const video = document.getElementById("inputVideo");
const canvas = document.getElementById("outputCanvas");
const ctx = canvas.getContext("2d");

const startButton = document.getElementById("startButton");
const stopButton = document.getElementById("stopButton");
const connectionState = document.getElementById("connectionState");
const secureWarning = document.getElementById("secureWarning");
const fatigueValue = document.getElementById("fatigueValue");
const levelText = document.getElementById("levelText");
const recommendationText = document.getElementById("recommendationText");
const meterFill = document.getElementById("meterFill");
const yawnCountEl = document.getElementById("yawnCount");
const sleepCountEl = document.getElementById("sleepCount");
const sessionTimeEl = document.getElementById("sessionTime");
const faceState = document.getElementById("faceState");
const gpsState = document.getElementById("gpsState");
const routeState = document.getElementById("routeState");
const routeOrigin = document.getElementById("routeOrigin");
const routeDestination = document.getElementById("routeDestination");
const routeVehicle = document.getElementById("routeVehicle");
const incidentButton = document.getElementById("incidentButton");
const finishButton = document.getElementById("finishButton");
const panelLink = document.getElementById("panelLink");
const offlineNotice = document.getElementById("offlineNotice");
const panelUrl = panelLink?.dataset.panelUrl || "index.php";

const LEFT_EYE = [33, 160, 158, 133, 153, 144];
const RIGHT_EYE = [362, 385, 387, 263, 373, 380];
const UPPER_LIP = 13;
const LOWER_LIP = 14;

let camera = null;
let faceMesh = null;
let audioContext = null;
let alarmOscillator = null;
let sessionStartedAt = 0;
let yawnCount = 0;
let sleepCount = 0;
let eyesClosedAt = null;
let yawnActive = false;
let lastYawnAt = 0;
let lastHighAlertAt = 0;
let timerId = null;
let routeId = null;
let routeIsLocal = false;
let watchId = null;
let lastPosition = null;
let lastPositionSentAt = 0;
let evidenceRecorder = null;
let evidenceChunks = [];
let lastEvidenceAt = 0;
let lastEar = null;

const EVIDENCE_WINDOW_MS = 12000;
const EVIDENCE_AFTER_ALERT_MS = 2500;
const EVIDENCE_COOLDOWN_MS = 15000;
const OFFLINE_DB = "drivesafe-offline-v1";
const OFFLINE_STORE = "queue";
const ROUTE_MAP_STORE = "routeMap";

let syncInProgress = false;

if (!window.isSecureContext) {
    secureWarning.hidden = false;
}

function openOfflineDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(OFFLINE_DB, 1);

        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains(OFFLINE_STORE)) {
                const store = db.createObjectStore(OFFLINE_STORE, { keyPath: "id", autoIncrement: true });
                store.createIndex("createdAt", "createdAt");
            }
            if (!db.objectStoreNames.contains(ROUTE_MAP_STORE)) {
                db.createObjectStore(ROUTE_MAP_STORE, { keyPath: "localRouteId" });
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function dbRequest(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function addOfflineJob(type, payload) {
    const db = await openOfflineDb();
    const tx = db.transaction(OFFLINE_STORE, "readwrite");
    const store = tx.objectStore(OFFLINE_STORE);
    await dbRequest(store.add({ type, payload, createdAt: Date.now(), attempts: 0 }));
    db.close();
    await updateOfflineState();
}

async function getOfflineJobs() {
    const db = await openOfflineDb();
    const tx = db.transaction(OFFLINE_STORE, "readonly");
    const store = tx.objectStore(OFFLINE_STORE);
    const jobs = await dbRequest(store.getAll());
    db.close();
    return jobs.sort((a, b) => a.createdAt - b.createdAt);
}

async function deleteOfflineJob(id) {
    const db = await openOfflineDb();
    const tx = db.transaction(OFFLINE_STORE, "readwrite");
    await dbRequest(tx.objectStore(OFFLINE_STORE).delete(id));
    db.close();
}

async function updateOfflineJob(job) {
    const db = await openOfflineDb();
    const tx = db.transaction(OFFLINE_STORE, "readwrite");
    await dbRequest(tx.objectStore(OFFLINE_STORE).put(job));
    db.close();
}

async function setLocalMapping(localId, serverId) {
    const db = await openOfflineDb();
    const tx = db.transaction(ROUTE_MAP_STORE, "readwrite");
    await dbRequest(tx.objectStore(ROUTE_MAP_STORE).put({ localRouteId: localId, serverRouteId: serverId }));
    db.close();
}

async function getLocalMapping(localId) {
    if (!localId) return localId;
    if (!String(localId).startsWith("local-") && !String(localId).startsWith("alert-")) return localId;

    const db = await openOfflineDb();
    const tx = db.transaction(ROUTE_MAP_STORE, "readonly");
    const row = await dbRequest(tx.objectStore(ROUTE_MAP_STORE).get(localId));
    db.close();
    return row?.serverRouteId ?? null;
}

async function countOfflineJobs() {
    const db = await openOfflineDb();
    const tx = db.transaction(OFFLINE_STORE, "readonly");
    const count = await dbRequest(tx.objectStore(OFFLINE_STORE).count());
    db.close();
    return count;
}

async function updateOfflineState() {
    const pending = await countOfflineJobs().catch(() => 0);
    const online = navigator.onLine;

    if (offlineNotice) {
        offlineNotice.hidden = online && pending === 0;
        offlineNotice.textContent = online
            ? `${pending} evento(s) pendiente(s) por sincronizar.`
            : `Sin conexion. ${pending} evento(s) guardado(s) en este dispositivo.`;
    }

    if (connectionState && pending > 0 && !syncInProgress) {
        connectionState.textContent = online ? `Pendientes ${pending}` : "Offline";
    }
}

async function postJson(url, payload) {
    const response = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return response.json();
}

async function postForm(url, formData) {
    const response = await fetch(url, {
        method: "POST",
        body: formData
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return response.json();
}

async function resolvePayloadRoute(payload) {
    const nextPayload = { ...payload };
    if (String(nextPayload.id_viaje ?? "").startsWith("local-")) {
        const mapped = await getLocalMapping(nextPayload.id_viaje);
        if (!mapped) return null;
        nextPayload.id_viaje = mapped;
    }
    return nextPayload;
}

async function syncOfflineJobs() {
    if (syncInProgress || !navigator.onLine) return;

    syncInProgress = true;
    connectionState.textContent = "Sincronizando";

    try {
        const jobs = await getOfflineJobs();

        for (const job of jobs) {
            try {
                if (job.type === "tracking") {
                    const payload = job.payload.action === "start"
                        ? job.payload
                        : await resolvePayloadRoute(job.payload);

                    if (!payload) continue;

                    const result = await postJson("api_tracking.php", payload);
                    if (payload.action === "start" && result?.id_viaje && job.payload.localRouteId) {
                        await setLocalMapping(job.payload.localRouteId, result.id_viaje);
                        if (routeId === job.payload.localRouteId) {
                            routeId = result.id_viaje;
                            routeIsLocal = false;
                        }
                    }
                }

                if (job.type === "alert") {
                    const payload = await resolvePayloadRoute(job.payload);
                    if (!payload) continue;
                    const result = await postJson("api_alerts.php", payload);
                    if (result?.id_alerta && job.payload.clientAlertId) {
                        await setLocalMapping(job.payload.clientAlertId, result.id_alerta);
                    }
                }

                if (job.type === "evidence") {
                    const payload = await resolvePayloadRoute(job.payload);
                    if (!payload) continue;
                    const alertId = payload.id_alerta || await getLocalMapping(payload.clientAlertId);
                    const formData = new FormData();
                    formData.append("video", payload.blob, payload.filename);
                    formData.append("id_viaje", String(payload.id_viaje));
                    formData.append("id_alerta", alertId ? String(alertId) : "");
                    formData.append("evento", payload.evento);
                    formData.append("nivel", payload.nivel);
                    formData.append("duration_ms", String(payload.duration_ms));
                    formData.append("latitud", payload.latitud ?? "");
                    formData.append("longitud", payload.longitud ?? "");
                    formData.append("fecha_evento", payload.fecha_evento ?? "");
                    await postForm("api_evidence.php", formData);
                }

                await deleteOfflineJob(job.id);
            } catch (error) {
                job.attempts = (job.attempts || 0) + 1;
                job.lastError = error.message;
                await updateOfflineJob(job);
                break;
            }
        }
    } finally {
        syncInProgress = false;
        await updateOfflineState();
        if (navigator.onLine && routeId) {
            connectionState.textContent = "Monitoreando";
        }
    }
}

window.addEventListener("online", () => {
    updateOfflineState();
    syncOfflineJobs();
});

window.addEventListener("offline", updateOfflineState);

updateOfflineState();
window.setTimeout(syncOfflineJobs, 800);

function distance(a, b) {
    return Math.hypot(a.x - b.x, a.y - b.y);
}

function eyeRatio(landmarks, eye) {
    const vertical = distance(landmarks[eye[1]], landmarks[eye[5]]);
    const horizontal = distance(landmarks[eye[0]], landmarks[eye[3]]);
    return horizontal ? vertical / horizontal : 0;
}

function getLevel(fatigue) {
    if (fatigue >= 72) return "CRITICO";
    if (fatigue >= 48) return "ALTO";
    if (fatigue >= 24) return "MEDIO";
    return "BAJO";
}

function getRecommendation(level) {
    return {
        BAJO: "Conduccion normal",
        MEDIO: "Mantenga ventilacion e hidratacion",
        ALTO: "Detengase pronto en un lugar seguro",
        CRITICO: "Detener vehiculo inmediatamente"
    }[level];
}

function updateMeter(fatigue, level) {
    fatigueValue.textContent = Math.round(fatigue);
    levelText.textContent = level;
    recommendationText.textContent = getRecommendation(level);
    meterFill.style.width = `${Math.min(100, Math.max(0, fatigue))}%`;
    meterFill.style.background = {
        BAJO: "#0f766e",
        MEDIO: "#f59e0b",
        ALTO: "#dc2626",
        CRITICO: "#7c2d12"
    }[level];
}

function formatTime(totalSeconds) {
    const minutes = String(Math.floor(totalSeconds / 60)).padStart(2, "0");
    const seconds = String(totalSeconds % 60).padStart(2, "0");
    return `${minutes}:${seconds}`;
}

function startTimer() {
    timerId = window.setInterval(() => {
        const elapsed = Math.floor((Date.now() - sessionStartedAt) / 1000);
        sessionTimeEl.textContent = formatTime(elapsed);
    }, 1000);
}

function startAlarm() {
    if (alarmOscillator) return;
    audioContext ||= new AudioContext();
    alarmOscillator = audioContext.createOscillator();
    const gain = audioContext.createGain();
    alarmOscillator.type = "sine";
    alarmOscillator.frequency.value = 880;
    gain.gain.value = 0.05;
    alarmOscillator.connect(gain);
    gain.connect(audioContext.destination);
    alarmOscillator.start();
}

function stopAlarm() {
    if (!alarmOscillator) return;
    alarmOscillator.stop();
    alarmOscillator.disconnect();
    alarmOscillator = null;
}
// Guardar alerta en el servidor o en la cola offline
async function saveAlert(evento, nivel, fatiga, recomendacion, features = {}) {
    const clientAlertId = `alert-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const fechaEvento = new Date().toISOString();
    // enviar también las features usadas por el cliente para ML
    const payload = {
        evento,
        nivel,
        fatiga,
        recomendacion,
        id_viaje: routeId,
        latitud: lastPosition?.latitud ?? null,
        longitud: lastPosition?.longitud ?? null,
        fecha_evento: fechaEvento,
        clientAlertId,
        // Features para entrenamiento/predicción
        ojos: typeof features.ojos !== 'undefined' ? features.ojos : (typeof lastEar !== 'undefined' && lastEar !== null ? lastEar : null),
        bostezos: typeof features.bostezos !== 'undefined' ? features.bostezos : (typeof yawnCount !== 'undefined' ? yawnCount : null),
        tiempo: typeof features.tiempo !== 'undefined' ? features.tiempo : (sessionStartedAt ? Math.floor((Date.now() - sessionStartedAt) / 60000) : 0)
    };

    try {
        const result = await postJson("api_alerts.php", payload);
        if (result?.id_alerta) {
            await setLocalMapping(clientAlertId, result.id_alerta);
        }
        return { ...result, clientAlertId };
    } catch (error) {
        console.warn("No se pudo registrar alerta", error);
        await addOfflineJob("alert", payload);
        return { ok: false, offline: true, clientAlertId };
    }
}

function startEvidenceRecorder() {
    if (evidenceRecorder || !video.srcObject || !window.MediaRecorder) return;

    const mimeType = MediaRecorder.isTypeSupported("video/webm;codecs=vp8")
        ? "video/webm;codecs=vp8"
        : "video/webm";

    try {
        evidenceRecorder = new MediaRecorder(
            video.srcObject,
            {
                mimeType,
                videoBitsPerSecond: 500000
            }
        );
    } catch (error) {
        console.warn("Grabacion de evidencia no disponible", error);
        return;
    }

    evidenceChunks = [];
    evidenceRecorder.ondataavailable = (event) => {
        if (!event.data || event.data.size === 0) return;

        const now = Date.now();
        evidenceChunks.push({ blob: event.data, createdAt: now });
        evidenceChunks = evidenceChunks.filter((chunk) => now - chunk.createdAt <= EVIDENCE_WINDOW_MS);
    };

    evidenceRecorder.start(1000);
}

function stopEvidenceRecorder() {
    if (!evidenceRecorder) return;

    if (evidenceRecorder.state !== "inactive") {
        evidenceRecorder.stop();
    }

    evidenceRecorder = null;
}

async function uploadEvidenceClip(evento, nivel, idAlerta = null) {
    if (!routeId || !evidenceRecorder || evidenceChunks.length === 0) return;

    const evidenceRouteId = routeId;
    const evidenceMimeType = evidenceRecorder.mimeType || "video/webm";
    const now = Date.now();
    if (now - lastEvidenceAt < EVIDENCE_COOLDOWN_MS) return;
    lastEvidenceAt = now;

    await new Promise((resolve) => window.setTimeout(resolve, EVIDENCE_AFTER_ALERT_MS));

    const chunks = evidenceChunks.filter((chunk) => now - chunk.createdAt <= EVIDENCE_WINDOW_MS + EVIDENCE_AFTER_ALERT_MS);
    if (chunks.length === 0) return;

    const blob = new Blob(chunks.map((chunk) => chunk.blob), { type: evidenceMimeType });
    const formData = new FormData();
    const filename = `evidencia_${evento.toLowerCase()}_${Date.now()}.webm`;
    formData.append("video", blob, filename);
    formData.append("id_viaje", String(evidenceRouteId));
    formData.append("id_alerta", idAlerta ? String(idAlerta) : "");
    formData.append("evento", evento);
    formData.append("nivel", nivel);
    formData.append("duration_ms", String(EVIDENCE_WINDOW_MS + EVIDENCE_AFTER_ALERT_MS));
    formData.append("latitud", lastPosition?.latitud ?? "");
    formData.append("longitud", lastPosition?.longitud ?? "");
    formData.append("fecha_evento", new Date(now).toISOString());

    try {
        await postForm("api_evidence.php", formData);
    } catch (error) {
        console.warn("No se pudo subir evidencia", error);
        await addOfflineJob("evidence", {
            id_viaje: evidenceRouteId,
            clientAlertId: idAlerta && String(idAlerta).startsWith("alert-") ? idAlerta : null,
            id_alerta: idAlerta && !String(idAlerta).startsWith("alert-") ? idAlerta : null,
            evento,
            nivel,
            duration_ms: EVIDENCE_WINDOW_MS + EVIDENCE_AFTER_ALERT_MS,
            latitud: lastPosition?.latitud ?? null,
            longitud: lastPosition?.longitud ?? null,
            fecha_evento: new Date(now).toISOString(),
            filename,
            blob
        });
    }
}

function saveAlertWithEvidence(evento, nivel, fatiga, recomendacion, forceEvidence = false, features = {}) {
    saveAlert(evento, nivel, fatiga, recomendacion, features).then((alert) => {
        if (forceEvidence || nivel === "ALTO" || nivel === "CRITICO") {
            uploadEvidenceClip(evento, nivel, alert?.id_alerta ?? alert?.clientAlertId ?? null);
        }
    });
}

async function tracking(action, data = {}) {
    const payload = { action, ...data };

    try {
        return await postJson("api_tracking.php", payload);
    } catch (error) {
        await addOfflineJob("tracking", payload);
        return { ok: false, offline: true };
    }
}

function startGpsTracking() {
    if (!navigator.geolocation) {
        gpsState.textContent = "No";
        return;
    }

    watchId = navigator.geolocation.watchPosition(
        async (position) => {
            const coords = position.coords;
            lastPosition = {
                latitud: coords.latitude,
                longitud: coords.longitude,
                precision_gps: coords.accuracy,
                velocidad: coords.speed !== null ? coords.speed * 3.6 : null
            };

            gpsState.textContent = `${Math.round(coords.accuracy)}m`;

            const now = Date.now();
            if (routeId && now - lastPositionSentAt > 10000) {
                lastPositionSentAt = now;
                await tracking("position", { id_viaje: routeId, ...lastPosition }).catch(() => { });
            }
        },
        () => {
            gpsState.textContent = "Error";
        },
        {
            enableHighAccuracy: true,
            maximumAge: 5000,
            timeout: 12000
        }
    );
}

async function startRoute() {
    const origen = routeOrigin.value.trim();
    const destino = routeDestination.value.trim();
    const idVehiculo = routeVehicle?.value ? Number(routeVehicle.value) : null;
    const viajeIdInput = document.getElementById("viajeId");
    const existingViajeId = viajeIdInput?.value ? Number(viajeIdInput.value) : null;

    if (!origen || !destino) {
        routeOrigin.reportValidity();
        routeDestination.reportValidity();
        throw new Error("Ruta incompleta");
    }

    // Si ya hay un viaje_id (ruta programada iniciada), usarlo directamente
    if (existingViajeId && existingViajeId > 0) {
        routeId = existingViajeId;
        routeIsLocal = false;
        routeState.textContent = "Activa";
        incidentButton.disabled = false;
        startGpsTracking();
        return;
    }

    // Si no hay viaje_id, crear una nueva ruta
    const localRouteId = `local-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const result = await tracking("start", { origen, destino, id_vehiculo: idVehiculo, localRouteId });
    if (!result.ok) {
        if (!result.offline) {
            throw new Error(result.error || "No se pudo iniciar la ruta");
        }

        routeId = localRouteId;
        routeIsLocal = true;
        connectionState.textContent = "Offline";
    } else {
        routeId = result.id_viaje;
        routeIsLocal = false;
    }

    routeState.textContent = "Activa";
    incidentButton.disabled = false;
    routeOrigin.disabled = true;
    routeDestination.disabled = true;
    if (routeVehicle) routeVehicle.disabled = true;
    startGpsTracking();
}

async function endRoute() {
    if (!routeId) return;
    const currentRouteId = routeId;
    await tracking("end", { id_viaje: currentRouteId }).catch(() => { });
    routeId = null;
    routeIsLocal = false;
    routeState.textContent = "Cerrada";
    incidentButton.disabled = true;
    routeOrigin.disabled = false;
    routeDestination.disabled = false;
    if (routeVehicle) routeVehicle.disabled = false;
}

async function reportIncident(tipo = "ACCIDENTE", descripcion = "Accidente reportado por el conductor") {
    if (!routeId) return;

    await tracking("incident", {
        id_viaje: routeId,
        tipo,
        descripcion,
        latitud: lastPosition?.latitud ?? null,
        longitud: lastPosition?.longitud ?? null
    }).catch(() => { });
}

function drawFrame(results) {
    canvas.width = canvas.clientWidth;
    canvas.height = canvas.clientHeight;

    const sourceWidth = video.videoWidth || canvas.width;
    const sourceHeight = video.videoHeight || canvas.height;
    const scale = Math.min(canvas.width / sourceWidth, canvas.height / sourceHeight);
    const drawWidth = sourceWidth * scale;
    const drawHeight = sourceHeight * scale;
    const offsetX = (canvas.width - drawWidth) / 2;
    const offsetY = (canvas.height - drawHeight) / 2;

    ctx.save();
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.scale(-1, 1);
    ctx.drawImage(results.image, -(offsetX + drawWidth), offsetY, drawWidth, drawHeight);
    ctx.restore();
}

function analyze(results) {
    drawFrame(results);

    const landmarks = results.multiFaceLandmarks?.[0];
    const now = Date.now();
    const minutes = sessionStartedAt ? (now - sessionStartedAt) / 60000 : 0;

    if (!landmarks) {
        faceState.textContent = "No";
        updateMeter(Math.min(100, minutes * 1.2), "BAJO");
        return;
    }

    faceState.textContent = "Si";

    const left = eyeRatio(landmarks, LEFT_EYE);
    const right = eyeRatio(landmarks, RIGHT_EYE);
    const ear = (left + right) / 2;
    lastEar = ear;
    const mouth = distance(landmarks[UPPER_LIP], landmarks[LOWER_LIP]);

    if (ear < 0.18) {
        eyesClosedAt ||= now;
    } else {
        eyesClosedAt = null;
    }

    const closedDuration = eyesClosedAt ? (now - eyesClosedAt) / 1000 : 0;

    if (closedDuration > 1.25 && now - lastHighAlertAt > 5000) {
        sleepCount += 1;
        sleepCountEl.textContent = sleepCount;
        lastHighAlertAt = now;
        // llamadas con evidencia para microsueño critico
        saveAlertWithEvidence("MICROSUENO", "CRITICO", 90, getRecommendation("CRITICO"), true, {ojos: ear, bostezos: yawnCount, tiempo: minutes});
        reportIncident("MICROSUENO", "Microsueno critico detectado durante la ruta");
    }

    if (mouth > 0.055 && !yawnActive && now - lastYawnAt > 2500) {
        yawnActive = true;
        lastYawnAt = now;
        yawnCount += 1;
        // Guardar alerta de bostezo con evidencia
        yawnCountEl.textContent = yawnCount;
        saveAlertWithEvidence("BOSTEZO", "MEDIO", 40, getRecommendation("MEDIO"), true, {ojos: ear, bostezos: yawnCount, tiempo: minutes});
    }

    if (mouth < 0.04) {
        yawnActive = false;
    }

    const eyePenalty = ear < 0.2 ? 26 : ear < 0.23 ? 12 : 0;
    const sleepPenalty = Math.min(42, sleepCount * 14 + closedDuration * 18);
    const yawnPenalty = Math.min(28, yawnCount * 6);
    const timePenalty = Math.min(18, minutes * 1.6);
    const fatigue = Math.min(100, eyePenalty + sleepPenalty + yawnPenalty + timePenalty);
    const level = getLevel(fatigue);
    const recommendation = getRecommendation(level);

    updateMeter(fatigue, level);

    if (level === "ALTO" || level === "CRITICO") {
        startAlarm();
        // Guardar alerta con evidencia si ha pasado suficiente tiempo desde la última alerta alta o critica
        if (now - lastHighAlertAt > 20000) {
            lastHighAlertAt = now;
        saveAlertWithEvidence(level === "CRITICO" ? "FATIGA_CRITICA" : "FATIGA_ALTA", level, fatigue, recommendation, false, {ojos: ear, bostezos: yawnCount, tiempo: minutes});
        }
    } else {
        stopAlarm();
    }
}

async function startMonitoring() {
    connectionState.textContent = "Cargando IA";
    startButton.disabled = true;
    await startRoute();

    faceMesh = new FaceMesh({
        locateFile: (file) => `https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/${file}`
    });

    faceMesh.setOptions({
        maxNumFaces: 1,
        refineLandmarks: true,
        minDetectionConfidence: 0.6,
        minTrackingConfidence: 0.6
    });

    faceMesh.onResults(analyze);

    camera = new Camera(video, {
        onFrame: async () => {
            await faceMesh.send({ image: video });
        },
        width: 960,
        height: 720,
        facingMode: "user"
    });

    sessionStartedAt = Date.now();
    startTimer();
    await camera.start();
    startEvidenceRecorder();

    connectionState.textContent = "Monitoreando";
    routeState.textContent = "Monitoreando";
    stopButton.disabled = false;
}

async function stopMonitoring() {
    stopAlarm();
    clearInterval(timerId);
    timerId = null;
    startButton.disabled = false;
    stopButton.disabled = true;
    connectionState.textContent = "Detenido";

    if (watchId !== null) {
        navigator.geolocation.clearWatch(watchId);
        watchId = null;
    }

    await endRoute();
    stopEvidenceRecorder();

    if (video.srcObject) {
        video.srcObject.getTracks().forEach((track) => track.stop());
        video.srcObject = null;
    }
}

async function finishMonitoring() {
    await stopMonitoring();
    window.location.href = panelUrl;
}

startButton.addEventListener("click", () => {
    startMonitoring().catch((error) => {
        console.error(error);
        connectionState.textContent = "Sin camara";
        startButton.disabled = false;
        secureWarning.hidden = false;
    });
});

stopButton.addEventListener("click", stopMonitoring);

panelLink?.addEventListener("click", (event) => {
    if (!routeId) return;

    event.preventDefault();
    finishMonitoring().catch(() => {
        window.location.href = panelUrl;
    });
});

finishButton?.addEventListener("click", () => {
    finishMonitoring().catch(() => {
        window.location.href = panelUrl;
    });
});

// Botón de reporte de incidente manual
incidentButton.addEventListener("click", () => {
    reportIncident("ACCIDENTE", "Accidente reportado manualmente por el conductor");
    saveAlertWithEvidence("FATIGA_CRITICA", "CRITICO", 100, "Accidente reportado. Verificar ubicacion inmediatamente", true, {ojos: null, bostezos: yawnCount, tiempo: sessionStartedAt ? Math.floor((Date.now() - sessionStartedAt) / 60000) : 0});
});

if ("serviceWorker" in navigator) {
    navigator.serviceWorker.register("service-worker.js").catch(() => { });
}
