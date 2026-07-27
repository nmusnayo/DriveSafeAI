# DriveSafe AI

Sistema web/PWA para monitoreo de fatiga del conductor con camara, alertas y panel administrativo.

## Requisitos

- XAMPP con Apache y MySQL activos.
- PHP 8 o superior.
- Navegador moderno con soporte WebRTC.
- Para usar la camara desde celular: abrir el sistema por HTTPS o desde `localhost`. En celulares, `http://IP-DE-LA-PC` normalmente no permite camara por politicas del navegador.

## Instalacion

1. Copiar el proyecto en `D:\xampp\htdocs\DriveSafeAI`.
2. Iniciar Apache y MySQL en XAMPP.
3. Importar `database.sql` en MySQL:

```powershell
Get-Content database.sql | D:\xampp\mysql\bin\mysql.exe -u root
```

4. Abrir la aplicacion:

```text
http://localhost/DriveSafeAI/
```

## Usuarios demo

```text
Administrador: admin@drivesafe.ai
Conductor: conductor@drivesafe.ai
Password: admin123
```

## Modulos

- Acceso con sesiones PHP.
- Panel operativo con KPIs, grafico de niveles e historial.
- Monitoreo con camara del navegador usando MediaPipe Face Mesh.
- Seguimiento de ruta con geolocalizacion del navegador.
- Registro de posiciones GPS, incidentes y alertas con ubicacion.
- Registro de alertas en MySQL mediante API.
- Dashboard separado para administradores y conductores.
- Manifest y service worker para modo PWA.

## Acceso desde celular

Para probar desde celular en la misma red:

1. Configurar HTTPS en Apache/XAMPP o usar un tunel HTTPS de desarrollo.
2. Abrir la URL segura desde el celular.
3. Iniciar sesion y entrar en `Monitoreo`.
4. Presionar `Iniciar` y aceptar el permiso de camara.
5. Completar origen/destino para crear la ruta y aceptar el permiso de ubicacion.

La deteccion en navegador depende de los archivos de MediaPipe cargados desde CDN, por lo que el dispositivo necesita conexion a internet salvo que esos assets se hospeden localmente.

## Seguimiento de accidentes

Durante una ruta, el sistema guarda puntos GPS cada 10 segundos aproximadamente. Si el conductor presiona `Reportar accidente` o si se detecta un microsueno critico, se crea un incidente con la ultima ubicacion disponible. El administrador puede revisar esos eventos desde `Admin` o `Rutas` y abrir la ubicacion en Google Maps.
